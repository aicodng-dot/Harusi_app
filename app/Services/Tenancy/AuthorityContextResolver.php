<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Connection;
use Throwable;

/** Dormant reader: no runtime authorization path invokes this resolver. */
class AuthorityContextResolver
{
    private const TABLES = [
        'users' => ['id', 'role', 'is_active', 'is_platform_admin'],
        'workspaces' => ['id'],
        'workspace_users' => ['id', 'workspace_id', 'user_id', 'role', 'is_active'],
    ];

    public function resolve(Connection $connection, mixed $userId, mixed $workspaceId = null): AuthorityContext
    {
        // Reject ambiguous input before connecting or inspecting any schema.
        if (! is_int($userId) || $userId < 1
            || ($workspaceId !== null && (! is_int($workspaceId) || $workspaceId < 1))) {
            throw AuthorityContextException::because('invalid_identifiers');
        }

        try {
            $this->assertSchema($connection);
            $accounts = $connection->table('users')->useWritePdo()
                ->select(['id', 'role', 'is_active', 'is_platform_admin'])
                ->where('id', $userId)->orderBy('id')->limit(2)->get();
            if ($accounts->count() !== 1) {
                return new AuthorityContext(false, false, null);
            }
            $account = $accounts->first();
            if ($this->storedId($account->id) !== $userId || ! $this->isTrue($account->is_active)
                || ! in_array($account->role, ['admin', 'scanner', 'customer'], true)) {
                return new AuthorityContext(false, false, null);
            }

            $platform = $this->isTrue($account->is_platform_admin);
            $role = $workspaceId === null ? null : $this->membershipRole($connection, $userId, $workspaceId);

            return new AuthorityContext(true, $platform, $role);
        } catch (AuthorityContextException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Discard raw errors, SQL, bindings and private account context.
            throw AuthorityContextException::because('database_unavailable');
        }
    }

    private function assertSchema(Connection $connection): void
    {
        $driver = $connection->getDriverName();
        $this->requireReady(in_array($driver, ['sqlite', 'mysql', 'mariadb'], true));
        $schema = $connection->getSchemaBuilder();
        foreach (self::TABLES as $table => $required) {
            // SQLiteBuilder also makes an auxiliary CREATE-SQL read through
            // the read PDO. This guard needs only column/type/null metadata,
            // so process its primary PRAGMA rows without that unused lookup.
            $columns = $driver === 'sqlite'
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
            $this->requireReady($id !== null && in_array(strtolower($id['type_name'] ?? ''), [
                'integer', 'int', 'bigint', 'mediumint', 'smallint', 'tinyint',
            ], true));
            // SQLite may report a declared integer PRIMARY KEY as nullable.
            // Every selected row's raw ID is checked without coercion instead;
            // this reader performs no unbounded null-ID or other row scan.
            $this->requireReady(($id['nullable'] ?? null) === false || $driver === 'sqlite');
            $primary = false;
            foreach ($schema->getIndexes($table) as $index) {
                if (($index['primary'] ?? false) === true && ($index['unique'] ?? false) === true
                    && ($index['columns'] ?? []) === ['id']) {
                    $primary = true;
                    break;
                }
            }
            $this->requireReady($primary);
        }
    }

    private function membershipRole(Connection $connection, int $userId, int $workspaceId): ?string
    {
        $parents = $connection->table('workspaces')->useWritePdo()->select('id')
            ->where('id', $workspaceId)->orderBy('id')->limit(2)->get();
        if ($parents->count() !== 1 || $this->storedId($parents->first()->id) !== $workspaceId) {
            return null;
        }
        $memberships = $connection->table('workspace_users')->useWritePdo()
            ->select(['id', 'workspace_id', 'user_id', 'role', 'is_active'])
            ->where('workspace_id', $workspaceId)->where('user_id', $userId)->orderBy('id')->limit(2)->get();
        if ($memberships->count() !== 1) {
            return null;
        }
        $membership = $memberships->first();
        if ($this->storedId($membership->id) === null
            || $this->storedId($membership->workspace_id) !== $workspaceId
            || $this->storedId($membership->user_id) !== $userId
            || ! $this->isTrue($membership->is_active)
            || ! in_array($membership->role, ['owner', 'manager'], true)) {
            return null;
        }

        return $membership->role;
    }

    private function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    private function storedId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id !== false) {
                return $id;
            }
        }

        return null;
    }

    private function requireReady(bool $ready): void
    {
        if (! $ready) {
            throw AuthorityContextException::because('invalid_prerequisites');
        }
    }
}
