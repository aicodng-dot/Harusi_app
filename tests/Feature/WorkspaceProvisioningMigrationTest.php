<?php

namespace Tests\Feature;

use App\Services\Tenancy\LegacyDataInventory;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkspaceProvisioningMigrationTest extends TestCase
{
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $name) {
            DB::purge($name);
        }
        parent::tearDown();
    }

    public function test_empty_expansion_and_reversal_preserve_both_populated_parents(): void
    {
        $connection = $this->fixture();
        $before = $this->snapshot($connection);
        $migration = $this->migration($connection);

        $migration->up();

        $this->assertSame(0, $connection->table('workspace_provisionings')->count());
        $this->assertSame([], $connection->select('PRAGMA foreign_key_check'));
        $this->assertSame($before['rows']['users'], $this->snapshot($connection)['rows']['users']);
        $this->assertSame($before['rows']['workspaces'], $this->snapshot($connection)['rows']['workspaces']);
        $migration->down();
        $this->assertSame($before, $this->snapshot($connection));
        $migration->up();
        $this->assertTrue($connection->getSchemaBuilder()->hasTable('workspace_provisionings'));
        $this->assertFalse($migration->withinTransaction);
    }

    public function test_populated_down_refuses_without_losing_creation_or_parent_records(): void
    {
        $connection = $this->fixture();
        $migration = $this->migration($connection);
        $migration->up();
        $this->insertReceipt($connection);
        $before = $this->snapshot($connection);

        $this->assertRefused(fn () => $migration->down());

        $this->assertSame($before, $this->snapshot($connection));
    }

    #[DataProvider('constraintViolations')]
    public function test_creator_request_workspace_and_parent_constraints_reject_invalid_changes(string $operation): void
    {
        $connection = $this->fixture();
        $this->migration($connection)->up();
        $this->insertReceipt($connection);
        $before = $this->snapshot($connection);
        try {
            match ($operation) {
                'duplicate request' => $this->insertReceipt($connection, ['workspace_id' => 12]),
                'duplicate workspace' => $this->insertReceipt($connection, ['request_hash' => str_repeat('b', 64)]),
                'missing creator' => $this->insertReceipt($connection, ['creator_user_id' => 999, 'workspace_id' => 12]),
                'missing workspace' => $this->insertReceipt($connection, ['workspace_id' => 999, 'request_hash' => str_repeat('b', 64)]),
                'delete creator' => $connection->table('users')->where('id', 21)->delete(),
                'delete workspace' => $connection->table('workspaces')->where('id', 11)->delete(),
            };
            $this->fail('Expected a database constraint refusal.');
        } catch (QueryException) {
            $this->assertSame($before, $this->snapshot($connection));
        }
        $this->assertSame([], $connection->select('PRAGMA foreign_key_check'));
    }

    public static function constraintViolations(): array
    {
        return array_combine($values = ['duplicate request', 'duplicate workspace', 'missing creator', 'missing workspace', 'delete creator', 'delete workspace'], array_map(fn (string $value): array => [$value], $values));
    }

    public function test_existing_partial_table_and_view_are_never_adopted(): void
    {
        foreach (['complete', 'partial', 'view'] as $shape) {
            $connection = $this->fixture();
            $migration = $this->migration($connection);
            if ($shape === 'complete') {
                $migration->up();
            } elseif ($shape === 'partial') {
                $connection->getSchemaBuilder()->create('workspace_provisionings', function (Blueprint $table): void {
                    $table->string('private_marker');
                });
            } else {
                $connection->statement('CREATE VIEW workspace_provisionings AS SELECT id FROM users');
            }
            $before = $this->snapshot($connection);
            $this->assertRefused(fn () => $migration->up());
            $this->assertSame($before, $this->snapshot($connection));
            if ($shape !== 'complete') {
                $this->assertRefused(fn () => $migration->down());
                $this->assertSame($before, $this->snapshot($connection));
            }
        }
    }

    public function test_ambient_sqlite_transactions_refuse_before_any_schema_or_data_query(): void
    {
        $connection = $this->fixture();
        $migration = $this->migration($connection);
        foreach (['up', 'down'] as $operation) {
            $connection->beginTransaction();
            $connection->enableQueryLog();
            $connection->flushQueryLog();
            try {
                $this->assertRefused(fn () => $migration->$operation());
                $this->assertSame([], $connection->getQueryLog());
                $this->assertSame(1, $connection->transactionLevel());
            } finally {
                $connection->disableQueryLog();
                $connection->rollBack();
            }
        }
    }

    public function test_missing_and_non_primary_parents_refuse_before_creating_a_ledger(): void
    {
        foreach (['users', 'workspaces'] as $parent) {
            foreach (['missing', 'nonprimary'] as $shape) {
                $connection = $this->fixture();
                $connection->getSchemaBuilder()->drop($parent);
                if ($shape === 'nonprimary') {
                    $connection->getSchemaBuilder()->create($parent, function (Blueprint $table): void {
                        $table->integer('id');
                    });
                }
                $before = $this->snapshot($connection);
                $this->assertRefused(fn () => $this->migration($connection)->up());
                $this->assertSame($before, $this->snapshot($connection));
            }
        }
    }

    public function test_inventory_counts_provisioning_without_exposing_private_hashes_or_names(): void
    {
        $connection = $this->fixture();
        $this->migration($connection)->up();
        $this->insertReceipt($connection);
        $before = $this->snapshot($connection);

        $report = app(LegacyDataInventory::class)->inspect($connection);

        $this->assertCount(32, $report['tables']);
        $this->assertSame(['present' => true, 'rows' => 1, 'missing_columns' => []], $report['tables']['workspace_provisionings']);
        $this->assertFalse($report['rollout_ready']);
        $output = json_encode($report, JSON_THROW_ON_ERROR);
        foreach ([str_repeat('a', 64), str_repeat('c', 64), 'Private existing workspace', 'Private existing user'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        $this->assertSame($before, $this->snapshot($connection));
    }

    private function fixture(): Connection
    {
        $name = 'provisioning_migration_'.count($this->connections);
        $this->connections[] = $name;
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $connection = DB::connection($name);
        foreach (['users', 'workspaces'] as $table) {
            $connection->getSchemaBuilder()->create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->string('name');
            });
        }
        $connection->table('users')->insert(['id' => 21, 'name' => 'Private existing user']);
        $connection->table('workspaces')->insert([['id' => 11, 'name' => 'Private existing workspace'], ['id' => 12, 'name' => 'Private second workspace']]);

        return $connection;
    }

    private function migration(Connection $connection): Migration
    {
        $migration = require database_path('migrations/2026_10_21_000000_add_workspace_provisionings.php');
        (new \ReflectionProperty($migration, 'connection'))->setValue($migration, $connection->getName());

        return $migration;
    }

    private function insertReceipt(Connection $connection, array $overrides = []): bool
    {
        return $connection->table('workspace_provisionings')->insert(array_replace([
            'creator_user_id' => 21, 'workspace_id' => 11, 'request_hash' => str_repeat('a', 64), 'name_hash' => str_repeat('c', 64),
        ], $overrides));
    }

    private function assertRefused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a guarded migration refusal.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('Workspace provisioning', $exception->getMessage());
        }
    }

    private function snapshot(Connection $connection): array
    {
        $rows = [];
        $schema = $connection->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name');
        foreach ($schema as $object) {
            if ($object->type === 'table') {
                $rows[$object->name] = array_map(fn (object $row): array => (array) $row, $connection->table($object->name)->get()->all());
            }
        }

        // A dropped empty SQLite AUTOINCREMENT table may retain sqlite_sequence
        // itself; fixtures already contain it through both parent allocators.
        return ['schema' => array_map(fn (object $row): array => (array) $row, $schema), 'rows' => $rows];
    }
}
