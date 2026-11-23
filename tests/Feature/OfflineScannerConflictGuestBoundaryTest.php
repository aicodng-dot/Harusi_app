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

/** Current Guest namespace only; sibling integrity, QR mutation fencing and internal return graphs remain separate. */
class OfflineScannerConflictGuestBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const NOTES = 'Synthetic retained Guest boundary resolution.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake($this->confirmationOfflineScannerDisk());
    }

    #[DataProvider('ownership')]
    public function test_bound_guest_moved_after_http_validation_is_refused_without_foreign_models(bool $owned): void
    {
        $f = $this->guestFixture();
        $this->lateGuestMove($f, $f['guestOne'], $owned, $this->resolutionInput(OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION),
            'conflict_resolution', 'The conflict guest no longer belongs to this event.');
    }

    #[DataProvider('ownership')]
    public function test_replacement_moved_after_http_validation_is_refused_without_foreign_models(bool $owned): void
    {
        $f = $this->guestFixture(true);
        $replacement = $this->confirmationGuest($f['event'], 'Own permitted Family replacement', '+255700008877', Guest::PASS_SPECIAL, 5, '8877');
        $input = $this->resolutionInput(OfflineScannerImportConflict::ACTION_MAP_TO_REPLACEMENT_GUEST);
        $input['replacement_guest_id'] = $replacement->id;
        $this->lateGuestMove($f, $replacement, $owned, $input, 'replacement_guest_id', 'The replacement guest must belong to the same event.');
    }

    public static function ownership(): array
    {
        return ['unowned neighbor' => [false], 'owned neighbor' => [true]];
    }

    #[DataProvider('aliases')]
    public function test_unsafe_raw_bound_guest_fk_is_rejected_before_guest_query(string $kind): void
    {
        $f = $this->guestFixture();
        $this->maximumGuest($f['event'], Guest::PASS_DOUBLE, 1);
        $before = $this->confirmationSnapshot();
        $alias = $kind === 'overflow' ? '9223372036854775808' : (string) PHP_INT_MAX."\n";
        $queries = [];
        $this->withLogModelHooks([OfflineScannerImportConflict::class => function ($row) use ($f, $alias): void {
            if (DB::connection()->transactionLevel() === 2 && $row->getKey() === $f['conflict']->id) {
                $this->rawLogContext($row, 'guest_id', $alias);
            }
        }], function () use ($f, &$queries, $before): void {
            $failure = null;
            try {
                $this->logQueries($queries, fn () => app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->resolutionInput(OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION)));
            } catch (\Throwable $exception) {
                $failure = $exception;
            }
            $this->assertSame($before, $this->confirmationSnapshot());
            $this->assertSame([], array_values(array_filter($queries, fn ($q) => preg_match('/\bfrom\s+"guests"/i', $q['sql']))));
            $this->assertInstanceOf(ValidationException::class, $failure);
            $this->assertSame(['conflict_resolution' => ['Choose a replacement guest for this conflict.']], $failure->errors());
        });
    }

    #[DataProvider('aliases')]
    public function test_unsafe_replacement_input_cannot_apply_admission_to_a_real_maximum_guest(string $kind): void
    {
        $f = $this->guestFixture(true);
        $this->maximumGuest($f['event'], Guest::PASS_SPECIAL, 0);
        $before = $this->confirmationSnapshot();
        $input = $this->resolutionInput(OfflineScannerImportConflict::ACTION_MAP_TO_REPLACEMENT_GUEST);
        $input['replacement_guest_id'] = $kind === 'overflow' ? '9223372036854775808' : (string) PHP_INT_MAX."\n";
        $failure = null;
        try {
            app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $input);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        $this->assertSame($before, $this->confirmationSnapshot());
        $this->assertInstanceOf(ValidationException::class, $failure);
        $this->assertSame(['replacement_guest_id' => ['Choose the replacement guest.']], $failure->errors());
    }

    public static function aliases(): array
    {
        return ['overflow' => ['overflow'], 'newline' => ['newline']];
    }

    public function test_valid_maximum_double_guest_accepts_exactly_one_remaining_entry(): void
    {
        $f = $this->guestFixture(false, true);
        $before = $this->confirmationSnapshot();
        app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->resolutionInput(OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION));
        $this->assertAdmissionEffects($f, $before, $f['guestOne'], 2, OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION);
    }

    public function test_valid_maximum_family_replacement_uses_the_existing_pass_and_admission_logic(): void
    {
        $f = $this->guestFixture(true);
        $replacement = $this->maximumGuest($f['event'], Guest::PASS_SPECIAL, 0);
        $before = $this->confirmationSnapshot();
        $input = $this->resolutionInput(OfflineScannerImportConflict::ACTION_MAP_TO_REPLACEMENT_GUEST);
        $input['replacement_guest_id'] = (string) PHP_INT_MAX;
        app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $input);
        $this->assertAdmissionEffects($f, $before, $replacement, 1, OfflineScannerImportConflict::ACTION_MAP_TO_REPLACEMENT_GUEST);
    }

    public function test_own_guest_manual_count_zero_keeps_existing_history(): void
    {
        $f = $this->guestFixture();
        $before = $this->confirmationSnapshot();
        $result = app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->manualInput());
        $this->assertManualResolution($f, $before, $result);
    }

    #[DataProvider('largeManualCounts')]
    public function test_http_manual_count_resolution_accepts_the_full_special_allowance(int $capacity): void
    {
        $f = $this->guestFixture();
        $f['guestOne']->update(['pass_type' => Guest::PASS_SPECIAL, 'allowed_entries' => $capacity]);
        $input = array_replace($this->manualInput(), ['manual_used_entries' => $capacity]);

        $this->actingAs($f['admin'])
            ->withSession(['selected_event_id' => $f['event']->id])
            ->post(route('admin.offline-scanner-conflicts.resolve', $f['conflict']), $input)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($capacity, $f['guestOne']->fresh()->allowed_entries);
        $this->assertSame($capacity, $f['guestOne']->fresh()->used_entries);
        $this->assertSame(Guest::STATUS_FULLY_USED, $f['guestOne']->fresh()->status);
        $this->assertSame(0, $f['guestOne']->fresh()->remainingEntries());
        $this->assertSame($capacity, $f['conflict']->fresh()->manual_used_entries);
        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_RESOLVED, $f['conflict']->fresh()->resolution_status);
    }

    public static function largeManualCounts(): array
    {
        return ['eleven entries' => [11], 'one hundred entries' => [100]];
    }

    public function test_http_manual_count_resolution_rejects_above_one_hundred_without_changing_guest(): void
    {
        $f = $this->guestFixture();
        $f['guestOne']->update(['pass_type' => Guest::PASS_SPECIAL, 'allowed_entries' => 100]);
        $before = $this->confirmationSnapshot();
        $input = array_replace($this->manualInput(), ['manual_used_entries' => 101]);

        $this->actingAs($f['admin'])
            ->withSession(['selected_event_id' => $f['event']->id])
            ->post(route('admin.offline-scanner-conflicts.resolve', $f['conflict']), $input)
            ->assertRedirect()
            ->assertSessionHasErrors('manual_used_entries');

        $this->assertSame($before, $this->confirmationSnapshot());
    }

    public function test_full_single_guest_cannot_be_over_admitted(): void
    {
        $f = $this->guestFixture();
        $f['guestOne']->update(['pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1, 'used_entries' => 1]);
        $before = $this->confirmationSnapshot();
        $failure = null;
        try {
            app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->resolutionInput(OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION));
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        $this->assertSame($before, $this->confirmationSnapshot());
        $this->assertInstanceOf(ValidationException::class, $failure);
        $this->assertSame(['conflict_resolution' => ['Accepting this offline admission would make Used greater than Allowed.']], $failure->errors());
    }

    #[DataProvider('nullActions')]
    public function test_literal_null_bound_guest_keeps_existing_refusal_or_keep_behavior(string $action): void
    {
        $f = $this->guestFixture();
        $f['conflict']->update(['guest_id' => null]);
        $before = $this->confirmationSnapshot();
        $failure = null;
        try {
            app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->resolutionInput($action));
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        if ($action === OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION) {
            $this->assertSame($before, $this->confirmationSnapshot());
            $this->assertInstanceOf(ValidationException::class, $failure);
            $this->assertSame(['conflict_resolution' => ['Choose a replacement guest for this conflict.']], $failure->errors());
        } else {
            $this->assertNull($failure);
            $this->assertClosedResolution($f, $before, false);
        }
    }

    public static function nullActions(): array
    {
        return ['accept refuses' => [OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION], 'keep remains' => [OfflineScannerImportConflict::ACTION_KEEP_SERVER_STATE]];
    }

    public function test_noncanonical_current_guest_event_refuses_before_qr_hydration(): void
    {
        $f = $this->guestFixture();
        $before = $this->confirmationSnapshot();
        $qrReads = [];
        $this->withLogModelHooks([Guest::class => function ($guest) use ($f): void {
            if ($guest->getKey() === $f['guestOne']->id) {
                $this->rawLogContext($guest, 'event_id', '0'.$f['event']->id);
            }
        }, QrCode::class => function ($qr) use ($f, &$qrReads): void {
            if ($qr->guest_id === $f['guestOne']->id) {
                $qrReads[] = $qr->id;
            }
        }], function () use ($f, $before, &$qrReads): void {
            $failure = null;
            try {
                app(OfflineScannerImportConflictResolver::class)->resolve($f['conflict'], $f['admin'], $this->manualInput());
            } catch (\Throwable $exception) {
                $failure = $exception;
            }
            $this->assertSame($before, $this->confirmationSnapshot());
            $this->assertSame([], $qrReads);
            $this->assertInstanceOf(ValidationException::class, $failure);
        });
    }

    private function lateGuestMove(array $f, Guest $guest, bool $owned, array $input, string $field, string $message): void
    {
        $changed = false;
        $expected = null;
        $observed = [];
        DB::connection()->beforeStartingTransaction(function () use ($f, $guest, $owned, &$changed, &$expected): void {
            if ($changed) {
                return;
            }
            $changed = true;
            DB::table('guests')->where('id', $guest->id)->update(['event_id' => ($owned ? $f['ownedNeighbor'] : $f['unownedNeighbor'])->id, 'name' => 'PRIVATE-FOREIGN-RESOLUTION-GUEST']);
            $expected = $this->confirmationSnapshot();
        });
        $this->withLogModelHooks([Guest::class => function ($row) use ($guest, &$observed): void {
            if ($row->id === $guest->id) {
                $observed[] = 'guest';
            }
        },
            QrCode::class => function ($row) use ($guest, &$observed): void {
                if ($row->guest_id === $guest->id) {
                    $observed[] = 'qr';
                }
            }], function () use ($f, $input, $field, $message, &$expected, &$changed, &$observed): void {
                $response = $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id])
                    ->post(route('admin.offline-scanner-conflicts.resolve', $f['conflict']), $input);
                $this->assertTrue($changed);
                $this->assertSame($expected, $this->confirmationSnapshot());
                $response->assertRedirect()->assertSessionHasErrors([$field => $message]);
                $this->assertSame([], $observed, 'Foreign current Guest/QR models must never be hydrated.');
            });
    }

    private function maximumGuest(Event $event, string $passType, int $used): Guest
    {
        $guest = new Guest(['event_id' => $event->id, 'name' => 'Own canonical MAX Guest', 'phone_number' => '+255700009898', 'manual_code' => '9898', 'pass_type' => $passType, 'allowed_entries' => $passType === Guest::PASS_DOUBLE ? 2 : 5, 'used_entries' => $used]);
        $guest->forceFill(['id' => PHP_INT_MAX])->save();
        QrCode::query()->create(['guest_id' => $guest->id, 'qr_token' => Guest::makeSecureToken(), 'is_active' => true, 'generated_at' => now()]);

        return $guest->fresh('qrCode');
    }

    private function guestFixture(bool $replacement = false, bool $maximumBound = false): array
    {
        $f = $this->confirmationFixture();
        DB::table('workspaces')->insert(['id' => 211, 'name' => 'Synthetic retained Guest boundary workspace']);
        $f['ownedNeighbor'] = $this->resolutionNeighbor(true);
        $f['unownedNeighbor'] = $this->resolutionNeighbor(false);
        if ($maximumBound) {
            $oldId = $f['guestOne']->id;
            $f['guestOne']->delete();
            $f['guestOne'] = $this->maximumGuest($f['event'], Guest::PASS_DOUBLE, 0);
            OfflineScannerPackageGuestSnapshot::query()->where('offline_scanner_session_id', $f['session']->id)->where('external_guest_id', (string) $oldId)
                ->update(['guest_id' => PHP_INT_MAX, 'external_guest_id' => (string) PHP_INT_MAX, 'record_version' => hash('sha256', 'guest-base-'.PHP_INT_MAX.'-1')]);
        }
        $f['import'] = $this->confirmationPreviewResults($f, $this->confirmationPayload($f), 'guest-boundary-resolution.wqrresults');
        if ($replacement) {
            $f['guestOne']->delete();
        } else {
            $f['guestOne']->update(['used_entries' => 1]);
        }
        app(OfflineScannerResultsImportService::class)->importVerified($f['import'], $f['admin']);
        $f['conflict'] = $f['import']->conflicts()->where('resolution_status', OfflineScannerImportConflict::RESOLUTION_OPEN)->orderBy('id')->firstOrFail();
        $f['logId'] = $f['conflict']->offline_scanner_imported_log_id;
        $f['conflictIds'] = $f['import']->conflicts()->where('offline_scanner_imported_log_id', $f['logId'])->pluck('id')->all();

        return $f;
    }

    private function assertAdmissionEffects(array $f, array $before, Guest $guest, int $used, string $action): void
    {
        $after = $this->confirmationSnapshot();
        $this->assertSame($used, $guest->fresh()->used_entries);
        foreach (['admissions', 'checkins'] as $table) {
            $ids = array_column($before[$table], 'id');
            $retained = array_values(array_filter($after[$table], fn ($row) => in_array($row['id'], $ids, true)));
            $added = array_values(array_filter($after[$table], fn ($row) => ! in_array($row['id'], $ids, true)));
            $this->assertSame($before[$table], $retained);
            $this->assertCount(1, $added);
            $this->assertSame($guest->id, $added[0]['guest_id']);
            $this->assertSame($f['event']->id, $added[0]['event_id']);
            $this->assertSame($f['logId'], $added[0]['offline_scanner_imported_log_id']);
            if ($table === 'checkins') {
                $this->assertSame($guest->qrCode->id, $added[0]['qr_code_id']);
            }
            $before[$table] = $after[$table];
        }
        $pending = array_filter($before['offline_scanner_import_conflicts'], fn ($row) => $row['offline_scanner_result_import_id'] === $f['import']->id
            && $row['resolution_status'] === OfflineScannerImportConflict::RESOLUTION_OPEN && ! in_array($row['id'], $f['conflictIds'], true));
        $beforeImport = array_column($before['offline_scanner_result_imports'], null, 'id')[$f['import']->id];
        $beforeSession = array_column($before['offline_scanner_sessions'], null, 'id')[$f['session']->id];
        $this->assertSame($pending ? $beforeImport['status'] : OfflineScannerResultImport::STATUS_COMPLETED, $f['import']->fresh()->status);
        $this->assertSame($pending ? $beforeSession['status'] : OfflineScannerSession::STATUS_CLOSED, $f['session']->fresh()->status);
        $changes = [
            'guests' => [[$guest->id], ['used_entries', 'status', 'updated_at']],
            'offline_scanner_import_conflicts' => [$f['conflictIds'], [...$this->conflictResolutionFields(), 'replacement_guest_id']],
            'offline_scanner_imported_logs' => [[$f['logId']], ['import_status', 'conflict_reason', 'updated_at']],
        ];
        if (! $pending) {
            $changes['offline_scanner_result_imports'] = [[$f['import']->id], ['status', 'imported_logs', 'duplicate_logs', 'conflict_logs', 'rejected_logs', 'unknown_guest_logs', 'imported_at', 'updated_at']];
            $changes['offline_scanner_sessions'] = [[$f['session']->id], ['status', 'last_master_sequence', 'results_imported_at', 'session_closed_at', 'updated_at']];
        }
        $this->assertAllowedResolutionChanges($f, $before, $changes, $pending ? 1 : 4);
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
