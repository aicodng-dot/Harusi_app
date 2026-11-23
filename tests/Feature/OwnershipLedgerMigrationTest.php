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
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class OwnershipLedgerMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const LEDGER_TABLES = ['legacy_ownership_plans', 'legacy_workspace_mappings'];

    private array $ownedConnections = [];

    protected function tearDown(): void
    {
        foreach ($this->ownedConnections as $name) {
            DB::purge($name);
        }

        parent::tearDown();
    }

    public function test_populated_upgrade_adds_only_empty_ledger_and_preserves_every_existing_record_and_schema_object(): void
    {
        $connection = $this->isolatedSchema(true);
        $this->populateLegacy($connection);
        $before = $this->snapshot($connection);
        $default = DB::getDefaultConnection();
        $defaultSnapshot = $this->snapshot(DB::connection());
        $this->assertFalse($this->migration()->withinTransaction);
        $this->assertSame(0, $connection->transactionLevel());

        $this->apply($connection, 'up');

        $after = $this->snapshot($connection);
        foreach (self::LEDGER_TABLES as $table) {
            $this->assertSame([], $after['rows'][$table]);
            unset($after['rows'][$table]);
        }
        $after['schema'] = array_values(array_filter(
            $after['schema'], fn (array $row): bool => ! in_array($row['tbl_name'], self::LEDGER_TABLES, true),
        ));
        $this->assertSame($before, $after);
        $this->assertSame($default, DB::getDefaultConnection());
        $this->assertSame($defaultSnapshot, $this->snapshot(DB::connection()));
        $this->assertSame([], $connection->select('PRAGMA foreign_key_check'));
        $this->assertSame(1, $connection->scalar('PRAGMA foreign_keys'));
        $this->assertSame(51, $connection->table('events')->where('id', 201)->value('workspace_id'));
        $this->assertSame(1, $connection->table('workspace_users')->where('user_id', 211)->value('is_active'));
        $this->assertSame(1, $connection->table('users')->where('id', 212)->value('is_platform_admin'));
        $this->assertSame('0042', $connection->table('guests')->where('id', 301)->value('manual_code'));
        $this->assertSame(2, $connection->table('guests')->where('id', 301)->value('used_entries'));
        $this->assertSame(str_repeat('a', 64), $connection->table('qr_codes')->where('id', 401)->value('qr_token'));
        $this->assertSame(201, $connection->table('offline_scanner_sessions')->where('id', 701)->value('event_id'));
        $this->assertSame(201, $connection->table('users')->where('id', 211)->value('event_id'));
    }

    public function test_unique_reference_scope_and_restrictive_constraints_preserve_data_on_rejection(): void
    {
        $connection = $this->isolatedSchema();
        $this->populateLegacy($connection);
        $connection->table('workspaces')->insert([
            ['id' => 52, 'name' => 'Second target'],
            ['id' => 53, 'name' => 'Third target'],
        ]);
        $connection->table('legacy_ownership_plans')->insert([
            ['id' => 11, 'schema_version' => 1, 'manifest_hash' => str_repeat('b', 64)],
            ['id' => 12, 'schema_version' => 1, 'manifest_hash' => str_repeat('c', 64)],
        ]);
        $connection->table('legacy_workspace_mappings')->insert([
            ['id' => 21, 'ownership_plan_id' => 11, 'manifest_reference' => 'legacy-main', 'workspace_id' => 51],
            ['id' => 22, 'ownership_plan_id' => 12, 'manifest_reference' => 'legacy-main', 'workspace_id' => 52],
        ]);
        $this->assertSame(2, $connection->table('legacy_workspace_mappings')->where('manifest_reference', 'legacy-main')->count());
        $schema = $connection->getSchemaBuilder();
        $this->assertTrue($schema->hasIndex('legacy_ownership_plans', ['manifest_hash'], 'unique'));
        $this->assertTrue($schema->hasIndex('legacy_workspace_mappings', ['ownership_plan_id', 'manifest_reference'], 'unique'));
        $this->assertTrue($schema->hasIndex('legacy_workspace_mappings', ['workspace_id'], 'unique'));
        $links = $connection->select('PRAGMA foreign_key_list(legacy_workspace_mappings)');
        $this->assertCount(2, $links);
        foreach ($links as $link) {
            $this->assertSame('RESTRICT', $link->on_delete);
        }

        $attempts = [
            'duplicate plan hash' => fn () => $connection->table('legacy_ownership_plans')->insert(['schema_version' => 1, 'manifest_hash' => str_repeat('b', 64)]),
            'missing schema version' => fn () => $connection->table('legacy_ownership_plans')->insert(['manifest_hash' => str_repeat('d', 64)]),
            'missing hash' => fn () => $connection->table('legacy_ownership_plans')->insert(['schema_version' => 1]),
            'duplicate same-plan reference' => fn () => $connection->table('legacy_workspace_mappings')->insert(['ownership_plan_id' => 11, 'manifest_reference' => 'legacy-main', 'workspace_id' => 53]),
            'duplicate target across plans' => fn () => $connection->table('legacy_workspace_mappings')->insert(['ownership_plan_id' => 12, 'manifest_reference' => 'other-reference', 'workspace_id' => 51]),
            'orphan plan' => fn () => $connection->table('legacy_workspace_mappings')->insert(['ownership_plan_id' => 999999, 'manifest_reference' => 'orphan-plan', 'workspace_id' => 53]),
            'orphan workspace' => fn () => $connection->table('legacy_workspace_mappings')->insert(['ownership_plan_id' => 11, 'manifest_reference' => 'orphan-workspace', 'workspace_id' => 999999]),
            'missing plan' => fn () => $connection->table('legacy_workspace_mappings')->insert(['manifest_reference' => 'missing-plan', 'workspace_id' => 53]),
            'missing reference' => fn () => $connection->table('legacy_workspace_mappings')->insert(['ownership_plan_id' => 11, 'workspace_id' => 53]),
            'missing workspace' => fn () => $connection->table('legacy_workspace_mappings')->insert(['ownership_plan_id' => 11, 'manifest_reference' => 'missing-workspace']),
            'referenced plan deletion' => fn () => $connection->table('legacy_ownership_plans')->where('id', 11)->delete(),
            'referenced workspace deletion' => fn () => $connection->table('workspaces')->where('id', 52)->delete(),
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

    public function test_empty_ledger_reversal_and_reapply_preserve_populated_workspace_and_legacy_records(): void
    {
        $connection = $this->isolatedSchema();
        $this->populateLegacy($connection);
        $expanded = $this->snapshot($connection);
        $default = DB::getDefaultConnection();

        $this->apply($connection, 'down');

        foreach (self::LEDGER_TABLES as $table) {
            $this->assertFalse($connection->getSchemaBuilder()->hasTable($table));
        }
        $this->assertTrue($connection->getSchemaBuilder()->hasTable('workspaces'));
        $this->assertSame(1, $connection->table('workspace_users')->count());
        $this->assertSame([], $connection->select('PRAGMA foreign_key_check'));

        $this->apply($connection, 'up');

        $this->assertSame($expanded, $this->snapshot($connection));
        $this->assertSame($default, DB::getDefaultConnection());
        $this->assertSame(1, $connection->scalar('PRAGMA foreign_keys'));
    }

    #[DataProvider('populatedLedger')]
    public function test_any_populated_ledger_refuses_reversal_before_ddl(string $table): void
    {
        $connection = $this->isolatedSchema();
        $this->populateLegacy($connection);
        if ($table === 'legacy_ownership_plans') {
            $connection->table($table)->insert(['schema_version' => 1, 'manifest_hash' => str_repeat('b', 64)]);
        } else {
            // A damaged orphan mapping must still block reversal when no plan remains.
            $connection->getSchemaBuilder()->disableForeignKeyConstraints();
            $connection->table($table)->insert(['ownership_plan_id' => 999999, 'manifest_reference' => 'must-preserve', 'workspace_id' => 51]);
            $connection->getSchemaBuilder()->enableForeignKeyConstraints();
        }
        $before = $this->snapshot($connection);

        $this->assertRefused(fn () => $this->apply($connection, 'down'));

        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function populatedLedger(): array
    {
        return ['plan' => ['legacy_ownership_plans'], 'mapping without plan' => ['legacy_workspace_mappings']];
    }

    #[DataProvider('conflictingObjects')]
    public function test_existing_ledger_table_or_view_refuses_upgrade_before_any_ddl(string $table, string $type): void
    {
        $connection = $this->isolatedSchema(true);
        if ($type === 'table') {
            $connection->getSchemaBuilder()->create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
            });
            $connection->table($table)->insert(['id' => 91]);
        } else {
            $connection->statement('CREATE VIEW '.$table.' AS SELECT id FROM workspaces');
        }
        $before = $this->snapshot($connection);

        $this->assertRefused(fn () => $this->apply($connection, 'up'));

        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function conflictingObjects(): array
    {
        return [
            'plan table' => ['legacy_ownership_plans', 'table'],
            'mapping table' => ['legacy_workspace_mappings', 'table'],
            'plan view' => ['legacy_ownership_plans', 'view'],
            'mapping view' => ['legacy_workspace_mappings', 'view'],
        ];
    }

    #[DataProvider('missingParent')]
    public function test_missing_workspace_parent_or_id_refuses_upgrade_before_ddl(string $missing): void
    {
        $connection = $this->isolatedSchema(true);
        $schema = $connection->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();
        $schema->drop('workspaces');
        if ($missing === 'id') {
            $schema->create('workspaces', function (Blueprint $table): void {
                $table->string('name');
            });
        } elseif ($missing === 'view') {
            $connection->statement('CREATE VIEW workspaces AS SELECT id FROM events');
        }
        $schema->enableForeignKeyConstraints();
        $before = $this->snapshot($connection);

        $this->assertRefused(fn () => $this->apply($connection, 'up'));

        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function missingParent(): array
    {
        return ['missing table' => ['table'], 'missing id column' => ['id'], 'view instead of parent' => ['view']];
    }

    #[DataProvider('unsafeParentKeys')]
    public function test_unsafe_workspace_parent_identity_refuses_upgrade_before_ddl(string $key): void
    {
        $connection = $this->isolatedSchema(true);
        $schema = $connection->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();
        $schema->drop('workspaces');
        if ($key === 'nullable text primary') {
            // SQLite permits NULL for a non-INTEGER primary key unless NOT NULL
            // is explicit; metadata alone is therefore insufficient.
            $connection->statement('CREATE TABLE workspaces (id TEXT PRIMARY KEY, name TEXT)');
            $connection->table('workspaces')->insert(['id' => null, 'name' => 'Preserve unknown identity']);
        } else {
            $schema->create('workspaces', function (Blueprint $table) use ($key): void {
                $table->unsignedBigInteger('id');
                $table->string('name');
                if ($key === 'unique only') {
                    $table->unique('id');
                } else {
                    $table->string('region');
                    $table->primary(['id', 'region']);
                }
            });
            $row = ['id' => 51, 'name' => 'Preserve existing identity'];
            if ($key === 'composite primary') {
                $row['region'] = 'legacy';
            }
            $connection->table('workspaces')->insert($row);
        }
        $schema->enableForeignKeyConstraints();
        $before = $this->snapshot($connection);

        $this->assertRefused(fn () => $this->apply($connection, 'up'));

        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function unsafeParentKeys(): array
    {
        return [
            'unique id without primary key' => ['unique only'],
            'composite primary key' => ['composite primary'],
            'observed null text primary key' => ['nullable text primary'],
        ];
    }

    #[DataProvider('incompleteLedger')]
    public function test_incomplete_ledger_refuses_reversal_before_ddl(string $missingTable, ?string $missingColumn): void
    {
        $connection = $this->isolatedSchema();
        $schema = $connection->getSchemaBuilder();
        $schema->drop('legacy_workspace_mappings');
        $schema->drop('legacy_ownership_plans');
        if ($missingColumn !== null || $missingTable !== 'legacy_ownership_plans') {
            $schema->create('legacy_ownership_plans', function (Blueprint $table) use ($missingTable, $missingColumn): void {
                $table->id();
                if ($missingTable !== 'legacy_ownership_plans' || $missingColumn !== 'schema_version') {
                    $table->unsignedTinyInteger('schema_version');
                }
                if ($missingTable !== 'legacy_ownership_plans' || $missingColumn !== 'manifest_hash') {
                    $table->char('manifest_hash', 64);
                }
                foreach (['created_at', 'updated_at'] as $column) {
                    if ($missingTable !== 'legacy_ownership_plans' || $missingColumn !== $column) {
                        $table->timestamp($column)->nullable();
                    }
                }
            });
        }
        if ($missingColumn !== null || $missingTable !== 'legacy_workspace_mappings') {
            $schema->create('legacy_workspace_mappings', function (Blueprint $table) use ($missingTable, $missingColumn): void {
                $table->id();
                foreach (['ownership_plan_id', 'workspace_id'] as $column) {
                    if ($missingTable !== 'legacy_workspace_mappings' || $missingColumn !== $column) {
                        $table->unsignedBigInteger($column);
                    }
                }
                if ($missingTable !== 'legacy_workspace_mappings' || $missingColumn !== 'manifest_reference') {
                    $table->string('manifest_reference', 64);
                }
                foreach (['created_at', 'updated_at'] as $column) {
                    if ($missingTable !== 'legacy_workspace_mappings' || $missingColumn !== $column) {
                        $table->timestamp($column)->nullable();
                    }
                }
            });
        }
        $before = $this->snapshot($connection);

        $this->assertRefused(fn () => $this->apply($connection, 'down'));

        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function incompleteLedger(): array
    {
        return [
            'missing plans' => ['legacy_ownership_plans', null],
            'missing mappings' => ['legacy_workspace_mappings', null],
            'missing schema version' => ['legacy_ownership_plans', 'schema_version'],
            'missing hash' => ['legacy_ownership_plans', 'manifest_hash'],
            'missing plan timestamp' => ['legacy_ownership_plans', 'created_at'],
            'missing plan link' => ['legacy_workspace_mappings', 'ownership_plan_id'],
            'missing reference' => ['legacy_workspace_mappings', 'manifest_reference'],
            'missing workspace link' => ['legacy_workspace_mappings', 'workspace_id'],
            'missing mapping timestamp' => ['legacy_workspace_mappings', 'updated_at'],
        ];
    }

    #[DataProvider('populatedLedger')]
    public function test_full_column_view_cannot_substitute_for_ledger_table_during_reversal(string $table): void
    {
        $connection = $this->isolatedSchema();
        $schema = $connection->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();
        $schema->drop($table);
        $projection = $table === 'legacy_ownership_plans'
            ? "1 AS id, 1 AS schema_version, 'private' AS manifest_hash, NULL AS created_at, NULL AS updated_at"
            : "1 AS id, 1 AS ownership_plan_id, 'private' AS manifest_reference, 1 AS workspace_id, NULL AS created_at, NULL AS updated_at";
        $connection->statement('CREATE VIEW '.$table.' AS SELECT '.$projection.' WHERE 0');
        $schema->enableForeignKeyConstraints();
        $before = $this->snapshot($connection);

        $this->assertRefused(fn () => $this->apply($connection, 'down'));

        $this->assertSame($before, $this->snapshot($connection));
    }

    #[DataProvider('directions')]
    public function test_sqlite_active_transaction_refuses_both_directions_before_ddl(string $direction): void
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
    public function test_unsupported_engine_refuses_both_directions_before_schema_work(string $direction): void
    {
        $base = $this->isolatedSchema($direction === 'up');
        $connection = Mockery::mock(SQLiteConnection::class.'[getDriverName]', [
            $base->getPdo(), ':memory:', '', $base->getConfig(),
        ]);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $name = 'ownership_ledger_unsupported';
        DB::extend($name, fn () => $connection);
        config(['database.connections.'.$name => ['driver' => $name]]);
        $this->ownedConnections[] = $name;
        $before = $this->snapshot($base);
        $default = DB::getDefaultConnection();
        $migration = $this->migration();
        (new ReflectionProperty(Migration::class, 'connection'))->setValue($migration, $name);

        $this->assertRefused(fn () => $migration->{$direction}());

        $this->assertSame($before, $this->snapshot($base));
        $this->assertSame($default, DB::getDefaultConnection());
    }

    public static function directions(): array
    {
        return ['up' => ['up'], 'down' => ['down']];
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_18_010000_add_legacy_ownership_ledger.php');
    }

    private function apply(Connection $connection, string $direction): void
    {
        $migration = $this->migration();
        (new ReflectionProperty(Migration::class, 'connection'))->setValue($migration, $connection->getName());
        $migration->{$direction}();
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

    private function isolatedSchema(bool $beforeLedger = false): Connection
    {
        $name = 'ownership_ledger_schema_'.count($this->ownedConnections);
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
        $this->assertSame(0, $connection->transactionLevel());
        if ($beforeLedger) {
            $this->apply($connection, 'down');
        }

        return $connection;
    }

    private function populateLegacy(Connection $connection): void
    {
        $connection->table('workspaces')->insert(['id' => 51, 'name' => 'Existing owned workspace']);
        $connection->table('events')->insert([
            ['id' => 201, 'event_name' => 'Legacy active event', 'status' => 'active', 'workspace_id' => 51],
            ['id' => 202, 'event_name' => 'Legacy archived event', 'status' => 'archived', 'workspace_id' => null],
        ]);
        $connection->table('users')->insert([
            ['id' => 211, 'name' => 'Legacy scanner', 'email' => 'legacy-scanner@example.invalid', 'phone_number' => '+255712345678', 'password' => Hash::make('legacy scanner password'), 'remember_token' => 'private-legacy-remember', 'role' => 'scanner', 'event_id' => 201, 'gate_name' => 'Legacy gate', 'is_active' => true, 'preferred_language' => 'en', 'is_platform_admin' => false],
            ['id' => 212, 'name' => 'Legacy admin', 'email' => 'legacy-admin@example.invalid', 'phone_number' => null, 'password' => Hash::make('legacy admin password'), 'remember_token' => null, 'role' => 'admin', 'event_id' => null, 'gate_name' => null, 'is_active' => true, 'preferred_language' => 'sw', 'is_platform_admin' => true],
        ]);
        $connection->table('workspace_users')->insert(['id' => 61, 'workspace_id' => 51, 'user_id' => 211, 'role' => 'owner', 'is_active' => true]);
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
        $rows = [];
        foreach ($connection->select("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $table) {
            $contents = $connection->table($table->name)->get()->map(fn (object $row): array => (array) $row)->all();
            usort($contents, fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR),
            ));
            $rows[$table->name] = $contents;
        }

        return [
            'schema' => array_map(fn (object $row): array => (array) $row, $connection->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')),
            'rows' => $rows,
        ];
    }
}
