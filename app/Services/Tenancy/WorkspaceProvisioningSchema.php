<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Connection;
use Throwable;

class WorkspaceProvisioningSchema
{
    private const TABLES = [
        'users' => ['id', 'role', 'is_active'],
        'workspaces' => ['id', 'name', 'created_at', 'updated_at'],
        'workspace_users' => ['id', 'workspace_id', 'user_id', 'role', 'is_active', 'created_at', 'updated_at'],
        'workspace_provisionings' => ['id', 'creator_user_id', 'workspace_id', 'request_hash', 'name_hash', 'created_at', 'updated_at'],
    ];

    private const INSERTED_TABLES = ['workspaces', 'workspace_users', 'workspace_provisionings'];

    private const UNIQUES = [
        ['workspace_users', ['workspace_id', 'user_id']],
        ['workspace_provisionings', ['creator_user_id', 'request_hash']],
        ['workspace_provisionings', ['workspace_id']],
    ];

    private const FOREIGN_KEYS = [
        ['workspace_users', 'workspace_id', 'workspaces'],
        ['workspace_users', 'user_id', 'users'],
        ['workspace_provisionings', 'creator_user_id', 'users'],
        ['workspace_provisionings', 'workspace_id', 'workspaces'],
    ];

    public function assertReady(Connection $connection): void
    {
        // These checks deliberately precede even schema/session reads. Each
        // new workspace transaction must be a real commit boundary.
        if ($connection->transactionLevel() !== 0
            || ! in_array($connection->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            throw WorkspaceProvisioningException::because('invalid_prerequisites');
        }

        try {
            $this->assertDatabase($connection);
        } catch (WorkspaceProvisioningException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Never retain connection/query exceptions, bindings or SQL as a
            // previous exception or diagnostic output.
            throw WorkspaceProvisioningException::because('database_unavailable');
        }
    }

    private function assertDatabase(Connection $connection): void
    {
        if ($connection->getDriverName() === 'sqlite') {
            $this->requireReady((int) $connection->selectFromWriteConnection('PRAGMA foreign_keys')[0]->foreign_keys === 1);
        } else {
            $this->requireReady((int) $connection->selectFromWriteConnection('SELECT @@FOREIGN_KEY_CHECKS AS enabled')[0]->enabled === 1);
            $expected = array_map(fn (string $table): string => $connection->getTablePrefix().$table, array_keys(self::TABLES));
            $engines = $connection->query()->useWritePdo()->fromRaw('information_schema.tables')
                ->where('table_schema', $connection->getDatabaseName())->whereIn('table_name', $expected)
                ->selectRaw('TABLE_NAME AS table_name, ENGINE AS engine')->get()->keyBy('table_name');
            foreach ($expected as $table) {
                $this->requireReady(isset($engines[$table]) && strtolower((string) $engines[$table]->engine) === 'innodb');
            }
        }

        $schema = $connection->getSchemaBuilder();
        foreach (self::TABLES as $table => $required) {
            // Builder::hasTable uses scalar's default read PDO even when the
            // rest of its metadata helpers read the primary explicitly.
            $existsSql = $connection->getSchemaGrammar()->compileTableExists(null, $connection->getTablePrefix().$table);
            $this->requireReady((bool) $connection->scalar($existsSql, [], false));
            // Avoid SQLiteBuilder's auxiliary CREATE-SQL read on a replica.
            $columns = $connection->getDriverName() === 'sqlite'
                ? $connection->getPostProcessor()->processColumns($connection->selectFromWriteConnection(
                    $connection->getSchemaGrammar()->compileColumns(null, $connection->getTablePrefix().$table)
                ))
                : $schema->getColumns($table);
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
            $this->requireReady($primary && ! $connection->table($table)->useWritePdo()->whereNull('id')->exists());
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
            $partialIndexes = $connection->query()->useWritePdo()->fromRaw('pragma_index_list(?)', [$connection->getTablePrefix().$table])
                ->where('partial', 1)->pluck('name')->map(fn (string $name): string => strtolower($name))->all();
        }
        foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {
            $indexed = $index['columns'];
            sort($indexed);
            if (! $index['unique'] || $indexed !== $columns || in_array($index['name'], $partialIndexes, true)) {
                continue;
            }
            if ($connection->getDriverName() === 'sqlite' && ! $index['primary']
                && $connection->query()->useWritePdo()->fromRaw('pragma_index_xinfo(?)', [$index['name']])
                    ->where('key', 1)->where('cid', '<', 0)->exists()) {
                continue;
            }
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                // GROUP_CONCAT(column_name) in normalized metadata omits NULL
                // functional-index slots. Check every raw part before relying
                // on uniqueness for conflict detection or a transactional write.
                $parts = $connection->query()->useWritePdo()->fromRaw('information_schema.statistics')
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
            throw WorkspaceProvisioningException::because('invalid_prerequisites');
        }
    }
}
