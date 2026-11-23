<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminController;
use App\Http\Middleware\EnsureAdminEventSelected;
use App\Models\Event;
use App\Models\User;
use App\Services\GuestSnapshotService;
use App\Services\OfflineEventLockService;
use App\Services\OfflineScannerPackageBuilder;
use App\Services\Tenancy\LegacyEventResolver;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class LegacySelectedEventBoundaryTest extends TestCase
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

    #[DataProvider('invalidIds')]
    public function test_resolver_rejects_noncanonical_ids_before_any_query(mixed $id): void
    {
        $this->fixture();
        $connection = DB::connection();
        $before = $this->snapshot($connection);
        $queries = 0;
        $connection->beforeExecuting(static function () use (&$queries): void {
            $queries++;
        });

        $this->assertNull(app(LegacyEventResolver::class)->find($id));

        $this->assertSame(0, $queries);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function invalidIds(): array
    {
        return ['absent' => [null], 'zero' => [0], 'negative' => [-1], 'leading zero' => ['0301'],
            'whitespace' => ['301 '], 'signed' => ['+301'], 'float' => [301.0], 'boolean' => [true],
            'array' => [[301]], 'overflow' => [(string) PHP_INT_MAX.'0']];
    }

    public function test_resolver_returns_only_fresh_unowned_events_for_canonical_ids_at_every_lifecycle(): void
    {
        $this->fixture();
        $connection = DB::connection();
        $before = $this->snapshot($connection);
        $resolver = app(LegacyEventResolver::class);
        $writes = [];
        $this->captureWrites($connection, $writes, function () use ($resolver): void {
            foreach ([301 => 'active', 302 => 'archived', 303 => 'cancelled'] as $id => $status) {
                foreach ([$id, (string) $id] as $input) {
                    $event = $resolver->find($input);
                    $this->assertInstanceOf(Event::class, $event);
                    $this->assertSame($id, $event->id);
                    $this->assertSame($status, $event->status);
                    $this->assertNull($event->workspace_id);
                }
            }
            foreach ([401, '401', 402, 999999] as $id) {
                $this->assertNull($resolver->find($id));
            }
        });

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
    }

    #[DataProvider('activeCounts')]
    public function test_sole_active_never_chooses_private_invalid_zero_or_negative_id_events(int $active): void
    {
        $this->fixture();
        $this->activeLegacyCount($active);
        $connection = DB::connection();
        $connection->table('events')->insert([
            ['id' => 0, 'event_name' => 'Synthetic zero active event', 'status' => 'active', 'workspace_id' => null],
            ['id' => -1, 'event_name' => 'Synthetic negative active event', 'status' => 'active', 'workspace_id' => null],
        ]);
        $before = $this->snapshot($connection);
        $queries = [];
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $event = app(LegacyEventResolver::class)->soleActive();
        } finally {
            $this->restoreDispatcher($connection, $original);
        }

        if ($active === 1) {
            $this->assertSame(301, $event?->id);
            $this->assertNull($event->workspace_id);
        } else {
            $this->assertNull($event);
        }
        $this->assertCount(1, $queries);
        $this->assertMatchesRegularExpression('/\blimit\s+2\b/i', $queries[0]['sql']);
        $this->assertMatchesRegularExpression('/\bbetween\s+\?\s+and\s+\?/i', $queries[0]['sql']);
        $this->assertContains(1, $queries[0]['bindings']);
        $this->assertContains(PHP_INT_MAX, $queries[0]['bindings']);
        // SQLite cannot represent MySQL unsigned overflow IDs. The SQL bound
        // proves the requested limit without claiming a live MySQL rehearsal.
        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function activeCounts(): array
    {
        return ['no eligible event' => [0], 'one eligible event' => [1], 'ambiguous events' => [2]];
    }

    #[DataProvider('resolverMethods')]
    public function test_resolver_database_errors_fail_privately_without_mutation_or_transaction_changes(string $method): void
    {
        $this->fixture();
        $connection = DB::connection();
        $before = $this->snapshot($connection);
        $level = $connection->transactionLevel();
        $throw = true;
        $attempts = 0;
        $connection->beforeExecuting(static function () use (&$throw, &$attempts): void {
            if ($throw) {
                $attempts++;
                throw new RuntimeException('synthetic-private-legacy-resolver-diagnostic');
            }
        });
        Log::spy();
        try {
            $resolver = app(LegacyEventResolver::class);
            $this->assertNull($method === 'find' ? $resolver->find(301) : $resolver->soleActive());
        } finally {
            $throw = false;
        }

        $this->assertSame(1, $attempts);
        $this->assertSame($level, $connection->transactionLevel());
        $this->assertSame($before, $this->snapshot($connection));
        foreach (['error', 'warning', 'critical', 'alert', 'emergency', 'debug', 'info', 'notice', 'log'] as $logMethod) {
            Log::shouldNotHaveReceived($logMethod);
        }
    }

    public static function resolverMethods(): array
    {
        return ['find' => ['find'], 'soleActive' => ['soleActive']];
    }

    public function test_resolver_uses_primary_reads_without_a_replica_or_ambient_transaction(): void
    {
        $this->fixture();
        $this->activeLegacyCount(1);
        $source = DB::connection();
        $sourceBefore = $this->snapshot($source);
        $connection = $this->cloneConnection($source);
        $before = $this->snapshot($connection);
        $this->assertSame(0, $connection->transactionLevel());
        $read = $connection->getReadPdo();
        $attempts = 0;
        $connection->setReadPdo(static function () use (&$attempts): never {
            $attempts++;
            throw new RuntimeException('synthetic-unavailable-legacy-replica');
        });
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection($connection->getName());
        try {
            $resolver = app(LegacyEventResolver::class);
            $this->assertSame(301, $resolver->find('301')?->id);
            $this->assertNull($resolver->find(401));
            $this->assertSame(301, $resolver->soleActive()?->id);
        } finally {
            $connection->setReadPdo($read);
            DB::setDefaultConnection($default);
        }

        $this->assertSame(0, $attempts);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame($sourceBefore, $this->snapshot($source));
    }

    public function test_http_gateway_rejects_an_owned_selection_without_falling_back_or_revealing_its_dashboard(): void
    {
        $admin = $this->fixture(true);
        $this->activeLegacyCount(1);
        $context = $this->context(401);
        $before = $this->snapshot(DB::connection());
        $writes = [];

        $this->captureWrites(DB::connection(), $writes, function () use ($admin, $context): void {
            $this->actingAs($admin)->withSession($context)->get(route('admin.dashboard'))
                ->assertRedirect(route('admin.events.index'))->assertDontSee('Synthetic private active event');
        });

        $this->assertSelectionCleared();
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    #[DataProvider('invalidSelections')]
    public function test_explicit_invalid_selection_is_cleared_and_never_falls_back_to_the_single_active_legacy_event(mixed $selected): void
    {
        $this->fixture();
        $this->activeLegacyCount(1);
        DB::table('events')->whereNotNull('workspace_id')->update(['status' => 'archived']);
        $before = $this->snapshot(DB::connection());
        session()->put($this->context($selected));
        $reached = false;

        $response = app(EnsureAdminEventSelected::class)->handle($this->request(), static function () use (&$reached): Response {
            $reached = true;

            return new Response('synthetic-next', 209);
        });

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('admin.events.index'), $response->headers->get('Location'));
        $this->assertFalse($reached);
        $this->assertSelectionCleared();
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function invalidSelections(): array
    {
        return ['workspace owned' => [401], 'missing or deleted' => [999999], 'explicit null' => [null], 'zero' => [0],
            'false' => [false], 'empty string' => [''], 'leading zero alias' => ['0301'],
            'array' => [[301]], 'overflow' => [(string) PHP_INT_MAX.'0']];
    }

    #[DataProvider('validSelections')]
    public function test_valid_selected_legacy_events_preserve_the_entire_context_and_import(mixed $selected): void
    {
        $this->fixture();
        $before = $this->snapshot(DB::connection());
        session()->put($this->context($selected));
        $sessionBefore = session()->all();
        $reached = 0;

        $response = app(EnsureAdminEventSelected::class)->handle($this->request(), static function () use (&$reached): Response {
            $reached++;

            return new Response('synthetic-next', 209);
        });

        $this->assertSame(209, $response->getStatusCode());
        $this->assertSame(1, $reached);
        $this->assertSame($sessionBefore, session()->all());
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function validSelections(): array
    {
        return ['active' => [301], 'canonical string' => ['301'], 'archived' => [302], 'cancelled' => [303]];
    }

    #[DataProvider('activeCounts')]
    public function test_absent_selection_clears_stale_import_and_only_autochooses_a_single_active_legacy_event(int $active): void
    {
        $this->fixture();
        $this->activeLegacyCount($active);
        $context = $this->context(null);
        unset($context['selected_event_id']);
        session()->put($context);
        $before = $this->snapshot(DB::connection());
        $reached = false;

        $response = app(EnsureAdminEventSelected::class)->handle($this->request(), static function () use (&$reached): Response {
            $reached = true;

            return new Response('synthetic-next', 209);
        });

        $this->assertSame($active === 1, $reached);
        $this->assertFalse(session()->has('guest_import'));
        $this->assertUnrelatedContext();
        if ($active === 1) {
            $this->assertSame(209, $response->getStatusCode());
            $this->assertSame(301, session('selected_event_id'));
        } else {
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame(route('admin.events.index'), $response->headers->get('Location'));
            $this->assertFalse(session()->has('selected_event_id'));
        }
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    #[DataProvider('postGatewayChanges')]
    public function test_current_event_rechecks_after_the_gateway_and_refuses_newly_owned_deleted_or_replaced_context(string $change): void
    {
        $this->fixture();
        session()->put($this->context(301));
        $connection = DB::connection();
        $expected = null;
        $reached = false;
        $this->assertNotFoundOperation(function () use ($connection, $change, &$expected, &$reached): void {
            app(EnsureAdminEventSelected::class)->handle($this->request(), function () use ($connection, $change, &$expected, &$reached): Response {
                $reached = true;
                // A deterministic fixture transition after the middleware read;
                // this does not claim a MySQL concurrency or writer fence proof.
                if ($change === 'assigned') {
                    $connection->table('events')->where('id', 301)->update(['workspace_id' => 101]);
                } elseif ($change === 'deleted') {
                    $connection->table('events')->where('id', 301)->delete();
                } else {
                    session()->put('selected_event_id', '0301');
                }
                $expected = $this->snapshot($connection);
                $this->currentEvent();

                return new Response('unexpected-next', 209);
            });
        });

        $this->assertTrue($reached);
        $this->assertSelectionCleared();
        $this->assertSame($expected, $this->snapshot($connection));
    }

    public static function postGatewayChanges(): array
    {
        return ['assigned after gateway' => ['assigned'], 'deleted after gateway' => ['deleted'], 'raw session alias after gateway' => ['alias']];
    }

    #[DataProvider('postGatewayChanges')]
    public function test_import_rechecks_after_the_gateway_before_exposing_cached_import_context(string $change): void
    {
        $this->fixture();
        session()->put($this->context(301));
        $connection = DB::connection();
        $expected = null;
        $reached = false;
        $this->assertNotFoundOperation(function () use ($connection, $change, &$expected, &$reached): void {
            app(EnsureAdminEventSelected::class)->handle($this->request(), function () use ($connection, $change, &$expected, &$reached): Response {
                $reached = true;
                // A controlled fixture transition after the gateway observation;
                // this does not establish an atomic writer or MySQL race fence.
                if ($change === 'assigned') {
                    $connection->table('events')->where('id', 301)->update(['workspace_id' => 101]);
                } elseif ($change === 'deleted') {
                    $connection->table('events')->where('id', 301)->delete();
                } else {
                    session()->put('selected_event_id', '0301');
                }
                $expected = $this->snapshot($connection);
                app(AdminController::class)->import();

                return new Response('unexpected-import-view', 209);
            });
        });

        $this->assertTrue($reached);
        $this->assertSelectionCleared();
        $this->assertSame($expected, $this->snapshot($connection));
    }

    public function test_current_event_does_not_create_or_select_a_default_when_called_without_context(): void
    {
        $this->fixture();
        DB::table('events')->where('id', 1)->update(['event_name' => 'Synthetic renamed original default']);
        $context = $this->context(null);
        unset($context['selected_event_id']);
        session()->put($context);
        $before = $this->snapshot(DB::connection());
        $writes = [];

        $this->captureWrites(DB::connection(), $writes, function (): void {
            $this->assertNotFoundOperation(fn () => $this->currentEvent());
        });

        $this->assertSelectionCleared();
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    #[DataProvider('offlineMethods')]
    public function test_direct_offline_entrypoints_reject_workspace_events_before_validation_or_snapshot_and_preserve_context(string $method): void
    {
        $admin = $this->fixture(true);
        $this->forbidOfflineWork();
        $context = $this->context(302);
        $this->actingAs($admin)->withSession($context);
        $before = $this->snapshot(DB::connection());
        $writes = [];

        $this->captureWrites(DB::connection(), $writes, function () use ($method, $context): void {
            foreach ([401, 402] as $id) {
                $response = $method === 'create'
                    ? $this->getJson(route('admin.offline-scanner-sessions.create', $id))
                    : $this->postJson(route('admin.offline-scanner-sessions.store', $id), ['master_scanner_user_id' => []]);
                $response->assertNotFound()->assertSessionMissing('errors');
                $this->assertContext($context);
            }
        });

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    #[DataProvider('offlineMethods')]
    public function test_stale_offline_route_binding_is_refreshed_before_any_context_change_or_validation(string $method): void
    {
        $admin = $this->fixture();
        $stale = Event::query()->findOrFail(301);
        DB::table('events')->where('id', 301)->update(['workspace_id' => 101]);
        Route::bind('event', static fn () => $stale);
        $this->forbidOfflineWork();
        $context = $this->context(302);
        $this->actingAs($admin)->withSession($context);
        $before = $this->snapshot(DB::connection());

        $response = $method === 'create'
            ? $this->getJson(route('admin.offline-scanner-sessions.create', 301))
            : $this->postJson(route('admin.offline-scanner-sessions.store', 301), []);

        $response->assertNotFound()->assertSessionMissing('errors');
        $this->assertContext($context);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function offlineMethods(): array
    {
        return ['create' => ['create'], 'store' => ['store']];
    }

    #[DataProvider('rawOfflineTargets')]
    public function test_raw_offline_bound_id_aliases_cannot_select_a_valid_legacy_victim(string $method, string $raw, int $victim): void
    {
        $admin = $this->fixture();
        if ($victim !== 301) {
            DB::table('events')->insert(['id' => $victim, 'event_name' => 'Synthetic maximum legacy event', 'status' => 'active']);
        }
        $cached = new Event;
        $cached->setRawAttributes(['id' => $raw, 'event_name' => 'Synthetic cached alias', 'workspace_id' => null, 'status' => 'active'], true);
        $cached->exists = true;
        $this->assertSame($victim, $cached->id);
        Route::bind('event', static fn () => $cached);
        $this->forbidOfflineWork();
        $context = $this->context(302);
        $this->actingAs($admin)->withSession($context);
        $before = $this->snapshot(DB::connection());
        $eventQueries = 0;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$eventQueries): void {
            if (preg_match('/\b(?:from|join)\s+["\x60]?events\b/i', $sql)) {
                $eventQueries++;
            }
        });

        $response = $method === 'create'
            ? $this->getJson(route('admin.offline-scanner-sessions.create', $victim))
            : $this->postJson(route('admin.offline-scanner-sessions.store', $victim), []);

        $response->assertNotFound()->assertSessionMissing('errors');
        $this->assertSame(0, $eventQueries);
        $this->assertContext($context);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function rawOfflineTargets(): array
    {
        return ['create leading zero' => ['create', '0301', 301], 'store leading zero' => ['store', '0301', 301],
            'create overflow' => ['create', (string) PHP_INT_MAX.'0', PHP_INT_MAX], 'store overflow' => ['store', (string) PHP_INT_MAX.'0', PHP_INT_MAX]];
    }

    #[DataProvider('sameContexts')]
    public function test_valid_offline_create_uses_the_fresh_event_and_preserves_import_only_for_the_same_context(bool $same): void
    {
        $admin = $this->fixture();
        $stale = Event::query()->findOrFail(301);
        DB::table('events')->where('id', 301)->update(['event_name' => 'Synthetic fresh legacy event']);
        Route::bind('event', static fn () => $stale);
        $context = $this->context($same ? '301' : 302);
        $before = $this->snapshot(DB::connection());

        $response = $this->actingAs($admin)->withSession($context)->get(route('admin.offline-scanner-sessions.create', 301));

        $response->assertOk()->assertViewHas('event', static fn (Event $event): bool => $event->id === 301
            && $event->event_name === 'Synthetic fresh legacy event' && $event->workspace_id === null);
        $this->assertSame(301, session('selected_event_id'));
        $this->assertImportState($same, $context);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    #[DataProvider('sameContexts')]
    public function test_valid_offline_store_checks_context_before_validation_without_generating_a_package(bool $same): void
    {
        $admin = $this->fixture();
        $this->mock(OfflineScannerPackageBuilder::class)->shouldNotReceive('generate');
        $context = $this->context($same ? '301' : 302);
        $before = $this->snapshot(DB::connection());

        $response = $this->actingAs($admin)->withSession($context)->postJson(route('admin.offline-scanner-sessions.store', 301), []);

        $response->assertUnprocessable()->assertJsonValidationErrors('master_scanner_user_id');
        $this->assertSame(301, session('selected_event_id'));
        $this->assertImportState($same, $context);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function sameContexts(): array
    {
        return ['different context' => [false], 'canonical string same context' => [true]];
    }

    #[DataProvider('selectionMethods')]
    public function test_explicit_legacy_selection_clears_import_only_when_the_actual_event_changes(string $verb, bool $same): void
    {
        $admin = $this->fixture();
        $context = $this->context($same ? '301' : 302);
        $before = $this->snapshot(DB::connection());

        $response = $this->actingAs($admin)->withSession($context)->call($verb, route('admin.events.select', 301));

        $response->assertRedirect(route('admin.dashboard'))->assertSessionHas('selected_event_id', 301);
        $this->assertImportState($same, $context);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function selectionMethods(): array
    {
        return ['GET changed' => ['GET', false], 'POST changed' => ['POST', false],
            'GET unchanged' => ['GET', true], 'POST unchanged' => ['POST', true]];
    }

    public function test_legacy_event_creation_clears_previous_import_when_it_selects_the_new_context(): void
    {
        $admin = $this->fixture();
        $context = $this->context(301);
        $privateBefore = DB::table('events')->whereNotNull('workspace_id')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();

        $response = $this->actingAs($admin)->withSession($context)->post(route('admin.events.store'), ['event_name' => 'Synthetic newly created legacy context', 'status' => 'active']);

        $response->assertRedirect(route('admin.dashboard'));
        $event = Event::query()->where('event_name', 'Synthetic newly created legacy context')->sole();
        $this->assertNull($event->workspace_id);
        $this->assertSame($event->id, session('selected_event_id'));
        $this->assertImportState(false, $context);
        $this->assertSame($privateBefore, DB::table('events')->whereNotNull('workspace_id')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all());
    }

    #[DataProvider('statusActions')]
    public function test_status_controls_clear_import_only_when_they_clear_the_selected_event(string $action, bool $same): void
    {
        $admin = $this->fixture();
        $context = $this->context($same ? '301' : 302);

        $response = $this->actingAs($admin)->withSession($context)->from(route('admin.events.index'))
            ->patch(route('admin.events.'.$action, 301));

        $response->assertRedirect(route('admin.events.index'));
        $this->assertDatabaseHas('events', ['id' => 301, 'status' => $action === 'archive' ? 'archived' : 'cancelled', 'workspace_id' => null]);
        if ($same) {
            $this->assertSelectionCleared();
        } else {
            $this->assertContext($context);
        }
    }

    public static function statusActions(): array
    {
        return ['archive selected' => ['archive', true], 'archive other' => ['archive', false],
            'cancel selected' => ['cancel', true], 'cancel other' => ['cancel', false]];
    }

    private function fixture(bool $platform = false): User
    {
        $connection = DB::connection();
        $connection->table('users')->insert(['id' => 211, 'name' => 'Synthetic legacy selected-event administrator',
            'email' => 'legacy-selected@example.invalid', 'password' => bcrypt('synthetic-password'), 'role' => 'admin',
            'is_active' => true, 'is_platform_admin' => $platform, 'preferred_language' => 'en']);
        $connection->table('workspaces')->insert([['id' => 101, 'name' => 'Synthetic owned workspace'], ['id' => 102, 'name' => 'Synthetic foreign workspace']]);
        $connection->table('workspace_users')->insert(['user_id' => 211, 'workspace_id' => 101, 'role' => 'owner', 'is_active' => true]);
        foreach ([301 => ['Synthetic legacy active', 'active', null], 302 => ['Synthetic legacy archived', 'archived', null],
            303 => ['Synthetic legacy cancelled', 'cancelled', null], 401 => ['Synthetic private active event', 'active', 101],
            402 => ['Synthetic private archived event', 'archived', 102]] as $id => [$name, $status, $workspace]) {
            $connection->table('events')->insert(['id' => $id, 'event_name' => $name, 'status' => $status, 'workspace_id' => $workspace]);
        }

        return User::query()->findOrFail(211);
    }

    private function activeLegacyCount(int $count): void
    {
        DB::table('events')->whereNull('workspace_id')->update(['status' => 'archived']);
        if ($count >= 1) {
            DB::table('events')->where('id', 301)->update(['status' => 'active']);
        }
        if ($count >= 2) {
            DB::table('events')->where('id', 302)->update(['status' => 'active']);
        }
    }

    private function context(mixed $event): array
    {
        return ['selected_event_id' => $event, 'selected_workspace_id' => 101, 'synthetic_unrelated' => ['preserve' => true],
            'guest_import' => ['rows' => [['name' => 'Synthetic pending import']], 'token' => 'synthetic-import-token', 'result' => ['inserted' => 4]]];
    }

    private function request(): Request
    {
        $request = Request::create('/admin/dashboard', 'GET');
        $request->setLaravelSession(session()->driver());

        return $request;
    }

    private function currentEvent(): Event
    {
        return (new ReflectionMethod(AdminController::class, 'currentEvent'))->invoke(app(AdminController::class));
    }

    private function assertUnrelatedContext(): void
    {
        $this->assertSame(101, session('selected_workspace_id'));
        $this->assertSame(['preserve' => true], session('synthetic_unrelated'));
    }

    private function assertSelectionCleared(): void
    {
        $this->assertFalse(session()->has('selected_event_id'));
        $this->assertFalse(session()->has('guest_import'));
        $this->assertUnrelatedContext();
    }

    private function assertContext(array $context): void
    {
        foreach ($context as $key => $value) {
            $this->assertSame($value, session($key));
        }
    }

    private function assertImportState(bool $preserved, array $context): void
    {
        if ($preserved) {
            $this->assertSame($context['guest_import'], session('guest_import'));
        } else {
            $this->assertFalse(session()->has('guest_import'));
        }
        $this->assertUnrelatedContext();
    }

    private function forbidOfflineWork(): void
    {
        $this->mock(GuestSnapshotService::class)->shouldNotReceive('summary');
        $this->mock(OfflineEventLockService::class)->shouldNotReceive('getActiveSessionForEvent');
        $this->mock(OfflineScannerPackageBuilder::class)->shouldNotReceive('generate');
    }

    private function assertNotFoundOperation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The invalid selected event must be refused before use.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    private function captureWrites(Connection $connection, array &$writes, callable $operation): void
    {
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $operation();
        } finally {
            $this->restoreDispatcher($connection, $original);
        }
    }

    private function restoreDispatcher(Connection $connection, mixed $dispatcher): void
    {
        if ($dispatcher !== null) {
            $connection->setEventDispatcher($dispatcher);
        } else {
            $connection->unsetEventDispatcher();
        }
    }

    private function cloneConnection(Connection $source): Connection
    {
        $name = 'legacy_selected_boundary_'.count($this->ownedConnections);
        $this->ownedConnections[] = $name;
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        $connection = DB::connection($name);
        $objects = $source->selectFromWriteConnection("SELECT type, name, sql FROM sqlite_master WHERE type IN ('table','index') AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name");
        foreach ($objects as $object) {
            $connection->statement($object->sql);
        }
        foreach ($objects as $object) {
            if ($object->type === 'table') {
                foreach ($source->table($object->name)->useWritePdo()->get() as $row) {
                    $connection->table($object->name)->insert((array) $row);
                }
            }
        }
        $connection->getSchemaBuilder()->enableForeignKeyConstraints();

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
