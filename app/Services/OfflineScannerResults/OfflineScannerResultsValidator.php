<?php

namespace App\Services\OfflineScannerResults;

use App\Models\OfflineScannerSession;
use App\Services\CanonicalJson;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class OfflineScannerResultsValidator
{
    private const ENVELOPE_KEYS = [
        'format', 'result_schema_version', 'source_package_export_id', 'session_uuid',
        'results_export_id', 'event_id', 'master_device_id', 'encryption_algorithm',
        'initialization_vector', 'encrypted_payload', 'authentication_tag', 'payload_hash',
    ];

    private const PAYLOAD_KEYS = ['session', 'devices', 'admission_logs', 'final_guest_states', 'metrics'];

    private const SESSION_KEYS = [
        'session_uuid', 'package_export_id', 'results_export_id', 'event_id', 'master_device_id',
        'session_started_at', 'session_closed_at', 'final_master_sequence_number', 'exported_at',
    ];

    private const DEVICE_KEYS = [
        'device_id', 'device_name', 'device_role', 'scanner_user_id', 'scanner_user_name',
        'gate_name', 'paired_at', 'last_seen_at', 'admissions_count',
    ];

    private const LOG_KEYS = [
        'log_uuid', 'request_uuid', 'master_sequence_number', 'external_guest_id',
        'scanner_user_id', 'source_device_id', 'source_gate_name', 'admission_method',
        'qr_payload_hash', 'added_entries', 'used_before', 'remaining_before', 'used_after',
        'remaining_after', 'result_code', 'device_timestamp', 'master_timestamp',
        'monotonic_sequence', 'base_guest_record_version',
    ];

    private const FINAL_GUEST_STATE_KEYS = [
        'external_guest_id', 'final_used', 'final_remaining', 'final_record_version',
        'last_master_sequence_number',
    ];

    private const METRIC_KEYS = [
        'total_scans', 'total_admitted_people', 'total_invalid_scans',
        'total_manual_admissions', 'total_qr_admissions',
    ];

    public function __construct(private readonly CanonicalJson $json)
    {
    }

    public function validateFileName(string $fileName): string
    {
        $normalized = str_replace('\\', '/', trim($fileName));
        $baseName = basename($normalized);

        if ($baseName === ''
            || $normalized !== $baseName
            || strlen($baseName) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $baseName)) {
            throw ValidationException::withMessages([
                'offline_results' => 'Offline scanner results must use a safe .wqrresults filename.',
            ]);
        }

        if (! str_ends_with(strtolower($baseName), OfflineScannerResultSchema::FILE_EXTENSION)) {
            throw ValidationException::withMessages([
                'offline_results' => 'Offline scanner results must use the .wqrresults extension.',
            ]);
        }

        return $baseName;
    }
    public function validateEnvelope(array $envelope, ?OfflineScannerSession $session = null): ResultsEnvelope
    {
        $errors = [];
        $this->validateObjectKeys($envelope, self::ENVELOPE_KEYS, 'envelope', $errors);

        $this->expectExactString($envelope, 'format', OfflineScannerResultSchema::FORMAT, 'envelope.format', $errors);
        $this->expectExactString($envelope, 'result_schema_version', OfflineScannerResultSchema::SCHEMA_VERSION, 'envelope.result_schema_version', $errors);
        $this->expectUuid($envelope['source_package_export_id'] ?? null, 'envelope.source_package_export_id', $errors);
        $this->expectUuid($envelope['session_uuid'] ?? null, 'envelope.session_uuid', $errors);
        $this->expectUuid($envelope['results_export_id'] ?? null, 'envelope.results_export_id', $errors);
        $this->expectPositiveInt($envelope['event_id'] ?? null, 'envelope.event_id', $errors);
        $this->expectNonEmptyString($envelope['master_device_id'] ?? null, 'envelope.master_device_id', $errors, 191);
        $this->expectExactString($envelope, 'encryption_algorithm', OfflineScannerResultSchema::ENCRYPTION_ALGORITHM, 'envelope.encryption_algorithm', $errors);
        $this->expectBase64Bytes($envelope['initialization_vector'] ?? null, 'envelope.initialization_vector', $errors, 12);
        $this->expectBase64Bytes($envelope['encrypted_payload'] ?? null, 'envelope.encrypted_payload', $errors);
        $this->expectBase64Bytes($envelope['authentication_tag'] ?? null, 'envelope.authentication_tag', $errors, 16);
        $this->expectSha256($envelope['payload_hash'] ?? null, 'envelope.payload_hash', $errors);

        if ($errors !== []) {
            $this->throw($errors);
        }

        $dto = new ResultsEnvelope(
            format: $envelope['format'],
            resultSchemaVersion: $envelope['result_schema_version'],
            sourcePackageExportId: $envelope['source_package_export_id'],
            sessionUuid: $envelope['session_uuid'],
            resultsExportId: $envelope['results_export_id'],
            eventId: (int) $envelope['event_id'],
            masterDeviceId: $envelope['master_device_id'],
            encryptionAlgorithm: $envelope['encryption_algorithm'],
            initializationVector: $envelope['initialization_vector'],
            encryptedPayload: $envelope['encrypted_payload'],
            authenticationTag: $envelope['authentication_tag'],
            payloadHash: $envelope['payload_hash'],
        );

        if ($session) {
            $this->validateEnvelopeBinding($dto, $session, $errors);
        }

        if ($errors !== []) {
            $this->throw($errors);
        }

        return $dto;
    }

    public function validatePayload(array $payload, ResultsEnvelope $envelope, ?OfflineScannerSession $session = null): ResultsPayload
    {
        $errors = [];
        $this->validateObjectKeys($payload, self::PAYLOAD_KEYS, 'payload', $errors);

        if (hash('sha256', $this->json->encode($payload)) !== $envelope->payloadHash) {
            $errors[] = 'payload_hash does not match the decrypted results payload.';
        }

        $sessionData = $this->expectObject($payload['session'] ?? null, 'payload.session', $errors);
        $deviceRows = $this->expectList($payload['devices'] ?? null, 'payload.devices', $errors);
        $logRows = $this->expectList($payload['admission_logs'] ?? null, 'payload.admission_logs', $errors);
        $finalStateRows = $this->expectList($payload['final_guest_states'] ?? null, 'payload.final_guest_states', $errors);
        $metricsData = $this->expectObject($payload['metrics'] ?? null, 'payload.metrics', $errors);

        $payloadSession = $this->validateSession($sessionData, $envelope, $errors);
        $devices = $this->validateDevices($deviceRows, $envelope, $session, $errors);
        $admissionLogs = $this->validateAdmissionLogs($logRows, $devices, $errors);
        $finalGuestStates = $this->validateFinalGuestStates($finalStateRows, $payloadSession, $admissionLogs, $errors);
        $metrics = $this->validateMetrics($metricsData, $admissionLogs, $errors);

        $this->validateMasterExportShape($payloadSession, $devices, $admissionLogs, $metrics, $errors);

        if ($session) {
            $this->validatePayloadBinding($payloadSession, $envelope, $session, $errors);
        }

        if ($errors !== []) {
            $this->throw($errors);
        }

        return new ResultsPayload($payloadSession, $devices, $admissionLogs, $finalGuestStates, $metrics);
    }
    private function validateEnvelopeBinding(ResultsEnvelope $envelope, OfflineScannerSession $session, array &$errors): void
    {
        $session->loadMissing('packageExport');

        if ($envelope->sessionUuid !== $session->session_uuid) {
            $errors[] = 'Results envelope is not bound to this offline scanner session UUID.';
        }

        if ($envelope->eventId !== (int) $session->event_id) {
            $errors[] = 'Results envelope event_id does not match the offline scanner session event.';
        }

        $packageUuid = $session->packageExport?->package_uuid;
        if ($packageUuid && $envelope->sourcePackageExportId !== $packageUuid) {
            $errors[] = 'Results envelope source_package_export_id does not match this session package export.';
        }
    }

    private function validatePayloadBinding(
        ResultsSession $payloadSession,
        ResultsEnvelope $envelope,
        OfflineScannerSession $session,
        array &$errors,
    ): void {
        if ($payloadSession->sessionUuid !== $session->session_uuid) {
            $errors[] = 'Results payload session_uuid does not match the offline scanner session.';
        }

        if ($payloadSession->eventId !== (int) $session->event_id) {
            $errors[] = 'Results payload event_id does not match the offline scanner session event.';
        }

        if ($payloadSession->packageExportId !== $envelope->sourcePackageExportId) {
            $errors[] = 'Results payload package_export_id does not match the envelope source_package_export_id.';
        }
    }

    private function validateSession(array $session, ResultsEnvelope $envelope, array &$errors): ResultsSession
    {
        $this->validateObjectKeys($session, self::SESSION_KEYS, 'payload.session', $errors);
        $this->expectUuid($session['session_uuid'] ?? null, 'payload.session.session_uuid', $errors);
        $this->expectUuid($session['package_export_id'] ?? null, 'payload.session.package_export_id', $errors);
        $this->expectUuid($session['results_export_id'] ?? null, 'payload.session.results_export_id', $errors);
        $this->expectPositiveInt($session['event_id'] ?? null, 'payload.session.event_id', $errors);
        $this->expectNonEmptyString($session['master_device_id'] ?? null, 'payload.session.master_device_id', $errors, 191);
        $this->expectTimestamp($session['session_started_at'] ?? null, 'payload.session.session_started_at', $errors, true);
        $this->expectTimestamp($session['session_closed_at'] ?? null, 'payload.session.session_closed_at', $errors, true);
        $this->expectNonNegativeInt($session['final_master_sequence_number'] ?? null, 'payload.session.final_master_sequence_number', $errors);
        $this->expectTimestamp($session['exported_at'] ?? null, 'payload.session.exported_at', $errors);

        if (($session['session_uuid'] ?? null) !== $envelope->sessionUuid) {
            $errors[] = 'payload.session.session_uuid does not match envelope.session_uuid.';
        }

        if (($session['package_export_id'] ?? null) !== $envelope->sourcePackageExportId) {
            $errors[] = 'payload.session.package_export_id does not match envelope.source_package_export_id.';
        }

        if (($session['results_export_id'] ?? null) !== $envelope->resultsExportId) {
            $errors[] = 'payload.session.results_export_id does not match envelope.results_export_id.';
        }

        if ((int) ($session['event_id'] ?? 0) !== $envelope->eventId) {
            $errors[] = 'payload.session.event_id does not match envelope.event_id.';
        }

        if (($session['master_device_id'] ?? null) !== $envelope->masterDeviceId) {
            $errors[] = 'payload.session.master_device_id does not match envelope.master_device_id.';
        }

        return new ResultsSession(
            sessionUuid: (string) ($session['session_uuid'] ?? ''),
            packageExportId: (string) ($session['package_export_id'] ?? ''),
            resultsExportId: (string) ($session['results_export_id'] ?? ''),
            eventId: (int) ($session['event_id'] ?? 0),
            masterDeviceId: (string) ($session['master_device_id'] ?? ''),
            sessionStartedAt: $session['session_started_at'] ?? null,
            sessionClosedAt: $session['session_closed_at'] ?? null,
            finalMasterSequenceNumber: (int) ($session['final_master_sequence_number'] ?? 0),
            exportedAt: (string) ($session['exported_at'] ?? ''),
        );
    }

    /**
     * @return array<int, ResultsDevice>
     */
    private function validateDevices(array $devices, ResultsEnvelope $envelope, ?OfflineScannerSession $session, array &$errors): array
    {
        if ($devices === []) {
            $errors[] = 'payload.devices must include the Master scanner device.';
        }

        $sessionUsers = collect();
        if ($session) {
            $session->loadMissing('sessionUsers');
            $sessionUsers = $session->sessionUsers->keyBy('scanner_user_id');
        }

        $seenDeviceIds = [];
        $masterDeviceCount = 0;
        $validated = [];

        foreach ($devices as $index => $device) {
            $path = 'payload.devices.'.($index + 1);
            if (! is_array($device) || array_is_list($device)) {
                $errors[] = "$path must be an object.";
                continue;
            }

            $this->validateObjectKeys($device, self::DEVICE_KEYS, $path, $errors);
            $this->expectNonEmptyString($device['device_id'] ?? null, "$path.device_id", $errors, 191);
            $this->expectNonEmptyString($device['device_name'] ?? null, "$path.device_name", $errors, 191);
            $this->expectControlledValue($device['device_role'] ?? null, OfflineScannerResultSchema::deviceRoles(), "$path.device_role", $errors);
            $this->expectPositiveInt($device['scanner_user_id'] ?? null, "$path.scanner_user_id", $errors);
            $this->expectNonEmptyString($device['scanner_user_name'] ?? null, "$path.scanner_user_name", $errors, 191);
            $this->expectNonEmptyString($device['gate_name'] ?? null, "$path.gate_name", $errors, 120);
            $this->expectTimestamp($device['paired_at'] ?? null, "$path.paired_at", $errors, true);
            $this->expectTimestamp($device['last_seen_at'] ?? null, "$path.last_seen_at", $errors, true);
            $this->expectNonNegativeInt($device['admissions_count'] ?? null, "$path.admissions_count", $errors);

            $deviceId = (string) ($device['device_id'] ?? '');
            $role = (string) ($device['device_role'] ?? '');
            $scannerUserId = (int) ($device['scanner_user_id'] ?? 0);
            $gateName = (string) ($device['gate_name'] ?? '');

            if ($deviceId !== '' && isset($seenDeviceIds[$deviceId])) {
                $errors[] = "$path duplicates device_id $deviceId.";
            }
            $seenDeviceIds[$deviceId] = true;

            if ($role === 'master') {
                $masterDeviceCount++;
                if ($deviceId !== $envelope->masterDeviceId) {
                    $errors[] = "$path is marked master but does not match envelope.master_device_id.";
                }
            }

            if ($session) {
                $sessionUser = $sessionUsers->get($scannerUserId);
                if (! $sessionUser) {
                    $errors[] = "$path scanner_user_id is not authorized for this offline scanner session.";
                } else {
                    if ((string) $sessionUser->device_role !== $role) {
                        $errors[] = "$path device_role does not match the scanner user's authorized session role.";
                    }

                    if ((string) $sessionUser->assigned_gate !== $gateName) {
                        $errors[] = "$path gate_name does not match the scanner user's authorized session gate.";
                    }
                }
            }

            $validated[] = new ResultsDevice(
                deviceId: $deviceId,
                deviceName: (string) ($device['device_name'] ?? ''),
                deviceRole: $role,
                scannerUserId: $scannerUserId,
                scannerUserName: (string) ($device['scanner_user_name'] ?? ''),
                gateName: $gateName,
                pairedAt: $device['paired_at'] ?? null,
                lastSeenAt: $device['last_seen_at'] ?? null,
                admissionsCount: (int) ($device['admissions_count'] ?? 0),
            );
        }

        if ($masterDeviceCount !== 1) {
            $errors[] = 'payload.devices must contain exactly one Master scanner device.';
        }

        return $validated;
    }
    /**
     * @param array<int, ResultsDevice> $devices
     * @return array<int, ResultsAdmissionLog>
     */
    private function validateAdmissionLogs(array $logs, array $devices, array &$errors): array
    {
        $devicesById = [];
        foreach ($devices as $device) {
            $devicesById[$device->deviceId] = $device;
        }

        $seenLogUuids = [];
        $seenRequestUuids = [];
        $seenMasterSequences = [];
        $seenDeviceMonotonicSequences = [];
        $validated = [];

        foreach ($logs as $index => $log) {
            $path = 'payload.admission_logs.'.($index + 1);
            if (! is_array($log) || array_is_list($log)) {
                $errors[] = "$path must be an object.";
                continue;
            }

            $this->validateObjectKeys($log, self::LOG_KEYS, $path, $errors, ['qr_payload_hash']);
            $this->expectUuid($log['log_uuid'] ?? null, "$path.log_uuid", $errors);
            $this->expectUuid($log['request_uuid'] ?? null, "$path.request_uuid", $errors);
            $this->expectPositiveInt($log['master_sequence_number'] ?? null, "$path.master_sequence_number", $errors);
            $this->expectNonEmptyString($log['external_guest_id'] ?? null, "$path.external_guest_id", $errors, 191);
            $this->expectPositiveInt($log['scanner_user_id'] ?? null, "$path.scanner_user_id", $errors);
            $this->expectNonEmptyString($log['source_device_id'] ?? null, "$path.source_device_id", $errors, 191);
            $this->expectNonEmptyString($log['source_gate_name'] ?? null, "$path.source_gate_name", $errors, 120);
            $this->expectControlledValue($log['admission_method'] ?? null, OfflineScannerResultSchema::admissionMethods(), "$path.admission_method", $errors);
            $this->expectNonNegativeInt($log['added_entries'] ?? null, "$path.added_entries", $errors);
            $this->expectNonNegativeInt($log['used_before'] ?? null, "$path.used_before", $errors);
            $this->expectNonNegativeInt($log['remaining_before'] ?? null, "$path.remaining_before", $errors);
            $this->expectNonNegativeInt($log['used_after'] ?? null, "$path.used_after", $errors);
            $this->expectNonNegativeInt($log['remaining_after'] ?? null, "$path.remaining_after", $errors);
            $this->expectControlledValue($log['result_code'] ?? null, OfflineScannerResultSchema::resultCodes(), "$path.result_code", $errors);
            $this->expectTimestamp($log['device_timestamp'] ?? null, "$path.device_timestamp", $errors);
            $this->expectTimestamp($log['master_timestamp'] ?? null, "$path.master_timestamp", $errors);
            $this->expectPositiveInt($log['monotonic_sequence'] ?? null, "$path.monotonic_sequence", $errors);
            $this->expectSha256($log['base_guest_record_version'] ?? null, "$path.base_guest_record_version", $errors);

            $method = (string) ($log['admission_method'] ?? '');
            $qrPayloadHash = $log['qr_payload_hash'] ?? null;
            if ($qrPayloadHash !== null && $qrPayloadHash !== '') {
                $this->expectSha256($qrPayloadHash, "$path.qr_payload_hash", $errors);
            } elseif ($method === 'qr') {
                $errors[] = "$path.qr_payload_hash is required for QR admission results.";
            }

            $logUuid = (string) ($log['log_uuid'] ?? '');
            $requestUuid = (string) ($log['request_uuid'] ?? '');
            $masterSequence = (int) ($log['master_sequence_number'] ?? 0);
            $sourceDeviceId = (string) ($log['source_device_id'] ?? '');
            $scannerUserId = (int) ($log['scanner_user_id'] ?? 0);
            $sourceGateName = (string) ($log['source_gate_name'] ?? '');
            $monotonicSequence = (int) ($log['monotonic_sequence'] ?? 0);
            $addedEntries = (int) ($log['added_entries'] ?? 0);
            $usedBefore = (int) ($log['used_before'] ?? 0);
            $remainingBefore = (int) ($log['remaining_before'] ?? 0);
            $usedAfter = (int) ($log['used_after'] ?? 0);
            $remainingAfter = (int) ($log['remaining_after'] ?? 0);
            $resultCode = (string) ($log['result_code'] ?? '');

            if ($logUuid !== '' && isset($seenLogUuids[$logUuid])) {
                $errors[] = "$path duplicates log_uuid $logUuid.";
            }
            $seenLogUuids[$logUuid] = true;

            if ($requestUuid !== '' && isset($seenRequestUuids[$requestUuid])) {
                $errors[] = "$path duplicates request_uuid $requestUuid.";
            }
            $seenRequestUuids[$requestUuid] = true;

            if ($masterSequence > 0 && isset($seenMasterSequences[$masterSequence])) {
                $errors[] = "$path duplicates master_sequence_number $masterSequence.";
            }
            $seenMasterSequences[$masterSequence] = true;

            if (! isset($devicesById[$sourceDeviceId])) {
                $errors[] = "$path source_device_id is not present in payload.devices.";
            } else {
                $device = $devicesById[$sourceDeviceId];
                if ($device->scannerUserId !== $scannerUserId) {
                    $errors[] = "$path scanner_user_id does not match the source device scanner user.";
                }
                if ($device->gateName !== $sourceGateName) {
                    $errors[] = "$path source_gate_name does not match the source device gate.";
                }
            }

            if ($sourceDeviceId !== '' && $monotonicSequence > 0) {
                $seenDeviceMonotonicSequences[$sourceDeviceId] ??= [];
                if (isset($seenDeviceMonotonicSequences[$sourceDeviceId][$monotonicSequence])) {
                    $errors[] = "$path duplicates monotonic_sequence $monotonicSequence for source_device_id $sourceDeviceId.";
                }
                $seenDeviceMonotonicSequences[$sourceDeviceId][$monotonicSequence] = true;
            }

            if ($usedAfter !== $usedBefore + $addedEntries) {
                $errors[] = "$path used_after must equal used_before plus added_entries.";
            }

            if ($remainingAfter !== $remainingBefore - $addedEntries) {
                $errors[] = "$path remaining_after must equal remaining_before minus added_entries.";
            }

            if ($addedEntries > $remainingBefore) {
                $errors[] = "$path added_entries cannot exceed remaining_before.";
            }

            if ($resultCode === OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY && $addedEntries < 1) {
                $errors[] = "$path admitted_successfully must add at least one entry.";
            }

            if ($resultCode !== OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY && $addedEntries !== 0) {
                $errors[] = "$path non-admission result codes must not add entries.";
            }

            $validated[] = new ResultsAdmissionLog(
                logUuid: $logUuid,
                requestUuid: $requestUuid,
                masterSequenceNumber: $masterSequence,
                externalGuestId: (string) ($log['external_guest_id'] ?? ''),
                scannerUserId: $scannerUserId,
                sourceDeviceId: $sourceDeviceId,
                sourceGateName: $sourceGateName,
                admissionMethod: $method,
                qrPayloadHash: $qrPayloadHash ?: null,
                addedEntries: $addedEntries,
                usedBefore: $usedBefore,
                remainingBefore: $remainingBefore,
                usedAfter: $usedAfter,
                remainingAfter: $remainingAfter,
                resultCode: $resultCode,
                deviceTimestamp: (string) ($log['device_timestamp'] ?? ''),
                masterTimestamp: (string) ($log['master_timestamp'] ?? ''),
                monotonicSequence: $monotonicSequence,
                baseGuestRecordVersion: (string) ($log['base_guest_record_version'] ?? ''),
            );
        }

        return $validated;
    }

    /**
     * @param array<int, ResultsAdmissionLog> $logs
     * @return array<int, ResultsFinalGuestState>
     */
    private function validateFinalGuestStates(array $states, ResultsSession $session, array $logs, array &$errors): array
    {
        $seenGuestIds = [];
        $logSequences = array_fill_keys(array_map(fn (ResultsAdmissionLog $log): int => $log->masterSequenceNumber, $logs), true);
        $changedGuestIds = [];
        foreach ($logs as $log) {
            if ($log->resultCode === OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY) {
                $changedGuestIds[$log->externalGuestId] = true;
            }
        }

        $validated = [];

        foreach ($states as $index => $state) {
            $path = 'payload.final_guest_states.'.($index + 1);
            if (! is_array($state) || array_is_list($state)) {
                $errors[] = "$path must be an object.";
                continue;
            }

            $this->validateObjectKeys($state, self::FINAL_GUEST_STATE_KEYS, $path, $errors);
            $this->expectNonEmptyString($state['external_guest_id'] ?? null, "$path.external_guest_id", $errors, 191);
            $this->expectNonNegativeInt($state['final_used'] ?? null, "$path.final_used", $errors);
            $this->expectNonNegativeInt($state['final_remaining'] ?? null, "$path.final_remaining", $errors);
            $this->expectSha256($state['final_record_version'] ?? null, "$path.final_record_version", $errors);
            $this->expectPositiveInt($state['last_master_sequence_number'] ?? null, "$path.last_master_sequence_number", $errors);

            $externalGuestId = (string) ($state['external_guest_id'] ?? '');
            $lastSequence = (int) ($state['last_master_sequence_number'] ?? 0);

            if ($externalGuestId !== '' && isset($seenGuestIds[$externalGuestId])) {
                $errors[] = "$path duplicates external_guest_id $externalGuestId.";
            }
            $seenGuestIds[$externalGuestId] = true;
            unset($changedGuestIds[$externalGuestId]);

            if ($lastSequence > $session->finalMasterSequenceNumber) {
                $errors[] = "$path last_master_sequence_number cannot exceed final_master_sequence_number.";
            }

            if ($lastSequence > 0 && $logs !== [] && ! isset($logSequences[$lastSequence])) {
                $errors[] = "$path last_master_sequence_number does not reference an admission log sequence.";
            }

            $validated[] = new ResultsFinalGuestState(
                externalGuestId: $externalGuestId,
                finalUsed: (int) ($state['final_used'] ?? 0),
                finalRemaining: (int) ($state['final_remaining'] ?? 0),
                finalRecordVersion: (string) ($state['final_record_version'] ?? ''),
                lastMasterSequenceNumber: $lastSequence,
            );
        }

        foreach (array_keys($changedGuestIds) as $missingGuestId) {
            $errors[] = "payload.final_guest_states is missing changed guest $missingGuestId.";
        }

        return $validated;
    }
    /**
     * @param array<int, ResultsAdmissionLog> $logs
     */
    private function validateMetrics(array $metrics, array $logs, array &$errors): ResultsMetrics
    {
        $this->validateObjectKeys($metrics, self::METRIC_KEYS, 'payload.metrics', $errors);

        foreach (self::METRIC_KEYS as $key) {
            $this->expectNonNegativeInt($metrics[$key] ?? null, 'payload.metrics.'.$key, $errors);
        }

        $totalScans = count($logs);
        $totalAdmittedPeople = array_sum(array_map(
            fn (ResultsAdmissionLog $log): int => $log->resultCode === OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY ? $log->addedEntries : 0,
            $logs,
        ));
        $totalInvalidScans = count(array_filter(
            $logs,
            fn (ResultsAdmissionLog $log): bool => in_array($log->resultCode, OfflineScannerResultSchema::invalidResultCodes(), true),
        ));
        $totalManualAdmissions = count(array_filter(
            $logs,
            fn (ResultsAdmissionLog $log): bool => $log->admissionMethod === 'manual'
                && $log->resultCode === OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY,
        ));
        $totalQrAdmissions = count(array_filter(
            $logs,
            fn (ResultsAdmissionLog $log): bool => $log->admissionMethod === 'qr'
                && $log->resultCode === OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY,
        ));

        $expected = [
            'total_scans' => $totalScans,
            'total_admitted_people' => $totalAdmittedPeople,
            'total_invalid_scans' => $totalInvalidScans,
            'total_manual_admissions' => $totalManualAdmissions,
            'total_qr_admissions' => $totalQrAdmissions,
        ];

        foreach ($expected as $key => $value) {
            if ((int) ($metrics[$key] ?? -1) !== $value) {
                $errors[] = "payload.metrics.$key must equal $value.";
            }
        }

        return new ResultsMetrics(
            totalScans: (int) ($metrics['total_scans'] ?? 0),
            totalAdmittedPeople: (int) ($metrics['total_admitted_people'] ?? 0),
            totalInvalidScans: (int) ($metrics['total_invalid_scans'] ?? 0),
            totalManualAdmissions: (int) ($metrics['total_manual_admissions'] ?? 0),
            totalQrAdmissions: (int) ($metrics['total_qr_admissions'] ?? 0),
        );
    }

    /**
     * @param array<int, ResultsDevice> $devices
     * @param array<int, ResultsAdmissionLog> $logs
     */
    private function validateMasterExportShape(
        ResultsSession $session,
        array $devices,
        array $logs,
        ResultsMetrics $metrics,
        array &$errors,
    ): void {
        $maxMasterSequence = $logs === [] ? 0 : max(array_map(fn (ResultsAdmissionLog $log): int => $log->masterSequenceNumber, $logs));

        if ($session->finalMasterSequenceNumber !== $maxMasterSequence) {
            $errors[] = 'payload.session.final_master_sequence_number must equal the highest master_sequence_number.';
        }

        $masterDevices = array_filter($devices, fn (ResultsDevice $device): bool => $device->deviceRole === 'master');
        if (count($masterDevices) !== 1) {
            $errors[] = 'Only the Android Master export is accepted; payload.devices must contain one master device.';
        }

        $admittedLogCountsByDevice = [];
        foreach ($logs as $log) {
            if ($log->resultCode === OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY) {
                $admittedLogCountsByDevice[$log->sourceDeviceId] = ($admittedLogCountsByDevice[$log->sourceDeviceId] ?? 0) + 1;
            }
        }

        foreach ($devices as $device) {
            $expectedAdmissions = $admittedLogCountsByDevice[$device->deviceId] ?? 0;
            if ($device->admissionsCount !== $expectedAdmissions) {
                $errors[] = "payload.devices device {$device->deviceId} admissions_count must equal $expectedAdmissions.";
            }
        }

        if ($metrics->totalScans !== count($logs)) {
            $errors[] = 'payload.metrics.total_scans does not match admission_logs count.';
        }
    }

    private function validateObjectKeys(array $data, array $allowedKeys, string $path, array &$errors, array $optionalKeys = []): void
    {
        $allowed = array_flip($allowedKeys);
        $optional = array_flip($optionalKeys);

        foreach (array_keys($data) as $key) {
            if (! isset($allowed[$key])) {
                $errors[] = "$path contains unsupported field $key.";
            }
        }

        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $data) && ! isset($optional[$key])) {
                $errors[] = "$path is missing required field $key.";
            }
        }
    }

    private function expectObject(mixed $value, string $path, array &$errors): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $errors[] = "$path must be an object.";
            return [];
        }

        return $value;
    }

    private function expectList(mixed $value, string $path, array &$errors): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $errors[] = "$path must be a list.";
            return [];
        }

        return $value;
    }

    private function expectExactString(array $data, string $key, string $expected, string $path, array &$errors): void
    {
        if (($data[$key] ?? null) !== $expected) {
            $errors[] = "$path must be $expected.";
        }
    }

    private function expectControlledValue(mixed $value, array $allowed, string $path, array &$errors): void
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $errors[] = "$path contains an unsupported value.";
        }
    }

    private function expectNonEmptyString(mixed $value, string $path, array &$errors, int $maxLength): void
    {
        if (! is_string($value) || trim($value) === '') {
            $errors[] = "$path must be a non-empty string.";
            return;
        }

        if (strlen($value) > $maxLength) {
            $errors[] = "$path must be $maxLength characters or fewer.";
        }
    }

    private function expectUuid(mixed $value, string $path, array &$errors): void
    {
        if (! is_string($value) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            $errors[] = "$path must be a UUID.";
        }
    }

    private function expectSha256(mixed $value, string $path, array &$errors): void
    {
        if (! is_string($value) || ! preg_match('/^[a-f0-9]{64}$/', $value)) {
            $errors[] = "$path must be a SHA-256 hex digest.";
        }
    }

    private function expectBase64Bytes(mixed $value, string $path, array &$errors, ?int $expectedBytes = null): void
    {
        if (! is_string($value) || $value === '') {
            $errors[] = "$path must be base64 encoded bytes.";
            return;
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            $errors[] = "$path must be valid base64.";
            return;
        }

        if ($expectedBytes !== null && strlen($decoded) !== $expectedBytes) {
            $errors[] = "$path must decode to $expectedBytes bytes.";
        }
    }

    private function expectPositiveInt(mixed $value, string $path, array &$errors): void
    {
        if (! is_int($value) || $value < 1) {
            $errors[] = "$path must be a positive integer.";
        }
    }

    private function expectNonNegativeInt(mixed $value, string $path, array &$errors): void
    {
        if (! is_int($value) || $value < 0) {
            $errors[] = "$path must be a non-negative integer.";
        }
    }

    private function expectTimestamp(mixed $value, string $path, array &$errors, bool $nullable = false): void
    {
        if ($value === null && $nullable) {
            return;
        }

        if (! is_string($value) || trim($value) === '') {
            $errors[] = "$path must be an ISO-8601 timestamp.";
            return;
        }

        try {
            Carbon::parse($value);
        } catch (\Throwable) {
            $errors[] = "$path must be an ISO-8601 timestamp.";
        }
    }

    private function throw(array $errors): never
    {
        throw ValidationException::withMessages(['offline_results' => $errors]);
    }
}