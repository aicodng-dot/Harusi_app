<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminEventController;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class LegacyEventAdministrationBoundaryTest extends TestCase
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

    #[DataProvider('platformModes')]
    public function test_catalogue_stats_and_layout_never_adopt_workspace_events_even_for_an_active_owner(bool $platform): void
    {
        $admin = $this->fixture($platform);
        $before = $this->snapshot(DB::connection());
        $context = $this->context(401);
        $layout = null;
        View::composer('layouts.admin', static function ($view) use (&$layout): void {
            $layout = $view->getData();
        });

        $response = $this->actingAs($admin)->withSession($context)->get(route('admin.events.index'));

        $response->assertOk()->assertViewIs('admin.events.index')
            ->assertDontSee('Private workspace one event')->assertDontSee('Private workspace two event')
            ->assertDontSee('Private couple')->assertDontSee('Private venue');
        $events = $response->viewData('events');
        $this->assertSame([1, 301, 302, 303], $events->pluck('id')->all());
        $this->assertEqualsCanonicalizing([1, 301, 302, 303], array_keys($response->viewData('eventStats')));
        $this->assertSame(['total_invites' => 2, 'expected_admissions' => 3, 'checked_in_count' => 0], $response->viewData('eventStats')[301]);
        $this->assertSame(2, $events->firstWhere('id', 301)->guests_count);
        $this->assertNotNull($layout);
        $this->assertNull($layout['selectedEvent']);
        $this->assertNull($layout['activeOfflineScannerSession']);
        $this->assertSame([1, 301], $layout['eventSwitcherEvents']->pluck('id')->all());
        $this->assertContext($context);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public function test_catalogue_statistics_and_switcher_exclude_ids_outside_the_supported_positive_integer_range(): void
    {
        $admin = $this->fixture();
        $connection = DB::connection();
        $connection->table('events')->insert([
            ['id' => 0, 'event_name' => 'Synthetic unsupported zero event', 'status' => 'active', 'workspace_id' => null],
            ['id' => -1, 'event_name' => 'Synthetic unsupported negative event', 'status' => 'active', 'workspace_id' => null],
        ]);
        $before = $this->snapshot($connection);
        $this->actingAs($admin);
        session()->put($this->context(401));
        $sessionBefore = session()->all();
        $layout = null;
        View::composer('layouts.admin', static function ($view) use (&$layout): void {
            $layout = $view->getData();
        });
        $queries = [];
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $view = app(AdminEventController::class)->index();
            $this->assertSame([1, 301, 302, 303], $view->getData()['events']->pluck('id')->all());
            $this->assertEqualsCanonicalizing([1, 301, 302, 303], array_keys($view->getData()['eventStats']));
            $html = $view->render();
            $this->assertStringNotContainsString('Synthetic unsupported zero event', $html);
            $this->assertStringNotContainsString('Synthetic unsupported negative event', $html);
            $this->assertNotNull($layout);
            $this->assertNull($layout['selectedEvent']);
            $this->assertSame([1, 301], $layout['eventSwitcherEvents']->pluck('id')->all());
        } finally {
            $this->restoreDispatcher($connection, $original);
        }

        $bounded = [];
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query['sql']);
            if (preg_match('/\bfrom\s+["\x60]?events\b/i', $query['sql'])
                && preg_match('/\bbetween\s+\?\s+and\s+\?/i', $query['sql'])) {
                $bounded[] = $query;
            }
        }
        $this->assertCount(2, $bounded);
        foreach ($bounded as $query) {
            // SQLite cannot represent MySQL's unsigned overflow IDs. These
            // bindings prove the upper SQL boundary without claiming that engine test.
            $this->assertContains(1, $query['bindings']);
            $this->assertContains(PHP_INT_MAX, $query['bindings']);
        }
        $this->assertSame($sessionBefore, session()->all());
        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function platformModes(): array
    {
        return ['legacy administrator' => [false], 'platform administrator who owns the workspace' => [true]];
    }

    #[DataProvider('protectedOperations')]
    public function test_every_legacy_metadata_route_rejects_both_workspace_targets_without_writes_or_context_changes(string $verb, string $action, bool $platform): void
    {
        $admin = $this->fixture($platform);
        $connection = DB::connection();
        $before = $this->snapshot($connection);
        $context = $this->context(401);
        $writes = [];
        $this->actingAs($admin)->withSession($context);

        $this->captureWrites($connection, $writes, function () use ($verb, $action, $context): void {
            foreach ([401, 402, 403, 404] as $id) {
                // Malformed update data must never reach validation for a private target.
                $response = $this->json($verb, route('admin.events.'.$action, $id), ['event_name' => [], 'status' => 'invalid', 'workspace_id' => null]);
                $response->assertNotFound()->assertDontSee('Private workspace');
                $response->assertSessionMissing('errors')->assertSessionMissing('success');
                $this->assertContext($context);
            }
        });

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function protectedOperations(): array
    {
        $cases = [];
        foreach ([['GET', 'edit'], ['PUT', 'update'], ['GET', 'select'], ['POST', 'select'],
            ['POST', 'archive'], ['PATCH', 'archive'], ['POST', 'cancel'], ['PATCH', 'cancel']] as [$verb, $action]) {
            foreach ([false, true] as $platform) {
                $cases[$verb.' '.$action.($platform ? ' platform' : ' staff')] = [$verb, $action, $platform];
            }
        }

        return $cases;
    }

    public function test_stale_bound_models_cannot_expose_or_modify_events_after_workspace_assignment(): void
    {
        $admin = $this->fixture();
        $stale = Event::query()->findOrFail(301);
        DB::table('events')->where('id', 301)->update(['workspace_id' => 101]);
        Route::bind('event', static fn () => $stale);
        $before = $this->snapshot(DB::connection());
        $context = $this->context(301);
        $this->actingAs($admin)->withSession($context);
        $writes = [];

        $this->captureWrites(DB::connection(), $writes, function () use ($stale, $context): void {
            foreach ([['GET', 'edit'], ['PUT', 'update'], ['GET', 'select'], ['POST', 'select'],
                ['POST', 'archive'], ['PATCH', 'archive'], ['POST', 'cancel'], ['PATCH', 'cancel']] as [$verb, $action]) {
                $this->json($verb, route('admin.events.'.$action, $stale), $this->payload())->assertNotFound();
                $this->assertContext($context);
            }
        });

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    #[DataProvider('lockedActions')]
    public function test_locked_mutation_rechecks_assignment_after_the_first_legacy_lookup(string $action): void
    {
        $admin = $this->fixture();
        $connection = DB::connection();
        $target = Event::query()->findOrFail(301);
        Route::bind('event', static fn () => $target);
        $context = $this->context(301);
        $this->actingAs($admin)->withSession($context);
        $converted = false;
        $capturing = true;
        $expected = null;
        $writes = [];
        $checks = 0;
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$checks, &$capturing, &$writes): void {
            if (preg_match('/\bfrom\s+["\x60]?events\b/i', $query->sql) && in_array(301, $query->bindings, true)) {
                $checks++;
            }
            if ($capturing && preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $connection->setEventDispatcher($dispatcher);
        $connection->beforeStartingTransaction(function () use ($connection, &$converted, &$capturing, &$expected, &$checks): void {
            if ($converted) {
                return;
            }
            // Fixture transition immediately before the controller's nested
            // transaction. This proves a second lookup, not engine concurrency.
            $this->assertSame(1, $checks);
            $this->assertSame(1, $connection->transactionLevel());
            $converted = true;
            $capturing = false;
            $connection->table('events')->where('id', 301)->update(['workspace_id' => 101]);
            $expected = $this->snapshot($connection);
            $capturing = true;
        });
        try {
            $verb = $action === 'update' ? 'PUT' : 'PATCH';
            $this->json($verb, route('admin.events.'.$action, $target), $this->payload())->assertNotFound();
        } finally {
            $capturing = false;
            $this->restoreDispatcher($connection, $original);
        }

        $this->assertTrue($converted);
        $this->assertSame([], $writes);
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertSame($expected, $this->snapshot($connection));
        $this->assertContext($context);
    }

    public static function lockedActions(): array
    {
        return ['update' => ['update'], 'archive' => ['archive'], 'cancel' => ['cancel']];
    }

    #[DataProvider('rawAliases')]
    public function test_noncanonical_bound_ids_are_denied_before_they_can_alias_a_valid_legacy_event(string $raw, int $victim): void
    {
        $this->fixture();
        if ($victim !== 301) {
            DB::table('events')->insert(['id' => $victim, 'event_name' => 'Synthetic maximum legacy event', 'status' => 'active']);
        }
        $cached = new Event;
        $cached->setRawAttributes(['id' => $raw, 'event_name' => 'Cached synthetic name', 'workspace_id' => null, 'status' => 'active'], true);
        $cached->exists = true;
        $this->assertSame($victim, $cached->getKey());
        $context = $this->context($victim);
        session()->put($context);
        $request = Request::create('/synthetic-legacy-event', 'PUT', $this->payload());
        $request->setLaravelSession(session()->driver());
        $beforeSession = session()->all();
        $connection = DB::connection();
        $before = $this->snapshot($connection);
        $queries = 0;
        $connection->beforeExecuting(static function () use (&$queries): void {
            $queries++;
        });

        foreach (['edit', 'update', 'select', 'archive', 'cancel'] as $action) {
            $controller = app(AdminEventController::class);
            $this->assertNotFoundOperation(static fn () => $action === 'edit'
                ? $controller->edit($cached) : $controller->{$action}($request, $cached));
            $this->assertSame($beforeSession, session()->all());
        }

        $this->assertSame(0, $queries);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function rawAliases(): array
    {
        return ['leading zero' => ['0301', 301], 'integer overflow' => [(string) PHP_INT_MAX.'0', PHP_INT_MAX]];
    }

    #[DataProvider('invalidSelections')]
    public function test_layout_rejects_noncanonical_or_private_selected_ids_without_repairing_the_session(mixed $selected): void
    {
        $admin = $this->fixture();
        $context = $this->context($selected);
        $before = $this->snapshot(DB::connection());
        $layout = null;
        View::composer('layouts.admin', static function ($view) use (&$layout): void {
            $layout = $view->getData();
        });

        $this->actingAs($admin)->withSession($context)->get(route('admin.events.create'))->assertOk()
            ->assertDontSee('Private workspace one event')->assertDontSee('Private workspace two event');

        $this->assertNotNull($layout);
        $this->assertNull($layout['selectedEvent']);
        $this->assertNull($layout['activeOfflineScannerSession']);
        $this->assertSame([1, 301], $layout['eventSwitcherEvents']->pluck('id')->all());
        $this->assertContext($context);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function invalidSelections(): array
    {
        return ['private owned' => [401], 'private foreign' => [402], 'leading zero legacy alias' => ['0301'],
            'overflow' => [(string) PHP_INT_MAX.'0'], 'array' => [[301]], 'zero' => [0]];
    }

    public function test_layout_still_resolves_canonical_legacy_selections_with_only_active_legacy_switcher_options(): void
    {
        $admin = $this->fixture();
        $before = $this->snapshot(DB::connection());
        $layout = null;
        View::composer('layouts.admin', static function ($view) use (&$layout): void {
            $layout = $view->getData();
        });
        $this->actingAs($admin);

        foreach ([301, '301', 302, 303] as $selected) {
            $context = $this->context($selected);
            $this->withSession($context)->get(route('admin.events.create'))->assertOk();
            $this->assertNotNull($layout);
            $this->assertSame((int) $selected, $layout['selectedEvent']->id);
            $this->assertNull($layout['selectedEvent']->workspace_id);
            $this->assertSame([1, 301], $layout['eventSwitcherEvents']->pluck('id')->all());
            $this->assertContext($context);
        }

        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public function test_valid_legacy_edit_update_and_selection_remain_available_without_workspace_injection(): void
    {
        $admin = $this->fixture();
        $privateBefore = $this->privateRows();
        $this->actingAs($admin)->withSession($this->context(302));
        $this->get(route('admin.events.edit', 301))->assertOk()->assertViewHas('event', static fn (Event $event): bool => $event->id === 301);
        $this->put(route('admin.events.update', 301), $this->payload() + ['workspace_id' => 101])
            ->assertRedirect(route('admin.events.index'))->assertSessionHas('selected_event_id', 302);
        $this->assertDatabaseHas('events', ['id' => 301, 'event_name' => 'Synthetic legacy replacement', 'workspace_id' => null]);
        $this->get(route('admin.events.select', 301))->assertRedirect(route('admin.dashboard'))->assertSessionHas('selected_event_id', 301);
        $this->post(route('admin.events.select', 302).'?redirect_to=offline_scanner')
            ->assertRedirect(route('admin.offline-scanner-sessions.create', 302))->assertSessionHas('selected_event_id', 302);
        $this->assertSame($privateBefore, $this->privateRows());
    }

    #[DataProvider('validStatusActions')]
    public function test_valid_legacy_status_controls_preserve_unrelated_context(string $verb, string $action, string $status, bool $selected): void
    {
        $admin = $this->fixture();
        $privateBefore = $this->privateRows();
        $context = $this->context($selected ? 301 : 302);
        $response = $this->actingAs($admin)->withSession($context)->from(route('admin.events.index'))
            ->call($verb, route('admin.events.'.$action, 301));
        $response->assertRedirect(route('admin.events.index'));
        $this->assertDatabaseHas('events', ['id' => 301, 'status' => $status, 'workspace_id' => null]);
        if ($selected) {
            $response->assertSessionMissing('selected_event_id');
        } else {
            $response->assertSessionHas('selected_event_id', 302);
        }
        $response->assertSessionHas('selected_workspace_id', 101)->assertSessionHas('guest_import_preview', ['synthetic' => 'preserve']);
        $this->assertSame($privateBefore, $this->privateRows());
    }

    public static function validStatusActions(): array
    {
        return ['post archive current' => ['POST', 'archive', 'archived', true],
            'patch archive other selected' => ['PATCH', 'archive', 'archived', false],
            'post cancel current' => ['POST', 'cancel', 'cancelled', true],
            'patch cancel other selected' => ['PATCH', 'cancel', 'cancelled', false]];
    }

    public function test_legacy_store_never_assigns_a_submitted_workspace_or_changes_existing_workspace_resources(): void
    {
        $admin = $this->fixture(true);
        $privateBefore = $this->privateRows();
        $beforeCount = Event::query()->count();

        $response = $this->actingAs($admin)->post(route('admin.events.store'), $this->payload() + ['workspace_id' => 101, 'owner_id' => $admin->id]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertSame($beforeCount + 1, Event::query()->count());
        $created = Event::query()->where('event_name', 'Synthetic legacy replacement')->sole();
        $this->assertNull($created->workspace_id);
        $response->assertSessionHas('selected_event_id', $created->id);
        $this->assertSame($privateBefore, $this->privateRows());
    }

    public function test_catalogue_statistics_and_composer_use_primary_reads_without_an_ambient_transaction(): void
    {
        $admin = $this->fixture();
        $source = DB::connection();
        $sourceBefore = $this->snapshot($source);
        $connection = $this->cloneConnection($source);
        $before = $this->snapshot($connection);
        $legacyTarget = Event::on($connection->getName())->useWritePdo()->findOrFail(301);
        $privateTarget = Event::on($connection->getName())->useWritePdo()->findOrFail(401);
        $this->assertSame(0, $connection->transactionLevel());
        $this->actingAs($admin);
        session()->put($this->context(401));
        $sessionBefore = session()->all();
        $read = $connection->getReadPdo();
        $attempts = 0;
        $connection->setReadPdo(static function () use (&$attempts): never {
            $attempts++;
            throw new RuntimeException('synthetic-unavailable-replica');
        });
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection($connection->getName());
        $layout = null;
        View::composer('layouts.admin', static function ($view) use (&$layout): void {
            $layout = $view->getData();
        });
        try {
            $view = app(AdminEventController::class)->index();
            $this->assertSame([1, 301, 302, 303], $view->getData()['events']->pluck('id')->all());
            $this->assertSame(3, $view->getData()['eventStats'][301]['expected_admissions']);
            $html = $view->render();
            $this->assertStringNotContainsString('Private workspace', $html);
            $this->assertNull($layout['selectedEvent']);
            $this->assertSame([1, 301], $layout['eventSwitcherEvents']->pluck('id')->all());
            $this->assertSame(301, app(AdminEventController::class)->edit($legacyTarget)->getData()['event']->id);
            $this->assertNotFoundOperation(static fn () => app(AdminEventController::class)->edit($privateTarget));
        } finally {
            $connection->setReadPdo($read);
            DB::setDefaultConnection($default);
        }
        $this->assertSame(0, $attempts);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame($sessionBefore, session()->all());
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame($sourceBefore, $this->snapshot($source));
    }

    private function fixture(bool $platform = false): User
    {
        $connection = DB::connection();
        $connection->table('users')->insert(['id' => 211, 'name' => 'Synthetic legacy administrator', 'email' => 'legacy-boundary@example.invalid',
            'password' => bcrypt('synthetic-password'), 'role' => 'admin', 'is_active' => true, 'is_platform_admin' => $platform, 'preferred_language' => 'en']);
        $connection->table('workspaces')->insert([['id' => 101, 'name' => 'Synthetic own workspace'], ['id' => 102, 'name' => 'Synthetic foreign workspace']]);
        $connection->table('workspace_users')->insert(['user_id' => 211, 'workspace_id' => 101, 'role' => 'owner', 'is_active' => true]);
        foreach ([301 => ['Synthetic legacy active', 'active', null], 302 => ['Synthetic legacy archived', 'archived', null],
            303 => ['Synthetic legacy cancelled', 'cancelled', null], 401 => ['Private workspace one event', 'active', 101],
            402 => ['Private workspace two event', 'active', 102], 403 => ['Private workspace archived event', 'archived', 101],
            404 => ['Private workspace cancelled event', 'cancelled', 102]] as $id => [$name, $status, $workspace]) {
            $connection->table('events')->insert(['id' => $id, 'event_name' => $name, 'status' => $status, 'workspace_id' => $workspace,
                'bride_name' => $workspace === null ? 'Synthetic legacy couple' : 'Private couple',
                'venue_name' => $workspace === null ? 'Synthetic legacy venue' : 'Private venue']);
        }
        foreach ([[501, 301, 3, 'active'], [502, 301, 9, 'cancelled'], [503, 401, 67, 'active'], [504, 402, 89, 'active']] as [$id, $event, $entries, $status]) {
            $connection->table('guests')->insert(['id' => $id, 'event_id' => $event, 'name' => 'Synthetic guest '.$id, 'phone_number' => '+255712340'.$id,
                'manual_code' => str_pad((string) $id, 4, '0', STR_PAD_LEFT), 'pass_type' => 'special',
                'allowed_entries' => $entries, 'used_entries' => 0, 'status' => $status]);
        }

        return User::query()->findOrFail(211);
    }

    private function payload(): array
    {
        return ['event_name' => 'Synthetic legacy replacement', 'bride_name' => 'Synthetic bride', 'groom_name' => 'Synthetic groom',
            'venue_name' => 'Synthetic venue', 'event_date' => '2027-01-02', 'status' => 'active'];
    }

    private function context(mixed $event): array
    {
        return ['selected_event_id' => $event, 'selected_workspace_id' => 101, 'guest_import_preview' => ['synthetic' => 'preserve']];
    }

    private function assertContext(array $context): void
    {
        foreach ($context as $key => $value) {
            $this->assertSame($value, session($key));
        }
    }

    private function assertNotFoundOperation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The private or malformed event target must fail before use.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    private function privateRows(): array
    {
        return ['workspaces' => DB::table('workspaces')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'memberships' => DB::table('workspace_users')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'events' => DB::table('events')->whereNotNull('workspace_id')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'guests' => DB::table('guests')->whereIn('event_id', [401, 402])->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all()];
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
        $name = 'legacy_event_boundary_'.count($this->ownedConnections);
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
