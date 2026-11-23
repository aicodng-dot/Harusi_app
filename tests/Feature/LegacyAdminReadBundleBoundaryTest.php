<?php

namespace Tests\Feature;

use App\Exceptions\LegacyAdminReadUnavailable;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\QrCode;
use App\Models\User;
use App\Services\Tenancy\LegacyAdminReadProjection;
use App\Support\AdminRead\GuestView;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use LogicException;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * P4-047 family-3 page and export read bundles: one read-only attempt per request with
 * shared primary locks in Event -> actor -> Session -> private-row order, statistics
 * computed from the materialized rows, one identity set per paginator, frozen context
 * and immutable view values. SQLite discards locks; the grammar reports builder intent,
 * never actual lock-wait proof, so MySQL observations remain a separate gate. The first
 * 82 cases were observed on the pre-change source (60 failing gaps, 22 passing controls);
 * the cases from test_a_page_window onward were added with the change.
 */
class LegacyAdminReadBundleBoundaryTest extends TestCase
{
    private const PRIVATE_TABLES = ['guests', 'qr_codes', 'checkins', 'notification_logs'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new LogicException('Read bundle fixtures require isolated in-memory SQLite.');
        }
        $pdo = $db->getPdo();
        $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool => $row['name'] === 'main'));
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || count($main) !== 1 || $main[0]['file'] !== ''
            || $db->transactionLevel() !== 0 || $pdo->inTransaction() || (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new LogicException('Read bundle fixtures require memory main, enabled FKs and actual autocommit0.');
        }
        $temporaryRoot = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'harusi-read-bundle-'.bin2hex(random_bytes(8));
        (new Filesystem)->makeDirectory($temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views', 0777, true);
        $this->app->useStoragePath($temporaryRoot);
        config(['view.compiled' => $temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            'logging.channels.single.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log',
            'mail.default' => 'array', 'cache.default' => 'array', 'session.driver' => 'array',
            'queue.default' => 'sync', 'services.whatsapp.provider' => 'null']);
        $this->beforeApplicationDestroyed(static function () use ($db, $temporaryRoot): void {
            $db->disconnect();
            $resolved = realpath($temporaryRoot);
            $prefix = rtrim((string) realpath(sys_get_temp_dir()), '/\\').DIRECTORY_SEPARATOR.'harusi-read-bundle-';
            if ($resolved !== false && str_starts_with(strtolower($resolved), strtolower($prefix))) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        });
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
        $this->freezeTime();
        $this->withoutVite();
    }

    #[DataProvider('pages')]
    public function test_page_content_is_read_in_one_primary_share_locked_attempt(string $route, array $parameters): void
    {
        $this->fixture();
        $transactions = $this->countTransactions();
        $grammar = $this->inspectingGrammar();

        $this->get(route($route, $parameters))->assertOk();
        $grammar->observing = false;

        $this->assertSame(1, $transactions->count, 'One read attempt per request.');
        $this->assertSame([], $this->unprotectedPrivateReads($grammar, $parameters !== []));
    }

    #[DataProvider('contentPages')]
    public function test_private_rows_are_read_after_event_actor_and_session(string $route, array $parameters): void
    {
        $this->fixture();
        $grammar = $this->inspectingGrammar();

        $this->get(route($route, $parameters))->assertOk();
        $grammar->observing = false;

        $attempt = array_values(array_filter($grammar->reads, static fn (array $read): bool => $read['level'] > 0));
        $tables = array_column($attempt, 'table');
        $private = array_keys(array_filter($tables, static fn (string $table): bool => in_array($table, self::PRIVATE_TABLES, true)));
        $this->assertNotSame([], $private, 'Private rows are read inside the attempt.');
        $firstPrivate = min($private);
        $this->assertSame(['events', 'users', 'offline_scanner_sessions'], array_slice($tables, 0, 3));
        $this->assertSame(3, $firstPrivate, 'Private rows follow the Event, actor and Session locks.');
        $this->assertSame([false, false, false], array_column(array_slice($attempt, 0, 3), 'lock'));
    }

    #[DataProvider('contentPages')]
    public function test_each_private_table_is_first_locked_in_primary_key_order(string $route, array $parameters): void
    {
        $this->fixture();
        $grammar = $this->inspectingGrammar();

        $this->get(route($route, $parameters))->assertOk();
        $grammar->observing = false;

        $first = [];
        foreach ($grammar->reads as $read) {
            if ($read['level'] > 0 && in_array($read['table'], self::PRIVATE_TABLES, true)) {
                $first[$read['table']] ??= $read['order'];
            }
        }
        $this->assertNotSame([], $first);
        foreach ($first as $table => $order) {
            // A single exact row needs no order; every wider read starts in stable PK order.
            if ($order !== null) {
                $this->assertContains($order[0]['column'] ?? null, ['id', $table.'.id'], $table);
                $this->assertSame('asc', $order[0]['direction'], $table);
            }
        }
    }

    #[DataProvider('hintedPages')]
    public function test_event_scoped_locking_reads_name_their_index(string $route, array $tables): void
    {
        $this->fixture();
        $grammar = $this->inspectingGrammar();

        $this->get(route($route))->assertOk();
        $grammar->observing = false;

        $expected = ['guests' => 'guests_event_id_index', 'checkins' => 'checkins_event_id_index',
            'notification_logs' => 'notification_logs_event_id_channel_status_index', 'qr_codes' => 'qr_codes_guest_id_unique'];
        $seen = [];
        foreach ($grammar->reads as $read) {
            if (in_array($read['table'], $tables, true)) {
                $seen[$read['table']] = true;
                $this->assertSame($expected[$read['table']], $read['index'], $read['table'].' must not be left to a plan that scans other Events.');
            }
        }
        $this->assertSame($tables, array_keys($seen));
    }

    public static function hintedPages(): array
    {
        return ['dashboard' => ['admin.dashboard', ['guests', 'qr_codes', 'checkins']], 'qr codes' => ['admin.qr-codes.index', ['guests', 'qr_codes']],
            'checkins' => ['admin.checkins.index', ['checkins', 'guests']], 'notification logs' => ['admin.notification-logs.index', ['notification_logs', 'guests']],
            'reports' => ['admin.reports.index', ['guests', 'qr_codes', 'checkins']]];
    }

    public function test_the_named_indexes_exist_after_the_migrations(): void
    {
        foreach (['guests' => 'guests_event_id_index', 'checkins' => 'checkins_event_id_index',
            'notification_logs' => 'notification_logs_event_id_channel_status_index', 'qr_codes' => 'qr_codes_guest_id_unique'] as $table => $index) {
            $this->assertContains($index, array_column(Schema::getIndexes($table), 'name'), $table);
        }
    }

    #[DataProvider('contentPages')]
    public function test_statistics_and_page_totals_are_not_sql_aggregates(string $route, array $parameters): void
    {
        $this->fixture();
        $grammar = $this->inspectingGrammar();

        $this->get(route($route, $parameters))->assertOk();
        $grammar->observing = false;

        $this->assertSame([], array_values(array_filter($grammar->reads, static fn (array $read): bool => $read['aggregate'] !== null
            && in_array($read['table'], self::PRIVATE_TABLES, true))));
    }

    #[DataProvider('contentPages')]
    public function test_views_receive_no_models(string $route, array $parameters): void
    {
        $this->fixture();

        $data = $this->get(route($route, $parameters))->assertOk()->original->getData();

        $this->assertSame([], $this->modelsIn($data));
    }

    #[DataProvider('retargets')]
    public function test_a_selection_changed_during_the_read_does_not_retarget_the_page(string $route, Closure $read, mixed $expected): void
    {
        $this->fixture();
        $this->flipSelectionAfterFirstPrivateRead();

        $data = $this->get(route($route))->assertOk()->original->getData();

        $this->assertSame($expected, $read($data), 'Every read stays on the Event captured before the first read.');
        $this->assertSame(301, $data['adminLayout']['selectedEvent']->id ?? null, 'The layout stays on the same Event.');
    }

    public static function retargets(): array
    {
        return [
            'dashboard' => ['admin.dashboard', static fn (array $data): array => [$data['stats']['total_guests'], $data['stats']['today_scans']], [3, 2]],
            'guest list' => ['admin.guests.index', static fn (array $data): int => $data['counts']['all'], 3],
            'qr list' => ['admin.qr-codes.index', static fn (array $data): int => $data['stats']['total_guests'], 3],
            'reports' => ['admin.reports.index', static fn (array $data): array => [$data['admissionSummary']['total_invites'], $data['stats']['total_guests']], [3, 3]],
            'checkins' => ['admin.checkins.index', static fn (array $data): int => $data['summary']['total_scans_today'], 2],
            'notification logs' => ['admin.notification-logs.index', static fn (array $data): int => $data['logs']->total(), 1],
        ];
    }

    #[DataProvider('detailPages')]
    public function test_a_stale_bound_guest_cannot_serve_a_guest_moved_to_another_event(string $route): void
    {
        $this->fixture();
        $stale = Guest::query()->findOrFail(901);
        DB::table('guests')->where('id', 901)->update(['event_id' => 302]);
        Route::bind('guest', static fn () => $stale);

        $this->get(route($route, 901))->assertNotFound()->assertDontSee('Selected ordinary Guest');
    }

    #[DataProvider('detailPages')]
    public function test_a_detail_page_shows_the_current_row_not_the_bound_copy(string $route): void
    {
        $this->fixture();
        $stale = Guest::query()->findOrFail(901);
        DB::table('guests')->where('id', 901)->update(['name' => 'Renamed current Guest']);
        Route::bind('guest', static fn () => $stale);

        $this->get(route($route, 901))->assertOk()->assertSee('Renamed current Guest')->assertDontSee('Selected ordinary Guest');
    }

    public static function detailPages(): array
    {
        return ['detail' => ['admin.guests.show'], 'edit' => ['admin.guests.edit']];
    }

    #[DataProvider('contentPages')]
    public function test_authority_lost_before_the_attempt_reads_no_private_row(string $route, array $parameters): void
    {
        $this->fixture();
        $grammar = $this->inspectingGrammar();
        $this->app['events']->listen(TransactionBeginning::class, static function (): void {
            DB::table('users')->where('id', 211)->update(['role' => User::ROLE_SCANNER]);
        });

        $this->get(route($route, $parameters))->assertForbidden();
        $grammar->observing = false;

        $this->assertSame([], $this->privateReads($grammar, $parameters !== []));
    }

    public function test_the_full_qr_credential_never_reaches_the_view(): void
    {
        $this->fixture();

        foreach (['admin.dashboard', 'admin.guests.index', 'admin.qr-codes.index'] as $route) {
            $data = $this->get(route($route))->assertOk()->original->getData();
            $this->assertStringNotContainsString('synthetic-full-qr-token-901', $this->flatten($data), $route);
        }
        $this->get(route('admin.qr-codes.index'))->assertSee('...'.substr('synthetic-full-qr-token-901', -10), false);
    }

    #[DataProvider('exports')]
    public function test_exports_read_in_one_primary_share_locked_attempt_before_the_response(string $route): void
    {
        $this->fixture();
        $transactions = $this->countTransactions();
        $grammar = $this->inspectingGrammar();

        $response = $this->get(route($route))->assertOk();
        $grammar->observing = false;

        $this->assertSame(1, $transactions->count, 'One read attempt per export.');
        $this->assertSame([], $this->unprotectedPrivateReads($grammar, false));
        $this->assertNotSame([], $this->privateReads($grammar, false));
        $this->assertSame([], array_values(array_filter($grammar->reads, static fn (array $read): bool => $read['aggregate'] !== null)));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[DataProvider('exports')]
    public function test_exports_refuse_authority_lost_before_their_attempt(string $route): void
    {
        $this->fixture();
        $grammar = $this->inspectingGrammar();
        $this->app['events']->listen(TransactionBeginning::class, static function (): void {
            DB::table('users')->where('id', 211)->update(['is_active' => false]);
        });

        $this->get(route($route))->assertForbidden();
        $grammar->observing = false;

        $this->assertSame([], $this->privateReads($grammar, false));
    }

    public static function exports(): array
    {
        return ['qr list' => ['admin.qr-codes.export'], 'checkins' => ['admin.checkins.export'],
            'guest list' => ['admin.reports.export.guest-list'], 'checked in' => ['admin.reports.export.checked-in-guests'],
            'remaining' => ['admin.reports.export.remaining-guests'], 'invalid scans' => ['admin.reports.export.invalid-scans']];
    }

    #[DataProvider('renderPages')]
    public function test_rendering_a_family_three_page_issues_no_sql(string $route, array $parameters, string $view): void
    {
        $this->fixture();
        $state = new stdClass;
        $state->counting = false;
        $state->queries = [];
        View::composer($view, static function () use ($state): void {
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

        $this->get(route($route, $parameters))->assertOk();

        $this->assertSame([], $state->queries);
    }

    public static function renderPages(): array
    {
        return ['dashboard' => ['admin.dashboard', [], 'admin.dashboard'], 'guests' => ['admin.guests.index', [], 'admin.guests'],
            'qr codes' => ['admin.qr-codes.index', [], 'admin.qr-codes'], 'guest detail' => ['admin.guests.show', [901], 'admin.guests.show'],
            'guest edit' => ['admin.guests.edit', [901], 'admin.guests.edit'], 'import' => ['admin.guests.import', [], 'admin.guests.import'],
            'checkins' => ['admin.checkins.index', [], 'admin.checkins'], 'logs' => ['admin.notification-logs.index', [], 'admin.notification-logs'],
            'reports' => ['admin.reports.index', [], 'admin.reports']];
    }

    #[DataProvider('pages')]
    public function test_reading_a_page_writes_nothing(string $route, array $parameters): void
    {
        $this->fixture();
        $before = $this->snapshot();

        $this->get(route($route, $parameters))->assertOk();

        $this->assertSame($before, $this->snapshot());
    }

    public static function pages(): array
    {
        return array_merge(self::contentPages(), ['import' => ['admin.guests.import', []]]);
    }

    public static function contentPages(): array
    {
        return ['dashboard' => ['admin.dashboard', []], 'guests' => ['admin.guests.index', []],
            'qr codes' => ['admin.qr-codes.index', []], 'guest detail' => ['admin.guests.show', [901]],
            'guest edit' => ['admin.guests.edit', [901]], 'checkins' => ['admin.checkins.index', []],
            'notification logs' => ['admin.notification-logs.index', []], 'reports' => ['admin.reports.index', []]];
    }

    #[DataProvider('pageWindows')]
    public function test_a_page_window_and_total_match_the_former_paginator(string $route, int $perPage, string $query, int $items, int $currentPage): void
    {
        $this->fixture();
        $this->manyGuests($perPage * 2);

        $guests = $this->get(route($route).$query)->assertOk()->original->getData()['guests'];

        $this->assertSame(($perPage * 2) + 3, $guests->total());
        $this->assertSame($perPage, $guests->perPage());
        $this->assertSame($currentPage, $guests->currentPage());
        $this->assertCount($items, $guests->items());
        $this->assertContainsOnlyInstancesOf(GuestView::class, $guests->items());
    }

    public static function pageWindows(): array
    {
        $windows = [];
        foreach (['guests' => ['admin.guests.index', 50], 'qr codes' => ['admin.qr-codes.index', 15]] as $name => [$route, $perPage]) {
            $windows[$name.' first'] = [$route, $perPage, '', $perPage, 1];
            $windows[$name.' last'] = [$route, $perPage, '?page=3', 3, 3];
            $windows[$name.' beyond the end'] = [$route, $perPage, '?page=9', 0, 9];
            $windows[$name.' not a number'] = [$route, $perPage, '?page=abc', $perPage, 1];
            $windows[$name.' beyond integer arithmetic'] = [$route, $perPage, '?page='.PHP_INT_MAX, 0, PHP_INT_MAX];
        }

        return $windows;
    }

    public function test_page_links_keep_the_query_string_on_both_guest_lists(): void
    {
        $this->fixture();
        $this->manyGuests(60);

        foreach (['admin.guests.index' => 'Bulk', 'admin.qr-codes.index' => 'Bulk'] as $route => $search) {
            $guests = $this->get(route($route, ['search' => $search]))->assertOk()->original->getData()['guests'];
            $this->assertStringContainsString('search='.$search, (string) $guests->nextPageUrl(), $route);
        }
    }

    public function test_guest_filters_and_counts_stay_inside_the_selected_event(): void
    {
        $this->fixture();

        $data = $this->get(route('admin.guests.index', ['status' => Guest::STATUS_CANCELLED]))->assertOk()->original->getData();

        $this->assertSame([903], array_column($data['guests']->items(), 'id'));
        $this->assertSame(['all' => 3, 'unused' => 2, Guest::STATUS_PARTIALLY_USED => 0, Guest::STATUS_FULLY_USED => 0,
            Guest::STATUS_CANCELLED => 1, 'revoked' => 0], $data['counts']);
        $this->assertSame(1, $data['guests']->total());
    }

    public function test_guest_search_cannot_reach_another_event_through_the_or_group(): void
    {
        $this->fixture();
        // A selected-Event attempt whose Guest now belongs elsewhere must not match by that Guest's name.
        $this->checkin(301, 904, 212, 'Selected gate');

        foreach (['admin.guests.index', 'admin.qr-codes.index'] as $route) {
            $guests = $this->get(route($route, ['search' => 'Foreign private']))->assertOk()->original->getData()['guests'];
            $this->assertSame(0, $guests->total(), $route);
        }
        $this->get(route('admin.checkins.index', ['search' => 'Foreign private']))->assertOk()
            ->assertViewHas('checkins', static fn ($checkins): bool => $checkins->total() === 0);
    }

    #[DataProvider('qrFilters')]
    public function test_each_qr_filter_selects_the_same_guests_as_the_former_relation_query(string $filter, array $expected): void
    {
        $this->fixture();
        Guest::query()->forceCreate(['id' => 905, 'event_id' => 301, 'name' => 'Selected revoked Guest', 'phone_number' => '+255711111905',
            'manual_code' => '0905', 'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1, 'used_entries' => 1, 'status' => Guest::STATUS_FULLY_USED]);
        QrCode::query()->forceCreate(['guest_id' => 905, 'qr_token' => 'synthetic-full-qr-token-905', 'qr_image_path' => null,
            'is_active' => false, 'generated_at' => now(), 'revoked_at' => now()]);

        $data = $this->get(route('admin.qr-codes.index', ['filter' => $filter]))->assertOk()->original->getData();

        $ids = array_column($data['guests']->items(), 'id');
        sort($ids);
        $this->assertSame($expected, $ids);
        $this->assertSame(['total_guests' => 4, 'has_qr' => 2, 'missing_qr' => 2, 'active' => 1, 'revoked' => 1, 'fully_used' => 1, 'unused' => 2], $data['stats']);
    }

    public static function qrFilters(): array
    {
        return ['none' => ['', [901, 902, 903, 905]], 'has' => ['has_qr', [901, 905]], 'missing' => ['missing_qr', [902, 903]],
            'active' => ['active_qr', [901]], 'revoked' => ['revoked_qr', [905]], 'fully used' => ['fully_used', [905]],
            'unused' => ['unused', [901, 902]], 'unknown' => ['not-a-filter', [901, 902, 903, 905]]];
    }

    #[DataProvider('qrStates')]
    public function test_either_qr_revocation_signal_counts_as_revoked_and_not_active(bool $isActive, bool $revokedAt, array $expected): void
    {
        $this->fixture();
        DB::table('qr_codes')->where('guest_id', 901)->update(['is_active' => $isActive, 'revoked_at' => $revokedAt ? now() : null]);

        $stats = $this->get(route('admin.qr-codes.index'))->assertOk()->original->getData()['stats'];

        $this->assertSame($expected, [$stats['active'], $stats['revoked']]);
    }

    public static function qrStates(): array
    {
        return ['active' => [true, false, [1, 0]], 'revoked timestamp only' => [true, true, [0, 1]],
            'inactive flag only' => [false, false, [0, 1]], 'both' => [false, true, [0, 1]]];
    }

    public function test_statistics_keep_the_existing_arithmetic(): void
    {
        $this->fixture();
        DB::table('guests')->where('id', 902)->update(['used_entries' => 5, 'status' => Guest::STATUS_FULLY_USED]);

        $stats = $this->get(route('admin.dashboard'))->assertOk()->original->getData()['stats'];

        $this->assertSame(3, $stats['total_guests']);
        $this->assertSame(4, $stats['total_allowed_entries'], 'Cancelled Guests are excluded from the allowance.');
        $this->assertSame(6, $stats['admitted_entries'], 'All stored used entries count, including cancelled Guests.');
        $this->assertSame(0, $stats['remaining_entries'], 'Remaining entries stop at zero.');
        $this->assertSame(1, $stats['today_admitted']);
        $this->assertSame(2, $stats['today_scans']);
        $this->assertSame(1, $stats['qr_codes']);
    }

    public function test_history_counts_need_both_the_guest_and_the_selected_event(): void
    {
        $this->fixture();
        $this->checkin(302, 901, 213, 'Foreign attributed gate');

        $guests = $this->get(route('admin.guests.index'))->assertOk()->original->getData()['guests'];
        $detail = $this->get(route('admin.guests.show', 901))->assertOk();

        $this->assertSame(2, collect($guests->items())->firstWhere('id', 901)->checkins_count);
        $this->assertSame(2, $detail->original->getData()['guest']->checkins_count);
        $detail->assertDontSee('Foreign attributed gate');
    }

    public function test_history_keeps_its_own_scalars_when_guest_and_scanner_have_moved(): void
    {
        $this->fixture();
        DB::table('guests')->where('id', 901)->update(['event_id' => 302]);
        DB::table('users')->where('id', 212)->update(['event_id' => 302]);

        $this->get(route('admin.checkins.index'))->assertOk()
            ->assertSee('Selected gate')->assertSee('Unknown QR')
            ->assertDontSee('Selected ordinary Guest')->assertDontSee('Selected ordinary Scanner');
    }

    public function test_a_row_that_disappears_under_the_read_refuses_the_whole_result(): void
    {
        $this->fixture();
        $state = new stdClass;
        $state->fired = false;
        DB::listen(static function ($query) use ($state): void {
            if (! $state->fired && str_contains($query->sql, 'from "guests"') && str_contains($query->sql, 'order by "created_at" desc')) {
                $state->fired = true;
                DB::table('guests')->where('id', 902)->update(['event_id' => 302]);
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->get(route('admin.guests.index'));
            $this->fail('A partial result must refuse.');
        } catch (LegacyAdminReadUnavailable) {
            $this->assertSame(0, DB::connection()->transactionLevel());
        }
    }

    public function test_a_missing_selection_during_the_attempt_refuses_and_clears_it(): void
    {
        $this->fixture();
        $this->app['events']->listen(TransactionBeginning::class, static function (): void {
            DB::table('events')->where('id', 301)->update(['workspace_id' => DB::table('workspaces')->insertGetId(['name' => 'Late owner', 'created_at' => now(), 'updated_at' => now()])]);
        });

        $this->withSession(['guest_import' => ['token' => 'x']])->get(route('admin.guests.index'))->assertNotFound();

        $this->assertFalse(session()->has('selected_event_id'));
        $this->assertFalse(session()->has('guest_import'));
    }

    public function test_non_sql_failures_become_the_fixed_refusal_with_no_transaction_left_open(): void
    {
        $this->fixture();
        Guest::retrieved(static function (): void {
            throw new RuntimeException('Synthetic private failure detail');
        });
        $this->withoutExceptionHandling();

        try {
            $this->get(route('admin.guests.index'));
            $this->fail('The failure must refuse.');
        } catch (LegacyAdminReadUnavailable $exception) {
            $this->assertSame('The administrator view could not be prepared.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertSame(0, DB::connection()->transactionLevel());
        }
    }

    public function test_actual_sql_failures_keep_framework_handling_with_no_transaction_left_open(): void
    {
        $this->fixture();
        DB::statement('ALTER TABLE qr_codes RENAME TO qr_codes_unavailable');
        $this->withoutExceptionHandling();

        try {
            $this->get(route('admin.guests.index'));
            $this->fail('The failure must refuse.');
        } catch (QueryException) {
            $this->assertSame(0, DB::connection()->transactionLevel());
        }
    }

    public function test_a_supplied_read_bundle_is_not_prepared_a_second_time(): void
    {
        $this->fixture();
        $transactions = $this->countTransactions();

        $data = $this->get(route('admin.guests.index'))->assertOk()->original->getData();

        $this->assertSame(1, $transactions->count);
        $this->assertSame(301, $data['adminLayout']['selectedEvent']->id);
    }

    public function test_exports_contain_only_the_selected_event_and_keep_their_columns(): void
    {
        $this->fixture();

        $guests = $this->get(route('admin.reports.export.guest-list'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Selected ordinary Guest', $guests);
        $this->assertStringNotContainsString('Foreign private Guest', $guests);
        $this->assertStringContainsString('"Guest name","Phone number","Pass type","Allowed entries","Used entries","Remaining entries",Status,"QR status"', $guests);

        $checkedIn = $this->get(route('admin.reports.export.checked-in-guests'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Selected cancelled Guest', $checkedIn);
        $this->assertStringNotContainsString('Selected unused Guest', $checkedIn);

        $remaining = $this->get(route('admin.reports.export.remaining-guests'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Selected unused Guest', $remaining);
        $this->assertStringNotContainsString('Selected cancelled Guest', $remaining);

        $checkins = $this->get(route('admin.checkins.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Selected ordinary Scanner', $checkins);
        $this->assertStringNotContainsString('Foreign', $checkins);

        $invalid = $this->get(route('admin.reports.export.invalid-scans'))->assertOk()->streamedContent();
        $this->assertStringContainsString('invalid', $invalid);
        $this->assertStringNotContainsString('admitted', $invalid);
    }

    public function test_an_export_emits_frozen_bytes_with_no_later_sql_or_session_read(): void
    {
        $this->fixture();
        $response = $this->get(route('admin.reports.export.guest-list'))->assertOk();
        DB::table('guests')->where('id', 901)->update(['name' => 'Changed after preparation']);
        session(['selected_event_id' => 302]);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $content = $response->streamedContent();

        $this->assertSame([], $queries);
        $this->assertStringContainsString('Selected ordinary Guest', $content);
        $this->assertStringNotContainsString('Changed after preparation', $content);
    }

    public function test_qr_list_export_file_names_come_from_the_captured_event_and_guest(): void
    {
        $this->fixture();
        $this->flipSelectionAfterFirstPrivateRead();

        $response = $this->get(route('admin.qr-codes.export'))->assertOk();

        $this->assertStringContainsString('synthetic_bundle_event_301-qr-list-', (string) $response->headers->get('Content-Disposition'));
        $path = $this->app->storagePath('synthetic-export-'.bin2hex(random_bytes(6)).'.xlsx');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray();
        $spreadsheet->disconnectWorksheets();

        $this->assertSame('Download file name', $rows[0][10]);
        $byName = array_column(array_slice($rows, 1), 10, 1);
        $this->assertSame(['Selected cancelled Guest', 'Selected ordinary Guest', 'Selected unused Guest'], array_keys($byName), 'Only the captured Event, by name.');
        $this->assertSame('synthetic_bundle_event_301_selected_ordinary_guest_901_qr_0901.png', $byName['Selected ordinary Guest']);
        $this->assertEmpty($byName['Selected unused Guest'], 'A Guest with no QR has no file name.');
    }

    public function test_import_preview_classifies_inside_one_locked_attempt_and_stays_in_the_selected_event(): void
    {
        $this->fixture();
        $transactions = $this->countTransactions();
        $grammar = $this->inspectingGrammar();

        $response = $this->post(route('admin.guests.import.process'), ['excel_file' => $this->workbook([
            ['SELECTED ORDINARY  guest', '0712000111', 'double', 2],
            ['Foreign private Guest', '0712000222', 'double', 2],
        ])]);
        $grammar->observing = false;

        $response->assertOk();
        $this->assertSame(1, $transactions->count, 'Duplicates and the layout share one read attempt.');
        $this->assertSame([], $this->unprotectedPrivateReads($grammar, false));
        $rows = $response->original->getData()['rows'];
        $this->assertSame('duplicate', $rows[0]['status']);
        $this->assertSame(901, $rows[0]['duplicate']['id']);
        $this->assertSame(['Name (case-insensitive)'], $rows[0]['duplicate']['match_reasons']);
        $this->assertSame('new', $rows[1]['status'], 'A Guest of another Event is not a duplicate.');
        $this->assertSame([], $this->modelsIn($response->original->getData()));
        $this->assertSame($rows, session('guest_import.rows'));
        $this->assertSame(301, session('guest_import.event_id'));
    }

    public function test_import_preview_refuses_authority_lost_before_its_attempt(): void
    {
        $this->fixture();
        $this->app['events']->listen(TransactionBeginning::class, static function (): void {
            DB::table('users')->where('id', 211)->update(['is_active' => false]);
        });

        $this->post(route('admin.guests.import.process'), ['excel_file' => $this->workbook([['New Guest', '0712000111', 'single', 1]])])->assertForbidden();

        $this->assertFalse(session()->has('guest_import.rows'), 'No preview is stored for a refused attempt.');
    }

    public function test_the_projection_needs_a_safe_actor_before_any_read(): void
    {
        $this->fixture();
        $actor = new User;
        $actor->setRawAttributes(['id' => 'not-an-id'], true);
        $actor->exists = true;
        $grammar = $this->inspectingGrammar();

        try {
            app(LegacyAdminReadProjection::class)->guestListPage($actor, 301, ['search' => '', 'pass_type' => '', 'status' => '', 'name_sort' => '']);
            $this->fail('An unsafe actor must refuse.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $grammar->observing = false;
        $this->assertSame([], $grammar->reads);
    }

    public function test_a_selection_changed_during_the_import_read_cannot_show_another_events_saved_result(): void
    {
        $this->fixture();
        $this->withSession(['guest_import.result' => $this->savedImportResult(),
            'guest_import.result_context' => ['actor_id' => 211, 'event_id' => 302]]);
        $flip = $this->flipSelectionToAnOwnedEventDuringTheImportRead();

        $response = $this->get(route('admin.guests.import'))->assertOk();

        $this->assertTrue($flip->fired, 'The selection changed inside the read attempt.');
        $data = $response->original->getData();
        $this->assertSame(301, $data['adminLayout']['selectedEvent']->id ?? null, 'The response stays on the authorized Event.');
        $this->assertNull($data['importResult'], 'Another Event\'s result never reaches the view.');
        $response->assertSee('Synthetic bundle Event 301')
            ->assertDontSee('Synthetic bundle Event 302')
            ->assertDontSee('Saved result private contact')
            ->assertDontSee('+255711119302')
            ->assertDontSee('Saved-result-code')
            ->assertSee(__('guest_issuance.result_withheld', [], 'en'));
        $this->assertNull(session('guest_import.result'), 'The mismatched result is cleared.');
        $this->assertNull(session('guest_import.result_context'), 'Its binding is cleared with it.');
    }

    public function test_a_selection_changed_during_the_import_read_keeps_the_saved_result_of_the_frozen_event(): void
    {
        $this->fixture();
        $result = $this->savedImportResult();
        $this->withSession(['guest_import.result' => $result,
            'guest_import.result_context' => ['actor_id' => 211, 'event_id' => 301]]);
        $flip = $this->flipSelectionToAnOwnedEventDuringTheImportRead();

        $response = $this->get(route('admin.guests.import'))->assertOk();

        $this->assertTrue($flip->fired, 'The selection changed inside the read attempt.');
        $data = $response->original->getData();
        $this->assertSame(301, $data['adminLayout']['selectedEvent']->id ?? null);
        $this->assertSame($result, $data['importResult'], 'A result bound to the frozen Event still shows.');
        $response->assertSee('Saved result private contact')
            ->assertSee('+255711119302')
            ->assertSee('Saved-result-code')
            ->assertDontSee(__('guest_issuance.result_withheld', [], 'en'));
        $this->assertSame($result, session('guest_import.result'));
    }

    #[DataProvider('listPages')]
    public function test_a_page_changed_during_the_read_does_not_retarget_the_list(string $route, string $list, string $identity, string $foreign, array $kept): void
    {
        $this->fixture();
        $this->threePagesOnEveryList();
        $expected = $this->get(route($route, ['page' => 1, 'kept' => 'frozen']))->assertOk()->original->getData()[$list];
        $flip = $this->flipPageDuringTheRead();

        $response = $this->get(route($route, ['page' => 1, 'kept' => 'frozen']))->assertOk();

        $this->assertTrue($flip->fired, 'The page changed inside the read attempt.');
        $data = $response->original->getData();
        $this->assertSame(301, $data['adminLayout']['selectedEvent']->id ?? null, 'The Event stays the authorized one.');
        $this->assertSame(1, $data[$list]->currentPage());
        $this->assertSame(array_column($expected->items(), $identity), array_column($data[$list]->items(), $identity), 'The rows are the first page.');
        $this->assertSame($expected->total(), $data[$list]->total());
        $this->assertNull($data[$list]->previousPageUrl());
        $next = route($route, [...$kept, 'page' => 2]);
        $this->assertSame($next, $data[$list]->nextPageUrl(), 'Links use the captured path and query.');
        $response->assertSee($next)->assertDontSee('retargeted')->assertDontSee($foreign);
    }

    #[DataProvider('listPages')]
    public function test_a_list_serves_each_requested_page_when_nothing_changes_during_the_read(string $route, string $list, string $identity, string $foreign, array $kept): void
    {
        $this->fixture();
        $this->threePagesOnEveryList();

        $first = $this->get(route($route, ['page' => 1, 'kept' => 'frozen']))->assertOk()->original->getData()[$list];
        $second = $this->get(route($route, ['page' => 2, 'kept' => 'frozen']))->assertOk()->assertDontSee($foreign)->original->getData()[$list];

        $this->assertSame(1, $first->currentPage());
        $this->assertSame(2, $second->currentPage());
        $this->assertSame($first->total(), $second->total());
        $this->assertCount($first->perPage(), $first->items());
        $this->assertCount($second->perPage(), $second->items());
        $this->assertSame([], array_values(array_intersect(array_column($first->items(), $identity), array_column($second->items(), $identity))), 'The two pages share no row.');
        $this->assertSame(route($route, [...$kept, 'page' => 1]), $second->previousPageUrl());
        $this->assertSame(route($route, [...$kept, 'page' => 3]), $second->nextPageUrl());
    }

    /** Route, paginator key, a field that identifies a row, a private value of the other Event, the query its links keep. */
    public static function listPages(): array
    {
        return [
            'guest list' => ['admin.guests.index', 'guests', 'id', 'Foreign private Guest', ['kept' => 'frozen']],
            'qr list' => ['admin.qr-codes.index', 'guests', 'id', 'Foreign private Guest', ['kept' => 'frozen']],
            'check-in list' => ['admin.checkins.index', 'checkins', 'gate_name', 'Foreign private gate', ['kept' => 'frozen']],
            'notification log list' => ['admin.notification-logs.index', 'logs', 'provider_message_id', 'foreign-provider-id', []],
        ];
    }

    #[DataProvider('guestListReads')]
    public function test_a_page_changed_during_the_guest_list_read_counts_history_for_the_captured_page(string $tables): void
    {
        $this->fixture();
        $this->manyGuests(110);
        $pageOne = array_column($this->get(route('admin.guests.index', ['page' => 1]))->assertOk()->original->getData()['guests']->items(), 'id');
        $pageTwo = array_column($this->get(route('admin.guests.index', ['page' => 2]))->assertOk()->original->getData()['guests']->items(), 'id');
        $this->historyFor($pageOne, 89);
        $this->historyFor($pageTwo, 5);
        $control = $this->get(route('admin.guests.index', ['page' => 2]))->assertOk()->original->getData()['guests'];
        $this->assertSame(5, array_sum(array_column($control->items(), 'checkins_count')), 'A real second-page request counts its own Guests.');
        $flip = $this->flipPageDuringTheRead($tables);

        $guests = $this->get(route('admin.guests.index', ['page' => 1]))->assertOk()->original->getData()['guests'];

        $this->assertTrue($flip->fired, 'The page changed inside the read attempt.');
        $this->assertSame(89, array_sum(array_column($guests->items(), 'checkins_count')), 'History counts belong to the captured page.');
        $this->assertSame(1, $guests->currentPage());
        $this->assertSame($pageOne, array_column($guests->items(), 'id'));
    }

    public static function guestListReads(): array
    {
        return ['at the first Guest read' => ['guests'], 'at the first history read' => ['checkins']];
    }

    private function manyGuests(int $count): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['id' => 10000 + $i, 'event_id' => 301, 'name' => 'Bulk synthetic Guest '.$i, 'phone_number' => '+25572'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'manual_code' => str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT), 'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1, 'used_entries' => 0,
                'status' => Guest::STATUS_ACTIVE, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('guests')->insert($rows);
    }

    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['name', 'phone_number', 'pass_type', 'allowed_entries'], null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }
        $path = $this->app->storagePath('synthetic-'.bin2hex(random_bytes(6)).'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'guests.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function fixture(): void
    {
        foreach ([301, 302] as $id) {
            Event::factory()->create(['id' => $id, 'workspace_id' => null,
                'event_name' => 'Synthetic bundle Event '.$id, 'status' => Event::STATUS_ACTIVE]);
        }
        $actor = User::factory()->create(['id' => 211, 'name' => 'Synthetic bundle Administrator',
            'email' => 'bundle-admin@example.invalid', 'role' => User::ROLE_ADMIN,
            'is_active' => true, 'is_platform_admin' => false, 'preferred_language' => 'en']);
        foreach ([212 => [301, 'Selected ordinary Scanner'], 213 => [302, 'Foreign private Scanner']] as $id => [$eventId, $name]) {
            User::factory()->create(['id' => $id, 'event_id' => $eventId, 'name' => $name,
                'email' => 'bundle-scanner-'.$id.'@example.invalid', 'role' => User::ROLE_SCANNER,
                'is_active' => true, 'is_platform_admin' => false, 'gate_name' => 'Synthetic scanner gate']);
        }
        foreach ([901 => [301, 'Selected ordinary Guest', Guest::STATUS_ACTIVE, 0], 902 => [301, 'Selected unused Guest', Guest::STATUS_ACTIVE, 0],
            903 => [301, 'Selected cancelled Guest', Guest::STATUS_CANCELLED, 1], 904 => [302, 'Foreign private Guest', Guest::STATUS_ACTIVE, 0]] as $id => [$eventId, $name, $status, $used]) {
            Guest::query()->forceCreate(['id' => $id, 'event_id' => $eventId, 'name' => $name,
                'phone_number' => '+255711111'.$id, 'manual_code' => '0'.$id, 'pass_type' => Guest::PASS_DOUBLE,
                'allowed_entries' => 2, 'used_entries' => $used, 'status' => $status]);
        }
        foreach ([901, 904] as $guestId) {
            QrCode::query()->forceCreate(['guest_id' => $guestId, 'qr_token' => 'synthetic-full-qr-token-'.$guestId,
                'qr_image_path' => null, 'is_active' => true, 'generated_at' => now(), 'revoked_at' => null]);
        }
        $this->checkin(301, 901, 212, 'Selected gate');
        $this->checkin(301, 901, 212, 'Selected gate', Checkin::RESULT_INVALID);
        $this->checkin(302, 904, 213, 'Foreign private gate');
        foreach ([[301, 901, 'selected-provider-id'], [302, 904, 'foreign-provider-id']] as [$eventId, $guestId, $providerId]) {
            NotificationLog::query()->create(['event_id' => $eventId, 'guest_id' => $guestId,
                'channel' => NotificationLog::CHANNEL_WHATSAPP, 'message_type' => NotificationLog::TYPE_GUEST_CODE,
                'template_name' => 'guest_code', 'recipient_phone' => '+255711111901',
                'message' => 'synthetic-unrendered-message', 'status' => NotificationLog::STATUS_SKIPPED,
                'provider' => NotificationLog::PROVIDER_META, 'provider_message_id' => $providerId,
                'error_message' => 'Synthetic error', 'context' => ['access_token' => 'synthetic-context-secret']]);
        }
        DB::table('offline_scanner_sessions')->insert(['id' => 701, 'session_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'event_id' => 302,
            'created_by_user_id' => 211, 'status' => 'cancelled', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($actor)->withSession(['selected_event_id' => 301]);
    }

    private function checkin(int $eventId, int $guestId, int $userId, string $gate, string $result = Checkin::RESULT_ADMITTED): void
    {
        Checkin::query()->create(['event_id' => $eventId, 'guest_id' => $guestId,
            'qr_code_id' => null, 'user_id' => $userId, 'gate_name' => $gate,
            'entries_added' => 1, 'used_entries_after_scan' => 1, 'remaining_entries_after_scan' => 1,
            'scan_result' => $result, 'device_info' => 'Synthetic bundle fixture',
            'ip_address' => '127.0.0.1', 'checked_in_at' => now()]);
    }

    private function flipSelectionAfterFirstPrivateRead(): void
    {
        $state = new stdClass;
        $state->fired = false;
        DB::listen(static function ($query) use ($state): void {
            if (! $state->fired && preg_match('/from "(guests|qr_codes|checkins|notification_logs)"/', $query->sql) === 1) {
                $state->fired = true;
                session(['selected_event_id' => 302]);
            }
        });
    }

    /**
     * The import page reads no private table, so this flip waits for the attempt's first
     * Event read. Event 302 is workspace-owned: authority for it would refuse outright.
     */
    private function flipSelectionToAnOwnedEventDuringTheImportRead(): stdClass
    {
        DB::table('events')->where('id', 302)->update(['workspace_id' => DB::table('workspaces')->insertGetId(['name' => 'Synthetic private workspace', 'created_at' => now(), 'updated_at' => now()])]);
        $state = new stdClass;
        $state->fired = false;
        DB::listen(static function ($query) use ($state): void {
            if (! $state->fired && DB::connection()->transactionLevel() > 0 && str_contains($query->sql, 'from "events"')) {
                $state->fired = true;
                session(['selected_event_id' => 302]);
            }
        });

        return $state;
    }

    /** Changes the request's page, query string and path at the first matching read inside the attempt. */
    private function flipPageDuringTheRead(string $tables = 'guests|qr_codes|checkins|notification_logs'): stdClass
    {
        $state = new stdClass;
        $state->fired = false;
        DB::listen(static function ($query) use ($state, $tables): void {
            if (! $state->fired && DB::connection()->transactionLevel() > 0 && preg_match('/from "('.$tables.')"/', $query->sql) === 1) {
                $state->fired = true;
                request()->query->set('page', 2);
                request()->query->set('retargeted', 'yes');
                Paginator::currentPathResolver(static fn (): string => 'http://retargeted.invalid/elsewhere');
            }
        });

        return $state;
    }

    /** At least three pages on every list (50 Guests, 15 QR rows, 20 check-ins, 20 logs a page), each row identifiable. */
    private function threePagesOnEveryList(): void
    {
        $this->manyGuests(110);
        DB::table('checkins')->where('event_id', 301)->delete();
        for ($i = 1; $i <= 45; $i++) {
            $this->checkin(301, 901, 212, 'Bulk gate '.$i);
            NotificationLog::query()->create(['event_id' => 301, 'guest_id' => 901,
                'channel' => NotificationLog::CHANNEL_WHATSAPP, 'message_type' => NotificationLog::TYPE_GUEST_CODE,
                'template_name' => 'guest_code', 'recipient_phone' => '+255711111901',
                'message' => 'synthetic-unrendered-message', 'status' => NotificationLog::STATUS_SKIPPED,
                'provider' => NotificationLog::PROVIDER_META, 'provider_message_id' => 'bulk-provider-id-'.$i,
                'error_message' => 'Synthetic error', 'context' => []]);
        }
    }

    /** Tops the selected-Event history of these Guests up to exactly this many attempts. */
    private function historyFor(array $guestIds, int $total): void
    {
        for ($i = Checkin::query()->where('event_id', 301)->whereIn('guest_id', $guestIds)->count(); $i < $total; $i++) {
            $this->checkin(301, $guestIds[$i % count($guestIds)], 212, 'Selected gate');
        }
    }

    private function savedImportResult(): array
    {
        return ['summary' => ['total_rows' => 1, 'imported_successfully' => 1,
            'imported_new_contacts' => 1, 'duplicates_added' => 0, 'duplicates_skipped' => 0,
            'invalid_rows' => 0, 'failed_rows' => 0, 'generated_qr_codes' => 0],
            'imported' => [['row_number' => 2, 'name' => 'Saved result private contact',
                'phone_number' => '+255711119302', 'pass_type' => Guest::PASS_SINGLE,
                'allowed_entries' => '1', 'reason' => 'Imported', 'guest_id' => 904, 'manual_code' => 'Saved-result-code']],
            'added_duplicates' => [], 'skipped_duplicates' => [], 'invalid' => [], 'failed' => []];
    }

    private function countTransactions(): stdClass
    {
        $state = new stdClass;
        $state->count = 0;
        $this->app['events']->listen(TransactionBeginning::class, static function () use ($state): void {
            $state->count++;
        });

        return $state;
    }

    /** Private rows read outside the attempt, from a replica or without the shared lock. */
    private function unprotectedPrivateReads(AdminReadInspectingGrammar $grammar, bool $routeBinding): array
    {
        return array_values(array_filter($this->privateReads($grammar, $routeBinding),
            static fn (array $read): bool => $read['level'] < 1 || $read['write'] !== true || $read['lock'] !== false));
    }

    /** The route's own {guest} binding precedes the controller and reaches no response; it is excluded. */
    private function privateReads(AdminReadInspectingGrammar $grammar, bool $routeBinding): array
    {
        $reads = array_values(array_filter($grammar->reads, static fn (array $read): bool => in_array($read['table'], self::PRIVATE_TABLES, true)));
        if ($routeBinding && ($reads[0]['table'] ?? null) === 'guests' && $reads[0]['level'] === 0) {
            array_shift($reads);
        }

        return $reads;
    }

    /** @return list<string> paths of every Eloquent model reachable from the view data */
    private function modelsIn(mixed $value, string $path = 'view'): array
    {
        if ($value instanceof Model) {
            return [$path.' => '.$value::class];
        }
        if ($value instanceof AbstractPaginator) {
            $value = $value->items();
        } elseif ($value instanceof Collection) {
            $value = $value->all();
        } elseif (is_object($value) && str_starts_with($value::class, 'App\\')) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return [];
        }
        $found = [];
        foreach ($value as $key => $item) {
            $found = [...$found, ...$this->modelsIn($item, $path.'.'.$key)];
        }

        return $found;
    }

    private function flatten(mixed $value): string
    {
        if ($value instanceof Model) {
            return json_encode($value->attributesToArray() + ['relations' => $this->flatten($value->getRelations())]);
        }
        if ($value instanceof AbstractPaginator) {
            $value = $value->items();
        } elseif ($value instanceof Collection) {
            $value = $value->all();
        } elseif (is_object($value) && str_starts_with($value::class, 'App\\')) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return is_scalar($value) ? (string) $value : '';
        }

        return implode('|', array_map(fn (mixed $item): string => $this->flatten($item), $value));
    }

    private function inspectingGrammar(): AdminReadInspectingGrammar
    {
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $grammar = new AdminReadInspectingGrammar($db);
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

/** SQLite discards locks; the grammar reports builder intent, never actual lock-wait proof. */
final class AdminReadInspectingGrammar extends SQLiteGrammar
{
    public bool $observing = true;

    public array $reads = [];

    public function compileSelect(Builder $query)
    {
        $sql = parent::compileSelect($query);
        if ($this->observing) {
            $this->reads[] = ['table' => is_string($query->from) ? $query->from : '', 'write' => $query->useWritePdo, 'lock' => $query->lock,
                'aggregate' => $query->aggregate, 'order' => $query->orders, 'index' => $query->indexHint?->index, 'level' => DB::connection()->transactionLevel()];
        }

        return $sql;
    }
}
