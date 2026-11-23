<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Connection;
use Throwable;

class OwnershipBackfillSchema
{
    private const TABLES = [
        'users' => ['id', 'role', 'is_active'],
        'events' => ['id', 'workspace_id'],
        'workspaces' => ['id', 'name', 'created_at', 'updated_at'],
        'workspace_users' => ['id', 'workspace_id', 'user_id', 'role', 'is_active', 'created_at', 'updated_at'],
        'legacy_ownership_plans' => ['id', 'schema_version', 'manifest_hash', 'created_at', 'updated_at'],
        'legacy_workspace_mappings' => ['id', 'ownership_plan_id', 'manifest_reference', 'workspace_id', 'created_at', 'updated_at'],
    ];

    private const INSERTED_TABLES = ['workspaces', 'workspace_users', 'legacy_ownership_plans', 'legacy_workspace_mappings'];

    private const UNIQUES = [
        ['legacy_ownership_plans', ['manifest_hash']],
        ['legacy_workspace_mappings', ['ownership_plan_id', 'manifest_reference']],
        ['legacy_workspace_mappings', ['workspace_id']],
        ['workspace_users', ['workspace_id', 'user_id']],
    ];

    private const FOREIGN_KEYS = [
        ['legacy_workspace_mappings', 'ownership_plan_id', 'legacy_ownership_plans'],
        ['legacy_workspace_mappings', 'workspace_id', 'workspaces'],
        ['workspace_users', 'workspace_id', 'workspaces'],
        ['workspace_users', 'user_id', 'users'],
        ['events', 'workspace_id', 'workspaces'],
    ];

    public function assertReady(Connection $connection): void
    {
        // These checks deliberately precede even schema/session reads. Each
        // eventual workspace transaction must be a real commit boundary.
        if ($connection->transactionLevel() !== 0
            || ! in_array($connection->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            throw OwnershipBackfillException::because('invalid_prerequisites');
        }

        try {
            $this->assertDatabase($connection);
        } catch (OwnershipBackfillException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Never retain connection/query exceptions, bindings or SQL as a
            // previous exception or diagnostic output.
            throw OwnershipBackfillException::because('database_unavailable');
        }
    }

    private function assertDatabase(Connection $connection): void
    {
        if ($connection->getDriverName() === 'sqlite') {
            $this->requireReady((int) $connection->scalar('PRAGMA foreign_keys') === 1);
        } else {
            $this->requireReady((int) $connection->scalar('SELECT @@FOREIGN_KEY_CHECKS') === 1);
            $expected = array_map(fn (string $table): string => $connection->getTablePrefix().$table, array_keys(self::TABLES));
            $engines = $connection->query()->fromRaw('information_schema.tables')
                ->where('table_schema', $connection->getDatabaseName())->whereIn('table_name', $expected)
                ->selectRaw('TABLE_NAME AS table_name, ENGINE AS engine')->get()->keyBy('table_name');
            foreach ($expected as $table) {
                $this->requireReady(isset($engines[$table]) && strtolower((string) $engines[$table]->engine) === 'innodb');
            }
        }

        $schema = $connection->getSchemaBuilder();
        foreach (self::TABLES as $table => $required) {
            $this->requireReady($schema->hasTable($table));
            $columns = $schema->getColumns($table);
            $this->requireReady(array_diff($required, array_column($columns, 'name')) === []);
            $id = null;
            foreach ($columns as $column) {
                if ($column['name'] === 'id') {
                    $id = $column;
                    break;
                }
            }
            $this->requireReady($id !== null && in_array(strtolower($id['type_name']), ['integer', 'int', 'bigint', 'mediumint', 'smallint', 'tinyint'], true));
            if (in_array($table, self::INSERTED_TABLES, true)) {
                $this->requireReady($id['auto_increment'] === true);
            }
            $primary = false;
            $rowidPrimary = false;
            foreach ($schema->getIndexes($table) as $index) {
                if ($index['primary'] && $index['unique'] && $index['columns'] === ['id']) {
                    $primary = true;
                    $rowidPrimary = $index['name'] === 'primary';
                    break;
                }
            }
            $this->requireReady($primary && ! $connection->table($table)->whereNull('id')->exists());
            if ($connection->getDriverName() === 'sqlite' && in_array($table, self::INSERTED_TABLES, true)) {
                // INTEGER PRIMARY KEY DESC and WITHOUT ROWID can resemble an
                // allocator in normalized column metadata. Their real PK index
                // replaces Laravel's synthetic rowid-primary entry.
                $this->requireReady($rowidPrimary);
            }
        }

        foreach (self::UNIQUES as [$table, $columns]) {
            $this->requireReady($this->hasUniqueIndex($connection, $table, $columns));
        }
        $targetSchema = $connection->getDriverName() === 'sqlite' ? 'main' : $connection->getDatabaseName();
        foreach (self::FOREIGN_KEYS as [$table, $column, $parent]) {
            $matched = false;
            foreach ($schema->getForeignKeys($table) as $foreign) {
                if ($foreign['columns'] === [$column] && $foreign['foreign_columns'] === ['id']
                    && $foreign['foreign_schema'] === $targetSchema
                    && $foreign['foreign_table'] === $connection->getTablePrefix().$parent
                    && in_array(strtolower($foreign['on_delete']), ['restrict', 'no action'], true)
                    && in_array(strtolower($foreign['on_update']), ['restrict', 'no action'], true)) {
                    $matched = true;
                    break;
                }
            }
            $this->requireReady($matched);
        }
    }

    private function hasUniqueIndex(Connection $connection, string $table, array $columns): bool
    {
        sort($columns);
        $partialIndexes = [];
        if ($connection->getDriverName() === 'sqlite') {
            $partialIndexes = $connection->query()->fromRaw('pragma_index_list(?)', [$connection->getTablePrefix().$table])
                ->where('partial', 1)->pluck('name')->map(fn (string $name): string => strtolower($name))->all();
        }
        foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {
            $indexed = $index['columns'];
            sort($indexed);
            if (! $index['unique'] || $indexed !== $columns || in_array($index['name'], $partialIndexes, true)) {
                continue;
            }
            if ($connection->getDriverName() === 'sqlite' && ! $index['primary']
                && $connection->query()->fromRaw('pragma_index_xinfo(?)', [$index['name']])
                    ->where('key', 1)->where('cid', '<', 0)->exists()) {
                continue;
            }
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                // GROUP_CONCAT(column_name) in normalized metadata omits NULL
                // functional-index slots. Check every raw part before relying
                // on uniqueness for conflict detection or a transactional write.
                $parts = $connection->query()->fromRaw('information_schema.statistics')
                    ->where('table_schema', $connection->getDatabaseName())
                    ->where('table_name', $connection->getTablePrefix().$table)
                    ->whereRaw('LOWER(index_name) = ?', [strtolower($index['name'])])
                    ->selectRaw('COUNT(*) AS parts, COUNT(column_name) AS named_parts, COUNT(sub_part) AS prefix_parts')->first();
                if ((int) $parts->parts !== count($columns) || (int) $parts->named_parts !== count($columns)
                    || (int) $parts->prefix_parts !== 0) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    private function requireReady(bool $ready): void
    {
        if (! $ready) {
            throw OwnershipBackfillException::because('invalid_prerequisites');
        }
    }
}
