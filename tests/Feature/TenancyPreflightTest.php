<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\OfflineScannerSession;
use App\Models\User;
use App\Services\Tenancy\LegacyDataInventory;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TenancyPreflightTest extends TestCase
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

    public function test_clean_inventory_is_repeatable_read_only_and_never_certifies_rollout(): void
    {
        $event = Event::factory()->create(['event_name' => 'Private preflight event marker']);
        $user = User::factory()->create([
            'name' => 'Private preflight account marker',
            'email' => 'private-preflight@example.invalid',
            'phone_number' => '+255712345678',
            'remember_token' => 'private-remember-marker',
        ]);
        $guest = $this->guest($event->id, ['manual_code' => '0042']);
        DB::table('qr_codes')->insert([
            'guest_id' => $guest,
            'qr_token' => 'private-qr-token-marker',
            'qr_image_path' => 'private-directory/qr-file.png',
        ]);
        $user->createToken('private-device-marker', ['auth:read'], now()->addHour());
        DB::table('sessions')->insert([
            'id' => 'private-session-marker', 'user_id' => $user->id,
            'payload' => 'private-session-payload-marker', 'last_activity' => 123456,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email, 'token' => 'private-reset-token-marker', 'created_at' => now(),
        ]);
        OfflineScannerSession::factory()->create([
            'event_id' => $event->id, 'created_by_user_id' => $user->id,
            'notes' => 'private-offline-notes-marker',
            'session_bootstrap_secret_encrypted' => 'private-offline-secret-marker',
        ]);

        $connection = DB::connection('sqlite');
        $before = $this->snapshot($connection);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        [$first, $firstOutput] = $this->runPreflight(['--connection' => 'sqlite'], 0);
        [$second, $secondOutput] = $this->runPreflight(['--connection' => 'sqlite'], 0);
        $this->assertSame($first, $second);
        $this->assertSame($firstOutput, $secondOutput);
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:insert|update|delete|replace|create|alter|drop|truncate|attach|detach|vacuum)\b/i',
                $query,
                'Inventory must issue only schema and aggregate reads.',
            );
        }
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame('complete', $first['status']);
        $this->assertSame('best_effort', $first['consistency']);
        $this->assertSame('sqlite', $first['database']['driver']);
        $this->assertIsString($first['database']['version']);
        $this->assertNotSame('', $first['database']['version']);
        $this->assertSame([], $first['findings']);
        $this->assertSame([], $first['migrations']['pending']);
        $this->assertSame(0, $first['migrations']['unknown_count']);
        $this->assertSame(DB::table('migrations')->count(), $first['migrations']['applied_count']);
        $this->assertCount(32, $first['tables']);
        foreach ($first['tables'] as $table => $inventory) {
            $this->assertTrue($inventory['present'], $table);
            $this->assertSame([], $inventory['missing_columns'], $table);
            $this->assertSame($connection->table($table)->count(), $inventory['rows'], $table);
        }
        $this->assertSame(1, $first['tables']['offline_scanner_sessions']['rows']);
        $this->assertNotEmpty($first['limitations']);
        foreach ([
            'Private preflight', 'private-preflight@example.invalid', '+255712345678',
            'private-remember-marker', 'private-qr-token-marker', 'private-directory',
            'private-device-marker', 'private-session-marker', 'private-session-payload-marker',
            'private-reset-token-marker', 'private-offline-notes-marker', 'private-offline-secret-marker',
            $user->password, '0042',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $firstOutput);
        }
    }

    public function test_empty_migrated_database_does_not_create_a_default_event(): void
    {
        DB::table('events')->delete();
        $before = $this->snapshot(DB::connection('sqlite'));

        [$report] = $this->runPreflight(['--connection' => 'sqlite'], 0);

        $this->assertSame('complete', $report['status']);
        $this->assertSame(0, $report['tables']['events']['rows']);
        $this->assertSame([], $report['findings']);
        $this->assertDatabaseCount('events', 0);
        $this->assertSame($before, $this->snapshot(DB::connection('sqlite')));
    }

    #[DataProvider('invalidConnectionOptions')]
    public function test_connection_must_be_explicit_and_known_before_any_database_query(array $options, string $reason): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Log::spy();

        [$report, $output] = $this->runPreflight($options, 2);

        $this->assertUnavailable($report, $reason);
        $this->assertSame([], $queries);
        $this->assertStringNotContainsString('private-unknown-connection', $output);
        $this->assertNoDiagnosticLogs();
    }

    public static function invalidConnectionOptions(): array
    {
        return [
            'missing' => [[], 'connection_required'],
            'blank' => [['--connection' => '   '], 'connection_required'],
            'unknown' => [['--connection' => 'private-unknown-connection'], 'unknown_connection'],
        ];
    }

    public function test_unavailable_sqlite_path_is_not_created_or_disclosed(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'private-preflight-'.bin2hex(random_bytes(12)).'.sqlite';
        $this->assertFileDoesNotExist($path);
        $this->configureConnection('preflight_unavailable', [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '',
            'username' => 'private-username-marker', 'password' => 'private-password-marker',
        ]);
        Log::spy();

        [$report, $output] = $this->runPreflight(['--connection' => 'preflight_unavailable'], 2);

        $this->assertUnavailable($report, 'database_unavailable');
        $this->assertFileDoesNotExist($path);
        foreach (['private-preflight-', 'private-username-marker', 'private-password-marker', 'SQLSTATE', 'does not exist'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $output);
        }
        $this->assertNoDiagnosticLogs();
    }

    public function test_unsupported_driver_is_rejected_without_connecting(): void
    {
        $this->configureConnection('preflight_unsupported', [
            'driver' => 'pgsql', 'host' => 'private-host.invalid', 'port' => 1,
            'database' => 'private-database', 'username' => 'private-user', 'password' => 'private-password',
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Log::spy();

        [$report, $output] = $this->runPreflight(['--connection' => 'preflight_unsupported'], 2);

        $this->assertUnavailable($report, 'unsupported_driver');
        $this->assertSame([], $queries);
        $this->assertStringNotContainsString('private-', $output);
        $this->assertNoDiagnosticLogs();
    }

    public function test_query_failure_is_sanitized_without_exception_details_or_logging(): void
    {
        $this->mock(LegacyDataInventory::class, function (MockInterface $mock): void {
            $mock->shouldReceive('inspect')->once()->andThrow(new RuntimeException(
                'SQLSTATE private-dsn /private/path private-password SELECT private_payload FROM private_table',
            ));
        });
        Log::spy();

        [$report, $output] = $this->runPreflight(['--connection' => 'sqlite'], 2);

        $this->assertUnavailable($report, 'database_unavailable');
        foreach (['SQLSTATE', 'private-', '/private/', 'SELECT', 'private_payload'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $output);
        }
        $this->assertNoDiagnosticLogs();
    }

    public function test_explicit_connection_is_inspected_without_using_or_changing_the_default(): void
    {
        $primary = DB::connection('sqlite');
        $before = $this->snapshot($primary);
        $this->configureConnection('preflight_empty', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $other = DB::connection('preflight_empty');
        $this->assertSame([], $other->getSchemaBuilder()->getTables());
        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection('private-unconfigured-default');

        try {
            [$report, $output] = $this->runPreflight(['--connection' => 'preflight_empty'], 2);
            $this->assertSame('private-unconfigured-default', DB::getDefaultConnection());
            $this->assertSame('incomplete', $report['status']);
            foreach ($report['tables'] as $table) {
                $this->assertFalse($table['present']);
                $this->assertNull($table['rows']);
                $this->assertNotEmpty($table['missing_columns']);
            }
            $this->assertSame(['applied_count' => null, 'pending' => [], 'unknown_count' => null], $report['migrations']);
            $this->assertSame([], $report['findings']);
            $this->assertStringNotContainsString('private-unconfigured-default', $output);
            $this->assertSame([], $other->getSchemaBuilder()->getTables());
            $this->assertSame($before, $this->snapshot($primary));
        } finally {
            DB::setDefaultConnection($originalDefault);
        }
    }

    public function test_partial_schema_reports_missing_columns_and_skips_dependent_checks(): void
    {
        $this->configureConnection('preflight_partial', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection = DB::connection('preflight_partial');
        $schema = $connection->getSchemaBuilder();
        $schema->create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->integer('batch');
        });
        $schema->create('guests', function (Blueprint $table): void {
            $table->id();
            $table->string('private_fixture_column');
        });
        $connection->table('guests')->insert(['private_fixture_column' => 'private-guest-payload-marker']);
        $before = $this->snapshot($connection);

        [$report, $output] = $this->runPreflight(['--connection' => 'preflight_partial'], 2);

        $this->assertSame('incomplete', $report['status']);
        $this->assertSame(['migration'], $report['tables']['migrations']['missing_columns']);
        $this->assertTrue($report['tables']['guests']['present']);
        $this->assertSame(1, $report['tables']['guests']['rows']);
        $this->assertEqualsCanonicalizing(
            ['event_id', 'manual_code', 'pass_type', 'allowed_entries', 'used_entries', 'status'],
            $report['tables']['guests']['missing_columns'],
        );
        $this->assertSame(['applied_count' => null, 'pending' => [], 'unknown_count' => null], $report['migrations']);
        $this->assertSame([], $report['findings']);
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertStringNotContainsString('private_fixture_column', $output);
        $this->assertStringNotContainsString('private-guest-payload-marker', $output);
    }

    public function test_pending_and_unknown_migrations_are_reported_without_unknown_names_or_paths(): void
    {
        $known = DB::table('migrations')->orderBy('migration')->value('migration');
        DB::table('migrations')->where('migration', $known)->delete();
        DB::table('migrations')->insert([
            'migration' => 'private-unknown-migration-/private/database-path', 'batch' => 99,
        ]);
        $before = $this->snapshot(DB::connection('sqlite'));

        [$report, $output] = $this->runPreflight(['--connection' => 'sqlite'], 1);

        $this->assertSame('complete', $report['status']);
        $this->assertSame([$known], $report['migrations']['pending']);
        $this->assertSame(1, $report['migrations']['unknown_count']);
        $this->assertSame(DB::table('migrations')->count(), $report['migrations']['applied_count']);
        $this->assertFinding($report, 'migrations_pending', 1, 'review');
        $this->assertFinding($report, 'migrations_unknown', 1, 'review');
        $this->assertStringNotContainsString('private-unknown', $output);
        $this->assertStringNotContainsString('/private/', $output);
        $this->assertSame($before, $this->snapshot(DB::connection('sqlite')));
    }

    #[DataProvider('specialAllowanceBounds')]
    public function test_inventory_uses_the_current_special_pass_and_admission_capacity(int $capacity, bool $invalid): void
    {
        $event = Event::factory()->create();
        $guest = $this->guest($event->id, ['pass_type' => 'special', 'allowed_entries' => $capacity]);
        DB::table('admissions')->insert([
            'guest_id' => $guest, 'event_id' => $event->id, 'quantity' => $capacity, 'admitted_at' => now(),
        ]);
        $before = $this->snapshot(DB::connection('sqlite'));

        [$report] = $this->runPreflight(['--connection' => 'sqlite'], $invalid ? 1 : 0);

        if ($invalid) {
            $this->assertFinding($report, 'guests_invalid_pass_bounds', 1);
            $this->assertFinding($report, 'guests_invalid_usage_bounds', 1);
            $this->assertFinding($report, 'admissions_invalid_quantity', 1);
            $this->assertCount(3, $report['findings']);
        } else {
            $this->assertSame([], $report['findings']);
        }
        $this->assertSame($before, $this->snapshot(DB::connection('sqlite')));
    }

    public static function specialAllowanceBounds(): array
    {
        return ['eleven entries' => [11, false], 'one hundred entries' => [100, false], 'above maximum' => [101, true]];
    }

    public function test_guest_validation_reaches_rows_beyond_the_streaming_chunk_boundary(): void
    {
        $event = Event::factory()->create();
        $rows = [];
        for ($index = 0; $index < 501; $index++) {
            $rows[] = $this->guestAttributes($event->id, ['manual_code' => str_pad((string) $index, 4, '0', STR_PAD_LEFT)]);
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('guests')->insert($chunk);
        }
        $last = DB::table('guests')->max('id');
        DB::table('guests')->where('id', $last)->update([
            'manual_code' => '１２３４', 'pass_type' => 'single', 'allowed_entries' => 0,
            'used_entries' => -1, 'status' => 'private-invalid-status-marker',
        ]);
        $before = $this->snapshot(DB::connection('sqlite'));

        [$report, $output] = $this->runPreflight(['--connection' => 'sqlite'], 1);

        $this->assertSame(501, $report['tables']['guests']['rows']);
        $this->assertFinding($report, 'guests_invalid_manual_code', 1);
        $this->assertFinding($report, 'guests_invalid_pass_bounds', 1);
        $this->assertFinding($report, 'guests_invalid_usage_bounds', 1);
        $this->assertFinding($report, 'guests_invalid_status', 1, 'review');
        $this->assertCount(4, $report['findings']);
        $this->assertStringNotContainsString('private-invalid-status-marker', $output);
        $this->assertSame($before, $this->snapshot(DB::connection('sqlite')));
    }

    public function test_orphan_core_references_cross_event_admissions_and_quantity_are_counted(): void
    {
        $connection = DB::connection('sqlite');
        // Deferral is fixture setup inside RefreshDatabase's rollback-only transaction.
        // It permits deliberately broken rows without changing production constraints.
        $connection->statement('PRAGMA defer_foreign_keys = ON');
        $first = Event::factory()->create();
        $second = Event::factory()->create();
        $guest = $this->guest($first->id);
        $this->guest(987654321, ['manual_code' => '0002']);
        DB::table('qr_codes')->insert(['guest_id' => 987654322, 'qr_token' => 'private-orphan-qr-marker']);
        DB::table('admissions')->insert([
            ['guest_id' => 987654323, 'event_id' => 987654324, 'quantity' => 0, 'admitted_at' => now()],
            ['guest_id' => $guest, 'event_id' => $second->id, 'quantity' => 1, 'admitted_at' => now()],
        ]);
        $before = $this->snapshot($connection);

        [$report, $output] = $this->runPreflight(['--connection' => 'sqlite'], 1);

        foreach ([
            'guests_missing_event', 'qr_codes_missing_guest', 'admissions_missing_guest',
            'admissions_missing_event', 'admissions_guest_event_mismatch', 'admissions_invalid_quantity',
        ] as $key) {
            $this->assertFinding($report, $key, 1);
        }
        $this->assertCount(6, $report['findings']);
        $this->assertStringNotContainsString('9876543', $output);
        $this->assertStringNotContainsString('private-orphan-qr-marker', $output);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public function test_duplicate_findings_count_groups_and_missing_unique_indexes_are_detected(): void
    {
        DB::statement('DROP INDEX guests_event_manual_code_unique');
        DB::statement('DROP INDEX qr_codes_guest_id_unique');
        DB::statement('DROP INDEX qr_codes_qr_token_unique');
        $first = Event::factory()->create();
        $second = Event::factory()->create();
        $guest = $this->guest($first->id, ['manual_code' => '0042']);
        $this->guest($first->id, ['manual_code' => '0042']);
        $this->guest($first->id, ['manual_code' => '0042']);
        $this->guest($second->id, ['manual_code' => '0042']);
        for ($index = 0; $index < 3; $index++) {
            DB::table('qr_codes')->insert(['guest_id' => $guest, 'qr_token' => 'private-duplicate-token-marker']);
        }
        $before = $this->snapshot(DB::connection('sqlite'));

        [$report] = $this->runPreflight(['--connection' => 'sqlite'], 1);

        foreach ([
            'guests_duplicate_manual_code', 'qr_codes_duplicate_guest', 'qr_codes_duplicate_token',
            'index_guests_event_manual_code_unique', 'index_qr_codes_guest_unique', 'index_qr_codes_token_unique',
        ] as $key) {
            $this->assertFinding($report, $key, 1);
        }
        $this->assertCount(6, $report['findings']);
        $this->assertSame($before, $this->snapshot(DB::connection('sqlite')));
    }

    #[DataProvider('insufficientUniqueIndexes')]
    public function test_partial_or_expression_indexes_do_not_prove_email_uniqueness(string $definition): void
    {
        DB::statement('DROP INDEX users_email_unique');
        DB::statement($definition);
        $before = $this->snapshot(DB::connection('sqlite'));

        [$report] = $this->runPreflight(['--connection' => 'sqlite'], 1);

        $this->assertFinding($report, 'index_users_email_unique', 1);
        $this->assertCount(1, $report['findings']);
        $this->assertSame($before, $this->snapshot(DB::connection('sqlite')));
    }

    public static function insufficientUniqueIndexes(): array
    {
        return [
            'partial' => ['CREATE UNIQUE INDEX fixture_email_unique ON users(email) WHERE is_active = 1'],
            'expression' => ['CREATE UNIQUE INDEX fixture_email_unique ON users(email, lower(name))'],
        ];
    }

    public function test_nullable_historical_links_are_review_counts_not_orphan_blockers(): void
    {
        $event = Event::factory()->create();
        DB::table('checkins')->insert(['event_id' => $event->id, 'scan_result' => 'invalid']);
        DB::table('scan_logs')->insert([
            'event_id' => $event->id, 'result' => 'invalid', 'message' => 'private-history-marker', 'scanned_at' => now(),
        ]);
        DB::table('notification_logs')->insert([
            'channel' => 'whatsapp', 'message_type' => 'fixture', 'recipient_phone' => '+255712345678',
            'status' => 'failed', 'context' => '{"private_payload":"private-notification-marker"}',
        ]);
        $before = $this->snapshot(DB::connection('sqlite'));

        [$report, $output] = $this->runPreflight(['--connection' => 'sqlite'], 1);

        foreach ([
            'checkins_guestless', 'checkins_userless', 'scan_logs_guestless',
            'notification_logs_eventless', 'notification_logs_guestless',
        ] as $key) {
            $this->assertFinding($report, $key, 1, 'review');
        }
        $this->assertCount(5, $report['findings']);
        $this->assertStringNotContainsString('private-history-marker', $output);
        $this->assertStringNotContainsString('private-notification-marker', $output);
        $this->assertStringNotContainsString('+255712345678', $output);
        $this->assertSame($before, $this->snapshot(DB::connection('sqlite')));
    }

    public function test_historical_scanner_assignment_and_admission_sums_are_not_invented_integrity_rules(): void
    {
        $historicalEvent = Event::factory()->create();
        $currentEvent = Event::factory()->create();
        $scanner = User::factory()->create(['role' => 'scanner', 'event_id' => $currentEvent->id, 'gate_name' => 'Current gate']);
        $guest = $this->guest($historicalEvent->id, ['pass_type' => 'double', 'allowed_entries' => 2, 'used_entries' => 0]);
        $qr = DB::table('qr_codes')->insertGetId(['guest_id' => $guest, 'qr_token' => 'private-history-qr-marker']);
        DB::table('admissions')->insert([
            'event_id' => $historicalEvent->id, 'guest_id' => $guest, 'quantity' => 2, 'admitted_at' => now(),
        ]);
        DB::table('checkins')->insert([
            'event_id' => $historicalEvent->id, 'guest_id' => $guest, 'qr_code_id' => $qr,
            'user_id' => $scanner->id, 'scan_result' => 'allowed',
        ]);
        $before = $this->snapshot(DB::connection('sqlite'));

        [$report] = $this->runPreflight(['--connection' => 'sqlite'], 0);

        $this->assertSame([], $report['findings']);
        $this->assertSame(0, DB::table('guests')->where('id', $guest)->value('used_entries'));
        $this->assertSame($before, $this->snapshot(DB::connection('sqlite')));
    }

    public function test_unassigned_and_dangling_scanners_are_aggregated_without_default_event_repair(): void
    {
        $unassigned = User::factory()->create();
        $dangling = User::factory()->create();
        DB::table('users')->where('id', $unassigned->id)->update(['role' => 'scanner', 'event_id' => null]);
        DB::table('users')->where('id', $dangling->id)->update(['role' => 'scanner', 'event_id' => 987654321]);
        $before = $this->snapshot(DB::connection('sqlite'));

        [$report, $output] = $this->runPreflight(['--connection' => 'sqlite'], 1);

        $this->assertFinding($report, 'scanners_unassigned', 1, 'review');
        $this->assertFinding($report, 'scanners_missing_event', 1, 'review');
        $this->assertCount(2, $report['findings']);
        $this->assertStringNotContainsString('987654321', $output);
        $this->assertSame($before, $this->snapshot(DB::connection('sqlite')));
    }

    private function runPreflight(array $options, int $expectedExit): array
    {
        $exit = Artisan::call('tenancy:preflight', $options);
        $output = Artisan::output();
        $this->assertSame($expectedExit, $exit, $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);
        $this->assertSame(1, $report['schema_version']);
        $this->assertFalse($report['rollout_ready']);
        $this->assertSame([
            'ownership_mapping' => 'unverified', 'backup_restore' => 'unverified', 'mysql_rehearsal' => 'unverified',
        ], $report['gates']);

        return [$report, $output];
    }

    private function assertUnavailable(array $report, string $reason): void
    {
        $this->assertSame('unavailable', $report['status']);
        $this->assertSame($reason, $report['reason']);
        $this->assertEqualsCanonicalizing(['schema_version', 'status', 'reason', 'rollout_ready', 'gates'], array_keys($report));
    }

    private function assertFinding(array $report, string $key, int $count, string $severity = 'blocker'): void
    {
        $this->assertArrayHasKey($key, $report['findings']);
        $this->assertSame(['severity' => $severity, 'count' => $count], $report['findings'][$key]);
    }

    private function assertNoDiagnosticLogs(): void
    {
        foreach (['log', 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    private function configureConnection(string $name, array $configuration): void
    {
        config(['database.connections.'.$name => $configuration]);
        $this->ownedConnections[] = $name;
    }

    private function guest(int $event, array $overrides = []): int
    {
        return DB::table('guests')->insertGetId($this->guestAttributes($event, $overrides));
    }

    private function guestAttributes(int $event, array $overrides = []): array
    {
        return array_replace([
            'event_id' => $event, 'manual_code' => '0001', 'name' => 'Private preflight guest marker',
            'phone_number' => '+255712345679', 'pass_type' => 'single', 'allowed_entries' => 1,
            'used_entries' => 0, 'status' => 'active',
        ], $overrides);
    }

    /** Full schema and row contents, including SQLite bookkeeping, with deterministic ordering. */
    private function snapshot(Connection $connection): array
    {
        $schema = $connection->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name');
        $contents = [];
        foreach ($schema as $object) {
            if ($object->type !== 'table') {
                continue;
            }
            $rows = $connection->table($object->name)->get()->map(fn (object $row): array => (array) $row)->all();
            usort($rows, fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR),
            ));
            $contents[$object->name] = $rows;
        }

        return ['schema' => array_map(fn (object $row): array => (array) $row, $schema), 'contents' => $contents];
    }
}
