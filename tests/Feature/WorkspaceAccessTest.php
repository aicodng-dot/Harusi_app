<?php

namespace Tests\Feature;

use App\Services\Tenancy\AuthorityContext;
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
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = ['workspace.view', 'workspace.rename', 'event.create', 'event.view', 'event.update'];

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

    public function test_owner_and_manager_have_only_the_explicit_five_action_matrix(): void
    {
        $connection = $this->fixture();
        $access = app(WorkspaceAccess::class);
        foreach ([211 => 'owner', 212 => 'manager'] as $user => $role) {
            foreach (self::ACTIONS as $action) {
                $allowed = $this->observe($connection, fn () => $access->allows(
                    $connection, $user, 101, $action, str_starts_with($action, 'event.') && $action !== 'event.create' ? 301 : null,
                ));
                $this->assertSame($role === 'owner' || $action !== 'workspace.rename', $allowed, $role.' '.$action);
            }
        }
        // A scanner may manage the workspace through membership without
        // inheriting access to the different event in its old assignment.
        $this->assertFalse($this->observe($connection, fn () => $access->allows($connection, 212, 102, 'event.view', 302)));
        $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, 214, 102, 'workspace.rename')));
        $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, 214, 102, 'event.update', 302)));
    }

    #[DataProvider('unavailableActors')]
    public function test_global_roles_platform_flags_and_invalid_accounts_do_not_replace_active_membership(int $user): void
    {
        $connection = $this->fixture();
        foreach (self::ACTIONS as $action) {
            $event = in_array($action, ['event.view', 'event.update'], true) ? 301 : null;
            $this->assertFalse($this->observe($connection, fn () => app(WorkspaceAccess::class)->allows($connection, $user, 101, $action, $event)));
        }
    }

    public static function unavailableActors(): array
    {
        return [
            'explicit platform admin without membership' => [213],
            'owner in another workspace' => [214],
            'global admin without membership' => [215],
            'inactive owner membership' => [216],
            'unsupported account role with owner membership' => [217],
            'inactive platform account with owner membership' => [218],
            'missing account' => [999999],
        ];
    }

    public function test_explicit_workspace_and_event_parents_override_navigation_context(): void
    {
        $connection = $this->fixture();
        $access = app(WorkspaceAccess::class);
        $this->withSession(['selected_event_id' => 302, 'selected_workspace_id' => 102]);
        $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, 211, 101, 'event.view', 301)));
        foreach ([302, 303, 999999] as $event) {
            foreach (['event.view', 'event.update'] as $action) {
                $this->assertFalse($this->observe($connection, fn () => $access->allows($connection, 211, 101, $action, $event)));
            }
        }
        $this->assertFalse($this->observe($connection, fn () => $access->allows($connection, 211, 102, 'event.view', 301)));
        $this->assertFalse($this->observe($connection, fn () => $access->allows($connection, 211, 999999, 'workspace.view')));
        // Ownership permission deliberately leaves lifecycle validation to the
        // future operation; it is not an assertion that cancellation permits a write.
        $connection->table('events')->where('id', 301)->update(['status' => 'cancelled']);
        $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, 211, 101, 'event.view', 301)));
    }

    public function test_reused_service_rereads_membership_account_and_resource_on_every_call(): void
    {
        $connection = $this->fixture();
        $access = app(WorkspaceAccess::class);
        $rename = fn () => $access->allows($connection, 211, 101, 'workspace.rename');
        $event = fn () => $access->allows($connection, 211, 101, 'event.update', 301);
        $this->assertTrue($this->observe($connection, $rename));
        $connection->table('workspace_users')->where('user_id', 211)->update(['role' => 'manager']);
        $this->assertFalse($this->observe($connection, $rename));
        $this->assertTrue($this->observe($connection, $event));
        $connection->table('workspace_users')->where('user_id', 211)->update(['is_active' => false]);
        $this->assertFalse($this->observe($connection, $event));
        $connection->table('workspace_users')->where('user_id', 211)->update(['is_active' => true, 'role' => 'owner']);
        $this->assertTrue($this->observe($connection, $event));
        $connection->table('events')->where('id', 301)->update(['workspace_id' => 102]);
        $this->assertFalse($this->observe($connection, $event));
        $connection->table('events')->where('id', 301)->update(['workspace_id' => 101]);
        $connection->table('users')->where('id', 211)->update(['is_active' => false, 'is_platform_admin' => true]);
        $this->assertFalse($this->observe($connection, $event));
        $connection->table('users')->where('id', 211)->update(['is_active' => true, 'role' => 'future-customer']);
        $this->assertFalse($this->observe($connection, $event));
        $connection->table('users')->where('id', 211)->update(['role' => 'admin']);
        $this->assertTrue($this->observe($connection, $event));
        $connection->table('workspace_users')->where('user_id', 211)->delete();
        $this->assertFalse($this->observe($connection, $event));
    }

    public function test_primary_only_observations_preserve_an_unavailable_default_and_read_connection(): void
    {
        // No ambient transaction here: Laravel routes read PDOs to the primary
        // inside transactions, which would otherwise hide an accidental replica read.
        $connection = $this->minimalFixture();
        $connection->table('users')->insert(['id' => 212, 'role' => 'scanner', 'is_active' => true, 'is_platform_admin' => false]);
        $connection->table('workspaces')->insert(['id' => 102]);
        $connection->table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 212, 'role' => 'manager', 'is_active' => true]);
        $connection->table('events')->insert(['id' => 302, 'workspace_id' => 102]);
        $this->assertSame(0, $connection->transactionLevel());
        $default = DB::getDefaultConnection();
        $defaultBefore = $this->snapshot(DB::connection());
        $read = $connection->getReadPdo();
        $attempts = 0;
        $connection->setReadPdo(static function () use (&$attempts): never {
            $attempts++;
            throw new RuntimeException('private-replica-marker');
        });
        DB::setDefaultConnection('unconfigured_workspace_access_default');
        try {
            $access = app(WorkspaceAccess::class);
            $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, 211, 101, 'event.update', 301)));
            $this->assertTrue($this->observe($connection, fn () => $access->allows($connection, 212, 101, 'event.create')));
            $this->assertFalse($this->observe($connection, fn () => $access->allows($connection, 211, 101, 'event.view', 302)));
            $this->assertSame('unconfigured_workspace_access_default', DB::getDefaultConnection());
        } finally {
            $connection->setReadPdo($read);
            DB::setDefaultConnection($default);
        }
        $this->assertSame(0, $attempts);
        $this->assertSame($defaultBefore, $this->snapshot(DB::connection()));
    }

    #[DataProvider('invalidParents')]
    public function test_unowned_dangling_and_noncanonical_event_parents_deny(mixed $parent): void
    {
        $connection = $this->minimalFixture();
        $connection->table('events')->where('id', 301)->update(['workspace_id' => $parent]);

        $this->assertFalse($this->observe($connection, fn () => app(WorkspaceAccess::class)->allows($connection, 211, 101, 'event.view', 301)));
    }

    public static function invalidParents(): array
    {
        return ['unowned' => [null], 'dangling' => [999999], 'zero' => [0],
            'fractional' => [101.5], 'noncanonical string' => ['101x']];
    }

    #[DataProvider('malformedEventSchemas')]
    public function test_malformed_or_duplicate_event_id_schema_cannot_authorize_a_resource(string $shape): void
    {
        $connection = $this->minimalFixture($shape);
        foreach (['event.view', 'event.update'] as $action) {
            $this->assertFalse($this->observe($connection, fn () => app(WorkspaceAccess::class)->allows($connection, 211, 101, $action, 301)));
        }
    }

    public static function malformedEventSchemas(): array
    {
        return array_combine($shapes = ['missing table', 'missing parent column', 'text primary ID',
            'duplicate IDs without primary', 'composite primary'], array_map(static fn (string $shape): array => [$shape], $shapes));
    }

    public function test_missing_workspace_parent_denies_even_when_membership_and_event_rows_remain(): void
    {
        $connection = $this->minimalFixture();
        $connection->table('workspaces')->where('id', 101)->delete();

        $this->assertFalse($this->observe($connection, fn () => app(WorkspaceAccess::class)->allows($connection, 211, 101, 'event.view', 301)));
    }

    #[DataProvider('invalidInputs')]
    public function test_ambiguous_context_actions_and_event_shapes_deny_before_connecting(mixed $user, mixed $workspace, mixed $action, mixed $event): void
    {
        $connections = $queries = $transactions = 0;
        $connection = $this->neverConnect($connections, $queries, $transactions);

        $this->assertFalse(app(WorkspaceAccess::class)->allows($connection, $user, $workspace, $action, $event));

        $this->assertSame([0, 0, 0], [$connections, $queries, $transactions]);
        $this->assertNoLogs();
    }

    public static function invalidInputs(): array
    {
        return [
            'numeric string user' => ['211', 101, 'workspace.view', null],
            'boolean user' => [true, 101, 'workspace.view', null],
            'zero user' => [0, 101, 'workspace.view', null],
            'numeric string workspace' => [211, '101', 'workspace.view', null],
            'float workspace' => [211, 101.0, 'workspace.view', null],
            'array action' => [211, 101, ['workspace.view'], null],
            'miscased action' => [211, 101, 'Workspace.View', null],
            'missing event ID' => [211, 101, 'event.view', null],
            'string event ID' => [211, 101, 'event.update', '301'],
            'unexpected create event ID' => [211, 101, 'event.create', 301],
            'unexpected workspace event ID' => [211, 101, 'workspace.view', 301],
            'authority context as identity' => [new AuthorityContext(true, true, 'owner'), 101, 'workspace.view', null],
            'provisioning receipt as identity' => [new WorkspaceProvisioningResult(101, true), 101, 'workspace.view', null],
        ];
    }

    public function test_future_resource_and_privileged_actions_are_not_inferred_from_owner_role(): void
    {
        $connections = $queries = $transactions = 0;
        $connection = $this->neverConnect($connections, $queries, $transactions);
        foreach (['team.invite', 'team.manage', 'billing.manage', 'ownership.transfer', 'workspace.delete',
            'event.delete', 'scanner.admit', 'file.download', 'guest.view', 'report.export', 'message.send', 'offline.manage', ''] as $action) {
            $this->assertFalse(app(WorkspaceAccess::class)->allows($connection, 211, 101, $action));
        }
        $this->assertSame([0, 0, 0], [$connections, $queries, $transactions]);
        $this->assertNoLogs();
    }

    public function test_unavailable_and_unsupported_connections_return_only_false_without_logging(): void
    {
        $connections = 0;
        $factory = static function () use (&$connections): never {
            $connections++;
            throw new RuntimeException('private-connection-password@example.invalid');
        };
        $unsupported = new Connection($factory, 'private-db', '', ['driver' => 'pgsql']);
        $this->assertFalse(app(WorkspaceAccess::class)->allows($unsupported, 211, 101, 'event.view', 301));
        $this->assertSame(0, $connections);
        $unavailable = new SQLiteConnection($factory, ':memory:', '', ['driver' => 'sqlite']);
        $this->assertFalse(app(WorkspaceAccess::class)->allows($unavailable, 211, 101, 'event.view', 301));
        $this->assertSame(1, $connections);
        $this->assertNoLogs();
    }

    public function test_raw_event_query_failure_is_a_conserved_unlogged_denial(): void
    {
        $connection = $this->fixture();
        $access = app(WorkspaceAccess::class);
        $this->assertTrue($access->allows($connection, 211, 101, 'event.view', 301));
        $armed = false;
        $triggered = false;
        $connection->beforeExecuting(static function (string $sql) use (&$armed, &$triggered): void {
            if ($armed && preg_match('/\bfrom\s+["\x60]?events\b/i', $sql)) {
                $triggered = true;
                throw new RuntimeException('private-event-query@example.invalid');
            }
        });
        try {
            $this->assertFalse($this->observe($connection, function () use ($access, $connection, &$armed): bool {
                $armed = true;
                try {
                    return $access->allows($connection, 211, 101, 'event.view', 301);
                } finally {
                    $armed = false;
                }
            }));
        } finally {
            $armed = false;
        }
        $this->assertTrue($triggered);
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
        }
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertNoLogs();

        return $result;
    }

    private function neverConnect(int &$connections, int &$queries, int &$transactions): Connection
    {
        $connection = new SQLiteConnection(static function () use (&$connections): never {
            $connections++;
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
        $password = Hash::make('synthetic-existing-password');
        foreach ([211 => ['admin', true, false], 212 => ['scanner', true, false],
            213 => ['admin', true, true], 214 => ['scanner', true, false],
            215 => ['admin', true, false], 216 => ['admin', true, false],
            217 => ['future-customer', true, false], 218 => ['admin', false, true]] as $id => [$role, $active, $platform]) {
            $connection->table('users')->insert(['id' => $id, 'name' => 'Synthetic account '.$id,
                'email' => 'synthetic-'.$id.'@example.invalid', 'password' => $password,
                'role' => $role, 'is_active' => $active, 'is_platform_admin' => $platform]);
        }
        $connection->table('workspaces')->insert([['id' => 101, 'name' => 'Synthetic workspace A'], ['id' => 102, 'name' => 'Synthetic workspace B']]);
        foreach ([[211, 101, 'owner', true], [212, 101, 'manager', true], [214, 102, 'owner', true],
            [216, 101, 'owner', false], [217, 101, 'owner', true], [218, 101, 'owner', true]] as [$user, $workspace, $role, $active]) {
            $connection->table('workspace_users')->insert(['workspace_id' => $workspace, 'user_id' => $user, 'role' => $role, 'is_active' => $active]);
        }
        $connection->table('events')->insert([
            ['id' => 301, 'event_name' => 'Synthetic event A', 'workspace_id' => 101, 'status' => 'active'],
            ['id' => 302, 'event_name' => 'Synthetic event B', 'workspace_id' => 102, 'status' => 'active'],
            ['id' => 303, 'event_name' => 'Synthetic legacy unowned event', 'workspace_id' => null, 'status' => 'active'],
        ]);
        $connection->table('users')->where('id', 212)->update(['event_id' => 302, 'gate_name' => 'Synthetic other-workspace assignment']);
        $connection->table('guests')->insert(['id' => 401, 'event_id' => 301, 'name' => 'Synthetic conserved guest',
            'phone_number' => '+255712345678', 'manual_code' => '0042', 'pass_type' => 'single',
            'allowed_entries' => 1, 'used_entries' => 0, 'status' => 'active']);
        $connection->table('qr_codes')->insert(['id' => 501, 'guest_id' => 401, 'qr_token' => str_repeat('a', 64)]);

        return $connection;
    }

    private function minimalFixture(string $shape = 'normal'): Connection
    {
        $name = 'workspace_access_'.count($this->ownedConnections);
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
        $connection->table('users')->insert(['id' => 211, 'role' => 'admin', 'is_active' => true, 'is_platform_admin' => false]);
        $connection->table('workspaces')->insert(['id' => 101]);
        $connection->table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 211, 'role' => 'owner', 'is_active' => true]);
        if ($shape !== 'missing table') {
            $schema->create('events', function (Blueprint $table) use ($shape): void {
                if ($shape === 'text primary ID') {
                    $table->string('id')->primary();
                } elseif (in_array($shape, ['duplicate IDs without primary', 'composite primary'], true)) {
                    $table->integer('id');
                } else {
                    $table->id();
                }
                if ($shape !== 'missing parent column') {
                    $table->unsignedBigInteger('workspace_id')->nullable();
                }
                if ($shape === 'composite primary') {
                    $table->primary(['id', 'workspace_id']);
                }
            });
            $row = $shape === 'missing parent column' ? ['id' => 301] : ['id' => 301, 'workspace_id' => 101];
            $connection->table('events')->insert($row);
            if ($shape === 'duplicate IDs without primary') {
                $connection->table('events')->insert($row);
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
