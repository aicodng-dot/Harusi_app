<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkspaceSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private array $ownedConnections = [];

    protected function tearDown(): void
    {
        foreach ($this->ownedConnections as $name) {
            DB::purge($name);
        }

        parent::tearDown();
    }

    public function test_populated_legacy_upgrade_preserves_identity_admission_and_offline_references(): void
    {
        $connection = $this->isolatedSchema(true);
        $this->populateLegacy($connection);
        $before = $this->rowContents($connection);
        $default = DB::getDefaultConnection();
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($this->migration()->withinTransaction);

        $this->apply($connection, 'up');

        $after = $this->rowContents($connection);
        $this->assertSame([], $after['workspaces']);
        $this->assertSame([], $after['workspace_users']);
        unset($after['workspaces'], $after['workspace_users']);
        foreach ($after['events'] as &$row) {
            $this->assertNull($row['workspace_id']);
            unset($row['workspace_id']);
        }
        unset($row);
        foreach ($after['users'] as &$row) {
            $this->assertSame(0, $row['is_platform_admin']);
            unset($row['is_platform_admin']);
        }
        unset($row);
        $this->assertSame($before, $after);
        $this->assertSame($default, DB::getDefaultConnection());
        $this->assertSame([], $connection->select('PRAGMA foreign_key_check'));
        $this->assertSame(201, $connection->table('offline_scanner_sessions')->where('id', 701)->value('event_id'));
        $this->assertSame(201, $connection->table('users')->where('id', 211)->value('event_id'));
        $this->assertSame('0042', $connection->table('guests')->where('id', 301)->value('manual_code'));
        $this->assertSame(2, $connection->table('guests')->where('id', 301)->value('used_entries'));
        $this->assertSame(str_repeat('a', 64), $connection->table('qr_codes')->where('id', 401)->value('qr_token'));
        $this->assertSame(1, $connection->scalar('PRAGMA foreign_keys'));
    }

    public function test_database_constraints_reject_invalid_membership_ownership_and_parent_deletion(): void
    {
        $connection = $this->isolatedSchema();
        $this->populateLegacy($connection);
        $connection->table('workspaces')->insert([
            ['id' => 51, 'name' => 'First workspace'],
            ['id' => 52, 'name' => 'Event-only workspace'],
        ]);
        $connection->table('workspace_users')->insert([
            'workspace_id' => 51, 'user_id' => 211, 'role' => 'owner',
        ]);
        $connection->table('events')->where('id', 202)->update(['workspace_id' => 52]);
        $this->assertSame(0, $connection->table('workspace_users')->value('is_active'));
        $this->assertTrue($connection->getSchemaBuilder()->hasIndex('workspace_users', ['user_id']));

        $attempts = [
            'duplicate membership' => fn () => $connection->table('workspace_users')->insert(['workspace_id' => 51, 'user_id' => 211, 'role' => 'manager']),
            'unsupported role' => fn () => $connection->table('workspace_users')->insert(['workspace_id' => 51, 'user_id' => 212, 'role' => 'admin']),
            'missing role' => fn () => $connection->table('workspace_users')->insert(['workspace_id' => 51, 'user_id' => 212]),
            'orphan workspace' => fn () => $connection->table('workspace_users')->insert(['workspace_id' => 999999, 'user_id' => 212, 'role' => 'manager']),
            'orphan user' => fn () => $connection->table('workspace_users')->insert(['workspace_id' => 51, 'user_id' => 999999, 'role' => 'manager']),
            'orphan event ownership' => fn () => $connection->table('events')->where('id', 201)->update(['workspace_id' => 999999]),
            'workspace with membership' => fn () => $connection->table('workspaces')->where('id', 51)->delete(),
            'workspace with event' => fn () => $connection->table('workspaces')->where('id', 52)->delete(),
            'user with membership' => fn () => $connection->table('users')->where('id', 211)->delete(),
        ];
        foreach ($attempts as $label => $attempt) {
            $before = $this->snapshot($connection);
            try {
                $attempt();
                $this->fail('Database constraint should reject '.$label.'.');
            } catch (QueryException) {
                $this->assertSame($before, $this->snapshot($connection), $label);
            }
        }
        $this->assertSame([], $connection->select('PRAGMA foreign_key_check'));
    }

    public function test_empty_expansion_reverses_and_reapplies_without_losing_legacy_rows(): void
    {
        $connection = $this->isolatedSchema();
        $this->populateLegacy($connection);
        $expandedRows = $this->rowContents($connection);

        $this->apply($connection, 'down');

        $schema = $connection->getSchemaBuilder();
        $this->assertFalse($schema->hasTable('workspaces'));
        $this->assertFalse($schema->hasTable('workspace_users'));
        $this->assertFalse($schema->hasColumn('events', 'workspace_id'));
        $this->assertFalse($schema->hasColumn('users', 'is_platform_admin'));
        $this->assertSame([], $connection->select('PRAGMA foreign_key_check'));

        $this->apply($connection, 'up');

        $this->assertSame($expandedRows, $this->rowContents($connection));
        $this->assertSame([], $connection->select('PRAGMA foreign_key_check'));
        $this->assertSame(1, $connection->scalar('PRAGMA foreign_keys'));
    }

    #[DataProvider('populatedRollbackGuards')]
    public function test_each_populated_rollback_guard_preserves_every_table_and_record(string $guard): void
    {
        $connection = $this->isolatedSchema();
        $this->populateLegacy($connection);
        if ($guard === 'workspace') {
            $connection->table('workspaces')->insert(['name' => 'Must preserve']);
        } elseif ($guard === 'membership') {
            // Deliberately orphaned fixture proves the membership guard itself
            // is effective even when no workspace row remains.
            $connection->getSchemaBuilder()->disableForeignKeyConstraints();
            $connection->table('workspace_users')->insert(['workspace_id' => 999999, 'user_id' => 211, 'role' => 'owner']);
            $connection->getSchemaBuilder()->enableForeignKeyConstraints();
        } elseif ($guard === 'event ownership') {
            // A damaged legacy reference must not be erased by rollback either.
            $connection->getSchemaBuilder()->disableForeignKeyConstraints();
            $connection->table('events')->where('id', 201)->update(['workspace_id' => 999999]);
            $connection->getSchemaBuilder()->enableForeignKeyConstraints();
        } elseif ($guard === 'unknown null platform flag') {
            $connection->getSchemaBuilder()->table('users', function (Blueprint $table): void {
                $table->boolean('is_platform_admin')->nullable()->default(false)->change();
            });
            $connection->table('users')->where('id', 211)->update(['is_platform_admin' => null]);
        } else {
            $connection->table('users')->where('id', 211)->update(['is_platform_admin' => $guard === 'noncanonical true platform flag' ? 2 : 1]);
        }
        $before = $this->snapshot($connection);
        $default = DB::getDefaultConnection();

        $this->assertRefused(fn () => $this->apply($connection, 'down'));

        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame($default, DB::getDefaultConnection());
    }

    public static function populatedRollbackGuards(): array
    {
        return [
            'workspace' => ['workspace'],
            'membership' => ['membership'],
            'event ownership' => ['event ownership'],
            'true platform flag' => ['true platform flag'],
            'noncanonical true platform flag' => ['noncanonical true platform flag'],
            'unknown null platform flag' => ['unknown null platform flag'],
        ];
    }

    #[DataProvider('conflictingExpansion')]
    public function test_up_refuses_existing_or_partial_expansion_before_ddl(string $conflict): void
    {
        $connection = $this->isolatedSchema(true);
        $schema = $connection->getSchemaBuilder();
        if ($conflict === 'workspace table' || $conflict === 'membership table') {
            $schema->create($conflict === 'workspace table' ? 'workspaces' : 'workspace_users', function (Blueprint $table): void {
                $table->id();
            });
        } elseif ($conflict === 'event ownership column') {
            $schema->table('events', function (Blueprint $table): void {
                $table->unsignedBigInteger('workspace_id')->nullable();
            });
        } elseif ($conflict === 'platform column') {
            $schema->table('users', function (Blueprint $table): void {
                $table->boolean('is_platform_admin')->default(false);
            });
        } else {
            $connection->statement('CREATE VIEW workspaces AS SELECT id FROM events');
        }
        $before = $this->snapshot($connection);

        $this->assertRefused(fn () => $this->apply($connection, 'up'));

        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function conflictingExpansion(): array
    {
        return [
            'workspace table' => ['workspace table'],
            'membership table' => ['membership table'],
            'event ownership column' => ['event ownership column'],
            'platform column' => ['platform column'],
            'workspace view' => ['workspace view'],
        ];
    }

    #[DataProvider('partialRollbackSchema')]
    public function test_down_refuses_incomplete_expansion_before_ddl(string $missing): void
    {
        $connection = $this->isolatedSchema();
        if ($missing === 'membership table') {
            $connection->getSchemaBuilder()->drop('workspace_users');
        } else {
            $connection->getSchemaBuilder()->table('users', function (Blueprint $table): void {
                $table->dropColumn('is_platform_admin');
            });
        }
        $before = $this->snapshot($connection);

        $this->assertRefused(fn () => $this->apply($connection, 'down'));

        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function partialRollbackSchema(): array
    {
        return ['membership table' => ['membership table'], 'platform column' => ['platform column']];
    }

    #[DataProvider('directions')]
    public function test_sqlite_rebuild_is_refused_inside_an_active_transaction(string $direction): void
    {
        $connection = $this->isolatedSchema($direction === 'up');
        $this->populateLegacy($connection);
        $before = $this->snapshot($connection);
        $connection->beginTransaction();
        try {
            $this->assertRefused(fn () => $this->apply($connection, $direction));
            $this->assertSame($before, $this->snapshot($connection));
        } finally {
            $connection->rollBack();
        }
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame(1, $connection->scalar('PRAGMA foreign_keys'));
    }

    #[DataProvider('directions')]
    public function test_unsupported_engine_is_refused_before_any_schema_change(string $direction): void
    {
        $base = $this->isolatedSchema($direction === 'up');
        $connection = Mockery::mock(SQLiteConnection::class.'[getDriverName]', [
            $base->getPdo(), ':memory:', '', $base->getConfig(),
        ]);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        DB::extend('workspace_unsupported_fixture', fn () => $connection);
        config(['database.connections.workspace_unsupported_fixture' => ['driver' => 'workspace_unsupported_fixture']]);
        $this->ownedConnections[] = 'workspace_unsupported_fixture';
        $before = $this->snapshot($base);

        $this->assertRefused(fn () => DB::usingConnection('workspace_unsupported_fixture', fn () => $this->migration()->{$direction}()));

        $this->assertSame($before, $this->snapshot($base));
    }

    public static function directions(): array
    {
        return ['up' => ['up'], 'down' => ['down']];
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_18_000000_add_workspace_foundation.php');
    }

    private function apply(Connection $connection, string $direction): void
    {
        DB::usingConnection($connection->getName(), fn () => $this->migration()->{$direction}());
    }

    private function assertRefused(callable $operation): void
    {
        $refusal = null;
        try {
            $operation();
        } catch (RuntimeException $exception) {
            $refusal = $exception;
        }
        $this->assertNotNull($refusal, 'Unsafe schema operation should be refused.');
        $this->assertNotInstanceOf(QueryException::class, $refusal, 'Refuse explicitly before DDL rather than fail midway.');
    }

    private function isolatedSchema(bool $legacy = false): Connection
    {
        $name = 'workspace_schema_'.count($this->ownedConnections);
        config(['database.connections.'.$name => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        $this->ownedConnections[] = $name;
        $connection = DB::connection($name);
        $source = DB::connection('sqlite');
        $objects = $source->select("SELECT type, name, sql FROM sqlite_master WHERE type IN ('table', 'index') AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name");
        foreach ($objects as $object) {
            $connection->statement($object->sql);
        }
        foreach ($objects as $object) {
            if ($object->type !== 'table') {
                continue;
            }
            foreach ($source->table($object->name)->get() as $row) {
                $connection->table($object->name)->insert((array) $row);
            }
        }
        $connection->getSchemaBuilder()->enableForeignKeyConstraints();
        // Reverse later empty migrations before testing this historical layer.
        DB::usingConnection($name, function (): void {
            (require database_path('migrations/2026_10_18_010000_add_legacy_ownership_ledger.php'))->down();
        });
        $this->assertSame(0, $connection->transactionLevel());
        if ($legacy) {
            $this->apply($connection, 'down');
        }

        return $connection;
    }

    private function populateLegacy(Connection $connection): void
    {
        $connection->table('events')->insert([
            ['id' => 201, 'event_name' => 'Legacy active event', 'status' => 'active'],
            ['id' => 202, 'event_name' => 'Legacy archived event', 'status' => 'archived'],
        ]);
        $connection->table('users')->insert([
            ['id' => 211, 'name' => 'Legacy scanner', 'email' => 'legacy-scanner@example.invalid', 'phone_number' => '+255712345678', 'password' => Hash::make('legacy scanner password'), 'remember_token' => 'private-legacy-remember', 'role' => 'scanner', 'event_id' => 201, 'gate_name' => 'Legacy gate', 'is_active' => true, 'preferred_language' => 'en'],
            ['id' => 212, 'name' => 'Legacy admin', 'email' => 'legacy-admin@example.invalid', 'phone_number' => null, 'password' => Hash::make('legacy admin password'), 'remember_token' => null, 'role' => 'admin', 'event_id' => null, 'gate_name' => null, 'is_active' => true, 'preferred_language' => 'sw'],
        ]);
        $connection->table('guests')->insert([
            'id' => 301, 'event_id' => 201, 'name' => 'Legacy guest', 'phone_number' => '+255712345679',
            'manual_code' => '0042', 'pass_type' => 'special', 'allowed_entries' => 6, 'used_entries' => 2, 'status' => 'partially_used',
        ]);
        $connection->table('qr_codes')->insert(['id' => 401, 'guest_id' => 301, 'qr_token' => str_repeat('a', 64), 'qr_image_path' => 'private/legacy-qr.png']);
        $connection->table('admissions')->insert(['id' => 501, 'event_id' => 201, 'guest_id' => 301, 'quantity' => 2, 'admitted_by' => 'Legacy scanner', 'admitted_at' => '2026-10-01 10:00:00']);
        $connection->table('checkins')->insert(['id' => 601, 'event_id' => 201, 'guest_id' => 301, 'qr_code_id' => 401, 'user_id' => 211, 'scan_result' => 'allowed', 'entries_added' => 2, 'used_entries_after_scan' => 2, 'remaining_entries_after_scan' => 4]);
        $connection->table('offline_scanner_sessions')->insert([
            'id' => 701, 'session_uuid' => '2bdc3388-28ab-471b-92a5-90c8fdc67aa1', 'event_id' => 201,
            'created_by_user_id' => 212, 'master_scanner_user_id' => 211, 'master_gate_name' => 'Legacy gate',
            'status' => 'draft', 'notes' => 'Preserve offline provenance', 'session_bootstrap_secret_encrypted' => 'private-legacy-encrypted-secret',
        ]);
        $connection->table('offline_scanner_session_users')->insert([
            'id' => 801, 'offline_scanner_session_id' => 701, 'scanner_user_id' => 211,
            'assigned_gate' => 'Legacy gate', 'device_role' => 'master',
        ]);
    }

    private function snapshot(Connection $connection): array
    {
        return [
            'schema' => array_map(fn (object $row): array => (array) $row, $connection->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')),
            'rows' => $this->rowContents($connection),
        ];
    }

    private function rowContents(Connection $connection): array
    {
        $contents = [];
        foreach ($connection->select("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $table) {
            // Values are compared exactly; physical column position is not part of the contract
            // (a re-added column is appended after columns from later migrations).
            $rows = $connection->table($table->name)->get()->map(function (object $row): array {
                $row = (array) $row;
                ksort($row);

                return $row;
            })->all();
            usort($rows, fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR),
            ));
            $contents[$table->name] = $rows;
        }

        return $contents;
    }
}
