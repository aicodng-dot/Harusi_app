<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P4-047 read/export boundary regressions: selected-Event history decoration,
 * relation-filter lineage, null-Event log exclusion, actor/Event-bound import
 * results, offline-banner creator/package decoration and CSV/XLSX bytes frozen
 * before the response callback runs.
 * No RefreshDatabase: actual memory/PDO/autocommit/FK checks precede migrate:fresh.
 * Media/private storage, layout, MySQL and full-matrix coverage remain open.
 */
class LegacyReadExportBoundaryTest extends TestCase
{
    private FilesystemAdapter $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new LogicException('Read boundary fixtures require isolated in-memory SQLite.');
        }
        $pdo = $db->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new LogicException('Read boundary fixtures require actual SQLite PDO.');
        }
        $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool => $row['name'] === 'main'));
        if (count($main) !== 1 || $main[0]['file'] !== '' || $db->transactionLevel() !== 0 || $pdo->inTransaction()
            || (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new LogicException('Read boundary fixtures require memory main, enabled FKs and actual autocommit0.');
        }

        $temporaryRoot = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'harusi-read-boundary-'.bin2hex(random_bytes(8));
        (new Filesystem)->makeDirectory($temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views', 0777, true);
        $this->app->useStoragePath($temporaryRoot);
        config(['view.compiled' => $temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            'logging.channels.single.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log',
            'logging.channels.daily.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log',
            'mail.default' => 'array', 'cache.default' => 'array', 'session.driver' => 'array',
            'queue.default' => 'sync', 'services.whatsapp.provider' => 'null']);
        $this->beforeApplicationDestroyed(static function () use ($db, $temporaryRoot): void {
            $db->disconnect();
            $resolved = realpath($temporaryRoot);
            $prefix = rtrim((string) realpath(sys_get_temp_dir()), '/\\').DIRECTORY_SEPARATOR.'harusi-read-boundary-';
            if ($resolved !== false && str_starts_with(strtolower($resolved), strtolower($prefix))) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        });
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
        $this->freezeTime();
        $this->withoutVite();
        $this->disk = Storage::fake('public');
        $fakeRoot = realpath($this->disk->path(''));
        if ($fakeRoot === false || ! str_starts_with(strtolower($fakeRoot), strtolower($temporaryRoot.DIRECTORY_SEPARATOR))) {
            throw new LogicException('The public fake must stay inside guarded TEMP storage.');
        }
        $this->disk->put('sentinels/ordinary-read.txt', 'Synthetic unchanged read sentinel.');
    }

    public function test_selected_event_history_does_not_decorate_a_foreign_current_guest(): void
    {
        $this->fixture();
        $this->checkin(301, 902, 212, 'Retained selected attempt');

        $this->read(fn () => $this->get(route('admin.checkins.index')))
            ->assertOk()
            ->assertSee('Retained selected attempt')
            ->assertDontSee('Foreign private Guest')
            ->assertDontSee('+255711111902')
            ->assertSee('Unknown QR');
    }

    public function test_selected_event_history_does_not_decorate_a_moved_current_scanner(): void
    {
        $this->fixture();
        $this->checkin(301, 901, 213, 'Retained operator attempt');

        $this->read(fn () => $this->get(route('admin.checkins.index')))
            ->assertOk()
            ->assertSee('Selected ordinary Guest')
            ->assertSee('Retained operator attempt')
            ->assertDontSee('Foreign private Scanner');
    }

    public function test_foreign_guest_name_cannot_select_attributable_checkins_through_search(): void
    {
        $this->fixture();
        $this->checkin(301, 902, 212, 'Selected searchable attempt');

        // Gate options may legitimately retain historical gate names; assert
        // actual filtered row membership, not absence of that repeated label.
        $this->read(fn () => $this->get(route('admin.checkins.index', ['search' => 'Foreign private Guest'])))
            ->assertOk()
            ->assertViewHas('checkins', static fn ($checkins): bool => $checkins->total() === 0);
    }

    public function test_guest_detail_excludes_history_attributed_to_a_different_event(): void
    {
        $this->fixture();
        $this->checkin(301, 901, 212, 'Own retained guest activity');
        $this->checkin(302, 901, 213, 'Foreign attributed guest activity');

        $this->read(fn () => $this->get(route('admin.guests.show', 901)))
            ->assertOk()
            ->assertSee('Own retained guest activity')
            ->assertDontSee('Foreign attributed guest activity');
    }

    public function test_guest_list_history_count_requires_both_guest_and_event(): void
    {
        $this->fixture();
        $this->checkin(301, 901, 212, 'Own counted attempt');
        $this->checkin(302, 901, 213, 'Foreign uncounted attempt');

        $this->read(fn () => $this->get(route('admin.guests.index')))
            ->assertOk()
            ->assertViewHas('guests', static function ($guests): bool {
                $guest = $guests->getCollection()->firstWhere('id', 901);

                return $guest !== null && (int) $guest->checkins_count === 1;
            });
    }

    public function test_null_event_notification_payload_is_not_selected_event_data(): void
    {
        $this->fixture();
        $this->notification(301, 901, 'selected-visible-provider-id', 'Selected visible error');
        $this->notification(null, null, 'null-event-private-provider-id', 'Null event private error');

        $this->read(fn () => $this->get(route('admin.notification-logs.index')))
            ->assertOk()
            ->assertSee('selected-visible-provider-id')
            ->assertDontSee('null-event-private-provider-id')
            ->assertDontSee('Null event private error');
    }

    public function test_unbound_saved_import_result_is_withheld_on_later_get(): void
    {
        $this->fixture();
        $this->withSession(['guest_import.result' => $this->unboundImportResult()]);

        // Deliberately no actor/Event sidecar: no dependency on a not-yet-chosen
        // future session-key spelling. An unbound legacy payload must be hidden.
        $this->read(fn () => $this->get(route('admin.guests.import')))
            ->assertOk()
            ->assertDontSee('Unbound private imported contact')
            ->assertDontSee('+255711119999')
            ->assertDontSee('8736');
    }

    public function test_bound_saved_import_result_is_shown_only_for_its_actor_and_event(): void
    {
        $this->fixture();
        $this->withSession(['guest_import.result' => $this->unboundImportResult(),
            'guest_import.result_context' => ['actor_id' => 211, 'event_id' => 301]]);

        $this->read(fn () => $this->get(route('admin.guests.import')))
            ->assertOk()
            ->assertSee('Unbound private imported contact');

        $this->withSession(['selected_event_id' => 302, 'guest_import.result' => $this->unboundImportResult(),
            'guest_import.result_context' => ['actor_id' => 211, 'event_id' => 301]]);
        $this->read(fn () => $this->get(route('admin.guests.import')))
            ->assertOk()
            ->assertDontSee('Unbound private imported contact');
        $this->assertNull(session('guest_import.result'));
    }

    public function test_guest_csv_is_frozen_before_a_later_session_retarget(): void
    {
        $this->fixture();
        $response = $this->read(fn () => $this->get(route('admin.reports.export.guest-list')));
        $response->assertOk()->assertDownload();

        session()->put('selected_event_id', 302);
        $csv = $this->read(fn () => $response->streamedContent());

        $this->assertStringContainsString('Selected ordinary Guest', $csv);
        $this->assertStringNotContainsString('Foreign private Guest', $csv);
    }

    public function test_checkin_csv_callback_does_not_run_late_private_sql(): void
    {
        $this->fixture();
        $this->checkin(301, 901, 212, 'Ordinary frozen CSV gate');
        $response = $this->read(fn () => $this->get(route('admin.checkins.export')));
        $response->assertOk()->assertDownload();

        $before = $this->snapshot();
        $lateQueries = [];
        $capturing = true;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$lateQueries, &$capturing): void {
            if ($capturing) {
                $lateQueries[] = $sql;
            }
        });
        try {
            $csv = $response->streamedContent();
        } finally {
            $capturing = false;
            $this->assertSame($before, $this->snapshot());
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        }

        $this->assertStringContainsString('Selected ordinary Guest', $csv);
        $this->assertStringContainsString('Ordinary frozen CSV gate', $csv);
        $this->assertSame([], $lateQueries, 'The response callback must emit prepared bytes without SQL.');
    }

    public function test_dashboard_gate_activity_does_not_decorate_a_foreign_current_guest(): void
    {
        $this->fixture();
        $this->checkin(301, 902, 212, 'Dashboard retained attempt');

        $this->read(fn () => $this->get(route('admin.dashboard')))
            ->assertOk()
            ->assertSee('Dashboard retained attempt')
            ->assertDontSee('Foreign private Guest')
            ->assertSee('Unknown QR');
    }

    public function test_invalid_scan_csv_keeps_history_scalars_without_foreign_live_profiles(): void
    {
        $this->fixture();
        $this->checkin(301, 902, 213, 'Retained invalid gate', Checkin::RESULT_INVALID);
        $response = $this->read(fn () => $this->get(route('admin.reports.export.invalid-scans')));
        $response->assertOk()->assertDownload();
        $csv = $this->read(fn () => $response->streamedContent());
        $lines = preg_split('/\r?\n/', trim($csv));
        $row = str_getcsv($lines[1], ',', '"', '');

        $this->assertCount(2, $lines);
        $this->assertSame(['Unknown QR', '', ''], array_slice($row, 1, 3));
        $this->assertSame(['invalid', 'Retained invalid gate', '', '127.0.0.1'], array_slice($row, 4));
        $this->assertStringNotContainsString('Foreign private', $csv);
    }

    public function test_history_operator_names_follow_the_exact_scanner_classification(): void
    {
        $this->fixture();
        $hidden = [214 => 'Platform flagged private Scanner', 215 => 'Workspace member private Scanner',
            216 => 'Role changed private Operator'];
        foreach ([214 => [User::ROLE_SCANNER, true, true], 215 => [User::ROLE_SCANNER, false, true],
            216 => [User::ROLE_ADMIN, false, true], 217 => [User::ROLE_SCANNER, false, false]] as $id => [$role, $platform, $active]) {
            User::factory()->create(['id' => $id, 'event_id' => 301, 'name' => $hidden[$id] ?? 'Inactive retained Scanner',
                'email' => 'read-operator-'.$id.'@example.invalid', 'role' => $role,
                'is_active' => $active, 'is_platform_admin' => $platform, 'gate_name' => 'Synthetic scanner gate']);
            $this->checkin(301, 901, $id, 'Operator gate '.$id);
        }
        $workspace = DB::table('workspaces')->insertGetId(['name' => 'Synthetic operator workspace', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => $workspace, 'user_id' => 215, 'role' => 'manager',
            'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);

        $page = $this->read(fn () => $this->get(route('admin.checkins.index')))->assertOk()->assertSee('Inactive retained Scanner');
        $response = $this->read(fn () => $this->get(route('admin.checkins.export')));
        $csv = $this->read(fn () => $response->streamedContent());
        $this->assertStringContainsString('Inactive retained Scanner', $csv);
        foreach ($hidden as $name) {
            $page->assertDontSee($name);
            $this->assertStringNotContainsString($name, $csv);
        }
        foreach ([214, 215, 216] as $id) {
            $page->assertSee('Operator gate '.$id);
        }
    }

    #[DataProvider('unboundResultContexts')]
    public function test_saved_import_result_requires_the_exact_actor_and_event_binding(mixed $context): void
    {
        $this->fixture();
        $session = ['guest_import.result' => $this->unboundImportResult()];
        if ($context !== 'absent') {
            $session['guest_import.result_context'] = $context;
        }
        $this->withSession($session);

        $this->read(fn () => $this->get(route('admin.guests.import')))
            ->assertOk()
            ->assertDontSee('Unbound private imported contact')
            ->assertDontSee('+255711119999')
            ->assertSee(__('guest_issuance.result_withheld', [], 'en'));
        $this->assertNull(session('guest_import.result'));
        $this->assertNull(session('guest_import.result_context'));
    }

    public static function unboundResultContexts(): array
    {
        return ['absent context' => ['absent'], 'another actor' => [['actor_id' => 999, 'event_id' => 301]],
            'padded actor' => [['actor_id' => '0211', 'event_id' => 301]], 'missing actor' => [['event_id' => 301]],
            'non-array context' => ['211:301']];
    }

    #[DataProvider('linkedActors')]
    public function test_offline_banner_names_a_platform_or_workspace_linked_actor_as_their_own_creator(string $link): void
    {
        $this->fixture();
        if ($link === 'platform') {
            DB::table('users')->where('id', 211)->update(['is_platform_admin' => true]);
        } else {
            $workspace = DB::table('workspaces')->insertGetId(['name' => 'Synthetic actor workspace', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('workspace_users')->insert(['workspace_id' => $workspace, 'user_id' => 211, 'role' => 'owner',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->offlineSession(301, 211);

        $this->read(fn () => $this->get(route('admin.guests.index')))
            ->assertOk()
            ->assertSee('Offline Android scanner session is active')
            ->assertSeeInOrder(['Created by', 'Synthetic read Administrator']);
    }

    public static function linkedActors(): array
    {
        return ['platform-flagged actor' => ['platform'], 'workspace-linked actor' => ['workspace']];
    }

    public function test_qr_list_workbook_bytes_are_frozen_before_the_response_callback(): void
    {
        $this->fixture();
        $response = $this->read(fn () => $this->get(route('admin.qr-codes.export')));
        $response->assertOk()->assertDownload();
        $callback = $response->baseResponse->getCallback();

        $before = $this->snapshot();
        $lateQueries = [];
        $capturing = true;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$lateQueries, &$capturing): void {
            if ($capturing) {
                $lateQueries[] = $sql;
            }
        });
        try {
            $first = $this->emit($callback);
            $second = $this->emit($callback);
        } finally {
            $capturing = false;
            $this->assertSame($before, $this->snapshot());
        }

        $this->assertSame([], $lateQueries, 'The workbook callback must emit prepared bytes without SQL.');
        $this->assertStringStartsWith('PK', $first);
        $this->assertSame($first, $second, 'The callback re-emits frozen bytes instead of serializing a live workbook.');
        $path = $this->app->storagePath('qr-list-boundary.xlsx');
        $this->assertSame(strlen($first), file_put_contents($path, $first));
        $spreadsheet = IOFactory::createReader('Xlsx')->load($path);
        try {
            $sheet = $spreadsheet->getSheetByName('QR Codes');
            $this->assertNotNull($sheet);
            $this->assertSame('Selected ordinary Guest', $sheet->getCell('B2')->getValue());
            $this->assertSame(2, $sheet->getHighestDataRow());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_offline_banner_creator_label_requires_actor_or_eligible_legacy_administrator(): void
    {
        $this->fixture();
        $session = $this->offlineSession(301, 211);
        $this->read(fn () => $this->get(route('admin.guests.index')))
            ->assertOk()
            ->assertSee('Offline Android scanner session is active')
            ->assertSeeInOrder(['Created by', 'Synthetic read Administrator']);

        foreach ([214 => ['Workspace-linked private Administrator', false, true],
            215 => ['Platform private Administrator', true, true],
            216 => ['Inactive legacy Administrator', false, false]] as $id => [$name, $platform, $active]) {
            User::factory()->create(['id' => $id, 'name' => $name, 'email' => 'read-creator-'.$id.'@example.invalid',
                'role' => User::ROLE_ADMIN, 'is_active' => $active, 'is_platform_admin' => $platform]);
        }
        $workspace = DB::table('workspaces')->insertGetId(['name' => 'Synthetic workspace', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => $workspace, 'user_id' => 214, 'role' => 'manager',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        foreach ([214 => 'Workspace-linked private Administrator', 215 => 'Platform private Administrator',
            212 => 'Selected ordinary Scanner'] as $creatorId => $name) {
            DB::table('offline_scanner_sessions')->where('id', $session)->update(['created_by_user_id' => $creatorId]);
            $this->read(fn () => $this->get(route('admin.guests.index')))
                ->assertOk()
                ->assertSeeInOrder(['Created by', 'Unknown user'])
                ->assertDontSee($name);
        }

        // An inactive but otherwise eligible legacy administrator stays a historical label.
        DB::table('offline_scanner_sessions')->where('id', $session)->update(['created_by_user_id' => 216]);
        $this->read(fn () => $this->get(route('admin.guests.index')))
            ->assertOk()
            ->assertSeeInOrder(['Created by', 'Inactive legacy Administrator']);
    }

    public function test_offline_banner_package_time_requires_the_package_to_link_back_to_the_session(): void
    {
        $this->fixture();
        $selected = $this->offlineSession(301, 211);
        $foreignPackage = $this->offlinePackage($this->offlineSession(302, 211, 'closed'));
        DB::table('offline_scanner_sessions')->where('id', $selected)->update(['package_export_id' => $foreignPackage]);

        $this->read(fn () => $this->get(route('admin.guests.index')))
            ->assertOk()
            ->assertSee('Offline Android scanner session is active')
            ->assertSeeInOrder(['Package exported', 'Not recorded'])
            ->assertDontSee('Mar 4, 2026 5:06 AM');

        $ownPackage = $this->offlinePackage($selected);
        DB::table('offline_scanner_sessions')->where('id', $selected)->update(['package_export_id' => $ownPackage]);
        $this->read(fn () => $this->get(route('admin.guests.index')))
            ->assertOk()
            ->assertSeeInOrder(['Package exported', 'Mar 4, 2026 5:06 AM']);
    }

    public function test_ordinary_selected_checkin_preserves_live_labels_and_history(): void
    {
        $this->fixture();
        $this->checkin(301, 901, 212, 'Ordinary selected gate');
        $this->checkin(302, 902, 213, 'Foreign event gate');

        $this->read(fn () => $this->get(route('admin.checkins.index')))
            ->assertOk()
            ->assertSee('Selected ordinary Guest')
            ->assertSee('+255711111901')
            ->assertSee('Selected ordinary Scanner')
            ->assertSee('Ordinary selected gate')
            ->assertSee('admitted')
            ->assertDontSee('Foreign event gate')
            ->assertDontSee('Foreign private Guest');
    }

    public function test_ordinary_filtered_checkin_csv_preserves_columns_and_values(): void
    {
        $this->fixture();
        $this->checkin(301, 901, 212, 'Ordinary CSV gate');
        $this->checkin(302, 902, 213, 'Foreign CSV gate');
        $response = $this->read(fn () => $this->get(route('admin.checkins.export', ['gate_name' => 'Ordinary CSV gate'])));
        $response->assertOk()->assertDownload()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $this->read(fn () => $response->streamedContent());
        $lines = preg_split('/\r?\n/', trim($csv));
        $headings = str_getcsv($lines[0], ',', '"', '');
        $row = str_getcsv($lines[1], ',', '"', '');

        $this->assertSame(['Time', 'Guest name', 'Phone number', 'Pass type', 'Entries added',
            'Used entries after scan', 'Remaining entries after scan', 'Scan result', 'Gate name', 'Scanner user', 'IP address'], $headings);
        $this->assertCount(2, $lines);
        $this->assertSame('Selected ordinary Guest', $row[1]);
        $this->assertSame("\t+255711111901", $row[2], 'Existing TAB formula neutralization remains intact.');
        $this->assertSame(['1', '1', '1', 'admitted', 'Ordinary CSV gate', 'Selected ordinary Scanner', '127.0.0.1'], array_slice($row, 4));
        $this->assertStringNotContainsString('Foreign private Guest', $csv);
    }

    public function test_selected_notification_keeps_visible_scalars_and_omits_secrets(): void
    {
        $this->fixture();
        $this->notification(301, 901, 'ordinary-visible-provider-id', 'Ordinary visible error');

        $this->read(fn () => $this->get(route('admin.notification-logs.index')))
            ->assertOk()
            ->assertSee('Selected ordinary Guest')
            ->assertSee('ordinary-visible-provider-id')
            ->assertSee('Ordinary visible error')
            ->assertDontSee('synthetic-context-secret')
            ->assertDontSee('synthetic-unrendered-message');
    }

    private function fixture(): void
    {
        foreach ([301, 302] as $id) {
            Event::factory()->create(['id' => $id, 'workspace_id' => null,
                'event_name' => 'Synthetic read Event '.$id, 'status' => Event::STATUS_ACTIVE]);
        }
        $actor = User::factory()->create(['id' => 211, 'name' => 'Synthetic read Administrator',
            'email' => 'read-admin@example.invalid', 'role' => User::ROLE_ADMIN,
            'is_active' => true, 'is_platform_admin' => false, 'preferred_language' => 'en']);
        foreach ([212 => [301, 'Selected ordinary Scanner'], 213 => [302, 'Foreign private Scanner']] as $id => [$eventId, $name]) {
            User::factory()->create(['id' => $id, 'event_id' => $eventId, 'name' => $name,
                'email' => 'read-scanner-'.$id.'@example.invalid', 'role' => User::ROLE_SCANNER,
                'is_active' => true, 'is_platform_admin' => false, 'gate_name' => 'Synthetic scanner gate']);
        }
        foreach ([901 => [301, 'Selected ordinary Guest'], 902 => [302, 'Foreign private Guest']] as $id => [$eventId, $name]) {
            Guest::query()->forceCreate(['id' => $id, 'event_id' => $eventId, 'name' => $name,
                'phone_number' => '+255711111'.$id, 'manual_code' => $id === 901 ? '0047' : '0053',
                'pass_type' => Guest::PASS_DOUBLE, 'allowed_entries' => 2, 'used_entries' => 0,
                'status' => Guest::STATUS_ACTIVE]);
        }
        $this->actingAs($actor)->withSession(['selected_event_id' => 301]);
    }

    private function checkin(int $eventId, int $guestId, int $userId, string $gate, string $result = Checkin::RESULT_ADMITTED): void
    {
        Checkin::query()->create(['event_id' => $eventId, 'guest_id' => $guestId,
            'qr_code_id' => null, 'user_id' => $userId, 'gate_name' => $gate,
            'entries_added' => 1, 'used_entries_after_scan' => 1, 'remaining_entries_after_scan' => 1,
            'scan_result' => $result, 'device_info' => 'Synthetic read fixture',
            'ip_address' => '127.0.0.1', 'checked_in_at' => now()]);
    }

    private function offlineSession(int $eventId, ?int $creatorId, string $status = 'active'): int
    {
        return (int) DB::table('offline_scanner_sessions')->insertGetId([
            'session_uuid' => (string) Str::uuid(), 'event_id' => $eventId,
            'created_by_user_id' => $creatorId, 'status' => $status,
            'package_created_at' => null, 'package_expires_at' => null,
            'created_at' => now(), 'updated_at' => now()]);
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

    private function emit(Closure $callback): string
    {
        ob_start();
        try {
            $callback();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function notification(?int $eventId, ?int $guestId, string $providerId, string $error): void
    {
        NotificationLog::query()->create(['event_id' => $eventId, 'guest_id' => $guestId,
            'channel' => NotificationLog::CHANNEL_WHATSAPP, 'message_type' => NotificationLog::TYPE_GUEST_CODE,
            'template_name' => 'guest_code', 'recipient_phone' => '+255711111901',
            'message' => 'synthetic-unrendered-message', 'status' => NotificationLog::STATUS_SKIPPED,
            'provider' => NotificationLog::PROVIDER_META, 'provider_message_id' => $providerId,
            'error_message' => $error, 'context' => ['access_token' => 'synthetic-context-secret']]);
    }

    private function unboundImportResult(): array
    {
        return ['summary' => ['total_rows' => 1, 'imported_successfully' => 1,
            'imported_new_contacts' => 1, 'duplicates_added' => 0, 'duplicates_skipped' => 0,
            'invalid_rows' => 0, 'failed_rows' => 0, 'generated_qr_codes' => 0],
            'imported' => [['row_number' => 2, 'name' => 'Unbound private imported contact',
                'phone_number' => '+255711119999', 'pass_type' => Guest::PASS_SINGLE,
                'allowed_entries' => '1', 'reason' => 'Imported', 'guest_id' => 902, 'manual_code' => '8736']],
            'added_duplicates' => [], 'skipped_duplicates' => [], 'invalid' => [], 'failed' => []];
    }

    /** Observe no database/table or fake-public-file mutation around actual reads. */
    private function read(Closure $operation): mixed
    {
        $before = $this->snapshot();
        try {
            return $operation();
        } finally {
            $this->assertSame($before, $this->snapshot());
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        }
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $rows = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row): array => (array) $row)->all();
            usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            $snapshot[$table->name] = $rows;
        }
        $files = $this->disk->allFiles();
        sort($files);
        $snapshot['_fake_public_files'] = [];
        foreach ($files as $file) {
            $snapshot['_fake_public_files'][$file] = hash('sha256', $this->disk->get($file));
        }

        return $snapshot;
    }
}
