<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\OfflineScannerSessionUser;
use App\Models\QrCode;
use App\Models\User;
use App\Services\CanonicalJson;
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use App\Services\OfflineEventLockService;
use App\Services\OfflineScannerResults\OfflineScannerResultsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfflineScannerResultsImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake($this->offlineScannerDisk());
    }

    public function test_admin_upload_verifies_results_and_shows_preview_without_modifying_guests(): void
    {
        $fixture = $this->fixture();
        $payload = $this->payload($fixture);
        $contents = $this->encryptedResultsFile($payload, $fixture);

        $response = $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('on-wedding-results.wqrresults', $contents),
            ]);

        $resultImport = OfflineScannerResultImport::query()->firstOrFail();
        $response->assertRedirect(route('admin.offline-scanner-results.show', $resultImport));

        $this->assertSame(0, $fixture['guestOne']->fresh()->used_entries);
        $this->assertSame(0, $fixture['guestTwo']->fresh()->used_entries);
        $this->assertSame(OfflineScannerSession::STATUS_RESULTS_UPLOADED, $fixture['session']->fresh()->status);
        $this->assertSame(OfflineScannerResultImport::STATUS_PREVIEW_READY, $resultImport->status);
        $this->assertSame($this->offlineScannerDisk(), $resultImport->storage_disk);
        $this->assertSame(OfflineScannerResultSchema::FORMAT, $resultImport->internal_format);
        $this->assertNotNull($resultImport->uploaded_at);
        $this->assertNotNull($resultImport->verified_at);
        $this->assertNotNull($resultImport->encrypted_file_path);
        $this->assertSame(strlen($contents), $resultImport->encrypted_file_size);
        Storage::disk($this->offlineScannerDisk())->assertExists($resultImport->encrypted_file_path);
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $fixture['session']->id,
            'action' => OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED,
        ]);
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $fixture['session']->id,
            'action' => OfflineScannerSessionAudit::ACTION_RESULTS_VERIFIED,
        ]);
        $this->assertSame(3, $resultImport->total_logs);
        $this->assertSame(1, $resultImport->rejected_logs);
        $this->assertDatabaseCount('offline_scanner_result_devices', 2);
        $this->assertDatabaseCount('offline_scanner_imported_logs', 3);
        $this->assertDatabaseCount('checkins', 0);
        $this->assertDatabaseCount('admissions', 0);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->get(route('admin.offline-scanner-results.show', $resultImport))
            ->assertOk()
            ->assertSee('Import Android Scanner Results')
            ->assertSee('ON Wedding')
            ->assertSee($fixture['session']->session_uuid)
            ->assertSee('Master Pixel')
            ->assertSee('Number of connected devices')
            ->assertSee('Total scan attempts')
            ->assertSee('Successful admissions')
            ->assertSee('Invalid scans')
            ->assertSee('Device summary')
            ->assertSee('Admission log table')
            ->assertSee('Manual code')
            ->assertSee('Import Verified Results');
    }

    public function test_admin_must_confirm_before_verified_results_update_guest_counts(): void
    {
        $fixture = $this->fixture();
        $payload = $this->payload($fixture);
        $contents = $this->encryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('on-wedding-results.wqrresults', $contents),
            ]);

        $resultImport = OfflineScannerResultImport::query()->firstOrFail();

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.confirm', $resultImport))
            ->assertSessionHasErrors('confirm_import');

        $this->assertSame(0, $fixture['guestOne']->fresh()->used_entries);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.confirm', $resultImport), [
                'confirm_import' => '1',
            ])
            ->assertRedirect(route('admin.offline-scanner-sessions.show', $fixture['session']));

        $this->assertSame(1, $fixture['guestOne']->fresh()->used_entries);
        $this->assertSame(1, $fixture['guestTwo']->fresh()->used_entries);
        $this->assertSame(2, Checkin::query()->where('scan_result', Checkin::RESULT_ADMITTED)->count());
        $this->assertSame(2, Admission::query()->count());
        $this->assertSame(OfflineScannerSession::STATUS_CLOSED, $fixture['session']->fresh()->status);
        $this->assertNotNull($fixture['session']->fresh()->results_imported_at);
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED, $resultImport->fresh()->status);
        $this->assertSame(2, $resultImport->fresh()->imported_logs);

        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $fixture['session']->id,
            'action' => OfflineScannerSessionAudit::ACTION_RESULTS_IMPORTED,
        ]);
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $fixture['session']->id,
            'action' => OfflineScannerSessionAudit::ACTION_SESSION_CLOSED,
        ]);
    }

    public function test_completed_result_import_is_idempotent_when_confirmation_is_retried(): void
    {
        $fixture = $this->fixture();
        $contents = $this->encryptedResultsFile($this->payload($fixture), $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('idempotent.wqrresults', $contents),
            ]);

        $resultImport = OfflineScannerResultImport::query()->firstOrFail();
        app(OfflineScannerResultsImporter::class)->import($resultImport, $fixture['admin']);

        $admissionCount = Admission::query()->count();
        $checkinCount = Checkin::query()->count();
        $auditCount = OfflineScannerSessionAudit::query()
            ->where('offline_scanner_session_id', $fixture['session']->id)
            ->where('action', OfflineScannerSessionAudit::ACTION_RESULTS_IMPORTED)
            ->count();

        app(OfflineScannerResultsImporter::class)->import($resultImport->fresh(), $fixture['admin']);

        $this->assertSame($admissionCount, Admission::query()->count());
        $this->assertSame($checkinCount, Checkin::query()->count());
        $this->assertSame($auditCount, OfflineScannerSessionAudit::query()
            ->where('offline_scanner_session_id', $fixture['session']->id)
            ->where('action', OfflineScannerSessionAudit::ACTION_RESULTS_IMPORTED)
            ->count());
        $this->assertSame(1, $fixture['guestOne']->fresh()->used_entries);
        $this->assertSame(1, $fixture['guestTwo']->fresh()->used_entries);
    }

    public function test_second_results_export_skips_identical_previously_imported_logs(): void
    {
        $fixture = $this->fixture();
        $payload = $this->payload($fixture);
        $contents = $this->encryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('first.wqrresults', $contents),
            ]);

        $firstImport = OfflineScannerResultImport::query()->firstOrFail();
        app(OfflineScannerResultsImporter::class)->import($firstImport, $fixture['admin']);
        $originalAdmissions = Admission::query()->count();
        $originalCheckins = Checkin::query()->count();

        // The transport may retry a combined Master export under a new results_export_id.
        $fixture['session']->fresh()->update([
            'status' => OfflineScannerSession::STATUS_ACTIVE,
            'results_imported_at' => null,
            'session_closed_at' => null,
            'last_master_sequence' => 0,
        ]);
        $payload['session']['results_export_id'] = (string) Str::uuid();
        $replayContents = $this->encryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('replay.wqrresults', $replayContents),
            ])
            ->assertSessionHasNoErrors();

        $replayImport = OfflineScannerResultImport::query()->latest('id')->firstOrFail();
        $this->assertSame(3, $replayImport->duplicate_logs);
        $this->assertSame(3, $replayImport->importedLogs()->count());
        $this->assertSame(3, $replayImport->importedLogs()->where('import_status', \App\Models\OfflineScannerImportedLog::STATUS_DUPLICATE)->count());

        app(OfflineScannerResultsImporter::class)->import($replayImport, $fixture['admin']);

        $this->assertSame($originalAdmissions, Admission::query()->count());
        $this->assertSame($originalCheckins, Checkin::query()->count());
        $this->assertSame(3, $replayImport->fresh()->duplicate_logs);
        $this->assertSame(0, $replayImport->fresh()->imported_logs);
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED, $replayImport->fresh()->status);
    }

    public function test_server_count_drift_is_stored_as_conflict_and_resolution_releases_lock(): void
    {
        $fixture = $this->fixture();
        $payload = $this->payload($fixture);
        $fixture['guestOne']->update(['used_entries' => 1]);
        $contents = $this->encryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('server-drift.wqrresults', $contents),
            ])
            ->assertSessionHasNoErrors();

        $resultImport = OfflineScannerResultImport::query()->firstOrFail();
        $this->assertNotNull($resultImport->encrypted_file_path);
        $this->assertSame(1, $resultImport->conflict_logs);
        $this->assertSame(1, $resultImport->importedLogs()->where('import_status', \App\Models\OfflineScannerImportedLog::STATUS_CONFLICT)->count());
        $types = $resultImport->conflicts()->pluck('conflict_type')->all();
        $this->assertContains(OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED, $types);
        $this->assertContains(OfflineScannerImportConflict::TYPE_SERVER_REMAINING_CHANGED, $types);

        $previewConflict = $resultImport->conflicts()->where('conflict_type', OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED)->firstOrFail();

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->get(route('admin.offline-scanner-results.show', $resultImport))
            ->assertOk()
            ->assertSee('Server state')
            ->assertSee('Exported snapshot')
            ->assertSee('Offline request')
            ->assertSee('Expected final')
            ->assertSee('Accept offline admission');

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->get(route('admin.offline-scanner-conflicts.show', $previewConflict))
            ->assertOk()
            ->assertSee('Server state')
            ->assertSee('Offline snapshot')
            ->assertSee('Offline result')
            ->assertSee('Resolution notes')
            ->assertSee('Accept offline admission');


        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.confirm', $resultImport), [
                'confirm_import' => '1',
            ])
            ->assertRedirect(route('admin.offline-scanner-sessions.show', $fixture['session']));

        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS, $resultImport->fresh()->status);
        $this->assertSame(OfflineScannerSession::STATUS_CONFLICT, $fixture['session']->fresh()->status);
        $this->assertNull($fixture['session']->fresh()->results_imported_at);
        $this->assertNotNull(app(OfflineEventLockService::class)->getActiveSessionForEvent($fixture['event']->fresh()));
        $this->assertSame(1, $fixture['guestOne']->fresh()->used_entries);
        $this->assertSame(1, $fixture['guestTwo']->fresh()->used_entries);
        $this->assertSame(1, Admission::query()->count());

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-conflicts.resolve', $previewConflict->fresh()), [
                'resolution_action' => OfflineScannerImportConflict::ACTION_ADJUST_SERVER_COUNT_MANUALLY,
                'manual_used_entries' => 3,
                'resolution_notes' => 'Attempt to set a count beyond the exported allowance.',
                'confirm_resolution' => '1',
            ])
            ->assertSessionHasErrors('manual_used_entries');

        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_OPEN, $previewConflict->fresh()->resolution_status);
        $this->assertSame(1, $fixture['guestOne']->fresh()->used_entries);

        $conflict = $previewConflict->fresh();
        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-conflicts.resolve', $conflict), [
                'resolution_action' => OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION,
                'resolution_notes' => 'Emergency server change reviewed; accept the offline gate admission.',
                'confirm_resolution' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.offline-scanner-results.show', $resultImport));

        $this->assertSame(2, $fixture['guestOne']->fresh()->used_entries);
        $this->assertSame(2, Admission::query()->count());
        $this->assertSame(2, Checkin::query()->where('scan_result', Checkin::RESULT_ADMITTED)->count());
        $this->assertSame(0, $resultImport->fresh()->conflicts()->where('resolution_status', OfflineScannerImportConflict::RESOLUTION_OPEN)->count());
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED, $resultImport->fresh()->status);
        $this->assertSame(OfflineScannerSession::STATUS_CLOSED, $fixture['session']->fresh()->status);
        $this->assertNotNull($fixture['session']->fresh()->results_imported_at);
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $fixture['session']->id,
            'action' => OfflineScannerSessionAudit::ACTION_CONFLICT_RESOLVED,
        ]);
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $fixture['session']->id,
            'action' => OfflineScannerSessionAudit::ACTION_EVENT_UNLOCKED,
        ]);
    }
    public function test_successful_import_preserves_offline_provenance_and_phone_admissions_are_supported(): void
    {
        $fixture = $this->fixture();
        $payload = $this->payload($fixture);
        $payload['admission_logs'][1]['admission_method'] = 'phone';
        $payload['metrics']['total_manual_admissions'] = 0;

        $resultImport = $this->previewResults($fixture, $payload, 'phone-admission.wqrresults');

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.confirm', $resultImport), [
                'confirm_import' => '1',
            ])
            ->assertRedirect(route('admin.offline-scanner-sessions.show', $fixture['session']));

        $qrLog = $payload['admission_logs'][0];
        $phoneLog = $payload['admission_logs'][1];
        $invalidLog = $payload['admission_logs'][2];

        $qrCheckin = Checkin::query()->where('offline_log_uuid', $qrLog['log_uuid'])->firstOrFail();
        $this->assertSame($fixture['guestOne']->id, $qrCheckin->guest_id);
        $this->assertSame($fixture['master']->id, $qrCheckin->user_id);
        $this->assertSame('Main Gate', $qrCheckin->gate_name);
        $this->assertSame(1, $qrCheckin->entries_added);
        $this->assertSame(1, $qrCheckin->used_entries_after_scan);
        $this->assertSame(1, $qrCheckin->remaining_entries_after_scan);
        $this->assertSame('android-master-1', $qrCheckin->offline_device_id);
        $this->assertSame('qr', $qrCheckin->offline_admission_method);
        $this->assertSame(1, $qrCheckin->offline_master_sequence_number);
        $this->assertSame($qrLog['request_uuid'], $qrCheckin->offline_request_uuid);
        $this->assertSame(\Illuminate\Support\Carbon::parse($qrLog['master_timestamp'])->toDateTimeString(), $qrCheckin->checked_in_at->toDateTimeString());

        $phoneCheckin = Checkin::query()->where('offline_log_uuid', $phoneLog['log_uuid'])->firstOrFail();
        $this->assertSame($fixture['guestTwo']->id, $phoneCheckin->guest_id);
        $this->assertSame($fixture['client']->id, $phoneCheckin->user_id);
        $this->assertSame('Garden Gate', $phoneCheckin->gate_name);
        $this->assertSame('android-client-2', $phoneCheckin->offline_device_id);
        $this->assertSame('phone', $phoneCheckin->offline_admission_method);
        $this->assertSame(2, $phoneCheckin->offline_master_sequence_number);
        $this->assertSame($phoneLog['request_uuid'], $phoneCheckin->offline_request_uuid);
        $this->assertSame(\Illuminate\Support\Carbon::parse($phoneLog['master_timestamp'])->toDateTimeString(), $phoneCheckin->checked_in_at->toDateTimeString());

        $this->assertDatabaseMissing('checkins', ['offline_log_uuid' => $invalidLog['log_uuid']]);
        $this->assertSame(2, Checkin::query()->where('scan_result', Checkin::RESULT_ADMITTED)->count());
        $this->assertSame(2, Admission::query()->count());
        $this->assertSame(1, $fixture['guestOne']->fresh()->used_entries);
        $this->assertSame(1, $fixture['guestTwo']->fresh()->used_entries);
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED, $resultImport->fresh()->status);
    }

    public function test_preview_detects_missing_deleted_changed_allowed_and_wrong_event_conflicts(): void
    {
        $missingFixture = $this->fixture();
        $missingPayload = $this->payload($missingFixture);
        $missingPayload['admission_logs'][0]['external_guest_id'] = 'missing-exported-guest';
        $missingPayload['final_guest_states'][0]['external_guest_id'] = 'missing-exported-guest';
        $missingImport = $this->previewResults($missingFixture, $missingPayload, 'missing-guest.wqrresults');
        $this->assertContains(
            OfflineScannerImportConflict::TYPE_GUEST_NOT_FOUND,
            $missingImport->conflicts()->pluck('conflict_type')->all(),
        );

        $deletedFixture = $this->fixture();
        $deletedPayload = $this->payload($deletedFixture);
        Guest::query()->whereKey($deletedFixture['guestOne']->id)->delete();
        $deletedImport = $this->previewResults($deletedFixture, $deletedPayload, 'deleted-guest.wqrresults');
        $this->assertContains(
            OfflineScannerImportConflict::TYPE_GUEST_DELETED,
            $deletedImport->conflicts()->pluck('conflict_type')->all(),
        );

        $allowedFixture = $this->fixture();
        $allowedPayload = $this->payload($allowedFixture);
        Guest::query()->whereKey($allowedFixture['guestOne']->id)->update(['allowed_entries' => 3]);
        $allowedImport = $this->previewResults($allowedFixture, $allowedPayload, 'allowed-changed.wqrresults');
        $this->assertContains(
            OfflineScannerImportConflict::TYPE_ALLOWED_ENTRIES_CHANGED,
            $allowedImport->conflicts()->pluck('conflict_type')->all(),
        );

        $wrongEventFixture = $this->fixture();
        $wrongEventPayload = $this->payload($wrongEventFixture);
        $otherEvent = Event::factory()->create();
        Guest::query()->whereKey($wrongEventFixture['guestOne']->id)->update(['event_id' => $otherEvent->id]);
        $wrongEventImport = $this->previewResults($wrongEventFixture, $wrongEventPayload, 'wrong-event-guest.wqrresults');
        $this->assertContains(
            OfflineScannerImportConflict::TYPE_WRONG_EVENT,
            $wrongEventImport->conflicts()->pluck('conflict_type')->all(),
        );
    }
    public function test_import_transaction_rolls_back_when_a_verified_log_is_tampered_before_confirmation(): void
    {
        $fixture = $this->fixture();
        $contents = $this->encryptedResultsFile($this->payload($fixture), $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('rollback.wqrresults', $contents),
            ]);

        $resultImport = OfflineScannerResultImport::query()->firstOrFail();
        $resultImport->importedLogs()
            ->where('result_code', OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY)
            ->orderBy('master_sequence_number')
            ->firstOrFail()
            ->update(['used_after' => 2]);

        try {
            app(OfflineScannerResultsImporter::class)->import($resultImport, $fixture['admin']);
            $this->fail('The tampered verified import should be rejected.');
        } catch (ValidationException) {
            // The importer must roll back status, guest, check-in, admission, and audit mutations together.
        }

        $this->assertSame(0, Admission::query()->count());
        $this->assertSame(0, Checkin::query()->count());
        $this->assertSame(0, $fixture['guestOne']->fresh()->used_entries);
        $this->assertSame(0, $fixture['guestTwo']->fresh()->used_entries);
        $this->assertSame(OfflineScannerResultImport::STATUS_PREVIEW_READY, $resultImport->fresh()->status);
        $this->assertSame(OfflineScannerSession::STATUS_RESULTS_UPLOADED, $fixture['session']->fresh()->status);
        $this->assertNull($fixture['session']->fresh()->results_imported_at);
    }
    public function test_upload_stores_reused_offline_identifiers_with_different_payloads_as_conflict(): void
    {
        $fixture = $this->fixture();
        $payload = $this->payload($fixture);
        $contents = $this->encryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('original.wqrresults', $contents),
            ])
            ->assertSessionHasNoErrors();

        $reused = $payload;
        $reused['session']['results_export_id'] = (string) Str::uuid();
        $reused['admission_logs'][0]['base_guest_record_version'] = hash('sha256', 'changed-replay-payload');
        $reusedContents = $this->encryptedResultsFile($reused, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('altered-replay.wqrresults', $reusedContents),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, OfflineScannerResultImport::query()->count());
        $conflictingImport = OfflineScannerResultImport::query()->latest('id')->firstOrFail();
        $this->assertSame(1, $conflictingImport->conflict_logs);
        $this->assertSame(1, $conflictingImport->importedLogs()->where('import_status', \App\Models\OfflineScannerImportedLog::STATUS_CONFLICT)->count());
        $types = $conflictingImport->conflicts()->pluck('conflict_type')->all();
        $this->assertContains(OfflineScannerImportConflict::TYPE_DUPLICATE_LOG_CONFLICT, $types);
        $this->assertContains(OfflineScannerImportConflict::TYPE_DUPLICATE_REQUEST_CONFLICT, $types);
        $this->assertContains(OfflineScannerImportConflict::TYPE_ALREADY_IMPORTED_DIFFERENT_PAYLOAD, $types);
    }
    public function test_upload_rejects_wrong_extension_tampered_authentication_and_duplicate_results_export(): void
    {
        $fixture = $this->fixture();
        $payload = $this->payload($fixture);
        $contents = $this->encryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('on-wedding-results.json', $contents),
            ])
            ->assertSessionHasErrors('offline_results');

        $tampered = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $tampered['authentication_tag'] = base64_encode(random_bytes(16));
        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('on-wedding-results.wqrresults', json_encode($tampered, JSON_THROW_ON_ERROR)),
            ])
            ->assertSessionHasErrors('offline_results');

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('on-wedding-results.wqrresults', $contents),
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('on-wedding-results.wqrresults', $contents),
            ])
            ->assertSessionHasErrors('offline_results');

        $this->assertSame(1, OfflineScannerResultImport::query()->count());
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $fixture['session']->id,
            'action' => OfflineScannerSessionAudit::ACTION_REIMPORT_ATTEMPTED,
        ]);
        $this->assertSame(0, $fixture['guestOne']->fresh()->used_entries);
    }
    public function test_upload_rejects_cancelled_session_and_out_of_order_master_sequences(): void
    {
        $fixture = $this->fixture();
        $fixture['session']->update(['status' => OfflineScannerSession::STATUS_CANCELLED]);
        $contents = $this->encryptedResultsFile($this->payload($fixture), $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('cancelled.wqrresults', $contents),
            ])
            ->assertSessionHasErrors('offline_results');

        $fixture = $this->fixture();
        $payload = $this->payload($fixture);
        $payload['admission_logs'][1]['master_sequence_number'] = 3;
        $payload['admission_logs'][2]['master_sequence_number'] = 2;
        $payload['final_guest_states'][1]['last_master_sequence_number'] = 3;
        $contents = $this->encryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('out-of-order.wqrresults', $contents),
            ])
            ->assertSessionHasErrors('offline_results');

        $this->assertSame(0, OfflineScannerResultImport::query()->count());
    }

    private function previewResults(array $fixture, array $payload, string $fileName): OfflineScannerResultImport
    {
        $contents = $this->encryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent($fileName, $contents),
            ])
            ->assertSessionHasNoErrors();

        return OfflineScannerResultImport::query()->latest('id')->firstOrFail();
    }
    private function offlineScannerDisk(): string
    {
        return (string) config('offline_scanner.disk', 'offline_scanner');
    }
    private function fixture(): array
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
        $guestOne = $this->guest($event, 'Asha Results Guest', '+255700000201', Guest::PASS_DOUBLE, 2, '1111');
        $guestTwo = $this->guest($event, 'Baraka Results Guest', '+255700000202', Guest::PASS_SINGLE, 1, '2222');
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

    private function guest(Event $event, string $name, string $phone, string $passType, int $allowedEntries, string $manualCode): Guest
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

    private function payload(array $fixture): array
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
                $this->log($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 1, 1, 0, 2, 1, 1),
                $this->log($guestTwo, $fixture['client'], 'android-client-2', 'Garden Gate', 'manual', 2, 1, 0, 1, 1, 0),
                $this->log($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 3, 0, 1, 1, 1, 1, OfflineScannerResultSchema::RESULT_INVALID_QR),
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

    private function log(
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

    private function encryptedResultsFile(array $payload, array $fixture): string
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
}

