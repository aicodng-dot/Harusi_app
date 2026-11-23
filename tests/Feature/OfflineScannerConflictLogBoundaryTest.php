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
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Bound Log processing only; Guest/replacement, sibling integrity and engine schedules are separate. */
class OfflineScannerConflictLogBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const NOTES = 'Synthetic retained bound-log resolution.';

    private const LOG_MESSAGE = 'The conflict log does not belong to this result import and scanner session.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake($this->confirmationOfflineScannerDisk());
    }

    #[DataProvider('lateLogChanges')]
    public function test_http_resolution_refuses_a_late_foreign_or_mismatched_bound_log_before_hydration_and_effects(string $kind): void
    {
        $f = $this->resolutionFixture();
        $target = $kind === 'wrong_session'
            ? OfflineScannerImportedLog::query()->findOrFail($f['logId'])
            : $this->neighborLog($f, $kind);
        $otherSession = $kind === 'wrong_session'
            ? OfflineScannerSession::factory()->create(['event_id' => $f['event']->id, 'created_by_user_id' => $f['admin']->id])
            : null;
        $changed = false;
        $expected = null;
        $hydrated = [];
        DB::connection()->beforeStartingTransaction(function () use ($f, $kind, $target, $otherSession, &$changed, &$expected): void {
            if ($changed) {
                return;
            }
            $changed = true;
            if ($kind === 'wrong_session') {
                DB::table('offline_scanner_imported_logs')->where('id', $target->id)
                    ->update(['offline_scanner_session_id' => $otherSession->id]);
            } else {
                DB::table('offline_scanner_import_conflicts')->where('id', $f['conflict']->id)
                    ->update(['offline_scanner_imported_log_id' => $target->id]);
            }
            $expected = $this->confirmationSnapshot();
        });
        $response = $this->withLogModelHooks([OfflineScannerImportedLog::class => static function (OfflineScannerImportedLog $log) use ($target, &$hydrated): void {
            if ($log->id === $target->id) {
                $hydrated[] = $log->id;
            }
        }], function () use ($f) {
            return $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id, 'guest_import' => ['marker' => 'retained']])
                ->from(route('admin.offline-scanner-conflicts.show', $f['conflict']))
                ->post(route('admin.offline-scanner-conflicts.resolve', $f['conflict']), $this->resolutionInput());
        });
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->confirmationSnapshot(), 'A late bound-Log change must not mutate either namespace.');
        $response->assertRedirect(route('admin.offline-scanner-conflicts.show', $f['conflict']))
            ->assertSessionHasErrors(['conflict_resolution' => self::LOG_MESSAGE])->assertSessionMissing('success')
            ->assertSessionHas('selected_event_id', $f['event']->id)->assertSessionHas('guest_import.marker', 'retained');
        $this->assertSame([], $hydrated, 'The unavailable bound Log must not be hydrated before refusing it.');
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function lateLogChanges(): array
    {
        return ['owned neighbor Log' => ['owned'], 'unowned neighbor Log' => ['unowned'],
            'same Event other Import Log' => ['same_event'], 'current Log other Session' => ['wrong_session']];
    }

    #[DataProvider('nullLogActions')]
    public function test_literal_null_log_preserves_keep_reject_and_escalate_without_touching_other_logs(string $action): void
    {
        $f = $this->resolutionFixture();
        $f['conflict']->update(['offline_scanner_imported_log_id' => null]);
        $f['conflict']->refresh();
        $before = $this->confirmationSnapshot();
        $result = app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->resolutionInput($action));
        $expectedStatus = $action === OfflineScannerImportConflict::ACTION_ESCALATE_ADMIN_REVIEW
            ? OfflineScannerImportConflict::RESOLUTION_OPEN : OfflineScannerImportConflict::RESOLUTION_DISMISSED;
        $this->assertSame($expectedStatus, $result->resolution_status);
        $this->assertTrue($result->relationLoaded('importedLog'));
        $this->assertNull($result->getRelation('importedLog'));
        $this->assertSame($action, $result->resolution_action);
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS, $f['import']->fresh()->status);
        $this->assertSame(OfflineScannerSession::STATUS_CONFLICT, $f['session']->fresh()->status);
        $this->assertAllowedResolutionChanges($f, $before, [
            'offline_scanner_import_conflicts' => [[$f['conflict']->id], $this->conflictResolutionFields()],
        ], 1);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function nullLogActions(): array
    {
        return ['keep without Log' => [OfflineScannerImportConflict::ACTION_KEEP_SERVER_STATE],
            'reject without Log' => [OfflineScannerImportConflict::ACTION_REJECT_IMPORTED_LOG],
            'escalate without Log' => [OfflineScannerImportConflict::ACTION_ESCALATE_ADMIN_REVIEW]];
    }

    public function test_manual_adjustment_marks_only_the_current_locked_bound_log(): void
    {
        $f = $this->resolutionFixture();
        $before = $this->confirmationSnapshot();
        $result = app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->manualInput());
        $this->assertManualResolution($f, $before, $result);
    }

    public function test_preloaded_malicious_log_relation_is_overwritten_before_manual_adjustment(): void
    {
        $f = $this->resolutionFixture();
        $foreign = $this->neighborLog($f, 'owned');
        $before = $this->confirmationSnapshot();
        $observed = 0;
        $result = $this->withLogModelHooks([OfflineScannerImportConflict::class => function (OfflineScannerImportConflict $row) use ($f, $foreign, &$observed): void {
            if (DB::connection()->transactionLevel() === 2 && $row->id === $f['conflict']->id) {
                $row->setRelation('importedLog', $foreign);
                $observed++;
            }
        }], fn () => app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->manualInput()));
        $this->assertGreaterThan(0, $observed);
        $this->assertManualResolution($f, $before, $result);
        $this->assertSame(OfflineScannerImportedLog::STATUS_PENDING, $foreign->fresh()->import_status);
    }

    #[DataProvider('unsafeLogReferences')]
    public function test_unsafe_or_missing_bound_log_reference_cannot_alias_a_canonical_maximum_log(string $kind): void
    {
        $f = $this->resolutionFixture();
        $maximum = $this->maximumLog($f);
        $before = $this->confirmationSnapshot();
        $raw = match ($kind) {
            'overflow' => '9223372036854775808',
            'newline' => (string) PHP_INT_MAX."\n",
            default => PHP_INT_MAX - 1,
        };
        $observed = 0;
        $hydrated = [];
        $error = null;
        $this->withLogModelHooks([
            OfflineScannerImportConflict::class => function (OfflineScannerImportConflict $row) use ($f, $raw, &$observed): void {
                if (DB::connection()->transactionLevel() === 2 && $row->id === $f['conflict']->id) {
                    $this->rawLogContext($row, 'offline_scanner_imported_log_id', $raw);
                    $observed++;
                }
            },
            OfflineScannerImportedLog::class => static function (OfflineScannerImportedLog $log) use (&$hydrated): void {
                $hydrated[] = $log->id;
            },
        ], function () use ($f, &$error): void {
            try {
                app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->resolutionInput());
            } catch (ValidationException $exception) {
                $error = $exception;
            }
        });
        $this->assertGreaterThan(0, $observed, 'Only the locked in-memory FK is altered; database columns stay valid.');
        $this->assertSame($before, $this->confirmationSnapshot(), 'Unsafe and absent Log references must refuse before all resolution effects.');
        $this->assertInstanceOf(ValidationException::class, $error);
        $this->assertSame([self::LOG_MESSAGE], $error->errors()['conflict_resolution']);
        $this->assertSame([], $hydrated);
        $this->assertSame(PHP_INT_MAX, $maximum->fresh()->id);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function unsafeLogReferences(): array
    {
        return ['overflow Log FK' => ['overflow'], 'newline Log FK' => ['newline'], 'missing canonical Log FK' => ['missing']];
    }

    #[DataProvider('unsafeConflictIds')]
    public function test_direct_unsafe_caller_conflict_id_refuses_before_lookup_and_cannot_borrow_maximum_conflict(string $kind): void
    {
        $f = $this->resolutionFixture();
        $maximum = $this->maximumConflict($f);
        $conflict = clone $f['conflict'];
        $this->rawLogContext($conflict, 'id', $kind === 'overflow' ? '9223372036854775808' : (string) PHP_INT_MAX."\n");
        $before = $this->confirmationSnapshot();
        $started = false;
        DB::connection()->beforeStartingTransaction(function () use (&$started): void {
            $started = true;
        });
        $queries = [];
        $error = null;
        try {
            $this->logQueries($queries, fn () => app(OfflineScannerImportConflictResolver::class)
                ->resolve($conflict, $f['admin'], $this->resolutionInput()));
        } catch (ValidationException $exception) {
            $error = $exception;
        }
        $this->assertSame($before, $this->confirmationSnapshot());
        $this->assertInstanceOf(ValidationException::class, $error);
        $this->assertArrayHasKey('conflict_resolution', $error->errors());
        $this->assertSame([], $queries);
        $this->assertFalse($started);
        $this->assertSame(PHP_INT_MAX, $maximum->fresh()->id);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function unsafeConflictIds(): array
    {
        return ['overflow Conflict ID' => ['overflow'], 'newline Conflict ID' => ['newline']];
    }

    public function test_canonical_maximum_conflict_id_still_resolves_with_its_original_log_and_counts(): void
    {
        $f = $this->resolutionFixture();
        $f['conflict'] = $this->maximumConflict($f);
        $f['conflictIds'] = $f['import']->conflicts()->where('offline_scanner_imported_log_id', $f['logId'])->pluck('id')->all();
        $before = $this->confirmationSnapshot();
        $result = app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->resolutionInput());
        $this->assertSame(PHP_INT_MAX, $result->id);
        $this->assertSame($f['logId'], $result->importedLog?->id);
        $this->assertClosedResolution($f, $before, false);
    }

    public function test_canonical_maximum_log_id_remains_a_usable_bound_log(): void
    {
        $f = $this->resolutionFixture();
        $maximum = $this->maximumLog($f);
        $f['conflict']->update(['offline_scanner_imported_log_id' => $maximum->id]);
        $f['conflict']->refresh();
        $before = $this->confirmationSnapshot();
        $result = app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->resolutionInput());
        $this->assertSame(PHP_INT_MAX, $result->importedLog?->id);
        $this->assertSame(OfflineScannerImportedLog::STATUS_REJECTED, $maximum->fresh()->import_status);
        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_DISMISSED, $result->resolution_status);
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS, $f['import']->fresh()->status);
        $this->assertAllowedResolutionChanges($f, $before, [
            'offline_scanner_import_conflicts' => [[$f['conflict']->id], $this->conflictResolutionFields()],
            'offline_scanner_imported_logs' => [[$maximum->id], ['import_status', 'conflict_reason', 'updated_at']],
        ], 1);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    #[DataProvider('invalidCurrentParents')]
    public function test_direct_current_parent_mismatch_refuses_before_bound_log_hydration(string $kind): void
    {
        $f = $this->resolutionFixture();
        match ($kind) {
            'conflict' => DB::table('offline_scanner_import_conflicts')->where('id', $f['conflict']->id)->update(['event_id' => $f['unownedNeighbor']->id]),
            'import' => DB::table('offline_scanner_result_imports')->where('id', $f['import']->id)->update(['event_id' => $f['unownedNeighbor']->id]),
            default => DB::table('offline_scanner_sessions')->where('id', $f['session']->id)->update(['event_id' => $f['unownedNeighbor']->id]),
        };
        $before = $this->confirmationSnapshot();
        $hydrated = [];
        $error = null;
        $this->withLogModelHooks([OfflineScannerImportedLog::class => static function (OfflineScannerImportedLog $log) use (&$hydrated): void {
            $hydrated[] = $log->id;
        }], function () use ($f, &$error): void {
            try {
                app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->resolutionInput());
            } catch (ValidationException $exception) {
                $error = $exception;
            }
        });
        $this->assertSame($before, $this->confirmationSnapshot());
        $this->assertInstanceOf(ValidationException::class, $error);
        $this->assertArrayHasKey('conflict_resolution', $error->errors());
        $this->assertSame([], $hydrated);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function invalidCurrentParents(): array
    {
        return ['Conflict Event mismatch' => ['conflict'], 'Import Event mismatch' => ['import'], 'Session Event mismatch' => ['session']];
    }

    private function neighborLog(array $f, string $kind): OfflineScannerImportedLog
    {
        $event = $kind === 'same_event' ? $f['event'] : ($kind === 'owned' ? $f['ownedNeighbor'] : $f['unownedNeighbor']);
        $session = $kind === 'same_event' ? $f['session']
            : OfflineScannerSession::factory()->create(['event_id' => $event->id, 'created_by_user_id' => $f['admin']->id]);
        $import = OfflineScannerResultImport::factory()->create([
            'event_id' => $event->id, 'offline_scanner_session_id' => $session->id, 'uploaded_by_user_id' => $f['admin']->id,
        ]);

        return OfflineScannerImportedLog::factory()->create([
            'offline_scanner_result_import_id' => $import->id, 'offline_scanner_session_id' => $session->id,
            'scanner_user_id' => null, 'master_sequence_number' => 500, 'result_code' => OfflineScannerResultSchema::RESULT_REJECTED,
            'import_status' => OfflineScannerImportedLog::STATUS_PENDING,
        ]);
    }

    private function maximumLog(array $f): OfflineScannerImportedLog
    {
        return OfflineScannerImportedLog::factory()->create([
            'id' => PHP_INT_MAX, 'offline_scanner_result_import_id' => $f['import']->id,
            'offline_scanner_session_id' => $f['session']->id, 'scanner_user_id' => null,
            'master_sequence_number' => 500, 'result_code' => OfflineScannerResultSchema::RESULT_REJECTED,
            'import_status' => OfflineScannerImportedLog::STATUS_PENDING,
        ]);
    }

    private function maximumConflict(array $f): OfflineScannerImportConflict
    {
        return OfflineScannerImportConflict::factory()->create([
            'id' => PHP_INT_MAX, 'offline_scanner_result_import_id' => $f['import']->id,
            'offline_scanner_imported_log_id' => $f['logId'], 'event_id' => $f['event']->id,
            'guest_id' => $f['guestOne']->id, 'conflict_type' => OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED,
            'conflict_reason' => 'Synthetic canonical maximum Conflict', 'resolution_status' => OfflineScannerImportConflict::RESOLUTION_OPEN,
        ]);
    }

    private function manualInput(): array
    {
        return ['resolution_action' => OfflineScannerImportConflict::ACTION_ADJUST_SERVER_COUNT_MANUALLY,
            'manual_used_entries' => 0, 'resolution_notes' => self::NOTES, 'confirm_resolution' => '1'];
    }

    private function resolutionInput(string $action = OfflineScannerImportConflict::ACTION_KEEP_SERVER_STATE): array
    {
        return ['resolution_action' => $action, 'resolution_notes' => self::NOTES, 'confirm_resolution' => '1'];
    }

    private function assertManualResolution(array $f, array $before, OfflineScannerImportConflict $result): void
    {
        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_RESOLVED, $result->resolution_status);
        $this->assertSame(0, $result->manual_used_entries);
        $this->assertTrue($result->relationLoaded('importedLog'));
        $this->assertSame($f['logId'], $result->getRelation('importedLog')?->id);
        $this->assertClosedResolution($f, $before, true);
    }

    private function assertClosedResolution(array $f, array $before, bool $manual): void
    {
        $this->assertSame($manual ? 0 : 1, $f['guestOne']->fresh()->used_entries);
        $this->assertSame(1, $f['guestTwo']->fresh()->used_entries);
        $this->assertSame(OfflineScannerImportedLog::STATUS_REJECTED, OfflineScannerImportedLog::query()->findOrFail($f['logId'])->import_status);
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED, $f['import']->fresh()->status);
        $this->assertSame(OfflineScannerSession::STATUS_CLOSED, $f['session']->fresh()->status);
        $changes = [
            'offline_scanner_import_conflicts' => [$f['conflictIds'], [...$this->conflictResolutionFields(), 'manual_used_entries']],
            'offline_scanner_imported_logs' => [[$f['logId']], ['import_status', 'conflict_reason', 'updated_at']],
            'offline_scanner_result_imports' => [[$f['import']->id], ['status', 'imported_logs', 'duplicate_logs', 'conflict_logs', 'rejected_logs', 'unknown_guest_logs', 'imported_at', 'updated_at']],
            'offline_scanner_sessions' => [[$f['session']->id], ['status', 'last_master_sequence', 'results_imported_at', 'session_closed_at', 'updated_at']],
        ];
        if ($manual) {
            $changes['guests'] = [[$f['guestOne']->id], ['used_entries', 'status', 'updated_at']];
        }
        $this->assertAllowedResolutionChanges($f, $before, $changes, 4);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    private function conflictResolutionFields(): array
    {
        return ['resolution_status', 'resolution_action', 'resolved_by_user_id', 'resolution_notes', 'resolved_at', 'updated_at'];
    }

    private function assertAllowedResolutionChanges(array $f, array $before, array $changes, int $auditCount): void
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
        $table = 'offline_scanner_session_audits';
        $oldIds = array_column($before[$table], 'id');
        $retained = array_values(array_filter($after[$table], static fn ($row): bool => in_array($row['id'], $oldIds, true)));
        $added = array_values(array_filter($after[$table], static fn ($row): bool => ! in_array($row['id'], $oldIds, true)));
        $this->assertSame($before[$table], $retained);
        $this->assertCount($auditCount, $added);
        foreach ($added as $row) {
            $this->assertSame($f['event']->id, $row['event_id']);
            $this->assertSame($f['session']->id, $row['offline_scanner_session_id']);
            $this->assertSame($f['admin']->id, $row['actor_user_id']);
        }
        $before[$table] = $after[$table];
        $this->assertSame($before, $after, 'All other rows, history and every private file hash must be preserved.');
    }

    private function withLogModelHooks(array $hooks, callable $work): mixed
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

    private function rawLogContext(Model $row, string $attribute, mixed $value): void
    {
        $attributes = $row->getAttributes();
        $attributes[$attribute] = $value;
        $row->setRawAttributes($attributes, true);
    }

    private function logQueries(array &$queries, callable $work): mixed
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
