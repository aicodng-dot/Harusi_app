<?php

namespace Tests\Feature;

use App\Services\Tenancy\OwnershipBackfillException;
use App\Services\Tenancy\OwnershipBackfillSchema;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\SQLiteBuilder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OwnershipBackfillSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['users', 'events', 'workspaces', 'workspace_users', 'legacy_ownership_plans', 'legacy_workspace_mappings'];

    private array $ownedConnections = [];

    protected function tearDown(): void
    {
        foreach ($this->ownedConnections as $name) {
            DB::purge($name);
        }

        parent::tearDown();
    }

    public function test_actual_migrated_schema_is_checked_read_only_on_the_explicit_connection(): void
    {
        $connection = $this->fixture();
        $before = $this->snapshot($connection);
        $source = DB::connection('sqlite');
        $sourceBefore = $this->snapshot($source);
        $default = DB::getDefaultConnection();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries, $connection): void {
            if ($query->connectionName === $connection->getName()) {
                $queries[] = $query->sql;
            }
        });
        DB::setDefaultConnection('private-unconfigured-default-marker');
        try {
            (new OwnershipBackfillSchema)->assertReady($connection);
            (new OwnershipBackfillSchema)->assertReady($connection);
            $this->assertSame('private-unconfigured-default-marker', DB::getDefaultConnection());
        } finally {
            DB::setDefaultConnection($default);
        }

        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace|create|alter|drop|truncate|attach|detach|vacuum)\b/i', $query);
        }
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame($sourceBefore, $this->snapshot($source));
        $this->assertSame(0, $connection->transactionLevel());
    }

    #[DataProvider('missingPrerequisites')]
    public function test_missing_table_or_column_is_refused_without_mutation(string $kind): void
    {
        $connection = $this->fixture();
        if ($kind === 'table') {
            $connection->getSchemaBuilder()->drop('legacy_workspace_mappings');
        } else {
            $connection->getSchemaBuilder()->table('legacy_ownership_plans', function (Blueprint $table): void {
                $table->dropColumn('schema_version');
            });
        }

        $this->assertRejectedUnchanged($connection);
    }

    public static function missingPrerequisites(): array
    {
        return ['missing table' => ['table'], 'missing column' => ['column']];
    }

    #[DataProvider('unsafeWorkspaceKeys')]
    public function test_workspace_key_must_be_integer_primary_and_allocate_real_inserted_ids(string $definition): void
    {
        $connection = $this->fixture();
        $connection->getSchemaBuilder()->drop('workspaces');
        $connection->statement($definition);

        $this->assertRejectedUnchanged($connection);
    }

    public static function unsafeWorkspaceKeys(): array
    {
        return [
            'unique not primary' => ['CREATE TABLE workspaces (id INTEGER NOT NULL UNIQUE, name TEXT NOT NULL, created_at DATETIME, updated_at DATETIME)'],
            'text primary' => ['CREATE TABLE workspaces (id TEXT PRIMARY KEY NOT NULL, name TEXT NOT NULL, created_at DATETIME, updated_at DATETIME)'],
            'composite primary' => ['CREATE TABLE workspaces (id INTEGER NOT NULL, name TEXT NOT NULL, created_at DATETIME, updated_at DATETIME, PRIMARY KEY(id, name))'],
            'integer without allocator' => ['CREATE TABLE workspaces (id BIGINT PRIMARY KEY NOT NULL, name TEXT NOT NULL, created_at DATETIME, updated_at DATETIME)'],
            'descending integer primary is not rowid alias' => ['CREATE TABLE workspaces (id INTEGER PRIMARY KEY DESC NOT NULL, name TEXT NOT NULL, created_at DATETIME, updated_at DATETIME)'],
            'without rowid cannot allocate IDs' => ['CREATE TABLE workspaces (id INTEGER PRIMARY KEY NOT NULL, name TEXT NOT NULL, created_at DATETIME, updated_at DATETIME) WITHOUT ROWID'],
        ];
    }

    public function test_observed_null_user_primary_keys_are_not_safe_even_with_integer_key_metadata(): void
    {
        $connection = $this->fixture();
        $connection->getSchemaBuilder()->drop('users');
        $connection->statement('CREATE TABLE users (id BIGINT PRIMARY KEY, role TEXT NOT NULL, is_active INTEGER NOT NULL)');
        $connection->table('users')->insert(['id' => null, 'role' => 'admin', 'is_active' => 1]);

        $this->assertRejectedUnchanged($connection);
    }

    #[DataProvider('requiredUniqueIndexes')]
    public function test_every_required_unique_constraint_is_verified(string $table, string $index): void
    {
        $connection = $this->fixture();
        $connection->getSchemaBuilder()->table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropUnique($index);
        });

        $this->assertRejectedUnchanged($connection);
    }

    public static function requiredUniqueIndexes(): array
    {
        return [
            'plan hash' => ['legacy_ownership_plans', 'legacy_plan_hash_unique'],
            'plan reference' => ['legacy_workspace_mappings', 'legacy_mapping_plan_ref_unique'],
            'mapping workspace' => ['legacy_workspace_mappings', 'legacy_mapping_workspace_unique'],
            'membership pair' => ['workspace_users', 'workspace_users_workspace_id_user_id_unique'],
        ];
    }

    #[DataProvider('insufficientUniqueIndexes')]
    public function test_partial_expression_or_wider_unique_indexes_cannot_stand_in_for_required_uniqueness(string $replacement): void
    {
        $connection = $this->fixture();
        $connection->statement('DROP INDEX legacy_plan_hash_unique');
        $connection->statement($replacement);

        $this->assertRejectedUnchanged($connection);
    }

    public static function insufficientUniqueIndexes(): array
    {
        return [
            'partial' => ['CREATE UNIQUE INDEX fixture_plan_hash_unique ON legacy_ownership_plans(manifest_hash) WHERE schema_version = 1'],
            'expression omitted by normalized metadata' => ['CREATE UNIQUE INDEX fixture_plan_hash_unique ON legacy_ownership_plans(manifest_hash, lower(CAST(schema_version AS TEXT)))'],
            'wider column set' => ['CREATE UNIQUE INDEX fixture_plan_hash_unique ON legacy_ownership_plans(manifest_hash, schema_version)'],
        ];
    }

    #[DataProvider('requiredForeignKeys')]
    public function test_every_required_restrictive_foreign_key_is_verified(string $table, string $column): void
    {
        $connection = $this->fixture();
        $connection->getSchemaBuilder()->table($table, function (Blueprint $blueprint) use ($column): void {
            // SQLite FK names are not preserved by normalized metadata; remove
            // this known single-column link by its columns, not a guessed name.
            $blueprint->dropForeign([$column]);
        });

        $this->assertRejectedUnchanged($connection);
    }

    public static function requiredForeignKeys(): array
    {
        return [
            'mapping plan' => ['legacy_workspace_mappings', 'ownership_plan_id'],
            'mapping workspace' => ['legacy_workspace_mappings', 'workspace_id'],
            'member workspace' => ['workspace_users', 'workspace_id'],
            'member user' => ['workspace_users', 'user_id'],
            'event workspace' => ['events', 'workspace_id'],
        ];
    }

    #[DataProvider('incorrectForeignKeys')]
    public function test_wrong_parent_or_cascading_actions_are_not_restrictive_expected_links(string $kind): void
    {
        $connection = $this->fixture();
        $connection->getSchemaBuilder()->table('events', function (Blueprint $table) use ($kind): void {
            $table->dropForeign(['workspace_id']);
            $foreign = $table->foreign('workspace_id')->references('id')->on($kind === 'wrong parent' ? 'users' : 'workspaces');
            if ($kind === 'delete cascade') {
                $foreign->cascadeOnDelete();
            } else {
                $foreign->restrictOnDelete();
            }
            if ($kind === 'update cascade') {
                $foreign->cascadeOnUpdate();
            }
        });

        $this->assertRejectedUnchanged($connection);
    }

    public static function incorrectForeignKeys(): array
    {
        return ['wrong parent' => ['wrong parent'], 'delete cascade' => ['delete cascade'], 'update cascade' => ['update cascade']];
    }

    public function test_disabled_sqlite_foreign_key_enforcement_is_refused_and_not_turned_on(): void
    {
        $connection = $this->fixture();
        $connection->getSchemaBuilder()->disableForeignKeyConstraints();

        $this->assertRejectedUnchanged($connection);

        $this->assertSame(0, $connection->scalar('PRAGMA foreign_keys'));
    }

    public function test_ambient_transaction_is_refused_before_any_sql(): void
    {
        $connection = $this->fixture();
        $connection->beginTransaction();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries, $connection): void {
            if ($query->connectionName === $connection->getName()) {
                $queries[] = $query->sql;
            }
        });
        try {
            $this->assertReason($connection, 'invalid_prerequisites');
            $this->assertSame([], $queries);
            $this->assertSame(1, $connection->transactionLevel());
        } finally {
            $connection->rollBack();
        }
    }

    public function test_unsupported_driver_is_refused_before_any_sql(): void
    {
        $base = $this->fixture();
        $connection = Mockery::mock(SQLiteConnection::class.'[getDriverName]', [$base->getPdo(), ':memory:', '', $base->getConfig()]);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $queries = [];
        $connection->setEventDispatcher($base->getEventDispatcher());
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertReason($connection, 'invalid_prerequisites');

        $this->assertSame([], $queries);
    }

    #[DataProvider('unsafeMysqlEnforcement')]
    public function test_mysql_requires_enforced_foreign_keys_and_innodb_for_all_relevant_tables(int $foreignKeyChecks, bool $nonTransactional): void
    {
        $base = $this->fixture();
        // This attached metadata fixture exercises the rejection decisions with
        // real SQLite reads. It does not certify a MySQL engine or connection.
        $base->statement("ATTACH DATABASE ':memory:' AS information_schema");
        $base->statement('CREATE TABLE information_schema.tables (table_schema TEXT, table_name TEXT, engine TEXT)');
        foreach (self::TABLES as $table) {
            $base->table('information_schema.tables')->insert([
                'table_schema' => ':memory:', 'table_name' => $table,
                'engine' => $nonTransactional && $table === 'events' ? 'MyISAM' : 'InnoDB',
            ]);
        }
        $connection = Mockery::mock(SQLiteConnection::class.'[getDriverName,scalar]', [$base->getPdo(), ':memory:', '', $base->getConfig()]);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('scalar')->with('SELECT @@FOREIGN_KEY_CHECKS')->once()->andReturn($foreignKeyChecks);
        $before = $this->snapshot($base);
        $metadata = $base->table('information_schema.tables')->get()->all();

        $this->assertReason($connection, 'invalid_prerequisites');

        $this->assertSame($before, $this->snapshot($base));
        $this->assertEquals($metadata, $base->table('information_schema.tables')->get()->all());
    }

    public static function unsafeMysqlEnforcement(): array
    {
        return ['foreign key checks disabled' => [0, false], 'nontransactional relevant table' => [1, true]];
    }

    public function test_mysql_prefix_index_metadata_cannot_prove_exact_full_column_uniqueness(): void
    {
        $base = $this->fixture();
        // Execute real SQLite metadata reads while modelling MySQL's engine,
        // schema labels and raw index parts. A clean baseline must pass before
        // introducing the shortened index, so another failed prerequisite
        // cannot accidentally make this rejection regression pass.
        $base->statement("ATTACH DATABASE ':memory:' AS information_schema");
        $base->statement('CREATE TABLE information_schema.tables (table_schema TEXT, table_name TEXT, engine TEXT)');
        $base->statement('CREATE TABLE information_schema.statistics (table_schema TEXT, table_name TEXT, index_name TEXT, column_name TEXT, sub_part INTEGER)');
        foreach (self::TABLES as $table) {
            $base->table('information_schema.tables')->insert(['table_schema' => ':memory:', 'table_name' => $table, 'engine' => 'InnoDB']);
            foreach ($base->getSchemaBuilder()->getIndexes($table) as $index) {
                foreach ($index['columns'] as $column) {
                    $base->table('information_schema.statistics')->insert([
                        'table_schema' => ':memory:', 'table_name' => $table,
                        'index_name' => $index['name'], 'column_name' => $column, 'sub_part' => null,
                    ]);
                }
            }
        }
        $connection = Mockery::mock(SQLiteConnection::class.'[getDriverName,scalar,getSchemaBuilder]', [$base->getPdo(), ':memory:', '', $base->getConfig()]);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('scalar')->andReturnUsing(function ($query, $bindings = [], $useReadPdo = true) use ($base) {
            return $query === 'SELECT @@FOREIGN_KEY_CHECKS' ? 1 : $base->scalar($query, $bindings, $useReadPdo);
        });
        $connection->useDefaultSchemaGrammar();
        $schema = Mockery::mock(SQLiteBuilder::class.'[getForeignKeys]', [$connection]);
        $schema->shouldReceive('getForeignKeys')->andReturnUsing(function ($table) use ($base): array {
            return array_map(function (array $foreign): array {
                $foreign['foreign_schema'] = ':memory:';

                return $foreign;
            }, $base->getSchemaBuilder()->getForeignKeys($table));
        });
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        (new OwnershipBackfillSchema)->assertReady($connection);
        $this->addToAssertionCount(1);
        $base->table('information_schema.statistics')->where('table_name', 'legacy_ownership_plans')
            ->where('index_name', 'legacy_plan_hash_unique')->update(['sub_part' => 1]);
        $before = $this->snapshot($base);
        $metadata = $base->table('information_schema.statistics')->get()->all();

        $this->assertReason($connection, 'invalid_prerequisites');

        $this->assertSame($before, $this->snapshot($base));
        $this->assertEquals($metadata, $base->table('information_schema.statistics')->get()->all());
    }

    public function test_unavailable_metadata_is_sanitized_without_previous_exception_or_logs(): void
    {
        $base = $this->fixture();
        $connection = Mockery::mock(SQLiteConnection::class.'[scalar]', [$base->getPdo(), ':memory:', '', $base->getConfig()]);
        $connection->shouldReceive('scalar')->with('PRAGMA foreign_keys')->once()->andThrow(new RuntimeException('SQLSTATE private-password-marker private-dsn-marker SELECT private_data'));
        Log::spy();

        $this->assertReason($connection, 'database_unavailable');

        foreach (['log', 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    public function test_safe_exception_reasons_are_allowlisted_and_never_reflect_unknown_content(): void
    {
        foreach (['platform_proposal_unsupported', 'invalid_prerequisites', 'eligibility_changed', 'coverage_conflict', 'ledger_conflict', 'database_unavailable'] as $reason) {
            $exception = OwnershipBackfillException::because($reason);
            $this->assertSame($reason, $exception->reason());
            $this->assertSame('Ownership backfill could not be completed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $caught = null;
        try {
            OwnershipBackfillException::because('private-password-marker SQLSTATE private-data-marker');
        } catch (InvalidArgumentException $exception) {
            $caught = $exception;
        }
        $this->assertNotNull($caught);
        $this->assertSame('Invalid ownership backfill reason.', $caught->getMessage());
        $this->assertNull($caught->getPrevious());
    }

    private function assertRejectedUnchanged(Connection $connection): void
    {
        $before = $this->snapshot($connection);
        $default = DB::getDefaultConnection();

        $this->assertReason($connection, 'invalid_prerequisites');

        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame($default, DB::getDefaultConnection());
    }

    private function assertReason(Connection $connection, string $expected): void
    {
        $caught = null;
        try {
            (new OwnershipBackfillSchema)->assertReady($connection);
        } catch (OwnershipBackfillException $exception) {
            $caught = $exception;
        }
        $this->assertNotNull($caught, 'Unsafe prerequisites must be refused.');
        $this->assertSame($expected, $caught->reason());
        $this->assertSame('Ownership backfill could not be completed.', $caught->getMessage());
        $this->assertNull($caught->getPrevious());
    }

    private function fixture(): Connection
    {
        $name = 'ownership_backfill_schema_'.count($this->ownedConnections);
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        $this->ownedConnections[] = $name;
        $connection = DB::connection($name);
        $source = DB::connection('sqlite');
        $objects = $source->table('sqlite_master')->whereIn('type', ['table', 'index'])->whereIn('tbl_name', self::TABLES)
            ->whereNotNull('sql')->orderByRaw("CASE type WHEN 'table' THEN 0 ELSE 1 END")->orderBy('name')->get(['type', 'name', 'sql']);
        foreach ($objects as $object) {
            $connection->statement($object->sql);
        }
        foreach (self::TABLES as $table) {
            foreach ($source->table($table)->get() as $row) {
                $connection->table($table)->insert((array) $row);
            }
        }
        $connection->getSchemaBuilder()->enableForeignKeyConstraints();
        $this->assertSame(0, $connection->transactionLevel());

        return $connection;
    }

    private function snapshot(Connection $connection): array
    {
        $schema = $connection->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name');
        $contents = [];
        foreach ($schema as $object) {
            if ($object->type !== 'table') {
                continue;
            }
            $rows = $connection->table($object->name)->get()->map(fn (object $row): array => (array) $row)->all();
            usort($rows, fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));
            $contents[$object->name] = $rows;
        }

        return ['schema' => array_map(fn (object $row): array => (array) $row, $schema), 'contents' => $contents];
    }
}
