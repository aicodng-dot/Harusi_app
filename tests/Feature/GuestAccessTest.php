<?php

namespace Tests\Feature;

use App\Services\Tenancy\AuthorityContext;
use App\Services\Tenancy\GuestAccess;
use App\Services\Tenancy\WorkspaceAccess;
use App\Services\Tenancy\WorkspaceProvisioningResult;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GuestAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = ['guest.view', 'guest.update', 'qr.view', 'qr.regenerate'];

    private array $ownedConnections = [];

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    protected function tearDown(): void
    {
        foreach ($this->ownedConnections as $name) {
            DB::purge($name);
        }

        parent::tearDown();
    }

    public function test_owners_on_both_workspaces_and_active_manager_have_explicit_resource_permissions(): void
    {
        $connection = $this->fixture();
        $access = app(GuestAccess::class);
        foreach ([[211, 101, 301, 401, 501], [212, 101, 301, 401, 501], [214, 102, 302, 403, 503]] as [$user, $workspace, $event, $guest, $qr]) {
            foreach (self::ACTIONS as $action) {
                $qrId = str_starts_with($action, 'qr.') ? $qr : null;
                $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, $user, $workspace, $event, $guest, $action, $qrId)));
            }
        }
        $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, 211, 101, 301, 402, 'qr.view', 502)));
    }

    public function test_view_and_mutation_observations_delegate_the_corresponding_event_action(): void
    {
        $connection = $this->fixture();
        $calls = [];
        $gate = Mockery::mock(WorkspaceAccess::class);
        $gate->shouldReceive('allows')->times(4)->withArgs(function (Connection $given, mixed $user, mixed $workspace, mixed $action, mixed $event) use ($connection, &$calls): bool {
            $calls[] = [$user, $workspace, $action, $event];

            return $given === $connection;
        })->andReturn(true);
        $this->app->instance(WorkspaceAccess::class, $gate);
        $access = app(GuestAccess::class);
        foreach (self::ACTIONS as $action) {
            $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, 211, 101, 301, 401, $action, str_starts_with($action, 'qr.') ? 501 : null)));
        }
        $this->assertSame([
            [211, 101, 'event.view', 301], [211, 101, 'event.update', 301],
            [211, 101, 'event.view', 301], [211, 101, 'event.update', 301],
        ], $calls);
    }

    public function test_resource_query_rechecks_the_actual_workspace_parent_after_a_positive_event_observation(): void
    {
        $connection = $this->minimalFixture();
        $connection->table('workspaces')->where('id', 101)->delete();
        // Isolate the child query from an earlier positive observation. Its
        // own lineage read must require the actual parent, not only its old ID.
        $gate = Mockery::mock(WorkspaceAccess::class);
        $gate->shouldReceive('allows')->once()->with($connection, 211, 101, 'event.view', 301)->andReturn(true);
        $this->app->instance(WorkspaceAccess::class, $gate);

        $this->assertFalse($this->observe($connection, fn () => app(GuestAccess::class)->allows($connection, 211, 101, 301, 401, 'guest.view')));
    }

    public function test_same_event_qr_swaps_and_cross_workspace_context_never_authorize_another_resource(): void
    {
        $connection = $this->fixture();
        $access = app(GuestAccess::class);
        $this->withSession(['selected_event_id' => 302, 'selected_workspace_id' => 102]);
        foreach ([
            [211, 101, 301, 401, 502], [211, 101, 301, 402, 501],
            [211, 101, 301, 401, 503], [211, 101, 301, 403, 503],
            [211, 101, 302, 403, 503], [211, 102, 302, 403, 503],
            [214, 101, 301, 401, 501], [212, 102, 302, 403, 503],
            [211, 101, 301, 999999, 501], [211, 101, 301, 401, 999999],
        ] as [$user, $workspace, $event, $guest, $qr]) {
            foreach (['qr.view', 'qr.regenerate'] as $action) {
                $this->assertFalse($this->observe($connection, fn () => $access->allows($connection, $user, $workspace, $event, $guest, $action, $qr)));
            }
        }
        $this->assertFalse($this->observe($connection, fn () => $access->allows($connection, 211, 101, 301, 403, 'guest.view')));
        $this->assertFalse($this->observe($connection, fn () => $access->allows($connection, 211, 101, 999999, 401, 'guest.view')));
        $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, 211, 101, 301, 401, 'guest.view')));
    }

    public function test_platform_global_scanner_and_inactive_authority_do_not_bypass_the_resource_gate(): void
    {
        $connection = $this->fixture();
        $access = app(GuestAccess::class);
        foreach ([213, 215, 216, 999999] as $user) {
            $this->assertFalse($this->observe($connection, fn () => $access->allows($connection, $user, 101, 301, 401, 'qr.regenerate', 501)));
        }
        // Manager212 has the old scanner assignment to B, but no membership B.
        $this->assertFalse($this->observe($connection, fn () => $access->allows($connection, 212, 102, 302, 403, 'qr.view', 503)));
    }

    public function test_reused_service_rereads_membership_account_guest_and_qr_lineage(): void
    {
        $connection = $this->fixture();
        $access = app(GuestAccess::class);
        $operation = fn () => $access->allows($connection, 211, 101, 301, 401, 'qr.regenerate', 501);
        $this->assertTrue($this->observe($connection, $operation));
        $connection->table('workspace_users')->where('user_id', 211)->update(['is_active' => false]);
        $this->assertFalse($this->observe($connection, $operation));
        $connection->table('workspace_users')->where('user_id', 211)->update(['is_active' => true, 'role' => 'manager']);
        $this->assertTrue($this->observe($connection, $operation));
        $connection->table('users')->where('id', 211)->update(['is_active' => false, 'is_platform_admin' => true]);
        $this->assertFalse($this->observe($connection, $operation));
        $connection->table('users')->where('id', 211)->update(['is_active' => true, 'role' => 'future-customer']);
        $this->assertFalse($this->observe($connection, $operation));
        $connection->table('users')->where('id', 211)->update(['role' => 'admin']);
        $connection->table('guests')->where('id', 401)->update(['event_id' => 302]);
        $this->assertFalse($this->observe($connection, $operation));
        $connection->table('guests')->where('id', 401)->update(['event_id' => 301]);
        $connection->table('qr_codes')->where('id', 501)->update(['guest_id' => 404]);
        $this->assertFalse($this->observe($connection, $operation));
        $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, 211, 101, 301, 404, 'qr.view', 501)));
        $connection->table('qr_codes')->where('id', 501)->update(['guest_id' => 401]);
        $this->assertTrue($this->observe($connection, $operation));
        $connection->table('workspace_users')->where('user_id', 211)->delete();
        $this->assertFalse($this->observe($connection, $operation));
    }

    public function test_primary_reads_ignore_replica_and_default_outside_an_ambient_transaction(): void
    {
        $connection = $this->minimalFixture();
        $this->assertSame(0, $connection->transactionLevel());
        $default = DB::getDefaultConnection();
        $defaultBefore = $this->snapshot(DB::connection());
        $read = $connection->getReadPdo();
        $attempts = 0;
        $connection->setReadPdo(static function () use (&$attempts): never {
            $attempts++;
            throw new RuntimeException('private-replica-marker');
        });
        DB::setDefaultConnection('unconfigured_guest_access_default');
        try {
            $access = app(GuestAccess::class);
            $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, 211, 101, 301, 401, 'qr.regenerate', 501)));
            $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, 212, 101, 301, 401, 'guest.update')));
            $this->assertFalse($this->observe($connection, fn () => $access->allows($connection, 211, 101, 301, 401, 'qr.view', 502)));
            $this->assertSame('unconfigured_guest_access_default', DB::getDefaultConnection());
        } finally {
            $connection->setReadPdo($read);
            DB::setDefaultConnection($default);
        }
        $this->assertSame(0, $attempts);
        $this->assertSame($defaultBefore, $this->snapshot(DB::connection()));
    }

    #[DataProvider('invalidLineages')]
    public function test_missing_dangling_or_noncanonical_resource_parents_deny(string $table, string $column, mixed $value): void
    {
        $connection = $this->minimalFixture();
        $connection->table($table)->where('id', $table === 'guests' ? 401 : 501)->update([$column => $value]);

        $this->assertFalse($this->observe($connection, fn () => app(GuestAccess::class)->allows($connection, 211, 101, 301, 401, 'qr.view', 501)));
    }

    public static function invalidLineages(): array
    {
        return [
            'unowned guest' => ['guests', 'event_id', null],
            'dangling guest parent' => ['guests', 'event_id', 999999],
            'fractional guest parent' => ['guests', 'event_id', 301.5],
            'dangling QR parent' => ['qr_codes', 'guest_id', 999999],
            'noncanonical QR parent' => ['qr_codes', 'guest_id', '401x'],
        ];
    }

    #[DataProvider('malformedSchemas')]
    public function test_malformed_primary_keys_or_required_parent_columns_deny_resource_access(string $shape): void
    {
        $connection = $this->minimalFixture($shape);
        $this->assertFalse($this->observe($connection, fn () => app(GuestAccess::class)->allows($connection, 211, 101, 301, 401, 'qr.view', 501)));
        if (str_starts_with($shape, 'guest ')) {
            $this->assertFalse($this->observe($connection, fn () => app(GuestAccess::class)->allows($connection, 211, 101, 301, 401, 'guest.view')));
        }
    }

    public static function malformedSchemas(): array
    {
        return array_combine($shapes = ['guest missing parent', 'guest text primary', 'guest duplicate ID',
            'QR missing parent', 'QR duplicate ID', 'QR composite primary'],
            array_map(static fn (string $shape): array => [$shape], $shapes));
    }

    #[DataProvider('invalidInputs')]
    public function test_ambiguous_ids_actions_and_qr_shapes_deny_before_database_work(mixed $user, mixed $workspace, mixed $event, mixed $guest, mixed $action, mixed $qr): void
    {
        $connects = $queries = $transactions = 0;
        $connection = $this->neverConnect($connects, $queries, $transactions);

        $this->assertFalse(app(GuestAccess::class)->allows($connection, $user, $workspace, $event, $guest, $action, $qr));

        $this->assertSame([0, 0, 0], [$connects, $queries, $transactions]);
        $this->assertNoLogs();
    }

    public static function invalidInputs(): array
    {
        return [
            'string user' => ['211', 101, 301, 401, 'guest.view', null],
            'boolean user' => [true, 101, 301, 401, 'guest.view', null],
            'string workspace' => [211, '101', 301, 401, 'guest.view', null],
            'string event' => [211, 101, '301', 401, 'guest.view', null],
            'zero event' => [211, 101, 0, 401, 'guest.view', null],
            'float guest' => [211, 101, 301, 401.0, 'guest.view', null],
            'zero guest' => [211, 101, 301, 0, 'guest.view', null],
            'null guest' => [211, 101, 301, null, 'guest.view', null],
            'array action' => [211, 101, 301, 401, ['guest.view'], null],
            'miscased action' => [211, 101, 301, 401, 'Guest.View', null],
            'missing QR' => [211, 101, 301, 401, 'qr.view', null],
            'string QR' => [211, 101, 301, 401, 'qr.regenerate', '501'],
            'unexpected QR for guest' => [211, 101, 301, 401, 'guest.update', 501],
            'authority facts as user' => [new AuthorityContext(true, true, 'owner'), 101, 301, 401, 'guest.view', null],
            'provisioning receipt as guest' => [211, 101, 301, new WorkspaceProvisioningResult(101, true), 'guest.view', null],
        ];
    }

    public function test_unknown_bulk_file_scanner_and_destructive_actions_deny_before_connecting(): void
    {
        $connects = $queries = $transactions = 0;
        $connection = $this->neverConnect($connects, $queries, $transactions);
        foreach (['guest.create', 'guest.delete', 'guest.bulkUpdate', 'guest.import', 'guest.export',
            'qr.download', 'qr.generate', 'scanner.admit', 'report.view', 'notification.send', 'offline.package', ''] as $action) {
            $this->assertFalse(app(GuestAccess::class)->allows($connection, 211, 101, 301, 401, $action));
        }
        $this->assertSame([0, 0, 0], [$connects, $queries, $transactions]);
        $this->assertNoLogs();
    }

    public function test_unavailable_unsupported_and_raw_resource_errors_are_unlogged_denials(): void
    {
        $connects = 0;
        $factory = static function () use (&$connects): never {
            $connects++;
            throw new RuntimeException('private-database-password@example.invalid');
        };
        $unsupported = new Connection($factory, 'private-db', '', ['driver' => 'pgsql']);
        $this->assertFalse(app(GuestAccess::class)->allows($unsupported, 211, 101, 301, 401, 'qr.view', 501));
        $this->assertSame(0, $connects);
        $unavailable = new SQLiteConnection($factory, ':memory:', '', ['driver' => 'sqlite']);
        $this->assertFalse(app(GuestAccess::class)->allows($unavailable, 211, 101, 301, 401, 'qr.view', 501));
        $this->assertSame(1, $connects);

        $connection = $this->fixture();
        $armed = $triggered = false;
        $connection->beforeExecuting(static function (string $sql) use (&$armed, &$triggered): void {
            if ($armed && preg_match('/\b(?:from|join)\s+["\x60]?(?:guests|qr_codes)\b/i', $sql)) {
                $triggered = true;
                throw new RuntimeException('private-resource-query@example.invalid');
            }
        });
        $this->assertFalse($this->observe($connection, function () use ($connection, &$armed): bool {
            $armed = true;
            try {
                return app(GuestAccess::class)->allows($connection, 211, 101, 301, 401, 'qr.view', 501);
            } finally {
                $armed = false;
            }
        }));
        $this->assertTrue($triggered);
        $this->assertNoLogs();
    }

    private function observe(Connection $connection, callable $operation): bool
    {
        $before = $this->snapshot($connection);
        $level = $connection->transactionLevel();
        $transaction = $connection->getPdo()->inTransaction();
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $sql = [];
        $begins = 0;
        $armed = true;
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$sql): void {
            $sql[] = $event->sql;
        });
        $connection->beforeStartingTransaction(static function () use (&$armed, &$begins): void {
            if ($armed) {
                $begins++;
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $result = $operation();
        } finally {
            $armed = false;
            if ($original !== null) {
                $connection->setEventDispatcher($original);
            } else {
                $connection->unsetEventDispatcher();
            }
        }
        $this->assertIsBool($result);
        $this->assertSame(0, $begins);
        $this->assertSame($level, $connection->transactionLevel());
        $this->assertSame($transaction, $connection->getPdo()->inTransaction());
        foreach ($sql as $query) {
            $this->assertDoesNotMatchRegularExpression('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b|\bfor\s+update\b|\block\s+in\s+share\s+mode\b/i', $query);
            if (preg_match('/\b(?:from|join)\s+["\x60]?(?:guests|qr_codes)\b/i', $query)) {
                $this->assertDoesNotMatchRegularExpression('/\b(?:qr_token|manual_code|qr_image_path|phone_number|name)\b/i', $query);
            }
        }
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertNoLogs();

        return $result;
    }

    private function neverConnect(int &$connects, int &$queries, int &$transactions): Connection
    {
        $connection = new SQLiteConnection(static function () use (&$connects): never {
            $connects++;
            throw new RuntimeException('private-never-connect');
        }, ':memory:', '', ['driver' => 'sqlite']);
        $connection->beforeExecuting(static function () use (&$queries): void {
            $queries++;
        });
        $connection->beforeStartingTransaction(static function () use (&$transactions): void {
            $transactions++;
        });

        return $connection;
    }

    private function assertNoLogs(): void
    {
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    private function fixture(): Connection
    {
        $connection = DB::connection();
        $password = Hash::make('synthetic-conserved-password');
        foreach ([211 => ['admin', false], 212 => ['scanner', false], 213 => ['admin', true],
            214 => ['scanner', false], 215 => ['admin', false], 216 => ['admin', false]] as $id => [$role, $platform]) {
            $connection->table('users')->insert(['id' => $id, 'name' => 'Synthetic account '.$id,
                'email' => 'synthetic-'.$id.'@example.invalid', 'password' => $password,
                'remember_token' => 'synthetic-remember-'.$id, 'role' => $role, 'is_active' => true, 'is_platform_admin' => $platform]);
        }
        $connection->table('workspaces')->insert([['id' => 101, 'name' => 'Synthetic workspace A'], ['id' => 102, 'name' => 'Synthetic workspace B']]);
        foreach ([[211, 101, 'owner', true], [212, 101, 'manager', true], [214, 102, 'owner', true], [216, 101, 'owner', false]] as [$user, $workspace, $role, $active]) {
            $connection->table('workspace_users')->insert(['workspace_id' => $workspace, 'user_id' => $user, 'role' => $role, 'is_active' => $active]);
        }
        $connection->table('events')->insert([
            ['id' => 301, 'event_name' => 'Synthetic event A', 'workspace_id' => 101, 'status' => 'active'],
            ['id' => 302, 'event_name' => 'Synthetic event B', 'workspace_id' => 102, 'status' => 'active'],
        ]);
        $connection->table('users')->where('id', 212)->update(['event_id' => 302, 'gate_name' => 'Synthetic B gate']);
        foreach ([[401, 301, '0042'], [402, 301, '0043'], [403, 302, '0044'], [404, 301, '0045']] as [$id, $event, $code]) {
            $connection->table('guests')->insert(['id' => $id, 'event_id' => $event, 'name' => 'Synthetic conserved guest '.$id,
                'phone_number' => '+255712345678', 'manual_code' => $code, 'pass_type' => 'special',
                'allowed_entries' => 6, 'used_entries' => 2, 'status' => 'partially_used']);
        }
        foreach ([[501, 401, 'a'], [502, 402, 'b'], [503, 403, 'c']] as [$id, $guest, $letter]) {
            $connection->table('qr_codes')->insert(['id' => $id, 'guest_id' => $guest, 'qr_token' => str_repeat($letter, 64),
                'qr_image_path' => 'private/synthetic-do-not-open-'.$id.'.png', 'is_active' => true]);
        }
        $connection->table('admissions')->insert(['id' => 601, 'event_id' => 301, 'guest_id' => 401, 'quantity' => 2, 'admitted_by' => 'Synthetic gate', 'admitted_at' => '2026-10-21 10:00:00']);
        $connection->table('checkins')->insert(['id' => 701, 'event_id' => 301, 'guest_id' => 401, 'qr_code_id' => 501, 'user_id' => 212,
            'scan_result' => 'admitted', 'entries_added' => 2, 'used_entries_after_scan' => 2, 'remaining_entries_after_scan' => 4]);
        $connection->table('scan_logs')->insert(['id' => 801, 'event_id' => 301, 'guest_id' => 401, 'action' => 'admit', 'result' => 'admitted',
            'quantity' => 2, 'message' => 'Synthetic conserved audit', 'scanned_at' => '2026-10-21 10:00:00']);

        return $connection;
    }

    private function minimalFixture(string $shape = 'normal'): Connection
    {
        $name = 'guest_access_'.count($this->ownedConnections);
        $this->ownedConnections[] = $name;
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        $connection = DB::connection($name);
        $schema = $connection->getSchemaBuilder();
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('role');
            $table->boolean('is_active');
            $table->boolean('is_platform_admin');
        });
        $schema->create('workspaces', fn (Blueprint $table) => $table->id());
        $schema->create('workspace_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->boolean('is_active');
        });
        $schema->create('events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
        });
        $schema->create('guests', function (Blueprint $table) use ($shape): void {
            if ($shape === 'guest text primary') {
                $table->string('id')->primary();
            } elseif ($shape === 'guest duplicate ID') {
                $table->integer('id');
            } else {
                $table->id();
            }
            if ($shape !== 'guest missing parent') {
                $table->unsignedBigInteger('event_id')->nullable();
            }
        });
        $schema->create('qr_codes', function (Blueprint $table) use ($shape): void {
            if (in_array($shape, ['QR duplicate ID', 'QR composite primary'], true)) {
                $table->integer('id');
            } else {
                $table->id();
            }
            if ($shape !== 'QR missing parent') {
                $table->unsignedBigInteger('guest_id')->nullable();
            }
            if ($shape === 'QR composite primary') {
                $table->primary(['id', 'guest_id']);
            }
        });
        $connection->table('users')->insert([
            ['id' => 211, 'role' => 'admin', 'is_active' => true, 'is_platform_admin' => false],
            ['id' => 212, 'role' => 'scanner', 'is_active' => true, 'is_platform_admin' => false],
        ]);
        $connection->table('workspaces')->insert(['id' => 101]);
        $connection->table('workspace_users')->insert([
            ['workspace_id' => 101, 'user_id' => 211, 'role' => 'owner', 'is_active' => true],
            ['workspace_id' => 101, 'user_id' => 212, 'role' => 'manager', 'is_active' => true],
        ]);
        $connection->table('events')->insert(['id' => 301, 'workspace_id' => 101]);
        foreach ([401, 402] as $guest) {
            $row = $shape === 'guest missing parent' ? ['id' => $guest] : ['id' => $guest, 'event_id' => 301];
            $connection->table('guests')->insert($row);
            if ($shape === 'guest duplicate ID' && $guest === 401) {
                $connection->table('guests')->insert($row);
            }
        }
        foreach ([501 => 401, 502 => 402] as $qr => $guest) {
            $row = $shape === 'QR missing parent' ? ['id' => $qr] : ['id' => $qr, 'guest_id' => $guest];
            $connection->table('qr_codes')->insert($row);
            if ($shape === 'QR duplicate ID' && $qr === 501) {
                $connection->table('qr_codes')->insert($row);
            }
        }

        return $connection;
    }

    private function snapshot(Connection $connection): array
    {
        $rows = [];
        foreach ($connection->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $table) {
            $contents = array_map(static fn (object $row): array => (array) $row, $connection->table($table->name)->useWritePdo()->get()->all());
            usort($contents, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            $rows[$table->name] = $contents;
        }

        return ['schema' => array_map(static fn (object $row): array => (array) $row,
            $connection->selectFromWriteConnection('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')), 'rows' => $rows];
    }
}
