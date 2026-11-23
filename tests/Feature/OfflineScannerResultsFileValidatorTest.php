<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionUser;
use App\Models\User;
use App\Services\CanonicalJson;
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use App\Services\OfflineScannerResults\OfflineScannerResultsCryptoService;
use App\Services\OfflineScannerResults\OfflineScannerResultsValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfflineScannerResultsFileValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_validator_accepts_bound_authoritative_master_results_export(): void
    {
        [$session, $package, $master, $client, $resultKey] = $this->sessionFixture();
        $payload = $this->validPayload($session, $package, $master, $client);
        $envelope = $this->encryptedEnvelopeForPayload($payload, $session, $package, $resultKey);

        $validator = app(OfflineScannerResultsValidator::class);
        $validator->validateFileName('on-wedding-results.wqrresults');
        $validatedEnvelope = $validator->validateEnvelope($envelope, $session);
        $validatedPayload = $validator->validatePayload($payload, $validatedEnvelope, $session);

        $this->assertSame(OfflineScannerResultSchema::FORMAT, $validatedEnvelope->format);
        $this->assertSame($session->session_uuid, $validatedPayload->session->sessionUuid);
        $this->assertSame($package->package_uuid, $validatedPayload->session->packageExportId);
        $this->assertSame('android-master-1', $validatedPayload->session->masterDeviceId);
        $this->assertCount(2, $validatedPayload->devices);
        $this->assertCount(2, $validatedPayload->admissionLogs);
        $this->assertSame(2, $validatedPayload->metrics->totalAdmittedPeople);
    }

    public function test_crypto_service_decrypts_and_validates_aes_gcm_bound_results_file(): void
    {
        [$session, $package, $master, $client, $resultKey] = $this->sessionFixture();
        $payload = $this->validPayload($session, $package, $master, $client);
        $envelope = $this->encryptedEnvelopeForPayload($payload, $session, $package, $resultKey);

        $validatedPayload = app(OfflineScannerResultsCryptoService::class)
            ->decryptAndValidate($session, $envelope);

        $this->assertSame(2, $validatedPayload->metrics->totalScans);
        $this->assertSame(1, $validatedPayload->metrics->totalQrAdmissions);
        $this->assertSame(1, $validatedPayload->metrics->totalManualAdmissions);
    }

    public function test_validator_rejects_client_only_results_export(): void
    {
        [$session, $package, $master, $client, $resultKey] = $this->sessionFixture();
        $payload = $this->validPayload($session, $package, $master, $client);
        $payload['devices'][0]['device_role'] = 'client';
        $payload['devices'][0]['device_id'] = 'android-client-5';
        $payload['session']['master_device_id'] = 'android-client-5';
        $envelope = $this->encryptedEnvelopeForPayload($payload, $session, $package, $resultKey, 'android-client-5');
        $validatedEnvelope = app(OfflineScannerResultsValidator::class)->validateEnvelope($envelope, $session);

        try {
            app(OfflineScannerResultsValidator::class)->validatePayload($payload, $validatedEnvelope, $session);
            $this->fail('Client-only results should not validate as an authoritative Master export.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('exactly one Master scanner device', implode(' ', $exception->errors()['offline_results']));
        }
    }

    public function test_validator_rejects_bad_extension_unbound_package_arbitrary_codes_and_metric_mismatch(): void
    {
        [$session, $package, $master, $client, $resultKey] = $this->sessionFixture();
        $validator = app(OfflineScannerResultsValidator::class);

        try {
            $validator->validateFileName('results.json');
            $this->fail('Invalid result extension should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('.wqrresults', $exception->errors()['offline_results'][0]);
        }

        $payload = $this->validPayload($session, $package, $master, $client);
        $envelope = $this->encryptedEnvelopeForPayload($payload, $session, $package, $resultKey);
        $envelope['source_package_export_id'] = (string) Str::uuid();

        try {
            $validator->validateEnvelope($envelope, $session);
            $this->fail('Unbound source package export IDs should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('source_package_export_id', implode(' ', $exception->errors()['offline_results']));
        }

        $payload = $this->validPayload($session, $package, $master, $client);
        $payload['admission_logs'][0]['result_code'] = 'random_android_code';
        $payload['metrics']['total_qr_admissions'] = 1;
        $envelope = $this->encryptedEnvelopeForPayload($payload, $session, $package, $resultKey);
        $validatedEnvelope = $validator->validateEnvelope($envelope, $session);

        try {
            $validator->validatePayload($payload, $validatedEnvelope, $session);
            $this->fail('Unsupported result codes should be rejected.');
        } catch (ValidationException $exception) {
            $messages = implode(' ', $exception->errors()['offline_results']);
            $this->assertStringContainsString('result_code contains an unsupported value', $messages);
            $this->assertStringContainsString('payload.metrics.total_qr_admissions must equal 0', $messages);
        }
    }

    public function test_validator_rejects_wrong_session_package_event_master_schema_sequences_authorization_and_counts(): void
    {
        [$session, $package, $master, $client, $resultKey] = $this->sessionFixture();
        $validator = app(OfflineScannerResultsValidator::class);
        $payload = $this->validPayload($session, $package, $master, $client);
        $envelope = $this->encryptedEnvelopeForPayload($payload, $session, $package, $resultKey);

        foreach ([
            ['session_uuid', (string) Str::uuid(), 'session UUID'],
            ['source_package_export_id', (string) Str::uuid(), 'source_package_export_id'],
            ['event_id', $session->event_id + 1000, 'event_id'],
            ['result_schema_version', '9.9', 'result_schema_version'],
        ] as [$field, $value, $needle]) {
            $mutatedEnvelope = $envelope;
            $mutatedEnvelope[$field] = $value;

            try {
                $validator->validateEnvelope($mutatedEnvelope, $session);
                $this->fail("Expected envelope field {$field} to be rejected.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($needle, implode(' ', $exception->errors()['offline_results']));
            }
        }

        $unknownMasterPayload = $this->validPayload($session, $package, $master, $client);
        $unknownMasterPayload['session']['master_device_id'] = 'unknown-master-device';
        $unknownMasterEnvelope = $this->encryptedEnvelopeForPayload(
            $unknownMasterPayload,
            $session,
            $package,
            $resultKey,
            'unknown-master-device',
        );
        $validatedEnvelope = $validator->validateEnvelope($unknownMasterEnvelope, $session);

        try {
            $validator->validatePayload($unknownMasterPayload, $validatedEnvelope, $session);
            $this->fail('Unknown Master device identity should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('does not match envelope.master_device_id', implode(' ', $exception->errors()['offline_results']));
        }

        $unauthorizedPayload = $this->validPayload($session, $package, $master, $client);
        $unauthorizedPayload['devices'][1]['scanner_user_id'] = User::factory()->create(['role' => User::ROLE_SCANNER])->id;
        $unauthorizedPayload['admission_logs'][1]['scanner_user_id'] = $unauthorizedPayload['devices'][1]['scanner_user_id'];
        $unauthorizedEnvelope = $this->encryptedEnvelopeForPayload($unauthorizedPayload, $session, $package, $resultKey);
        $validatedEnvelope = $validator->validateEnvelope($unauthorizedEnvelope, $session);

        try {
            $validator->validatePayload($unauthorizedPayload, $validatedEnvelope, $session);
            $this->fail('Unauthorized scanner devices should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('is not authorized for this offline scanner session', implode(' ', $exception->errors()['offline_results']));
        }

        $duplicateSequencePayload = $this->validPayload($session, $package, $master, $client);
        $duplicateSequencePayload['admission_logs'][1]['master_sequence_number'] = 1;
        $duplicateSequenceEnvelope = $this->encryptedEnvelopeForPayload($duplicateSequencePayload, $session, $package, $resultKey);
        $validatedEnvelope = $validator->validateEnvelope($duplicateSequenceEnvelope, $session);

        try {
            $validator->validatePayload($duplicateSequencePayload, $validatedEnvelope, $session);
            $this->fail('Duplicate Master sequence numbers should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('duplicates master_sequence_number 1', implode(' ', $exception->errors()['offline_results']));
        }

        $invalidCountPayload = $this->validPayload($session, $package, $master, $client);
        $invalidCountPayload['admission_logs'][0]['used_after'] = 2;
        $invalidCountEnvelope = $this->encryptedEnvelopeForPayload($invalidCountPayload, $session, $package, $resultKey);
        $validatedEnvelope = $validator->validateEnvelope($invalidCountEnvelope, $session);

        try {
            $validator->validatePayload($invalidCountPayload, $validatedEnvelope, $session);
            $this->fail('Impossible count transitions should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('used_after must equal used_before plus added_entries', implode(' ', $exception->errors()['offline_results']));
        }
    }
    private function sessionFixture(): array
    {
        $event = Event::factory()->create();
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
        $resultKey = random_bytes(32);

        $session = OfflineScannerSession::factory()->active()->create([
            'event_id' => $event->id,
            'created_by_user_id' => $admin->id,
            'master_scanner_user_id' => $master->id,
            'master_gate_name' => 'Main Gate',
            'package_created_at' => now()->subHour(),
            'package_expires_at' => now()->addDay(),
            'result_file_encryption_secret_encrypted' => Crypt::encryptString(base64_encode($resultKey)),
        ]);
        $package = OfflineScannerPackage::factory()->create([
            'offline_scanner_session_id' => $session->id,
            'generated_by_user_id' => $admin->id,
        ]);
        $session->update(['package_export_id' => $package->id]);

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

        return [$session->fresh(['packageExport', 'sessionUsers']), $package, $master, $client, $resultKey];
    }

    private function validPayload(OfflineScannerSession $session, OfflineScannerPackage $package, User $master, User $client): array
    {
        $exportedAt = now()->toJSON();
        $resultsExportId = (string) Str::uuid();
        $baseVersionOne = hash('sha256', 'guest-1-base');
        $baseVersionTwo = hash('sha256', 'guest-2-base');

        return [
            'session' => [
                'session_uuid' => $session->session_uuid,
                'package_export_id' => $package->package_uuid,
                'results_export_id' => $resultsExportId,
                'event_id' => (int) $session->event_id,
                'master_device_id' => 'android-master-1',
                'session_started_at' => now()->subHour()->toJSON(),
                'session_closed_at' => now()->toJSON(),
                'final_master_sequence_number' => 2,
                'exported_at' => $exportedAt,
            ],
            'devices' => [
                [
                    'device_id' => 'android-master-1',
                    'device_name' => 'Master Pixel',
                    'device_role' => 'master',
                    'scanner_user_id' => $master->id,
                    'scanner_user_name' => $master->name,
                    'gate_name' => 'Main Gate',
                    'paired_at' => now()->subHour()->toJSON(),
                    'last_seen_at' => now()->toJSON(),
                    'admissions_count' => 1,
                ],
                [
                    'device_id' => 'android-client-2',
                    'device_name' => 'Client Pixel 2',
                    'device_role' => 'client',
                    'scanner_user_id' => $client->id,
                    'scanner_user_name' => $client->name,
                    'gate_name' => 'Garden Gate',
                    'paired_at' => now()->subHour()->toJSON(),
                    'last_seen_at' => now()->toJSON(),
                    'admissions_count' => 1,
                ],
            ],
            'admission_logs' => [
                [
                    'log_uuid' => (string) Str::uuid(),
                    'request_uuid' => (string) Str::uuid(),
                    'master_sequence_number' => 1,
                    'external_guest_id' => 'guest-1',
                    'scanner_user_id' => $master->id,
                    'source_device_id' => 'android-master-1',
                    'source_gate_name' => 'Main Gate',
                    'admission_method' => 'qr',
                    'qr_payload_hash' => hash('sha256', 'qr-payload-1'),
                    'added_entries' => 1,
                    'used_before' => 0,
                    'remaining_before' => 2,
                    'used_after' => 1,
                    'remaining_after' => 1,
                    'result_code' => OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY,
                    'device_timestamp' => now()->subMinutes(20)->toJSON(),
                    'master_timestamp' => now()->subMinutes(19)->toJSON(),
                    'monotonic_sequence' => 1,
                    'base_guest_record_version' => $baseVersionOne,
                ],
                [
                    'log_uuid' => (string) Str::uuid(),
                    'request_uuid' => (string) Str::uuid(),
                    'master_sequence_number' => 2,
                    'external_guest_id' => 'guest-2',
                    'scanner_user_id' => $client->id,
                    'source_device_id' => 'android-client-2',
                    'source_gate_name' => 'Garden Gate',
                    'admission_method' => 'manual',
                    'added_entries' => 1,
                    'used_before' => 0,
                    'remaining_before' => 1,
                    'used_after' => 1,
                    'remaining_after' => 0,
                    'result_code' => OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY,
                    'device_timestamp' => now()->subMinutes(10)->toJSON(),
                    'master_timestamp' => now()->subMinutes(9)->toJSON(),
                    'monotonic_sequence' => 1,
                    'base_guest_record_version' => $baseVersionTwo,
                ],
            ],
            'final_guest_states' => [
                [
                    'external_guest_id' => 'guest-1',
                    'final_used' => 1,
                    'final_remaining' => 1,
                    'final_record_version' => hash('sha256', 'guest-1-final'),
                    'last_master_sequence_number' => 1,
                ],
                [
                    'external_guest_id' => 'guest-2',
                    'final_used' => 1,
                    'final_remaining' => 0,
                    'final_record_version' => hash('sha256', 'guest-2-final'),
                    'last_master_sequence_number' => 2,
                ],
            ],
            'metrics' => [
                'total_scans' => 2,
                'total_admitted_people' => 2,
                'total_invalid_scans' => 0,
                'total_manual_admissions' => 1,
                'total_qr_admissions' => 1,
            ],
        ];
    }

    private function encryptedEnvelopeForPayload(
        array $payload,
        OfflineScannerSession $session,
        OfflineScannerPackage $package,
        string $resultKey,
        string $masterDeviceId = 'android-master-1',
    ): array {
        $json = app(CanonicalJson::class);
        $payloadHash = hash('sha256', $json->encode($payload));
        $iv = random_bytes(12);
        $tag = '';
        $binding = [
            'event_id' => (int) $session->event_id,
            'format' => OfflineScannerResultSchema::FORMAT,
            'master_device_id' => $masterDeviceId,
            'payload_hash' => $payloadHash,
            'result_schema_version' => OfflineScannerResultSchema::SCHEMA_VERSION,
            'results_export_id' => $payload['session']['results_export_id'],
            'session_uuid' => $session->session_uuid,
            'source_package_export_id' => $package->package_uuid,
        ];
        $ciphertext = openssl_encrypt(
            $json->encode($payload),
            'aes-256-gcm',
            $resultKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $json->encode($binding),
        );

        $this->assertNotFalse($ciphertext);

        return [
            'format' => OfflineScannerResultSchema::FORMAT,
            'result_schema_version' => OfflineScannerResultSchema::SCHEMA_VERSION,
            'source_package_export_id' => $package->package_uuid,
            'session_uuid' => $session->session_uuid,
            'results_export_id' => $payload['session']['results_export_id'],
            'event_id' => (int) $session->event_id,
            'master_device_id' => $masterDeviceId,
            'encryption_algorithm' => OfflineScannerResultSchema::ENCRYPTION_ALGORITHM,
            'initialization_vector' => base64_encode($iv),
            'encrypted_payload' => base64_encode($ciphertext),
            'authentication_tag' => base64_encode($tag),
            'payload_hash' => $payloadHash,
        ];
    }
}
