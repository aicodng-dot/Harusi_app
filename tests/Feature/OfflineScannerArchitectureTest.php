<?php

namespace Tests\Feature;

use App\Exceptions\OfflineScannerSessionAlreadyActiveException;
use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerFinalGuestState;
use App\Models\OfflineScannerResultDevice;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionUser;
use App\Models\User;
use App\Services\OfflineScannerSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class OfflineScannerArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_models_and_relationships_preserve_the_session_audit_chain(): void
    {
        $event = Event::factory()->create();
        $admin = User::factory()->create();
        $scanner = User::factory()->state([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Main Gate',
        ])->create();
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => 'Offline Guest',
            'phone_number' => '+255700000001',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);
        $session = OfflineScannerSession::factory()->create([
            'event_id' => $event->id,
            'created_by_user_id' => $admin->id,
            'master_scanner_user_id' => $scanner->id,
            'master_gate_name' => $scanner->gate_name,
        ]);
        $sessionUser = OfflineScannerSessionUser::factory()->master()->create([
            'offline_scanner_session_id' => $session->id,
            'scanner_user_id' => $scanner->id,
        ]);
        $package = OfflineScannerPackage::factory()->create([
            'offline_scanner_session_id' => $session->id,
            'generated_by_user_id' => $admin->id,
        ]);
        $session->update(['package_export_id' => $package->id]);
        $resultImport = OfflineScannerResultImport::factory()->create([
            'offline_scanner_session_id' => $session->id,
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
        ]);
        $resultDevice = OfflineScannerResultDevice::factory()->master()->create([
            'offline_scanner_result_import_id' => $resultImport->id,
            'offline_scanner_session_id' => $session->id,
            'scanner_user_id' => $scanner->id,
            'gate_name' => $scanner->gate_name,
        ]);
        $finalGuestState = OfflineScannerFinalGuestState::factory()->create([
            'offline_scanner_result_import_id' => $resultImport->id,
            'offline_scanner_session_id' => $session->id,
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'external_guest_id' => (string) $guest->id,
        ]);
        $log = OfflineScannerImportedLog::factory()->create([
            'offline_scanner_result_import_id' => $resultImport->id,
            'offline_scanner_session_id' => $session->id,
            'scanner_user_id' => $scanner->id,
        ]);
        $conflict = OfflineScannerImportConflict::factory()->create([
            'offline_scanner_result_import_id' => $resultImport->id,
            'offline_scanner_imported_log_id' => $log->id,
            'event_id' => $event->id,
            'guest_id' => $guest->id,
        ]);

        $session->refresh();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $session->session_uuid);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $package->package_uuid);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $resultImport->import_uuid);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $log->log_uuid);
        $this->assertTrue($event->offlineScannerSessions->first()->is($session));
        $this->assertTrue($session->createdBy->is($admin));
        $this->assertTrue($session->masterScannerUser->is($scanner));
        $this->assertTrue($session->sessionUsers->first()->is($sessionUser));
        $this->assertTrue($session->packageExport->is($package));
        $this->assertTrue($session->packages->first()->is($package));
        $this->assertTrue($session->resultImports->first()->is($resultImport));
        $this->assertTrue($resultImport->devices->first()->is($resultDevice));
        $this->assertTrue($resultImport->finalGuestStates->first()->is($finalGuestState));
        $this->assertTrue($session->resultDevices->first()->is($resultDevice));
        $this->assertTrue($session->finalGuestStates->first()->is($finalGuestState));
        $this->assertTrue($resultDevice->session->is($session));
        $this->assertTrue($finalGuestState->guest->is($guest));
        $this->assertTrue($resultImport->importedLogs->first()->is($log));
        $this->assertTrue($resultImport->conflicts->first()->is($conflict));
        $this->assertTrue($log->conflict->is($conflict));
        $this->assertTrue($conflict->guest->is($guest));
    }

    public function test_service_and_database_allow_only_one_blocking_session_per_event(): void
    {
        $event = Event::factory()->create();
        $admin = User::factory()->create();
        $service = app(OfflineScannerSessionService::class);

        $first = $service->createDraft($event, $admin);
        $service->transition($first, OfflineScannerSession::STATUS_PACKAGE_GENERATED);

        $second = $service->createDraft($event, $admin);

        try {
            $service->transition($second, OfflineScannerSession::STATUS_ACTIVE);
            $this->fail('A second blocking session should not be activated.');
        } catch (OfflineScannerSessionAlreadyActiveException) {
            $this->assertSame(OfflineScannerSession::STATUS_DRAFT, $second->fresh()->status);
        }

        $this->expectException(QueryException::class);

        OfflineScannerSession::query()->create([
            'event_id' => $event->id,
            'created_by_user_id' => $admin->id,
            'status' => OfflineScannerSession::STATUS_ACTIVE,
        ]);
    }

    public function test_imported_log_idempotency_keys_can_be_reused_for_explicit_conflict_review(): void
    {
        $event = Event::factory()->create();
        $admin = User::factory()->create();
        $session = OfflineScannerSession::factory()->create([
            'event_id' => $event->id,
            'created_by_user_id' => $admin->id,
        ]);
        $resultImport = OfflineScannerResultImport::factory()->create([
            'offline_scanner_session_id' => $session->id,
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
        ]);

        $log = OfflineScannerImportedLog::factory()->create([
            'offline_scanner_result_import_id' => $resultImport->id,
            'offline_scanner_session_id' => $session->id,
        ]);

        $conflictingLog = OfflineScannerImportedLog::query()->create([
            'offline_scanner_result_import_id' => $resultImport->id,
            'offline_scanner_session_id' => $session->id,
            'log_uuid' => $log->log_uuid,
            'request_uuid' => $log->request_uuid,
            'master_sequence_number' => $log->master_sequence_number,
            'result_code' => 'admitted_successfully',
            'import_status' => OfflineScannerImportedLog::STATUS_CONFLICT,
            'conflict_reason' => 'Identifier reused with different payload content.',
            'raw_payload_hash' => hash('sha256', 'different-payload'),
        ]);

        $this->assertNotSame($log->id, $conflictingLog->id);
        $this->assertSame(2, OfflineScannerImportedLog::query()->where('log_uuid', $log->log_uuid)->count());
        $this->assertSame(OfflineScannerImportedLog::STATUS_CONFLICT, $conflictingLog->import_status);
    }
    public function test_offline_policies_allow_administrators_and_reject_scanner_users(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $scanner = User::factory()->create(['role' => User::ROLE_SCANNER, 'gate_name' => 'Main Gate']);
        $session = OfflineScannerSession::factory()->active()->create([
            'created_by_user_id' => $admin->id,
        ]);
        $package = OfflineScannerPackage::factory()->create([
            'offline_scanner_session_id' => $session->id,
            'generated_by_user_id' => $admin->id,
        ]);
        $session->update(['package_export_id' => $package->id]);
        $session = $session->fresh();

        $this->assertTrue($admin->can('viewAny', OfflineScannerSession::class));
        $this->assertTrue($admin->can('create', OfflineScannerSession::class));
        $this->assertTrue($admin->can('view', $session));
        $this->assertTrue($admin->can('download', $session));
        $this->assertTrue($admin->can('cancel', $session));
        $this->assertTrue($admin->can('create', OfflineScannerResultImport::class));
        $this->assertFalse($scanner->can('viewAny', OfflineScannerSession::class));
        $this->assertFalse($scanner->can('create', OfflineScannerSession::class));
        $this->assertFalse($scanner->can('view', $session));
        $this->assertFalse($scanner->can('download', $session));
        $this->assertFalse($scanner->can('cancel', $session));
        $this->assertFalse($scanner->can('create', OfflineScannerResultImport::class));
    }
}
