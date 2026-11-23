<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\ScanLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class DatabaseSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Http::fake();
    }

    #[DataProvider('disallowedEnvironments')]
    public function test_disallowed_environments_refuse_before_database_or_hash_work(string $environment, string $invocation): void
    {
        // This also makes the pre-fix production/direct reproduction disposable.
        foreach (self::sampleTables() as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $before = $this->snapshot();
        $foreignKeys = $this->foreignKeys();

        [$failure, $work] = $this->observeWork(fn () => $this->inEnvironment(
            $environment, fn () => $this->invokeSeeder($invocation),
        ));

        $this->assertSame([], $work->queries, 'An environment refusal must precede even attempted SQL.');
        $this->assertSame(0, $work->transactions);
        $this->assertSame(0, $work->hashes);
        $this->assertFixedFailure($failure, 'Demo seeding is allowed only in local or testing environments.');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($foreignKeys, $this->foreignKeys());
        $this->assertNoMessages();
    }

    #[DataProvider('populatedTargets')]
    public function test_each_populated_sample_table_refuses_without_changing_any_rows_or_foreign_keys(string $table): void
    {
        $this->populateTarget($table);
        $this->assertTrue(DB::table($table)->exists());
        $before = $this->snapshot();
        $foreignKeys = $this->foreignKeys();
        $this->assertSame(1, $foreignKeys);

        [$failure, $work] = $this->observeWork(fn () => (new DatabaseSeeder)->run());

        $this->assertFixedFailure($failure, 'Demo seeding requires empty sample-data tables.');
        $this->assertNotEmpty($work->queries);
        $this->assertSame(0, $work->hashes);
        foreach ($work->queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query);
            $this->assertDoesNotMatchRegularExpression('/\bpragma\s+foreign_keys\s*=/i', $query);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($foreignKeys, $this->foreignKeys());
        $this->assertNoMessages();
    }

    #[DataProvider('allowedEnvironments')]
    public function test_empty_local_and_testing_targets_keep_sample_values_and_refuse_a_repeat(string $environment, string $invocation): void
    {
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
        foreach (self::sampleTables() as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $eventsBefore = DB::table('events')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $foreignKeys = $this->foreignKeys();
        $this->assertSame(1, $foreignKeys);

        $exit = $this->inEnvironment($environment, fn () => $this->invokeSeeder($invocation));
        $this->assertSame($invocation === 'forced command' ? 0 : null, $exit);
        $this->assertSampleCounts();
        $this->assertSame($eventsBefore, DB::table('events')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        $event = Event::query()->where('event_name', 'Default Wedding Event')->sole();
        $admin = User::query()->where('email', 'admin@example.com')->sole();
        $scanner = User::query()->where('email', 'scanner@example.com')->sole();
        $this->assertSame(['Wedding Admin', User::ROLE_ADMIN, null, null], [$admin->name, $admin->role, $admin->gate_name, $admin->event_id]);
        $this->assertSame(['Main Gate Scanner', User::ROLE_SCANNER, 'Main Gate', $event->id], [$scanner->name, $scanner->role, $scanner->gate_name, $scanner->event_id]);
        foreach ([$admin, $scanner] as $user) {
            $this->assertTrue($user->is_active);
            $this->assertFalse($user->is_platform_admin);
            $this->assertNull($user->phone_number);
            $this->assertSame('sw', $user->preferred_language);
            $this->assertNotSame('password', $user->password);
            $this->assertTrue(Hash::check('password', $user->password));
        }

        $expected = [
            ['Asha Mohamed', '+255712000101', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE],
            ['Baraka Family', '+255713000202', Guest::PASS_SPECIAL, 6, 2, Guest::STATUS_PARTIALLY_USED],
            ['Neema & Amani', '+255714000303', Guest::PASS_DOUBLE, 2, 2, Guest::STATUS_FULLY_USED],
            ['Joseph Kileo', '+255715000404', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE],
            ['Mariam Group', '+255716000505', Guest::PASS_SPECIAL, 10, 0, Guest::STATUS_ACTIVE],
            ['Cancelled Sample', '+255717000606', Guest::PASS_DOUBLE, 2, 0, Guest::STATUS_CANCELLED],
        ];
        foreach ($expected as [$name, $phone, $pass, $allowed, $used, $status]) {
            $guest = Guest::query()->where('name', $name)->sole();
            $this->assertSame([$phone, $pass, $allowed, $used, $status, $event->id], [$guest->phone_number, $guest->pass_type, $guest->allowed_entries, $guest->used_entries, $guest->status, $guest->event_id]);
            $this->assertMatchesRegularExpression('/\A[0-9]{4}\z/', $guest->manual_code);
            $qr = $guest->qrCode()->sole();
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{64}\z/', $qr->qr_token);
            $this->assertNull($qr->qr_image_path);
            $this->assertTrue($qr->generated_at->equalTo(now()));
            $this->assertSame($status !== Guest::STATUS_CANCELLED, $qr->is_active);
            $this->assertSame($status === Guest::STATUS_CANCELLED, $qr->revoked_at !== null);
            if ($qr->revoked_at !== null) {
                $this->assertTrue($qr->revoked_at->equalTo(now()));
            }
            if ($used === 0) {
                $this->assertSame(0, $guest->checkins()->count());
                $this->assertSame(0, $guest->admissions()->count());
                $this->assertSame(0, $guest->scanLogs()->count());

                continue;
            }
            $checkin = $guest->checkins()->sole();
            $this->assertSame([$event->id, $qr->id, null, 'Main Gate', $used, $used, $allowed - $used, Checkin::RESULT_ADMITTED, 'Seeder', '127.0.0.1'], [$checkin->event_id, $checkin->qr_code_id, $checkin->user_id, $checkin->gate_name, $checkin->entries_added, $checkin->used_entries_after_scan, $checkin->remaining_entries_after_scan, $checkin->scan_result, $checkin->device_info, $checkin->ip_address]);
            $this->assertTrue($checkin->checked_in_at->equalTo(now()->subMinutes(12)));
            $admission = $guest->admissions()->sole();
            $this->assertSame([$event->id, $used, 'Seed gate', 'Seeder', '127.0.0.1', 'Seeder'], [$admission->event_id, $admission->quantity, $admission->admitted_by, $admission->device_label, $admission->ip_address, $admission->user_agent]);
            $this->assertTrue($admission->admitted_at->equalTo(now()->subMinutes(12)));
            $log = $guest->scanLogs()->sole();
            $this->assertSame([$event->id, Guest::hashToken($qr->qr_token), 'admit', 'admitted', $used, 'Seed admission recorded.', '127.0.0.1', 'Seeder', ['remaining' => $allowed - $used]], [$log->event_id, $log->token_hash, $log->action, $log->result, $log->quantity, $log->message, $log->ip_address, $log->user_agent, $log->metadata]);
            $this->assertTrue($log->scanned_at->equalTo(now()->subMinutes(12)));
        }
        $this->assertSame(6, Guest::query()->distinct()->count('manual_code'));
        $this->assertSame(6, QrCode::query()->distinct()->count('qr_token'));
        $this->assertDatabaseCount('workspace_users', 0);
        $this->assertDatabaseCount('workspaces', 0);
        $beforeRepeat = $this->snapshot();

        [$failure, $work] = $this->observeWork(fn () => $this->inEnvironment(
            $environment, fn () => $this->invokeSeeder($invocation),
        ));

        $this->assertFixedFailure($failure, 'Demo seeding requires empty sample-data tables.');
        $this->assertSame(0, $work->hashes);
        $this->assertSame($beforeRepeat, $this->snapshot());
        $this->assertSame($foreignKeys, $this->foreignKeys());
        $this->assertNoMessages();
    }

    public function test_mid_qr_failure_rolls_back_all_fixture_rows_and_observer_event_then_allows_retry(): void
    {
        foreach (self::sampleTables() as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        // Migrations create this synthetic default; remove it to exercise the observer.
        Event::query()->where('event_name', 'Default Wedding Event')->delete();
        $this->assertDatabaseCount('events', 0);
        $before = $this->snapshot();
        $foreignKeys = $this->foreignKeys();
        $transactionLevel = DB::connection()->transactionLevel();
        $armed = true;
        $qrAttempts = 0;
        $partial = [];
        DB::connection()->beforeExecuting(function (string $query) use (&$armed, &$qrAttempts, &$partial): void {
            if (! $armed || preg_match('/\A\s*insert\s+into\s+["`]?qr_codes["`]?\s*\(/i', $query) !== 1) {
                return;
            }
            if (++$qrAttempts === 3) {
                foreach (['events', ...self::sampleTables()] as $table) {
                    $partial[$table] = DB::table($table)->count();
                }

                throw new RuntimeException('Synthetic QR insert failure.');
            }
        });
        try {
            $failure = $this->captureFailure(fn () => (new DatabaseSeeder)->run());
        } finally {
            $armed = false;
        }

        $this->assertFixedFailure($failure, 'Synthetic QR insert failure.');
        $this->assertSame(3, $qrAttempts);
        foreach (['events', 'users', 'guests', 'qr_codes', 'checkins', 'admissions', 'scan_logs'] as $table) {
            $this->assertGreaterThan(0, $partial[$table] ?? 0, 'Failure must follow actual '.$table.' inserts.');
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($transactionLevel, DB::connection()->transactionLevel());
        $this->assertSame($foreignKeys, $this->foreignKeys());

        (new DatabaseSeeder)->run();

        $this->assertSampleCounts();
        $this->assertDatabaseCount('events', 1);
        $event = Event::query()->where('event_name', 'Default Wedding Event')->sole();
        $this->assertSame(6, Guest::query()->where('event_id', $event->id)->count());
        $this->assertSame($transactionLevel, DB::connection()->transactionLevel());
        $this->assertSame($foreignKeys, $this->foreignKeys());
        $this->assertNoMessages();
    }

    public static function disallowedEnvironments(): array
    {
        $cases = [];
        foreach (['production', 'staging', 'development', 'LOCAL', '', 'testing '] as $environment) {
            foreach (['direct', 'forced command'] as $invocation) {
                $label = $environment === '' ? 'empty' : ($environment === 'testing ' ? 'testing with trailing space' : $environment);
                $cases[$label.' '.$invocation] = [$environment, $invocation];
            }
        }

        return $cases;
    }

    public static function populatedTargets(): array
    {
        return array_combine(self::sampleTables(), array_map(fn (string $table): array => [$table], self::sampleTables()));
    }

    public static function allowedEnvironments(): array
    {
        return ['local direct' => ['local', 'direct'], 'testing forced command' => ['testing', 'forced command']];
    }

    private static function sampleTables(): array
    {
        return ['scan_logs', 'admissions', 'checkins', 'qr_codes', 'guests', 'users'];
    }

    private function populateTarget(string $table): void
    {
        $event = Event::query()->create(['event_name' => 'Existing preserved event', 'status' => Event::STATUS_ACTIVE]);
        if ($table === 'users') {
            User::query()->create(['name' => 'Existing administrator', 'email' => 'existing@example.invalid', 'password' => Hash::make('existing-fixture-password'), 'role' => User::ROLE_ADMIN]);

            return;
        }
        if ($table === 'scan_logs') {
            ScanLog::query()->create(['event_id' => $event->id, 'action' => 'verify', 'result' => 'invalid', 'message' => 'Existing preserved log', 'scanned_at' => now()]);

            return;
        }
        if ($table === 'checkins') {
            Checkin::query()->create(['event_id' => $event->id, 'scan_result' => Checkin::RESULT_INVALID, 'gate_name' => 'Existing gate', 'checked_in_at' => now()]);

            return;
        }
        $guest = Guest::query()->create(['event_id' => $event->id, 'manual_code' => '0042', 'name' => 'Existing preserved guest', 'phone_number' => '+255712345678', 'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1, 'used_entries' => 0]);
        if ($table === 'qr_codes') {
            QrCode::query()->create(['guest_id' => $guest->id, 'qr_token' => str_repeat('e', 64), 'is_active' => true, 'generated_at' => now()]);
        } elseif ($table === 'admissions') {
            Admission::query()->create(['event_id' => $event->id, 'guest_id' => $guest->id, 'quantity' => 1, 'admitted_by' => 'Existing gate', 'admitted_at' => now()]);
        }
    }

    private function invokeSeeder(string $invocation): ?int
    {
        if ($invocation === 'forced command') {
            return Artisan::call('db:seed', ['--force' => true]);
        }
        (new DatabaseSeeder)->run();

        return null;
    }

    private function inEnvironment(string $environment, callable $callback): mixed
    {
        $original = $this->app->environment();
        $this->app->instance('env', $environment);
        try {
            return $callback();
        } finally {
            $this->app->instance('env', $original);
        }
    }

    private function observeWork(callable $callback): array
    {
        $work = (object) ['queries' => [], 'transactions' => 0, 'hashes' => 0];
        $armed = true;
        $connection = DB::connection();
        $connection->beforeExecuting(static function (string $query) use ($work, &$armed): void {
            if ($armed) {
                $work->queries[] = $query;
            }
        });
        $connection->beforeStartingTransaction(static function () use ($work, &$armed): void {
            if ($armed) {
                $work->transactions++;
            }
        });
        $originalHasher = Hash::getFacadeRoot();
        $hasher = new class($this->app) extends HashManager
        {
            public int $makeCalls = 0;

            public function make(#[\SensitiveParameter] $value, array $options = []): string
            {
                $this->makeCalls++;

                return parent::make($value, $options);
            }
        };
        Hash::swap($hasher);
        try {
            $failure = $this->captureFailure($callback);
        } finally {
            $armed = false;
            $work->hashes = $hasher->makeCalls;
            Hash::swap($originalHasher);
        }

        return [$failure, $work];
    }

    private function captureFailure(callable $callback): ?Throwable
    {
        try {
            $callback();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private function assertFixedFailure(?Throwable $failure, string $message): void
    {
        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertSame($message, $failure->getMessage());
    }

    private function assertSampleCounts(): void
    {
        foreach (['users' => 2, 'guests' => 6, 'qr_codes' => 6, 'checkins' => 2, 'admissions' => 2, 'scan_logs' => 2] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
    }

    private function foreignKeys(): int
    {
        return (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys;
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            $rows = array_map(fn (object $row): array => (array) $row, DB::table($table)->get()->all());
            usort($rows, fn (array $a, array $b): int => strcmp(serialize($a), serialize($b)));
            $snapshot[$table] = $rows;
        }
        ksort($snapshot);

        return $snapshot;
    }

    private function assertNoMessages(): void
    {
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }
}
