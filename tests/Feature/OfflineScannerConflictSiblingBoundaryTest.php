<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionUser;
use App\Models\QrCode;
use App\Models\User;
use App\Services\CanonicalJson;
use App\Services\OfflineScannerResults\OfflineScannerImportConflictResolver;
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use App\Services\OfflineScannerResults\OfflineScannerResultsImportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Sibling write-set integrity; same-connection hooks are controlled observations, not MySQL races. */
class OfflineScannerConflictSiblingBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const NOTES = 'Synthetic retained sibling boundary resolution.';

    private const MESSAGE = 'The conflict sibling context is invalid.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake($this->confirmationOfflineScannerDisk());
    }

    #[DataProvider('foreignOwnership')]
    public function test_preexisting_foreign_open_sibling_refuses_the_whole_http_operation_before_guest_hydration_or_dml(bool $owned): void
    {
        $f = $this->resolutionFixture();
        $foreign = $this->sibling($f, ['event_id' => ($owned ? $f['ownedNeighbor'] : $f['unownedNeighbor'])->id]);
        $before = $this->confirmationSnapshot();
        $hydrated = [];
        $queries = [];
        $response = $this->withSiblingModelHooks([
            Guest::class => static function (Guest $guest) use (&$hydrated): void {
                $hydrated[] = ['Guest', $guest->id];
            },
            QrCode::class => static function (QrCode $qr) use (&$hydrated): void {
                $hydrated[] = ['QR', $qr->id];
            },
        ], function () use ($f, &$queries) {
            return $this->siblingQueries($queries, fn () => $this->actingAs($f['admin'])
                ->withSession(['selected_event_id' => $f['event']->id, 'guest_import' => ['marker' => 'retained']])
                ->from(route('admin.offline-scanner-conflicts.show', $f['conflict']))
                ->post(route('admin.offline-scanner-conflicts.resolve', $f['conflict']), $this->siblingInput()));
        });
        $this->assertSame($before, $this->confirmationSnapshot(), 'A preexisting foreign OPEN child must cause refusal before all effects.');
        $response->assertRedirect(route('admin.offline-scanner-conflicts.show', $f['conflict']))
            ->assertSessionHasErrors(['conflict_resolution' => self::MESSAGE])->assertSessionMissing('success')
            ->assertSessionHas('selected_event_id', $f['event']->id)->assertSessionHas('guest_import.marker', 'retained');
        $this->assertSame([], $hydrated, 'Integrity refusal must precede the Guest/QR phase.');
        $this->assertSame([], $this->siblingDml($queries));
        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_OPEN, $foreign->fresh()->resolution_status);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function foreignOwnership(): array
    {
        return ['owned foreign Event' => [true], 'unowned foreign Event' => [false]];
    }

    public function test_valid_siblings_including_maximum_id_resolve_with_the_original_admission_and_history_rules(): void
    {
        $f = $this->resolutionFixture();
        $maximum = $this->sibling($f, ['id' => PHP_INT_MAX]);
        $f['conflictIds'] = $f['import']->conflicts()->where('offline_scanner_imported_log_id', $f['logId'])
            ->where('resolution_status', OfflineScannerImportConflict::RESOLUTION_OPEN)->pluck('id')->all();
        $before = $this->confirmationSnapshot();
        app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->siblingInput());
        $this->assertSame(PHP_INT_MAX, $maximum->fresh()->id);
        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_RESOLVED, $maximum->fresh()->resolution_status);
        $this->assertAdmittedResolution($f, $before, true);
    }

    public function test_resolved_foreign_history_and_other_import_or_log_records_are_not_in_the_sibling_write_set(): void
    {
        $f = $this->resolutionFixture();
        $this->sibling($f, ['event_id' => $f['ownedNeighbor']->id,
            'resolution_status' => OfflineScannerImportConflict::RESOLUTION_DISMISSED, 'resolution_notes' => 'Retained foreign resolved history']);
        $otherImport = OfflineScannerResultImport::factory()->create([
            'event_id' => $f['event']->id, 'offline_scanner_session_id' => $f['session']->id, 'uploaded_by_user_id' => $f['admin']->id,
        ]);
        $this->sibling($f, ['offline_scanner_result_import_id' => $otherImport->id, 'resolution_notes' => 'Retained other Import']);
        $otherLog = OfflineScannerImportedLog::factory()->create([
            'offline_scanner_result_import_id' => $f['import']->id, 'offline_scanner_session_id' => $f['session']->id,
            'scanner_user_id' => null, 'master_sequence_number' => 500, 'result_code' => OfflineScannerResultSchema::RESULT_REJECTED,
            'import_status' => OfflineScannerImportedLog::STATUS_REJECTED,
        ]);
        $this->sibling($f, ['offline_scanner_imported_log_id' => $otherLog->id, 'resolution_notes' => 'Retained other Log']);
        $before = $this->confirmationSnapshot();
        app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->siblingInput());
        $this->assertAdmittedResolution($f, $before, false);
    }

    public function test_null_log_keeps_other_null_log_conflicts_untouched(): void
    {
        $f = $this->resolutionFixture();
        $f['conflict']->update(['offline_scanner_imported_log_id' => null]);
        $f['conflict']->refresh();
        $other = $this->sibling($f, ['offline_scanner_imported_log_id' => null, 'resolution_notes' => 'Independent null-Log conflict']);
        $before = $this->confirmationSnapshot();
        $queries = [];
        $result = $this->siblingQueries($queries, fn () => app(OfflineScannerImportConflictResolver::class)
            ->resolve($f['conflict'], $f['admin'], $this->siblingInput(OfflineScannerImportConflict::ACTION_KEEP_SERVER_STATE)));
        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_DISMISSED, $result->resolution_status);
        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_OPEN, $other->fresh()->resolution_status);
        $this->assertSame([], $this->identitySiblingQueries($queries));
        $this->assertAllowedSiblingChanges($f, $before, [
            'offline_scanner_import_conflicts' => [[$f['conflict']->id], $this->siblingResolutionFields()],
        ], 1);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public function test_escalation_can_flag_a_valid_head_while_a_corrupt_sibling_remains_untouched(): void
    {
        $f = $this->resolutionFixture();
        $foreign = $this->sibling($f, ['event_id' => $f['ownedNeighbor']->id]);
        $before = $this->confirmationSnapshot();
        $queries = [];
        $result = $this->siblingQueries($queries, fn () => app(OfflineScannerImportConflictResolver::class)
            ->resolve($f['conflict'], $f['admin'], $this->siblingInput(OfflineScannerImportConflict::ACTION_ESCALATE_ADMIN_REVIEW)));
        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_OPEN, $result->resolution_status);
        $this->assertSame(OfflineScannerImportConflict::ACTION_ESCALATE_ADMIN_REVIEW, $result->resolution_action);
        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_OPEN, $foreign->fresh()->resolution_status);
        $this->assertSame([], $this->identitySiblingQueries($queries));
        $this->assertAllowedSiblingChanges($f, $before, [
            'offline_scanner_import_conflicts' => [[$f['conflict']->id], $this->siblingResolutionFields()],
        ], 1);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    #[DataProvider('insertedOwnership')]
    public function test_controlled_post_freeze_insert_is_not_bulk_updated_and_keeps_authoritative_readiness_open(bool $foreign): void
    {
        $f = $this->resolutionFixture();
        $before = $this->confirmationSnapshot();
        $inserted = null;
        $identityReadBeforeGuest = false;
        $queries = [];
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($f, $foreign, &$inserted, &$identityReadBeforeGuest, &$queries): void {
            if ($inserted !== null || ! preg_match('/\Aselect "id" from "guests" where "id" = \?/i', $sql)
                || ($bindings[0] ?? null) !== $f['guestOne']->id) {
                return;
            }
            $identityReadBeforeGuest = $this->identitySiblingQueries($queries) !== [];
            $inserted = [];
            $id = DB::table('offline_scanner_import_conflicts')->insertGetId([
                'offline_scanner_result_import_id' => $f['import']->id, 'offline_scanner_imported_log_id' => $f['logId'],
                'event_id' => $foreign ? $f['ownedNeighbor']->id : $f['event']->id, 'guest_id' => $f['guestOne']->id,
                'conflict_type' => OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED,
                'conflict_reason' => 'Controlled same-connection post-freeze insertion', 'resolution_status' => OfflineScannerImportConflict::RESOLUTION_OPEN,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $inserted = (array) DB::table('offline_scanner_import_conflicts')->where('id', $id)->first();
        });
        $this->siblingQueries($queries, fn () => app(OfflineScannerImportConflictResolver::class)
            ->resolve($f['conflict'], $f['admin'], $this->siblingInput()));
        $this->assertNotNull($inserted, 'The controlled insertion occurs immediately before the Guest identity query.');
        $actual = (array) DB::table('offline_scanner_import_conflicts')->where('id', $inserted['id'])->first();
        $this->assertSame($inserted, $actual, 'A child absent from the validated identity snapshot must not receive sibling resolution fields.');
        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_OPEN, $actual['resolution_status']);
        $this->assertTrue($identityReadBeforeGuest, 'The primary ordered sibling identity read must complete before Guest processing.');
        $before['offline_scanner_import_conflicts'][] = $inserted;
        $this->sortSiblingRows($before['offline_scanner_import_conflicts']);
        $this->assertAdmittedResolution($f, $before, false);
    }

    public static function insertedOwnership(): array
    {
        return ['new own-Event OPEN child' => [false], 'new foreign-Event OPEN child' => [true]];
    }

    public function test_repeated_parent_predicates_protect_a_validated_sibling_changed_by_a_controlled_late_hook(): void
    {
        $f = $this->resolutionFixture();
        $siblingId = collect($f['conflictIds'])->first(fn ($id) => $id !== $f['conflict']->id);
        $this->assertNotNull($siblingId);
        $before = $this->confirmationSnapshot();
        $changed = null;
        DB::connection()->beforeExecuting(function (string $sql) use ($f, $siblingId, &$changed): void {
            if ($changed !== null || ! preg_match('/\Aupdate "offline_scanner_import_conflicts"/i', $sql)) {
                return;
            }
            $changed = [];
            DB::table('offline_scanner_import_conflicts')->where('id', $siblingId)->update(['event_id' => $f['ownedNeighbor']->id]);
            $changed = (array) DB::table('offline_scanner_import_conflicts')->where('id', $siblingId)->first();
        });
        app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->siblingInput());
        $this->assertNotNull($changed);
        $actual = (array) DB::table('offline_scanner_import_conflicts')->where('id', $siblingId)->first();
        $this->assertSame($changed, $actual, 'Frozen IDs must still match current Event/Import/Log/open predicates at bulk update.');
        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_OPEN, $actual['resolution_status']);
        foreach ($before['offline_scanner_import_conflicts'] as &$row) {
            if ($row['id'] === $siblingId) {
                $row = $changed;
            }
        }
        unset($row);
        $this->sortSiblingRows($before['offline_scanner_import_conflicts']);
        $f['conflictIds'] = array_values(array_filter($f['conflictIds'], static fn ($id): bool => $id !== $siblingId));
        $this->assertAdmittedResolution($f, $before, false);
    }

    public function test_empty_open_sibling_set_does_not_rewrite_previously_dismissed_history(): void
    {
        $f = $this->resolutionFixture();
        $otherIds = array_values(array_filter($f['conflictIds'], fn ($id): bool => $id !== $f['conflict']->id));
        DB::table('offline_scanner_import_conflicts')->whereIn('id', $otherIds)->update([
            'resolution_status' => OfflineScannerImportConflict::RESOLUTION_DISMISSED, 'resolution_notes' => 'Retained preexisting dismissal',
        ]);
        $f['conflictIds'] = [$f['conflict']->id];
        $before = $this->confirmationSnapshot();
        app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->siblingInput());
        $this->assertAdmittedResolution($f, $before, true);
    }

    private function sibling(array $f, array $attributes = []): OfflineScannerImportConflict
    {
        return OfflineScannerImportConflict::factory()->create(array_replace([
            'offline_scanner_result_import_id' => $f['import']->id, 'offline_scanner_imported_log_id' => $f['logId'],
            'event_id' => $f['event']->id, 'guest_id' => $f['guestOne']->id,
            'conflict_type' => OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED, 'conflict_reason' => 'Synthetic retained sibling context',
            'resolution_status' => OfflineScannerImportConflict::RESOLUTION_OPEN,
        ], $attributes));
    }

    private function siblingInput(string $action = OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION): array
    {
        return ['resolution_action' => $action, 'resolution_notes' => self::NOTES, 'confirm_resolution' => '1'];
    }

    private function assertAdmittedResolution(array $f, array $before, bool $closed): void
    {
        $this->assertSame(2, $f['guestOne']->fresh()->used_entries);
        $this->assertSame(1, $f['guestTwo']->fresh()->used_entries);
        $this->assertSame(OfflineScannerImportedLog::STATUS_IMPORTED, OfflineScannerImportedLog::query()->findOrFail($f['logId'])->import_status);
        foreach ($f['conflictIds'] as $id) {
            $conflict = OfflineScannerImportConflict::query()->findOrFail($id);
            $this->assertSame(OfflineScannerImportConflict::RESOLUTION_RESOLVED, $conflict->resolution_status);
            $this->assertSame(OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION, $conflict->resolution_action);
            $this->assertSame($f['admin']->id, $conflict->resolved_by_user_id);
            $this->assertSame(self::NOTES, $conflict->resolution_notes);
        }
        $this->assertSame($closed ? OfflineScannerResultImport::STATUS_COMPLETED : OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS, $f['import']->fresh()->status);
        $this->assertSame($closed ? OfflineScannerSession::STATUS_CLOSED : OfflineScannerSession::STATUS_CONFLICT, $f['session']->fresh()->status);
        $changes = [
            'guests' => [[$f['guestOne']->id], ['used_entries', 'status', 'updated_at']],
            'offline_scanner_imported_logs' => [[$f['logId']], ['import_status', 'conflict_reason', 'updated_at']],
            'offline_scanner_import_conflicts' => [$f['conflictIds'], $this->siblingResolutionFields()],
        ];
        if ($closed) {
            $changes['offline_scanner_result_imports'] = [[$f['import']->id], ['status', 'imported_logs', 'duplicate_logs', 'conflict_logs', 'rejected_logs', 'unknown_guest_logs', 'imported_at', 'updated_at']];
            $changes['offline_scanner_sessions'] = [[$f['session']->id], ['status', 'last_master_sequence', 'results_imported_at', 'session_closed_at', 'updated_at']];
        }
        $this->assertAllowedSiblingChanges($f, $before, $changes, $closed ? 4 : 1, true);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    private function siblingResolutionFields(): array
    {
        return ['resolution_status', 'resolution_action', 'resolved_by_user_id', 'resolution_notes', 'resolved_at', 'updated_at'];
    }

    private function assertAllowedSiblingChanges(array $f, array $before, array $changes, int $auditCount, bool $admitted = false): void
    {
        $after = $this->confirmationSnapshot();
        foreach ($changes as $table => [$ids, $fields]) {
            $this->assertCount(count($before[$table]), $after[$table]);
            $previous = array_column($before[$table], null, 'id');
            foreach ($after[$table] as $row) {
                $old = $previous[$row['id']];
                if (in_array($row['id'], $ids, true)) {
                    if ($table === 'offline_scanner_sessions') {
                        $this->assertSame(in_array($row['status'], OfflineScannerSession::blockingStatuses(), true) ? $row['event_id'] : null, $row['active_event_id']);
                        unset($old['active_event_id'], $row['active_event_id']);
                    }
                    foreach ($fields as $field) {
                        unset($old[$field], $row[$field]);
                    }
                }
                $this->assertSame($old, $row, 'Only named target fields may change in '.$table.'.');
            }
            $before[$table] = $after[$table];
        }
        foreach (['offline_scanner_session_audits' => $auditCount, 'admissions' => $admitted ? 1 : 0, 'checkins' => $admitted ? 1 : 0] as $table => $expectedAdded) {
            $oldIds = array_column($before[$table], 'id');
            $retained = array_values(array_filter($after[$table], static fn ($row): bool => in_array($row['id'], $oldIds, true)));
            $added = array_values(array_filter($after[$table], static fn ($row): bool => ! in_array($row['id'], $oldIds, true)));
            $this->assertSame($before[$table], $retained);
            $this->assertCount($expectedAdded, $added);
            foreach ($added as $row) {
                $this->assertSame($f['event']->id, $row['event_id']);
                if ($table === 'offline_scanner_session_audits') {
                    $this->assertSame($f['session']->id, $row['offline_scanner_session_id']);
                    $this->assertSame($f['admin']->id, $row['actor_user_id']);
                } else {
                    $this->assertSame($f['guestOne']->id, $row['guest_id']);
                    $this->assertSame($f['logId'], $row['offline_scanner_imported_log_id']);
                    if ($table === 'admissions') {
                        $this->assertSame(1, $row['quantity']);
                    } else {
                        $this->assertSame(1, $row['entries_added']);
                    }
                }
            }
            $before[$table] = $after[$table];
        }
        $this->assertSame($before, $after, 'Unrelated rows, existing history and every private-file hash must remain intact.');
    }

    private function sortSiblingRows(array &$rows): void
    {
        usort($rows, static fn ($left, $right): int => strcmp(json_encode($left), json_encode($right)));
    }

    private function identitySiblingQueries(array $queries): array
    {
        return array_values(array_filter($queries, static fn ($query): bool => preg_match('/\Aselect (?!\*)/i', $query['sql'])
            && str_contains($query['sql'], 'from "offline_scanner_import_conflicts"')
            && preg_match('/order by (?:(?:"offline_scanner_import_conflicts")\.)?"id" asc/i', $query['sql'])));
    }

    private function siblingDml(array $queries): array
    {
        return array_values(array_filter($queries, static fn ($query): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $query['sql'])));
    }

    private function siblingQueries(array &$queries, callable $work): mixed
    {
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = ['sql' => $event->sql, 'bindings' => $event->bindings, 'level' => $event->connection->transactionLevel()];
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            return $work();
        } finally {
            $connection->setEventDispatcher($original);
        }
    }

    private function withSiblingModelHooks(array $hooks, callable $work): mixed
    {
        $original = Model::getEventDispatcher();
        $dispatcher = clone $original;
        foreach ($hooks as $class => $hook) {
            $dispatcher->listen('eloquent.retrieved: '.$class, $hook);
        }
        Model::setEventDispatcher($dispatcher);
        try {
            return $work();
        } finally {
            Model::setEventDispatcher($original);
        }
    }

    private function resolutionFixture(string $kind = 'ordinary'): array
    {
        $f = $this->confirmationFixture();
        DB::table('workspaces')->insert(['id' => 211, 'name' => 'Synthetic retained resolution workspace']);
        $f['ownedNeighbor'] = $this->resolutionNeighbor(true);
        $f['unownedNeighbor'] = $this->resolutionNeighbor(false);
        if ($kind === 'platform') {
            DB::table('users')->where('id', $f['admin']->id)->update(['is_platform_admin' => true]);
        }
        if ($kind === 'member') {
            DB::table('workspace_users')->insert(['workspace_id' => 211, 'user_id' => $f['admin']->id, 'role' => 'manager', 'is_active' => false]);
        }
        $f['admin']->refresh();
        $f['import'] = $this->confirmationPreviewResults($f, $this->confirmationPayload($f), 'authority-resolution.wqrresults');
        $f['guestOne']->update(['used_entries' => 1]);
        app(OfflineScannerResultsImportService::class)->importVerified($f['import'], $f['admin']);
        $f['conflict'] = $f['import']->conflicts()->where('conflict_type', OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED)
            ->where('resolution_status', OfflineScannerImportConflict::RESOLUTION_OPEN)->firstOrFail();
        $f['logId'] = $f['conflict']->offline_scanner_imported_log_id;
        $f['conflictIds'] = $f['import']->conflicts()->where('offline_scanner_imported_log_id', $f['logId'])->pluck('id')->all();

        return $f;
    }

    private function resolutionNeighbor(bool $owned): Event
    {
        $event = Event::factory()->create(['event_name' => 'Synthetic retained resolution neighbor', 'workspace_id' => $owned ? 211 : null]);
        $this->confirmationGuest($event, 'Retained resolution neighbor', '+255700008932', Guest::PASS_DOUBLE, 2, '8932');
        Storage::disk($this->confirmationOfflineScannerDisk())->put('resolution-neighbor/'.$event->id.'.bin', 'invented-retained-resolution-bytes');

        return $event;
    }

    private function confirmationFixture(): array
    {
        $event = Event::factory()->create([
            'event_name' => 'ON Wedding',
            'venue_name' => 'Ocean Hall',
            'event_date' => '2026-08-01',
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $master = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Main Gate',
            'name' => 'Master Scanner',
        ]);
        $client = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Garden Gate',
            'name' => 'Client Scanner 2',
        ]);
        $guestOne = $this->confirmationGuest($event, 'Asha Results Guest', '+255700000201', Guest::PASS_DOUBLE, 2, '1111');
        $guestTwo = $this->confirmationGuest($event, 'Baraka Results Guest', '+255700000202', Guest::PASS_SINGLE, 1, '2222');
        $resultKey = random_bytes(32);

        $session = OfflineScannerSession::factory()->active()->create([
            'event_id' => $event->id,
            'created_by_user_id' => $admin->id,
            'master_scanner_user_id' => $master->id,
            'master_gate_name' => 'Main Gate',
            'package_created_at' => now()->subHours(2),
            'package_expires_at' => now()->addDay(),
            'result_file_encryption_secret_encrypted' => Crypt::encryptString(base64_encode($resultKey)),
        ]);
        $package = OfflineScannerPackage::factory()->create([
            'offline_scanner_session_id' => $session->id,
            'generated_by_user_id' => $admin->id,
        ]);
        $session->update([
            'package_export_id' => $package->id,
            'package_fingerprint' => $package->package_fingerprint,
        ]);

        foreach ([
            [$guestOne, hash('sha256', 'guest-base-'.$guestOne->id.'-1')],
            [$guestTwo, hash('sha256', 'guest-base-'.$guestTwo->id.'-2')],
        ] as [$guest, $recordVersion]) {
            OfflineScannerPackageGuestSnapshot::query()->create([
                'offline_scanner_session_id' => $session->id,
                'offline_scanner_package_id' => $package->id,
                'guest_id' => $guest->id,
                'external_guest_id' => (string) $guest->id,
                'record_version' => $recordVersion,
                'allowed_entries' => $guest->allowed_entries,
                'used_entries' => $guest->used_entries,
                'remaining_entries' => $guest->remainingEntries(),
            ]);
        }

        OfflineScannerSessionUser::factory()->master()->create([
            'offline_scanner_session_id' => $session->id,
            'scanner_user_id' => $master->id,
            'assigned_gate' => 'Main Gate',
        ]);
        OfflineScannerSessionUser::factory()->create([
            'offline_scanner_session_id' => $session->id,
            'scanner_user_id' => $client->id,
            'assigned_gate' => 'Garden Gate',
        ]);

        return [
            'event' => $event,
            'admin' => $admin,
            'master' => $master,
            'client' => $client,
            'guestOne' => $guestOne,
            'guestTwo' => $guestTwo,
            'session' => $session->fresh(['packageExport', 'sessionUsers']),
            'package' => $package,
            'resultKey' => $resultKey,
        ];
    }

    private function confirmationGuest(Event $event, string $name, string $phone, string $passType, int $allowedEntries, string $manualCode): Guest
    {
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => $name,
            'phone_number' => $phone,
            'manual_code' => $manualCode,
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
            'used_entries' => 0,
        ]);

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => Guest::makeSecureToken(),
            'is_active' => true,
            'generated_at' => now(),
        ]);

        return $guest->fresh('qrCode');
    }

    private function confirmationPayload(array $fixture): array
    {
        $resultsExportId = (string) Str::uuid();
        $guestOne = $fixture['guestOne'];
        $guestTwo = $fixture['guestTwo'];

        return [
            'session' => [
                'session_uuid' => $fixture['session']->session_uuid,
                'package_export_id' => $fixture['package']->package_uuid,
                'results_export_id' => $resultsExportId,
                'event_id' => (int) $fixture['event']->id,
                'master_device_id' => 'android-master-1',
                'session_started_at' => now()->subHour()->toJSON(),
                'session_closed_at' => now()->subMinutes(5)->toJSON(),
                'final_master_sequence_number' => 3,
                'exported_at' => now()->toJSON(),
            ],
            'devices' => [
                [
                    'device_id' => 'android-master-1',
                    'device_name' => 'Master Pixel',
                    'device_role' => 'master',
                    'scanner_user_id' => $fixture['master']->id,
                    'scanner_user_name' => $fixture['master']->name,
                    'gate_name' => 'Main Gate',
                    'paired_at' => now()->subHour()->toJSON(),
                    'last_seen_at' => now()->toJSON(),
                    'admissions_count' => 1,
                ],
                [
                    'device_id' => 'android-client-2',
                    'device_name' => 'Client Pixel 2',
                    'device_role' => 'client',
                    'scanner_user_id' => $fixture['client']->id,
                    'scanner_user_name' => $fixture['client']->name,
                    'gate_name' => 'Garden Gate',
                    'paired_at' => now()->subHour()->toJSON(),
                    'last_seen_at' => now()->toJSON(),
                    'admissions_count' => 1,
                ],
            ],
            'admission_logs' => [
                $this->confirmationLog($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 1, 1, 0, 2, 1, 1),
                $this->confirmationLog($guestTwo, $fixture['client'], 'android-client-2', 'Garden Gate', 'manual', 2, 1, 0, 1, 1, 0),
                $this->confirmationLog($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 3, 0, 1, 1, 1, 1, OfflineScannerResultSchema::RESULT_INVALID_QR),
            ],
            'final_guest_states' => [
                [
                    'external_guest_id' => (string) $guestOne->id,
                    'final_used' => 1,
                    'final_remaining' => 1,
                    'final_record_version' => hash('sha256', 'guest-one-final'),
                    'last_master_sequence_number' => 1,
                ],
                [
                    'external_guest_id' => (string) $guestTwo->id,
                    'final_used' => 1,
                    'final_remaining' => 0,
                    'final_record_version' => hash('sha256', 'guest-two-final'),
                    'last_master_sequence_number' => 2,
                ],
            ],
            'metrics' => [
                'total_scans' => 3,
                'total_admitted_people' => 2,
                'total_invalid_scans' => 1,
                'total_manual_admissions' => 1,
                'total_qr_admissions' => 1,
            ],
        ];
    }

    private function confirmationLog(
        Guest $guest,
        User $scanner,
        string $deviceId,
        string $gate,
        string $method,
        int $sequence,
        int $added,
        int $usedBefore,
        int $remainingBefore,
        int $usedAfter,
        int $remainingAfter,
        string $resultCode = OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY,
    ): array {
        return [
            'log_uuid' => (string) Str::uuid(),
            'request_uuid' => (string) Str::uuid(),
            'master_sequence_number' => $sequence,
            'external_guest_id' => (string) $guest->id,
            'scanner_user_id' => $scanner->id,
            'source_device_id' => $deviceId,
            'source_gate_name' => $gate,
            'admission_method' => $method,
            'qr_payload_hash' => $method === 'qr' ? hash('sha256', 'qr-payload-'.$sequence) : null,
            'added_entries' => $added,
            'used_before' => $usedBefore,
            'remaining_before' => $remainingBefore,
            'used_after' => $usedAfter,
            'remaining_after' => $remainingAfter,
            'result_code' => $resultCode,
            'device_timestamp' => now()->subMinutes(20 - $sequence)->toJSON(),
            'master_timestamp' => now()->subMinutes(19 - $sequence)->toJSON(),
            'monotonic_sequence' => $sequence,
            'base_guest_record_version' => hash('sha256', 'guest-base-'.$guest->id.'-'.$sequence),
        ];
    }

    private function confirmationEncryptedResultsFile(array $payload, array $fixture): string
    {
        $json = app(CanonicalJson::class);
        $payloadHash = hash('sha256', $json->encode($payload));
        $iv = random_bytes(12);
        $tag = '';
        $binding = [
            'event_id' => (int) $fixture['event']->id,
            'format' => OfflineScannerResultSchema::FORMAT,
            'master_device_id' => 'android-master-1',
            'payload_hash' => $payloadHash,
            'result_schema_version' => OfflineScannerResultSchema::SCHEMA_VERSION,
            'results_export_id' => $payload['session']['results_export_id'],
            'session_uuid' => $fixture['session']->session_uuid,
            'source_package_export_id' => $fixture['package']->package_uuid,
        ];
        $ciphertext = openssl_encrypt(
            $json->encode($payload),
            'aes-256-gcm',
            $fixture['resultKey'],
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $json->encode($binding),
        );

        $this->assertNotFalse($ciphertext);

        return $json->encode([
            'format' => OfflineScannerResultSchema::FORMAT,
            'result_schema_version' => OfflineScannerResultSchema::SCHEMA_VERSION,
            'source_package_export_id' => $fixture['package']->package_uuid,
            'session_uuid' => $fixture['session']->session_uuid,
            'results_export_id' => $payload['session']['results_export_id'],
            'event_id' => (int) $fixture['event']->id,
            'master_device_id' => 'android-master-1',
            'encryption_algorithm' => OfflineScannerResultSchema::ENCRYPTION_ALGORITHM,
            'initialization_vector' => base64_encode($iv),
            'encrypted_payload' => base64_encode($ciphertext),
            'authentication_tag' => base64_encode($tag),
            'payload_hash' => $payloadHash,
        ]);
    }

    private function confirmationSnapshot(): array
    {
        $rows = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $contents = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($contents, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $rows[$table->name] = $contents;
        }
        $files = Storage::disk($this->confirmationOfflineScannerDisk())->allFiles();
        sort($files);
        $rows['_files'] = [];
        foreach ($files as $file) {
            $rows['_files'][$file] = hash('sha256', Storage::disk($this->confirmationOfflineScannerDisk())->get($file));
        }

        return $rows;
    }

    private function confirmationPreviewResults(array $fixture, array $payload, string $fileName): OfflineScannerResultImport
    {
        $contents = $this->confirmationEncryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent($fileName, $contents),
            ])
            ->assertSessionHasNoErrors();

        return OfflineScannerResultImport::query()->latest('id')->firstOrFail();
    }

    private function confirmationOfflineScannerDisk(): string
    {
        return (string) config('offline_scanner.disk', 'offline_scanner');
    }
}
