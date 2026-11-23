<?php

namespace Tests\Feature;

use App\Exceptions\LegacyAdminReadUnavailable;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Services\Tenancy\LegacyAdminReadProjection;
use App\Support\AdminLayout\LayoutCreator;
use App\Support\AdminLayout\LayoutEvent;
use App\Support\AdminLayout\LayoutOfflineSession;
use App\Support\AdminLayout\LayoutPackage;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * P4-047 shared administrator layout and private HTML boundary: layout values are
 * prepared before rendering in one primary locking read attempt and the layout
 * composer issues no SQL; family-3 HTML is private no-store. The first 34 cases
 * were observed on the pre-change source (31 failing gaps, 3 passing controls);
 * the cases after them were added with the change. Page-content lazy reads,
 * family-3 read bundles and MySQL observations remain open.
 */
class LegacyAdminLayoutBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new LogicException('Layout boundary fixtures require isolated in-memory SQLite.');
        }
        $pdo = $db->getPdo();
        $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool => $row['name'] === 'main'));
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || count($main) !== 1 || $main[0]['file'] !== ''
            || $db->transactionLevel() !== 0 || $pdo->inTransaction() || (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new LogicException('Layout boundary fixtures require memory main, enabled FKs and actual autocommit0.');
        }
        $temporaryRoot = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'harusi-layout-boundary-'.bin2hex(random_bytes(8));
        (new Filesystem)->makeDirectory($temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views', 0777, true);
        $this->app->useStoragePath($temporaryRoot);
        config(['view.compiled' => $temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            'logging.channels.single.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log',
            'mail.default' => 'array', 'cache.default' => 'array', 'session.driver' => 'array',
            'queue.default' => 'sync', 'services.whatsapp.provider' => 'null']);
        $this->beforeApplicationDestroyed(static function () use ($db, $temporaryRoot): void {
            $db->disconnect();
            $resolved = realpath($temporaryRoot);
            $prefix = rtrim((string) realpath(sys_get_temp_dir()), '/\\').DIRECTORY_SEPARATOR.'harusi-layout-boundary-';
            if ($resolved !== false && str_starts_with(strtolower($resolved), strtolower($prefix))) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        });
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
        $this->freezeTime();
        $this->withoutVite();
    }

    #[DataProvider('layoutPages')]
    public function test_admin_layout_renders_without_private_sql(string $route, array $parameters): void
    {
        $this->fixture();
        [$response, $queries] = $this->layoutSql(fn () => $this->get(route($route, $parameters)));

        $response->assertOk();
        $this->assertSame([], $queries, 'The shared layout must issue no SQL while it renders.');
    }

    public static function layoutPages(): array
    {
        return ['dashboard' => ['admin.dashboard', []], 'guests' => ['admin.guests.index', []],
            'qr codes' => ['admin.qr-codes.index', []], 'guest detail' => ['admin.guests.show', [901]],
            'guest edit' => ['admin.guests.edit', [901]], 'guest create' => ['admin.guests.create', []],
            'guest import' => ['admin.guests.import', []], 'checkins' => ['admin.checkins.index', []],
            'notification logs' => ['admin.notification-logs.index', []], 'reports' => ['admin.reports.index', []],
            'settings' => ['admin.settings.index', []], 'events' => ['admin.events.index', []],
            'event create' => ['admin.events.create', []], 'event edit' => ['admin.events.edit', [301]],
            'users' => ['admin.users.index', []], 'user create' => ['admin.users.create', []],
            'user edit' => ['admin.users.edit', [212]], 'offline create' => ['admin.offline-scanner-sessions.create', [302]],
            'offline show' => ['admin.offline-scanner-sessions.show', [701]]];
    }

    #[DataProvider('retargetPages')]
    public function test_admin_layout_keeps_the_selection_captured_before_render(string $route, string $view): void
    {
        $this->fixture();
        $layout = $this->captureLayout();
        View::composer($view, static function (): void {
            session(['selected_event_id' => 302]);
        });

        $this->get(route($route))->assertOk();

        $this->assertSame(301, $layout->data['selectedEvent']?->id, 'A render-time session change must not retarget the layout.');
    }

    public static function retargetPages(): array
    {
        return ['family-3 guest list' => ['admin.guests.index', 'admin.guests'], 'event catalogue' => ['admin.events.index', 'admin.events.index']];
    }

    #[DataProvider('familyThreePages')]
    public function test_family_three_html_pages_are_private_no_store(string $route, array $parameters): void
    {
        $this->fixture();
        $response = $this->get(route($route, $parameters));

        $response->assertOk();
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    public static function familyThreePages(): array
    {
        return array_slice(self::layoutPages(), 0, 10, true);
    }

    public function test_control_layout_shows_selection_catalogue_and_banner(): void
    {
        $this->fixture();
        $layout = $this->captureLayout();

        $this->get(route('admin.guests.index'))->assertOk()
            ->assertSee('Offline Android scanner session is active')
            ->assertSeeInOrder(['Created by', 'Synthetic layout Administrator'])
            ->assertSeeInOrder(['Package exported', 'Mar 4, 2026 5:06 AM']);

        $this->assertSame(301, $layout->data['selectedEvent']->id);
        $this->assertSame(701, $layout->data['activeOfflineScannerSession']->id);
        // The migrations seed default Event 1; order follows the existing event_date/event_name query.
        $this->assertEqualsCanonicalizing([1, 301, 302], $layout->data['eventSwitcherEvents']->pluck('id')->all());
    }

    public function test_control_catalogue_pages_render_without_a_selected_event(): void
    {
        $this->fixture();
        $this->withSession(['selected_event_id' => null]);
        $layout = $this->captureLayout();

        $this->get(route('admin.events.index'))->assertOk();

        $this->assertNull($layout->data['selectedEvent']);
        $this->assertNull($layout->data['activeOfflineScannerSession']);
        // The migrations seed default Event 1; order follows the existing event_date/event_name query.
        $this->assertEqualsCanonicalizing([1, 301, 302], $layout->data['eventSwitcherEvents']->pluck('id')->all());
    }

    public function test_control_banner_decoration_falls_back_for_ineligible_creator_and_foreign_package(): void
    {
        $this->fixture();
        User::factory()->create(['id' => 214, 'name' => 'Platform private Administrator', 'email' => 'layout-platform@example.invalid',
            'role' => User::ROLE_ADMIN, 'is_active' => true, 'is_platform_admin' => true]);
        $foreign = $this->offlinePackage((int) DB::table('offline_scanner_sessions')->insertGetId([
            'session_uuid' => (string) Str::uuid(), 'event_id' => 302, 'created_by_user_id' => 211, 'status' => 'closed',
            'package_created_at' => null, 'package_expires_at' => null, 'created_at' => now(), 'updated_at' => now()]));
        DB::table('offline_scanner_sessions')->where('id', 701)->update(['created_by_user_id' => 214, 'package_export_id' => $foreign]);

        $this->get(route('admin.guests.index'))->assertOk()
            ->assertSeeInOrder(['Created by', 'Unknown user'])
            ->assertSeeInOrder(['Package exported', 'Not recorded'])
            ->assertDontSee('Platform private Administrator');
    }

    public function test_every_admin_layout_view_is_prepared_before_rendering(): void
    {
        foreach (["@extends('layouts.admin')", '@extends ("layouts.admin")', "@extends( 'layouts/admin' )",
            "@extendsFirst(['layouts.admin', 'layouts.app'])"] as $form) {
            $this->assertContains('layouts.admin', self::parentViews($form), 'Recognised form: '.$form);
        }
        $parents = [];
        foreach ((new Filesystem)->allFiles(resource_path('views')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $parents[str_replace(['/', '\\'], '.', substr($file->getRelativePathname(), 0, -strlen('.blade.php')))]
                    = self::parentViews($file->getContents());
            }
        }
        $usesLayout = static function (string $view, array $seen) use (&$usesLayout, $parents): bool {
            foreach ($parents[$view] ?? [] as $parent) {
                if ($parent === 'layouts.admin' || (! in_array($parent, $seen, true) && $usesLayout($parent, [...$seen, $view]))) {
                    return true;
                }
            }

            return false;
        };
        $extended = array_merge([], ...array_values($parents));
        $pages = [];
        foreach (array_keys($parents) as $view) {
            if ($view !== 'layouts.admin' && $usesLayout($view, [])) {
                // A page made through an intermediate view would be prepared too late (during rendering).
                $this->assertNotContains($view, $extended, 'Intermediate admin layout view needs explicit handling: '.$view);
                $pages[] = $view;
            }
        }
        sort($pages);
        $registered = LegacyAdminReadProjection::LAYOUT_PAGES;
        sort($registered);

        $this->assertSame($registered, $pages, 'Every view extending layouts.admin needs its layout values prepared before rendering.');
    }

    public function test_an_authenticated_layout_render_without_prepared_values_refuses(): void
    {
        $this->fixture();

        try {
            view('layouts.admin')->render();
            $this->fail('An authenticated layout render without prepared values must refuse.');
        } catch (LegacyAdminReadUnavailable $exception) {
            $this->assertSame('The administrator view could not be prepared.', $exception->getMessage());
        }
    }

    public function test_layout_values_are_immutable_projections_not_models(): void
    {
        $this->fixture();
        $layout = $this->captureLayout();

        $this->get(route('admin.guests.index'))->assertOk();

        $banner = $layout->data['activeOfflineScannerSession'];
        $this->assertInstanceOf(LayoutEvent::class, $layout->data['selectedEvent']);
        $this->assertInstanceOf(LayoutOfflineSession::class, $banner);
        $this->assertInstanceOf(LayoutPackage::class, $banner->packageExport);
        $this->assertInstanceOf(LayoutCreator::class, $banner->createdBy);
        $this->assertContainsOnlyInstancesOf(LayoutEvent::class, $layout->data['eventSwitcherEvents']->all());
        $this->assertSame(['id', 'session_uuid', 'status', 'package_created_at', 'package_expires_at', 'packageExport', 'createdBy'],
            array_keys(get_object_vars($banner)), 'Only the banner fields reach the view; no secret or extra column.');
    }

    public function test_layout_projection_is_one_primary_locking_attempt_in_event_then_actor_order(): void
    {
        $this->fixture();
        $actor = User::query()->findOrFail(211);
        $transactions = 0;
        $this->app['events']->listen(TransactionBeginning::class, static function () use (&$transactions): void {
            $transactions++;
        });
        $grammar = $this->inspectingGrammar();

        $layout = app(LegacyAdminReadProjection::class)->layout($actor, '301');
        $grammar->observing = false;

        $this->assertSame(1, $transactions);
        // Laravel records sharedLock() as lock === false and lockForUpdate() as true.
        $this->assertSame([['events', false, true, 1], ['users', false, true, 1], ['offline_scanner_sessions', false, true, 1],
            ['offline_scanner_packages', null, true, 1], ['users', null, true, 1], ['events', null, true, 1]],
            array_map(static fn (array $read): array => [$read['table'], $read['lock'], $read['write'], $read['level']], $grammar->reads),
            'Shared-locked selected Event, then actor, then the scoped Session; package, creator label and catalogue are unlocked primary reads.');
        $this->assertSame(301, $layout['selectedEvent']->id);
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    #[DataProvider('actorRefreshes')]
    public function test_layout_projection_refreshes_the_actor(string $change, ?int $status): void
    {
        $this->fixture();
        $actor = User::query()->findOrFail(211);
        if ($change === 'missing row') {
            $actor = new User;
            $actor->setRawAttributes(['id' => 299], true);
            $actor->exists = true;
        } elseif ($change === 'workspace-linked') {
            $workspace = DB::table('workspaces')->insertGetId(['name' => 'Synthetic actor workspace', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('workspace_users')->insert(['workspace_id' => $workspace, 'user_id' => 211, 'role' => 'manager',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('users')->where('id', 211)->update(match ($change) {
                'demoted' => ['role' => User::ROLE_SCANNER],
                'deactivated' => ['is_active' => false],
                'platform-flagged' => ['is_platform_admin' => true],
            });
        }

        try {
            $layout = app(LegacyAdminReadProjection::class)->layout($actor, 301);
            $this->assertNull($status, 'The stale actor must be refused.');
            $this->assertSame(301, $layout['selectedEvent']->id, 'Platform/workspace-linked legacy administrators stay compatible.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public static function actorRefreshes(): array
    {
        return ['demoted' => ['demoted', 403], 'deactivated' => ['deactivated', 403], 'missing row' => ['missing row', 403],
            'platform-flagged' => ['platform-flagged', null], 'workspace-linked' => ['workspace-linked', null]];
    }

    #[DataProvider('unusableSelections')]
    public function test_an_unusable_selection_projects_no_event_without_refusing_the_page(mixed $selection): void
    {
        $this->fixture();
        $this->withSession(['selected_event_id' => $selection]);
        $layout = $this->captureLayout();

        $this->get(route('admin.events.index'))->assertOk();

        $this->assertNull($layout->data['selectedEvent']);
        $this->assertNull($layout->data['activeOfflineScannerSession']);
    }

    public static function unusableSelections(): array
    {
        return ['leading zero' => ['0301'], 'negative' => ['-1'], 'not numeric' => ['abc'], 'empty' => [''], 'zero' => [0],
            'beyond PHP range' => ['9223372036854775808'], 'missing Event' => [999], 'workspace-owned Event' => [304]];
    }

    public function test_an_archived_unowned_selection_stays_selected_but_outside_the_catalogue(): void
    {
        $this->fixture();
        $this->withSession(['selected_event_id' => 303]);
        $layout = $this->captureLayout();

        $this->get(route('admin.events.index'))->assertOk();

        $this->assertSame(303, $layout->data['selectedEvent']->id);
        $this->assertNotContains(303, $layout->data['eventSwitcherEvents']->pluck('id')->all());
    }

    public function test_the_layout_composer_issues_no_sql_without_prepared_values(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $layout = $this->captureLayout();

        view('layouts.admin')->render();

        $this->assertSame([], $queries);
        $this->assertNull($layout->data['selectedEvent']);
        $this->assertCount(0, $layout->data['eventSwitcherEvents']);
    }

    public function test_layout_preparation_writes_nothing(): void
    {
        $this->fixture();
        $before = $this->snapshot();

        foreach (['admin.guests.index', 'admin.events.index', 'admin.settings.index'] as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_family_three_html_is_nosniff_and_the_import_post_is_private_no_store(): void
    {
        $this->fixture();
        foreach (self::familyThreePages() as [$route, $parameters]) {
            $this->assertSame('nosniff', $this->get(route($route, $parameters))->headers->get('X-Content-Type-Options'), $route);
        }
        $response = $this->post(route('admin.guests.import.process'), []);

        $response->assertRedirect();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_pages_outside_family_three_keep_their_existing_cache_policy(): void
    {
        $this->fixture();
        foreach (['admin.events.index', 'admin.settings.index', 'admin.users.index'] as $route) {
            $cache = (string) $this->get(route($route))->headers->get('Cache-Control');
            $this->assertStringNotContainsString('no-store', $cache, $route);
        }
    }

    private function fixture(): void
    {
        $workspace = DB::table('workspaces')->insertGetId(['name' => 'Synthetic layout workspace', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([301 => [Event::STATUS_ACTIVE, null], 302 => [Event::STATUS_ACTIVE, null],
            303 => [Event::STATUS_ARCHIVED, null], 304 => [Event::STATUS_ACTIVE, $workspace]] as $id => [$status, $owner]) {
            Event::factory()->create(['id' => $id, 'workspace_id' => $owner, 'event_name' => 'Synthetic layout Event '.$id, 'status' => $status]);
        }
        $actor = User::factory()->create(['id' => 211, 'name' => 'Synthetic layout Administrator',
            'email' => 'layout-admin@example.invalid', 'role' => User::ROLE_ADMIN,
            'is_active' => true, 'is_platform_admin' => false, 'preferred_language' => 'en']);
        User::factory()->create(['id' => 212, 'event_id' => 301, 'name' => 'Synthetic layout Scanner',
            'email' => 'layout-scanner@example.invalid', 'role' => User::ROLE_SCANNER,
            'is_active' => true, 'is_platform_admin' => false, 'gate_name' => 'Synthetic gate']);
        Guest::query()->forceCreate(['id' => 901, 'event_id' => 301, 'name' => 'Synthetic layout Guest',
            'phone_number' => '+255711111901', 'manual_code' => '0047', 'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1, 'used_entries' => 0, 'status' => Guest::STATUS_ACTIVE]);
        DB::table('offline_scanner_sessions')->insert(['id' => 701, 'session_uuid' => (string) Str::uuid(), 'event_id' => 301,
            'created_by_user_id' => 211, 'status' => 'active', 'package_created_at' => null, 'package_expires_at' => null,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('offline_scanner_sessions')->where('id', 701)->update(['package_export_id' => $this->offlinePackage(701)]);
        $this->actingAs($actor)->withSession(['selected_event_id' => 301]);
    }

    /** Metadata row only; the synthetic path is never read or written. */
    private function offlinePackage(int $sessionId): int
    {
        return (int) DB::table('offline_scanner_packages')->insertGetId([
            'package_uuid' => (string) Str::uuid(), 'offline_scanner_session_id' => $sessionId,
            'file_name' => 'synthetic.kadipkg', 'file_path' => 'synthetic/never-read.kadipkg',
            'file_hash' => str_repeat('a', 64), 'encrypted_file_size' => 1,
            'encryption_algorithm' => 'synthetic', 'package_fingerprint' => str_repeat('b', 64),
            'generated_at' => '2026-03-04 05:06:00', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** SQL issued from the moment the layout view is made until the response is prepared. */
    private function layoutSql(Closure $request): array
    {
        $state = new stdClass;
        $state->counting = false;
        $state->queries = [];
        View::creator('layouts.admin', static function () use ($state): void {
            $state->counting = true;
        });
        DB::listen(static function ($query) use ($state): void {
            if ($state->counting) {
                $state->queries[] = $query->sql;
            }
        });
        $this->app['events']->listen(ResponsePrepared::class, static function () use ($state): void {
            $state->counting = false;
        });

        return [$request(), $state->queries];
    }

    private function captureLayout(): stdClass
    {
        $layout = new stdClass;
        $layout->data = null;
        View::composer('layouts.admin', static function ($view) use ($layout): void {
            $layout->data = $view->getData();
        });

        return $layout;
    }

    /** @return list<string> parent views named by @extends or @extendsFirst, with / normalized to . */
    private static function parentViews(string $contents): array
    {
        $names = [];
        if (preg_match_all('/@extends\s*\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches) > 0) {
            $names = $matches[1];
        }
        if (preg_match_all('/@extendsFirst\s*\(\s*\[([^\]]*)\]/', $contents, $lists) > 0) {
            foreach ($lists[1] as $list) {
                preg_match_all('/[\'"]([^\'"]+)[\'"]/', $list, $entries);
                $names = [...$names, ...$entries[1]];
            }
        }

        return array_values(array_unique(array_map(static fn (string $name): string => str_replace('/', '.', $name), $names)));
    }

    private function inspectingGrammar(): AdminLayoutInspectingGrammar
    {
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $grammar = new AdminLayoutInspectingGrammar($db);
        $db->setQueryGrammar($grammar);
        $this->beforeApplicationDestroyed(static fn () => $db->setQueryGrammar($original));

        return $grammar;
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $rows = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row): array => (array) $row)->all();
            usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            $snapshot[$table->name] = $rows;
        }

        return $snapshot;
    }
}

/** SQLite discards FOR UPDATE; the grammar reports builder intent, never actual lock-wait proof. */
final class AdminLayoutInspectingGrammar extends SQLiteGrammar
{
    public bool $observing = true;

    public array $reads = [];

    public function compileSelect(Builder $query)
    {
        $sql = parent::compileSelect($query);
        if ($this->observing && in_array($query->from, ['events', 'users', 'offline_scanner_sessions', 'offline_scanner_packages'], true)) {
            $this->reads[] = ['table' => $query->from, 'write' => $query->useWritePdo, 'lock' => $query->lock,
                'level' => DB::connection()->transactionLevel()];
        }

        return $sql;
    }
}
