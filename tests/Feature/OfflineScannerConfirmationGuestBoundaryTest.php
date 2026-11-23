<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionUser;
use App\Models\QrCode;
use App\Models\User;
use App\Services\CanonicalJson;
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use App\Services\OfflineScannerResults\OfflineScannerResultsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Reconciliation processing only; old stored/eager review relations remain a separate boundary. */
class OfflineScannerConfirmationGuestBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const PRIVATE_NAME = 'PRIVATE-CURRENT-CONFIRMATION-GUEST-8931';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake($this->confirmationOfflineScannerDisk());
    }

    #[DataProvider('foreignOwnership')]
    public function test_confirmation_redacts_current_guest_that_moved_after_preview(bool $owned): void
    {
        $f = $this->confirmationFixture();
        $neighbor = $this->confirmationNeighbor($owned);
        $payload = $this->confirmationPayload($f);
        $this->assertStringNotContainsString(self::PRIVATE_NAME, app(CanonicalJson::class)->encode($payload));
        $import = $this->confirmationPreviewResults($f, $payload, 'before-guest-transfer.wqrresults');
        $this->moveConfirmationGuest($f['guestOne'], $neighbor);
        $before = $this->confirmationSnapshot();

        $this->confirmImport($f, $import);

        $conflicts = $import->conflicts()->whereHas('importedLog', fn ($query) => $query->where('external_guest_id', (string) $f['guestOne']->id))->get();
        $this->assertCount(2, $conflicts);
        foreach ($conflicts as $conflict) {
            $this->assertSame(OfflineScannerImportConflict::TYPE_WRONG_EVENT, $conflict->conflict_type);
            $this->assertNoCurrentConfirmationGuest($conflict);
            $this->assertSame((string) $f['guestOne']->id, (string) $conflict->snapshot_state['guest_id']);
            $this->assertSame(2, $conflict->snapshot_state['allowed_entries']);
            $this->assertSame(0, $conflict->snapshot_state['used_entries']);
        }
        $this->assertSame(7, $f['guestOne']->fresh()->used_entries);
        $this->assertSame(1, $f['guestTwo']->fresh()->used_entries);
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS, $import->fresh()->status);
        $this->assertConfirmationEffectsConfined($before, $import, [$f['guestTwo']->id], 1, 2, 1);
    }

    public static function foreignOwnership(): array
    {
        return ['other unowned Event' => [false], 'owned tenant Event' => [true]];
    }

    #[DataProvider('foreignOwnership')]
    public function test_moved_guest_final_state_without_current_import_log_is_not_applied(bool $owned): void
    {
        [$f, $first, $second] = $this->twoConfirmationPreviews();
        $neighbor = $this->confirmationNeighbor($owned);
        $this->moveConfirmationGuest($f['guestOne'], $neighbor);
        $this->assertFalse($second->importedLogs()->where('external_guest_id', (string) $f['guestOne']->id)->exists());
        $this->assertTrue($second->finalGuestStates()->where('external_guest_id', (string) $f['guestOne']->id)->exists());
        $before = $this->confirmationSnapshot();

        $this->confirmImport($f, $second);

        $this->assertSame(7, $f['guestOne']->fresh()->used_entries, 'A foreign final state cannot be applied just because no current-import log blocks it.');
        $this->assertSame(0, $f['guestTwo']->fresh()->used_entries);
        $this->assertSame(OfflineScannerResultImport::STATUS_PREVIEW_READY, $first->fresh()->status);
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS, $second->fresh()->status);
        $this->assertConfirmationEffectsConfined($before, $second, [], 0, 0, 1);
    }

    public function test_existing_same_event_final_state_without_current_import_log_remains_compatible(): void
    {
        [$f, $first, $second] = $this->twoConfirmationPreviews();
        $before = $this->confirmationSnapshot();

        $this->confirmImport($f, $second);

        // This characterizes existing same-Event session-ledger behavior, not protocol approval.
        $this->assertSame(1, $f['guestOne']->fresh()->used_entries);
        $this->assertSame(0, $f['guestTwo']->fresh()->used_entries);
        $this->assertSame(OfflineScannerResultImport::STATUS_PREVIEW_READY, $first->fresh()->status);
        $this->assertConfirmationEffectsConfined($before, $second, [$f['guestOne']->id], 0, 0, 1);
    }

    #[DataProvider('sameEventGuestIds')]
    public function test_normal_same_event_confirmation_and_completed_retry_are_preserved(string $kind): void
    {
        $f = $this->confirmationFixture();
        $this->confirmationNeighbor(true);
        if ($kind === 'maximum') {
            $originalGuestId = $f['guestOne']->id;
            $guest = Guest::query()->forceCreate([
                'id' => PHP_INT_MAX, 'event_id' => $f['event']->id, 'name' => 'Canonical maximum confirmation Guest',
                'phone_number' => '+255700008933', 'manual_code' => '8933', 'pass_type' => Guest::PASS_DOUBLE,
                'allowed_entries' => 2, 'used_entries' => 0,
            ]);
            $qr = QrCode::query()->create(['guest_id' => $guest->id, 'qr_token' => Guest::makeSecureToken(), 'is_active' => true, 'generated_at' => now()]);
            OfflineScannerPackageGuestSnapshot::query()
                ->where('offline_scanner_session_id', $f['session']->id)
                ->where('guest_id', $originalGuestId)
                ->update([
                    'guest_id' => $guest->id, 'external_guest_id' => (string) $guest->id,
                    'record_version' => hash('sha256', 'guest-base-'.$guest->id.'-1'),
                    'manual_code_hash' => hash('sha256', $guest->manual_code),
                    'qr_token_hash' => hash('sha256', $qr->qr_token),
                ]);
            $f['guestOne'] = $guest->fresh('qrCode');
        }
        $import = $this->confirmationPreviewResults($f, $this->confirmationPayload($f), 'same-event-control.wqrresults');
        $before = $this->confirmationSnapshot();

        $this->confirmImport($f, $import);

        $this->assertSame(1, $f['guestOne']->fresh()->used_entries);
        $this->assertSame(1, $f['guestTwo']->fresh()->used_entries);
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED, $import->fresh()->status);
        $this->assertConfirmationEffectsConfined($before, $import, [$f['guestOne']->id, $f['guestTwo']->id], 2, 0, 3);
        $after = $this->confirmationSnapshot();
        $this->post(route('admin.offline-scanner-results.confirm', $import), ['confirm_import' => '1'])->assertForbidden();
        $this->assertSame($after, $this->confirmationSnapshot(), 'Existing closed-session HTTP policy remains a refusal.');
        app(OfflineScannerResultsImporter::class)->import($import, $f['admin']);
        $this->assertSame($after, $this->confirmationSnapshot(), 'An internal completed-confirmation retry remains a no-op.');
    }

    public static function sameEventGuestIds(): array
    {
        return ['ordinary Guest IDs' => ['ordinary'], 'canonical PHP maximum Guest' => ['maximum']];
    }

    #[DataProvider('snapshotAliases')]
    public function test_confirmation_uses_raw_snapshot_guest_id_before_model_integer_cast(string $kind): void
    {
        $f = $this->confirmationFixture();
        $this->confirmationNeighbor(true);
        $import = $this->confirmationPreviewResults($f, $this->confirmationPayload($f), 'snapshot-id-control.wqrresults');
        $victim = Guest::query()->forceCreate([
            'id' => PHP_INT_MAX, 'event_id' => $f['event']->id, 'name' => self::PRIVATE_NAME,
            'phone_number' => '+255700008931', 'manual_code' => '8931', 'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 10, 'used_entries' => 7, 'status' => Guest::STATUS_PARTIALLY_USED,
        ]);
        QrCode::query()->create(['guest_id' => $victim->id, 'qr_token' => Guest::makeSecureToken(), 'is_active' => true, 'generated_at' => now()]);
        $before = $this->confirmationSnapshot();
        $dispatcher = OfflineScannerPackageGuestSnapshot::getEventDispatcher();
        $isolated = clone $dispatcher;
        $observed = false;
        $isolated->listen('eloquent.retrieved: '.OfflineScannerPackageGuestSnapshot::class, function (OfflineScannerPackageGuestSnapshot $snapshot) use ($f, $kind, &$observed): void {
            if (DB::connection()->transactionLevel() !== 3 || (string) $snapshot->external_guest_id !== (string) $f['guestOne']->id) {
                return;
            }
            $attributes = $snapshot->getAttributes();
            $attributes['guest_id'] = $kind === 'overflow' ? '9223372036854775808' : (string) PHP_INT_MAX."\n";
            $snapshot->setRawAttributes($attributes, true);
            $observed = true;
        });
        OfflineScannerPackageGuestSnapshot::setEventDispatcher($isolated);
        $guestQueries = [];
        $capture = true;
        DB::listen(function ($query) use (&$guestQueries, &$capture): void {
            if ($capture && (str_contains($query->sql, 'from "guests"') || str_contains($query->sql, 'from "qr_codes"'))) {
                $guestQueries[] = $query->bindings;
            }
        });
        try {
            $this->confirmImport($f, $import);
        } finally {
            $capture = false;
            OfflineScannerPackageGuestSnapshot::setEventDispatcher($dispatcher);
        }

        $this->assertTrue($observed);
        $conflicts = $import->conflicts()->whereHas('importedLog', fn ($query) => $query->where('external_guest_id', (string) $f['guestOne']->id))->get();
        $this->assertCount(2, $conflicts);
        foreach ($conflicts as $conflict) {
            $this->assertSame(OfflineScannerImportConflict::TYPE_GUEST_DELETED, $conflict->conflict_type);
            $this->assertNoCurrentConfirmationGuest($conflict);
        }
        foreach ($guestQueries as $bindings) {
            $this->assertNotContains((string) PHP_INT_MAX, array_map(static fn ($value): string => (string) $value, $bindings), 'The unsafe snapshot key must not reach a numeric Guest/QR lookup as MAX.');
        }
        $this->assertSame(7, $victim->fresh()->used_entries);
        $this->assertSame(1, $f['guestTwo']->fresh()->used_entries);
        $this->assertConfirmationEffectsConfined($before, $import, [$f['guestTwo']->id], 1, 2, 1);
    }

    public static function snapshotAliases(): array
    {
        return ['unsigned overflow' => ['overflow'], 'trailing newline' => ['newline']];
    }

    #[DataProvider('lockedEventReferences')]
    public function test_locked_raw_event_reference_is_validated_before_confirmation_effects(string $kind): void
    {
        $f = $this->confirmationFixture();
        $neighbor = $this->confirmationNeighbor(true);
        $import = $this->confirmationPreviewResults($f, $this->confirmationPayload($f), 'locked-event-control.wqrresults');
        $before = $this->confirmationSnapshot();
        $dispatcher = OfflineScannerSession::getEventDispatcher();
        $isolated = clone $dispatcher;
        $observed = false;
        $targetClass = $kind === 'session_padded' ? OfflineScannerSession::class : OfflineScannerResultImport::class;
        $isolated->listen('eloquent.retrieved: '.$targetClass, function ($model) use ($f, $import, $neighbor, $kind, &$observed): void {
            $targetId = $kind === 'session_padded' ? $f['session']->id : $import->id;
            if (DB::connection()->transactionLevel() !== 3 || $model->getKey() !== $targetId) {
                return;
            }
            $attributes = $model->getAttributes();
            $attributes['event_id'] = match ($kind) {
                'session_padded' => '0'.$f['event']->id,
                'import_newline' => (string) $f['event']->id."\n",
                default => $neighbor->id,
            };
            $model->setRawAttributes($attributes, true);
            $observed = true;
        });
        OfflineScannerSession::setEventDispatcher($isolated);
        try {
            $this->post(route('admin.offline-scanner-results.confirm', $import), ['confirm_import' => '1'])
                ->assertRedirect()
                ->assertSessionHasErrors(['offline_results' => 'The result import event does not match the locked offline scanner session.']);
        } finally {
            OfflineScannerSession::setEventDispatcher($dispatcher);
        }
        $this->assertTrue($observed, 'Only the locked model hydration is altered; stored parent rows remain valid.');
        $this->assertSame(1, DB::connection()->transactionLevel());
        $this->assertSame($before, $this->confirmationSnapshot(), 'An invalid locked Event reference must refuse before confirmation writes/audits.');
    }

    public static function lockedEventReferences(): array
    {
        return ['padded Session Event' => ['session_padded'], 'newline Import Event' => ['import_newline'], 'mismatched Import Event' => ['import_other_event']];
    }

    private function confirmationNeighbor(bool $owned): Event
    {
        $attributes = ['event_name' => 'Synthetic retained confirmation neighbor'];
        if ($owned) {
            DB::table('workspaces')->insert(['id' => 211, 'name' => 'Synthetic retained confirmation workspace']);
            $attributes['workspace_id'] = 211;
        }
        $event = Event::factory()->create($attributes);
        $this->confirmationGuest($event, 'Retained neighbor Guest', '+255700008932', Guest::PASS_DOUBLE, 2, '8932');
        Storage::disk($this->confirmationOfflineScannerDisk())->put('confirmation-neighbor/retain.bin', 'invented-retained-confirmation-bytes');

        return $event;
    }

    private function moveConfirmationGuest(Guest $guest, Event $event): void
    {
        Guest::query()->whereKey($guest->id)->update([
            'event_id' => $event->id, 'name' => self::PRIVATE_NAME, 'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 10, 'used_entries' => 7, 'status' => Guest::STATUS_PARTIALLY_USED,
        ]);
    }

    private function confirmImport(array $f, OfflineScannerResultImport $import): void
    {
        $this->post(route('admin.offline-scanner-results.confirm', $import), ['confirm_import' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.offline-scanner-sessions.show', $f['session']));
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    private function twoConfirmationPreviews(): array
    {
        $f = $this->confirmationFixture();
        $one = $this->singleConfirmationPayload($f, false);
        $first = $this->confirmationPreviewResults($f, $one, 'first-pending-session-ledger.wqrresults');
        $two = $this->singleConfirmationPayload($f, true);
        $second = $this->confirmationPreviewResults($f, $two, 'second-conflicted-session-ledger.wqrresults');
        $this->assertSame(1, $first->importedLogs()->count());
        $this->assertSame(1, $second->importedLogs()->count());
        $this->assertSame(2, $second->conflicts()->count());

        return [$f, $first, $second];
    }

    private function singleConfirmationPayload(array $f, bool $second): array
    {
        $payload = $this->confirmationPayload($f);
        $payload['session']['final_master_sequence_number'] = 1;
        $payload['admission_logs'] = [$second
            ? $this->confirmationLog($f['guestTwo'], $f['client'], 'android-client-2', 'Garden Gate', 'manual', 1, 1, 0, 1, 1, 0)
            : $this->confirmationLog($f['guestOne'], $f['master'], 'android-master-1', 'Main Gate', 'qr', 1, 1, 0, 2, 1, 1)];
        $payload['devices'][0]['admissions_count'] = $second ? 0 : 1;
        $payload['devices'][1]['admissions_count'] = $second ? 1 : 0;
        $payload['final_guest_states'][0]['last_master_sequence_number'] = 1;
        $payload['final_guest_states'][1]['last_master_sequence_number'] = 1;
        if (! $second) {
            $payload['final_guest_states'] = [$payload['final_guest_states'][0]];
        }
        $payload['metrics'] = ['total_scans' => 1, 'total_admitted_people' => 1, 'total_invalid_scans' => 0,
            'total_manual_admissions' => $second ? 1 : 0, 'total_qr_admissions' => $second ? 0 : 1];

        return $payload;
    }

    private function assertNoCurrentConfirmationGuest(OfflineScannerImportConflict $conflict): void
    {
        $this->assertNull($conflict->getRawOriginal('guest_id'));
        $this->assertNull($conflict->server_state);
        $this->assertNull($conflict->getRawOriginal('server_used'));
        $this->assertNull($conflict->getRawOriginal('server_remaining'));
    }

    private function assertConfirmationEffectsConfined(array $before, OfflineScannerResultImport $import, array $guestIds, int $newAdmissions, int $newConflicts, int $newAudits): void
    {
        $after = $this->confirmationSnapshot();
        $targetLogIds = $import->importedLogs()->pluck('id')->all();
        $rowChanges = [
            'guests' => [$guestIds, ['used_entries', 'status', 'updated_at']],
            'offline_scanner_sessions' => [[$import->offline_scanner_session_id], ['status', 'results_imported_at', 'last_master_sequence', 'session_closed_at', 'updated_at']],
            'offline_scanner_result_imports' => [[$import->id], ['status', 'imported_logs', 'duplicate_logs', 'conflict_logs', 'rejected_logs', 'unknown_guest_logs', 'imported_at', 'updated_at']],
            'offline_scanner_imported_logs' => [$targetLogIds, ['import_status', 'conflict_reason', 'updated_at']],
        ];
        foreach ($rowChanges as $table => [$allowedIds, $allowedFields]) {
            $this->assertCount(count($before[$table]), $after[$table]);
            $previous = array_column($before[$table], null, 'id');
            foreach ($after[$table] as $row) {
                $old = $previous[$row['id']];
                if (in_array($row['id'], $allowedIds, true)) {
                    if ($table === 'offline_scanner_sessions') {
                        $expectedActiveEvent = in_array($row['status'], OfflineScannerSession::blockingStatuses(), true) ? $row['event_id'] : null;
                        $this->assertSame($expectedActiveEvent, $row['active_event_id'], 'The generated active Event column must follow its stored status.');
                        unset($old['active_event_id'], $row['active_event_id']);
                    }
                    foreach ($allowedFields as $field) {
                        unset($old[$field], $row[$field]);
                    }
                }
                $this->assertSame($old, $row, 'Only explicit target fields may change in '.$table.'.');
            }
            $before[$table] = $after[$table];
        }
        foreach (['admissions' => $newAdmissions, 'checkins' => $newAdmissions, 'offline_scanner_import_conflicts' => $newConflicts, 'offline_scanner_session_audits' => $newAudits] as $table => $expectedAdded) {
            $oldIds = array_column($before[$table], 'id');
            $retained = array_values(array_filter($after[$table], static fn ($row): bool => in_array($row['id'], $oldIds, true)));
            $added = array_values(array_filter($after[$table], static fn ($row): bool => ! in_array($row['id'], $oldIds, true)));
            $this->assertSame($before[$table], $retained, 'Existing '.$table.' history must remain intact.');
            $this->assertCount($expectedAdded, $added);
            foreach ($added as $row) {
                if (in_array($table, ['admissions', 'checkins'], true)) {
                    $this->assertContains($row['guest_id'], $guestIds);
                    $this->assertContains($row['offline_scanner_imported_log_id'], $targetLogIds);
                }
                if (array_key_exists('offline_scanner_result_import_id', $row)) {
                    $this->assertSame((string) $import->id, (string) $row['offline_scanner_result_import_id']);
                }
                if (array_key_exists('offline_scanner_session_id', $row)) {
                    $this->assertSame((string) $import->offline_scanner_session_id, (string) $row['offline_scanner_session_id']);
                }
                if (array_key_exists('event_id', $row)) {
                    $this->assertSame((string) $import->event_id, (string) $row['event_id']);
                }
            }
            $before[$table] = $after[$table];
        }
        $this->assertSame($before, $after, 'All other rows and every private fixture-file hash must be conserved.');
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
