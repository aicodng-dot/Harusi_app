<?php

namespace App\Services\OfflineScannerResults;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerFinalGuestState;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerResultDevice;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\User;
use App\Services\CanonicalJson;
use App\Services\OfflineScannerFileStorage;
use App\Services\OfflineScannerSessionAuditService;
use App\Services\Tenancy\LegacyOfflineUploadAudits;
use App\Support\PositiveIntegerId;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDO;
use RuntimeException;
use Throwable;

class OfflineScannerResultsImportService
{
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;

    private const IMPORTABLE_SESSION_STATUSES = [
        OfflineScannerSession::STATUS_ACTIVE,
        OfflineScannerSession::STATUS_AWAITING_RESULTS,
        OfflineScannerSession::STATUS_RESULTS_UPLOADED,
    ];

    public function __construct(
        private readonly OfflineScannerResultsValidator $validator,
        private readonly OfflineScannerResultsCryptoService $crypto,
        private readonly CanonicalJson $json,
        private readonly OfflineScannerResultsImporter $importer,
        private readonly OfflineScannerResultConflictRecorder $conflicts,
        private readonly OfflineScannerFileStorage $files,
        private readonly OfflineScannerSessionAuditService $audits,
        private readonly LegacyOfflineUploadAudits $uploadAudits,
    ) {}

    public function verifyUpload(
        OfflineScannerSession $session,
        User $actor,
        string $fileName,
        string $contents,
        int $fileSize,
        mixed $selectedEventId,
    ): OfflineScannerResultImport {
        $fileName = $this->validator->validateFileName($fileName);
        $this->validateFileSize($fileSize);

        $fileHash = hash('sha256', $contents);
        $context = $this->uploadAudits->record($session, $actor, $selectedEventId, OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED, [
            'file_name' => $fileName,
            'file_hash' => $fileHash,
            'file_size' => $fileSize,
            'storage_disk' => $this->files->diskName(),
            'internal_format' => $this->files->resultsFormat(),
        ]);
        $session = $context['session'];
        $actor = $context['actor'];
        $session->load(['packageExport', 'sessionUsers']);

        try {
            $envelopeData = $this->decodeEnvelope($contents);
            $envelope = $this->validator->validateEnvelope($envelopeData, $session);

            $this->assertSessionCanAcceptResults($session, $envelope);
            $this->assertResultsExportHasNotBeenUploaded($envelope->resultsExportId, $session, $actor, true, $selectedEventId);

            $payload = $this->crypto->decryptAndValidate($session, $envelopeData);
            $this->assertPayloadCanBeImported($payload);
        } catch (ValidationException $exception) {
            $this->uploadAudits->record($session, $actor, $selectedEventId, OfflineScannerSessionAudit::ACTION_RESULTS_VERIFICATION_FAILED, [
                'file_name' => $fileName,
                'file_hash' => $fileHash,
                'file_size' => $fileSize,
                'errors' => $exception->errors(),
            ]);

            throw $exception;
        }

        return $this->storePreview($session, $actor, $fileName, $contents, $envelope, $payload, $selectedEventId);
    }

    public function importVerified(OfflineScannerResultImport $resultImport, User $actor): OfflineScannerResultImport
    {
        return $this->importer->import($resultImport, $actor);
    }

    public function previewSummary(OfflineScannerResultImport $resultImport): array
    {
        $import = $resultImport->loadMissing(['session.event', 'devices', 'importedLogs', 'conflicts.importedLog', 'conflicts.guest']);
        $successfulAdmissions = $import->importedLogs
            ->where('result_code', OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY)
            ->count();

        return [
            'total_logs' => (int) $import->total_logs,
            'successful_admissions' => $successfulAdmissions,
            'total_people_admitted' => (int) ($import->metrics['total_admitted_people'] ?? 0),
            'qr_admissions' => (int) ($import->metrics['total_qr_admissions'] ?? 0),
            'manual_admissions' => (int) ($import->metrics['total_manual_admissions'] ?? 0),
            'invalid_scans' => (int) ($import->metrics['total_invalid_scans'] ?? 0),
            'duplicate_requests' => $import->importedLogs
                ->where('result_code', OfflineScannerResultSchema::RESULT_DUPLICATE_REQUEST)
                ->count(),
            'potential_conflicts' => $import->conflicts->count(),
            'unknown_guests' => (int) $import->unknown_guest_logs,
            'already_imported_logs' => (int) $import->duplicate_logs,
        ];
    }

    private function validateFileSize(int $fileSize): void
    {
        if ($fileSize < 1) {
            throw ValidationException::withMessages(['offline_results' => 'The uploaded results file is empty.']);
        }

        if ($fileSize > self::MAX_FILE_BYTES) {
            throw ValidationException::withMessages(['offline_results' => 'The uploaded results file is larger than 20 MB.']);
        }
    }

    private function decodeEnvelope(string $contents): array
    {
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['offline_results' => 'The uploaded results file is not valid JSON.']);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw ValidationException::withMessages(['offline_results' => 'The uploaded results file must contain a result envelope object.']);
        }

        return $decoded;
    }

    private function assertSessionCanAcceptResults(OfflineScannerSession $session, ResultsEnvelope $envelope): void
    {
        $session->loadMissing('packageExport');
        $errors = [];

        if ($session->status === OfflineScannerSession::STATUS_CANCELLED) {
            $errors[] = 'This offline scanner session was cancelled.';
        }

        if (! in_array($session->status, self::IMPORTABLE_SESSION_STATUSES, true)) {
            $errors[] = 'This offline scanner session is not active for result import.';
        }

        if ($session->results_imported_at || in_array($session->status, [
            OfflineScannerSession::STATUS_RESULTS_IMPORTED,
            OfflineScannerSession::STATUS_CLOSED,
        ], true)) {
            $errors[] = 'Results have already been imported for this offline scanner session.';
        }

        $package = $session->packageExport;
        if (! $package) {
            $errors[] = 'This offline scanner session does not have a package export.';
        } else {
            if ((int) $package->offline_scanner_session_id !== (int) $session->id) {
                $errors[] = 'The package export does not belong to this offline scanner session.';
            }

            if ($package->package_uuid !== $envelope->sourcePackageExportId) {
                $errors[] = 'The results package export ID does not match this session package.';
            }

            if ($session->package_fingerprint && $package->package_fingerprint && $session->package_fingerprint !== $package->package_fingerprint) {
                $errors[] = 'The session package fingerprint does not match the related package record.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['offline_results' => $errors]);
        }
    }

    private function assertResultsExportHasNotBeenUploaded(
        string $resultsExportId,
        ?OfflineScannerSession $session = null,
        ?User $actor = null,
        bool $guardPreliminaryAudit = false,
        mixed $selectedEventId = null,
    ): void {
        if (OfflineScannerResultImport::query()->where('results_export_id', $resultsExportId)->exists()) {
            if ($session && $actor) {
                $metadata = ['results_export_id' => $resultsExportId];
                if ($guardPreliminaryAudit) {
                    $this->uploadAudits->record($session, $actor, $selectedEventId, OfflineScannerSessionAudit::ACTION_REIMPORT_ATTEMPTED, $metadata);
                } else {
                    // Already inside storePreview's current Event/User/Session
                    // transaction; keep this attempt's audit in that boundary.
                    $this->audits->record($session, OfflineScannerSessionAudit::ACTION_REIMPORT_ATTEMPTED, $actor, null, $metadata);
                }
            }

            throw ValidationException::withMessages([
                'offline_results' => 'This Android scanner results export has already been uploaded or imported.',
            ]);
        }
    }

    private function assertPayloadCanBeImported(ResultsPayload $payload): void
    {
        $errors = [];
        $previousSequence = 0;

        foreach ($payload->admissionLogs as $index => $log) {
            $path = 'payload.admission_logs.'.($index + 1);
            if ($log->masterSequenceNumber !== $previousSequence + 1) {
                $errors[] = "$path master_sequence_number is out of order.";
            }
            $previousSequence = $log->masterSequenceNumber;
        }

        if ($payload->session->finalMasterSequenceNumber !== count($payload->admissionLogs)) {
            $errors[] = 'payload.session.final_master_sequence_number must equal the number of ordered master log records.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['offline_results' => $errors]);
        }
    }

    private function storePreview(
        OfflineScannerSession $session,
        User $actor,
        string $fileName,
        string $contents,
        ResultsEnvelope $envelope,
        ResultsPayload $payload,
        mixed $selectedEventId,
    ): OfflineScannerResultImport {
        $sessionId = PositiveIntegerId::parse($session->getRawOriginal($session->getKeyName()));
        $eventId = PositiveIntegerId::parse($session->getRawOriginal('event_id'));
        $attributes = $session->getAttributes();
        abort_unless($sessionId !== null && $eventId !== null
            && PositiveIntegerId::parse($selectedEventId) === $eventId
            && PositiveIntegerId::parse($envelope->eventId) === $eventId
            && PositiveIntegerId::parse($attributes[$session->getKeyName()] ?? null) === $sessionId
            && PositiveIntegerId::parse($attributes['event_id'] ?? null) === $eventId, 404);

        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = PositiveIntegerId::parse($actor->getAttributes()[$actor->getKeyName()] ?? null);
        abort_unless($actorId !== null
            && PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())) === $actorId, 403);

        $connection = DB::connection();
        $rootRetryCleanup = false;
        try {
            $rootRetryCleanup = $connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction();
        } catch (Throwable) {
            // Unobservable physical state never grants the new retry cleanup.
        }

        $storedPath = null;
        $storedDisk = null;
        $attemptNumber = 0;
        $rolledBackArtifacts = [];

        try {
            return $connection->transaction(function () use ($connection, $rootRetryCleanup, $sessionId, $eventId, $actorId, $fileName, $contents, $envelope, $payload, &$storedPath, &$storedDisk, &$attemptNumber, &$rolledBackArtifacts): OfflineScannerResultImport {
                $storedPath = null;
                $storedDisk = null;
                $currentAttempt = ++$attemptNumber;
                $attemptPdo = null;

                try {
                    $attemptPdo = $connection->getPdo();
                    $event = Event::query()->useWritePdo()->whereKey($eventId)->lockForUpdate()->first();
                    abort_unless($event instanceof Event
                        && PositiveIntegerId::parse($event->getRawOriginal($event->getKeyName())) === $eventId
                        && $event->getRawOriginal('workspace_id') === null, 404);

                    $lockedActor = User::query()->useWritePdo()->whereKey($actorId)->lockForUpdate()->first();
                    abort_unless($lockedActor instanceof User
                        && PositiveIntegerId::parse($lockedActor->getRawOriginal($lockedActor->getKeyName())) === $actorId
                        && $lockedActor->getRawOriginal('role') === User::ROLE_ADMIN
                        && in_array($lockedActor->getRawOriginal('is_active'), [true, 1, '1'], true), 403);

                    $lockedSession = OfflineScannerSession::query()->useWritePdo()->whereKey($sessionId)->lockForUpdate()->first();
                    abort_unless($lockedSession instanceof OfflineScannerSession, 404);

                    $lockedEventId = PositiveIntegerId::parse($lockedSession->getRawOriginal('event_id'));
                    if ($lockedEventId === null) {
                        throw ValidationException::withMessages([
                            'offline_results' => 'This offline scanner session has an invalid event reference.',
                        ]);
                    }
                    abort_unless(PositiveIntegerId::parse($lockedSession->getRawOriginal($lockedSession->getKeyName())) === $sessionId
                        && $lockedEventId === $eventId, 404);

                    if ($rootRetryCleanup) {
                        if (! $attemptPdo instanceof PDO || $connection->transactionLevel() !== 1
                            || $connection->getPdo() !== $attemptPdo || ! $attemptPdo->inTransaction()) {
                            throw new RuntimeException('Unable to recover an earlier offline scanner preview file.');
                        }

                        foreach ($rolledBackArtifacts as $priorAttempt => $artifact) {
                            if ($priorAttempt >= $attemptNumber) {
                                continue;
                            }

                            if ($artifact['pdo'] !== $attemptPdo) {
                                throw new RuntimeException('Unable to recover an earlier offline scanner preview file.');
                            }

                            $this->cleanupRolledBackPreviewArtifact($connection, $artifact['path'], $artifact['disk']);
                            unset($rolledBackArtifacts[$priorAttempt]);
                        }
                    }

                    $lockedSession->load(['packageExport', 'sessionUsers']);
                    $this->assertSessionCanAcceptResults($lockedSession, $envelope);
                    $this->assertResultsExportHasNotBeenUploaded($envelope->resultsExportId, $lockedSession, $lockedActor);
                    $fileHash = hash('sha256', $contents);
                    $verifiedAt = now();
                    $storedDisk = $this->files->diskName();

                    $import = OfflineScannerResultImport::query()->create([
                        'offline_scanner_session_id' => $lockedSession->id,
                        'event_id' => $lockedSession->event_id,
                        'uploaded_by_user_id' => $lockedActor->id,
                        'uploaded_at' => $verifiedAt,
                        'source_package_export_id' => $envelope->sourcePackageExportId,
                        'results_export_id' => $envelope->resultsExportId,
                        'result_schema_version' => $envelope->resultSchemaVersion,
                        'source_master_device_id' => $envelope->masterDeviceId,
                        'file_name' => $fileName,
                        'file_hash' => $fileHash,
                        'storage_disk' => $storedDisk,
                        'internal_format' => $this->files->resultsFormat(),
                        'encryption_algorithm' => $envelope->encryptionAlgorithm,
                        'initialization_vector' => $envelope->initializationVector,
                        'authentication_tag' => $envelope->authenticationTag,
                        'payload_hash' => $envelope->payloadHash,
                        'final_master_sequence_number' => $payload->session->finalMasterSequenceNumber,
                        'metrics' => [
                            'total_scans' => $payload->metrics->totalScans,
                            'total_admitted_people' => $payload->metrics->totalAdmittedPeople,
                            'total_invalid_scans' => $payload->metrics->totalInvalidScans,
                            'total_manual_admissions' => $payload->metrics->totalManualAdmissions,
                            'total_qr_admissions' => $payload->metrics->totalQrAdmissions,
                        ],
                        'exported_at' => Carbon::parse($payload->session->exportedAt),
                        'result_session_started_at' => $payload->session->sessionStartedAt ? Carbon::parse($payload->session->sessionStartedAt) : null,
                        'result_session_closed_at' => $payload->session->sessionClosedAt ? Carbon::parse($payload->session->sessionClosedAt) : null,
                        'status' => OfflineScannerResultImport::STATUS_PREVIEW_READY,
                        'verified_at' => $verifiedAt,
                        'total_logs' => count($payload->admissionLogs),
                        'imported_logs' => 0,
                    ]);
                    $storedPath = $this->files->resultsPath($lockedSession, $import->import_uuid);
                    $this->files->put($storedPath, $contents, $storedDisk);

                    $import->update([
                        'encrypted_file_path' => $storedPath,
                        'encrypted_file_size' => strlen($contents),
                    ]);

                    foreach ($payload->devices as $device) {
                        OfflineScannerResultDevice::query()->create([
                            'offline_scanner_result_import_id' => $import->id,
                            'offline_scanner_session_id' => $lockedSession->id,
                            'device_id' => $device->deviceId,
                            'device_name' => $device->deviceName,
                            'device_role' => $device->deviceRole,
                            'scanner_user_id' => $device->scannerUserId,
                            'scanner_user_name' => $device->scannerUserName,
                            'gate_name' => $device->gateName,
                            'paired_at' => $device->pairedAt ? Carbon::parse($device->pairedAt) : null,
                            'last_seen_at' => $device->lastSeenAt ? Carbon::parse($device->lastSeenAt) : null,
                            'admissions_count' => $device->admissionsCount,
                        ]);
                    }

                    $externalGuestIds = collect($payload->admissionLogs)
                        ->pluck('externalGuestId')
                        ->merge(collect($payload->finalGuestStates)->pluck('externalGuestId'))
                        ->filter()
                        ->uniqueStrict()
                        ->values();
                    $guestIds = $externalGuestIds
                        ->map(fn (string $id): ?int => $this->externalGuestIdToInt($id))
                        ->filter()
                        ->unique()
                        ->values();
                    $guests = Guest::query()
                        ->with('qrCode')
                        ->where('event_id', $eventId)
                        ->whereIn('id', $guestIds)
                        ->get()
                        ->keyBy('id');
                    $snapshots = OfflineScannerPackageGuestSnapshot::query()
                        ->where('offline_scanner_session_id', $lockedSession->id)
                        ->whereIn('external_guest_id', $externalGuestIds)
                        ->get()
                        ->keyBy('external_guest_id');
                    $snapshotGuestIds = $snapshots->keys()
                        ->map(fn ($id): ?int => PositiveIntegerId::parse($id))
                        ->filter()
                        ->unique()
                        ->values();
                    // Only exported identities may distinguish a moved guest from a deletion.
                    // Fetch IDs alone; foreign current Guest models/state never enter this preview.
                    $foreignSnapshotGuestIds = Guest::query()
                        ->whereIn('id', $snapshotGuestIds)
                        ->whereNotIn('id', $guests->keys())
                        ->pluck('id')
                        ->flip();
                    $finalStatePayloads = collect($payload->finalGuestStates)
                        ->keyBy('externalGuestId')
                        ->map(fn (ResultsFinalGuestState $state): array => $this->expectedFinalStateFromPayload($state));

                    $logUuids = collect($payload->admissionLogs)->pluck('logUuid')->all();
                    $requestUuids = collect($payload->admissionLogs)->pluck('requestUuid')->all();
                    $sequences = collect($payload->admissionLogs)->pluck('masterSequenceNumber')->all();
                    $existingLogs = OfflineScannerImportedLog::query()
                        ->where('offline_scanner_session_id', $lockedSession->id)
                        ->where(function ($query) use ($logUuids, $requestUuids, $sequences): void {
                            $query->whereIn('log_uuid', $logUuids)
                                ->orWhereIn('request_uuid', $requestUuids)
                                ->orWhereIn('master_sequence_number', $sequences);
                        })
                        ->lockForUpdate()
                        ->get();
                    $existingByLogUuid = $existingLogs->keyBy('log_uuid');
                    $existingByRequestUuid = $existingLogs->keyBy('request_uuid');
                    $existingBySequence = $existingLogs->keyBy('master_sequence_number');
                    $duplicateCount = 0;

                    foreach ($payload->admissionLogs as $log) {
                        $guestId = $this->externalGuestIdToInt($log->externalGuestId);
                        $guest = $guestId ? $guests->get($guestId) : null;
                        $snapshot = $snapshots->get($log->externalGuestId);
                        $offlineRequest = $this->conflicts->offlineRequestFromResultsLog($log);
                        $expectedFinalState = $finalStatePayloads->get($log->externalGuestId);
                        $existingMatches = [
                            'log_uuid' => $existingByLogUuid->get($log->logUuid),
                            'request_uuid' => $existingByRequestUuid->get($log->requestUuid),
                            'master_sequence_number' => $existingBySequence->get($log->masterSequenceNumber),
                        ];

                        $identifierTypes = $this->identifierConflictTypes($log, $existingMatches);
                        if ($identifierTypes !== []) {
                            $reason = $this->conflictReason($identifierTypes);
                            $importedLog = $this->createImportedLog($import, $lockedSession, $log, OfflineScannerImportedLog::STATUS_CONFLICT, $reason);
                            foreach ($identifierTypes as $type) {
                                $this->conflicts->record($import, $importedLog, $guest, $type, $reason, $snapshot, null, $offlineRequest, $expectedFinalState);
                            }

                            continue;
                        }

                        $duplicateReason = $this->identicalDuplicateReason($log, $existingMatches);
                        if ($duplicateReason !== null) {
                            $duplicateCount++;
                            $this->createImportedLog($import, $lockedSession, $log, OfflineScannerImportedLog::STATUS_DUPLICATE, $duplicateReason);

                            continue;
                        }

                        [$status, $reason, $types] = $this->classifyLog(
                            $log,
                            $guest,
                            $snapshot,
                            $guestId !== null && $foreignSnapshotGuestIds->has($guestId),
                        );
                        $importedLog = $this->createImportedLog($import, $lockedSession, $log, $status, $reason);

                        foreach ($types as $type) {
                            $this->conflicts->record($import, $importedLog, $guest, $type, (string) $reason, $snapshot, null, $offlineRequest, $expectedFinalState);
                        }
                    }

                    foreach ($payload->finalGuestStates as $state) {
                        $guestId = $this->externalGuestIdToInt($state->externalGuestId);
                        $guest = $guestId ? $guests->get($guestId) : null;

                        OfflineScannerFinalGuestState::query()->create([
                            'offline_scanner_result_import_id' => $import->id,
                            'offline_scanner_session_id' => $lockedSession->id,
                            'event_id' => $lockedSession->event_id,
                            'external_guest_id' => $state->externalGuestId,
                            'guest_id' => $guest?->id,
                            'final_used' => $state->finalUsed,
                            'final_remaining' => $state->finalRemaining,
                            'final_record_version' => $state->finalRecordVersion,
                            'last_master_sequence_number' => $state->lastMasterSequenceNumber,
                        ]);
                    }

                    $counts = $this->statusCounts($import->fresh());
                    $import->update([
                        'duplicate_logs' => $counts['duplicate_logs'],
                        'conflict_logs' => $counts['conflict_logs'],
                        'rejected_logs' => $counts['rejected_logs'],
                        'unknown_guest_logs' => $counts['unknown_guest_logs'],
                    ]);

                    if ($lockedSession->status !== OfflineScannerSession::STATUS_RESULTS_UPLOADED) {
                        $lockedSession->update(['status' => OfflineScannerSession::STATUS_RESULTS_UPLOADED]);
                    }

                    $this->audits->record($lockedSession, OfflineScannerSessionAudit::ACTION_RESULTS_VERIFIED, $lockedActor, null, [
                        'import_uuid' => $import->import_uuid,
                        'results_export_id' => $import->results_export_id,
                        'source_package_export_id' => $import->source_package_export_id,
                        'file_name' => $fileName,
                        'file_hash' => $fileHash,
                        'file_size' => strlen($contents),
                        'storage_disk' => $storedDisk,
                        'internal_format' => $this->files->resultsFormat(),
                    ]);

                    return $import->fresh(['session.event', 'session.packageExport', 'devices', 'importedLogs.scannerUser', 'finalGuestStates.guest', 'conflicts.importedLog', 'conflicts.guest']);
                } catch (Throwable $exception) {
                    if ($rootRetryCleanup && $storedPath !== null && $storedDisk !== null) {
                        try {
                            if ($attemptPdo instanceof PDO && $connection->transactionLevel() === 1
                                && $connection->getPdo() === $attemptPdo && $attemptPdo->inTransaction()) {
                                $path = $storedPath;
                                $disk = $storedDisk;
                                $connection->afterRollBack(static function () use (&$rolledBackArtifacts, $currentAttempt, $path, $disk, $attemptPdo): void {
                                    $rolledBackArtifacts[$currentAttempt] = ['path' => $path, 'disk' => $disk, 'pdo' => $attemptPdo];
                                });
                            }
                        } catch (Throwable) {
                            // Eligibility/registration failure must preserve the original error.
                        }
                    }

                    throw $exception;
                }
            }, 3);
        } catch (Throwable $exception) {
            if ($storedPath && $storedDisk !== null && $this->files->exists($storedPath, $storedDisk)) {
                $this->files->delete($storedPath, $storedDisk);
            }

            throw $exception;
        }
    }

    private function cleanupRolledBackPreviewArtifact(Connection $connection, string $path, string $disk): void
    {
        // A reference on any Event/disk is a veto, never a private model load.
        $reference = $connection->table('offline_scanner_result_imports')
            ->useWritePdo()
            ->select('id')
            ->where('encrypted_file_path', $path)
            ->lockForUpdate()
            ->first();
        if ($reference !== null) {
            throw new RuntimeException('Unable to recover an earlier offline scanner preview file.');
        }

        try {
            if ($this->files->exists($path, $disk) && ! $this->files->delete($path, $disk)) {
                throw new RuntimeException('Unable to recover an earlier offline scanner preview file.');
            }
        } catch (Throwable $exception) {
            // Storage failures must not enter the database concurrency retry path.
            throw new RuntimeException('Unable to recover an earlier offline scanner preview file.', 0, $exception);
        }
    }

    private function createImportedLog(
        OfflineScannerResultImport $import,
        OfflineScannerSession $session,
        ResultsAdmissionLog $log,
        string $status,
        ?string $reason,
    ): OfflineScannerImportedLog {
        return OfflineScannerImportedLog::query()->create([
            'offline_scanner_result_import_id' => $import->id,
            'offline_scanner_session_id' => $session->id,
            'log_uuid' => $log->logUuid,
            'request_uuid' => $log->requestUuid,
            'master_sequence_number' => $log->masterSequenceNumber,
            'external_guest_id' => $log->externalGuestId,
            'scanner_user_id' => $log->scannerUserId,
            'source_device_id' => $log->sourceDeviceId,
            'source_gate_name' => $log->sourceGateName,
            'admission_method' => $log->admissionMethod,
            'qr_payload_hash' => $log->qrPayloadHash,
            'added_entries' => $log->addedEntries,
            'used_before' => $log->usedBefore,
            'remaining_before' => $log->remainingBefore,
            'used_after' => $log->usedAfter,
            'remaining_after' => $log->remainingAfter,
            'result_code' => $log->resultCode,
            'device_timestamp' => Carbon::parse($log->deviceTimestamp),
            'master_timestamp' => Carbon::parse($log->masterTimestamp),
            'monotonic_sequence' => $log->monotonicSequence,
            'base_guest_record_version' => $log->baseGuestRecordVersion,
            'import_status' => $status,
            'conflict_reason' => $reason,
            'raw_payload_hash' => $this->logHash($log),
        ]);
    }

    private function classifyLog(
        ResultsAdmissionLog $log,
        ?Guest $guest,
        ?OfflineScannerPackageGuestSnapshot $snapshot,
        bool $guestExistsOutsideEvent,
    ): array {
        $types = [];

        if (! $snapshot) {
            return [
                OfflineScannerImportedLog::STATUS_CONFLICT,
                'Guest is absent from the exported package snapshot.',
                [OfflineScannerImportConflict::TYPE_GUEST_NOT_FOUND],
            ];
        }

        if ($guestExistsOutsideEvent) {
            return [
                OfflineScannerImportedLog::STATUS_CONFLICT,
                'Guest from the Android result file belongs to another event.',
                [OfflineScannerImportConflict::TYPE_WRONG_EVENT],
            ];
        }

        if (! $guest) {
            return [
                OfflineScannerImportedLog::STATUS_UNKNOWN_GUEST,
                'Guest from the Android result file was deleted after package export.',
                [OfflineScannerImportConflict::TYPE_GUEST_DELETED],
            ];
        }

        if ($log->resultCode !== OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY) {
            return [OfflineScannerImportedLog::STATUS_REJECTED, 'No admission count change is required for this result code.', []];
        }

        if ((string) $log->baseGuestRecordVersion !== (string) $snapshot->record_version) {
            $types[] = OfflineScannerImportConflict::TYPE_RECORD_VERSION_MISMATCH;
        }

        $types = array_values(array_unique([...$types, ...$this->baselineConflictTypes($snapshot, $guest)]));
        if ($types !== []) {
            return [OfflineScannerImportedLog::STATUS_CONFLICT, $this->conflictReason($types), $types];
        }

        return [OfflineScannerImportedLog::STATUS_PENDING, null, []];
    }

    private function baselineConflictTypes(OfflineScannerPackageGuestSnapshot $snapshot, Guest $guest): array
    {
        $server = $this->conflicts->serverState($guest) ?: [];
        $types = [];

        if ((int) $guest->allowed_entries !== (int) $snapshot->allowed_entries) {
            $types[] = OfflineScannerImportConflict::TYPE_ALLOWED_ENTRIES_CHANGED;
        }

        if ((int) $guest->used_entries !== (int) $snapshot->used_entries) {
            $types[] = OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED;
        }

        if ($guest->remainingEntries() !== (int) $snapshot->remaining_entries) {
            $types[] = OfflineScannerImportConflict::TYPE_SERVER_REMAINING_CHANGED;
        }

        if ($snapshot->pass_type !== null && $snapshot->pass_type !== '' && (string) $guest->pass_type !== (string) $snapshot->pass_type) {
            $types[] = OfflineScannerImportConflict::TYPE_PASS_TYPE_CHANGED;
        }

        if ($snapshot->guest_status !== null
            && $snapshot->guest_status !== Guest::STATUS_CANCELLED
            && (string) $guest->status === Guest::STATUS_CANCELLED) {
            $types[] = OfflineScannerImportConflict::TYPE_GUEST_DISABLED_AFTER_EXPORT;
        }

        if ($snapshot->manual_code_hash !== null
            && $snapshot->manual_code_hash !== ''
            && (string) ($server['manual_code_hash'] ?? '') !== (string) $snapshot->manual_code_hash) {
            $types[] = OfflineScannerImportConflict::TYPE_MANUAL_CODE_CHANGED;
        }

        if ($snapshot->qr_token_hash !== null
            && $snapshot->qr_token_hash !== ''
            && (string) ($server['qr_token_hash'] ?? '') !== (string) $snapshot->qr_token_hash) {
            $types[] = OfflineScannerImportConflict::TYPE_QR_TOKEN_CHANGED;
        }

        return $types;
    }

    private function identifierConflictTypes(ResultsAdmissionLog $log, array $existingLogs): array
    {
        $hash = $this->logHash($log);
        $types = [];

        if ($existingLogs['log_uuid'] && ! hash_equals((string) $existingLogs['log_uuid']->raw_payload_hash, $hash)) {
            $types[] = OfflineScannerImportConflict::TYPE_DUPLICATE_LOG_CONFLICT;
            $types[] = OfflineScannerImportConflict::TYPE_ALREADY_IMPORTED_DIFFERENT_PAYLOAD;
        }

        if ($existingLogs['request_uuid'] && ! hash_equals((string) $existingLogs['request_uuid']->raw_payload_hash, $hash)) {
            $types[] = OfflineScannerImportConflict::TYPE_DUPLICATE_REQUEST_CONFLICT;
            $types[] = OfflineScannerImportConflict::TYPE_ALREADY_IMPORTED_DIFFERENT_PAYLOAD;
        }

        if ($existingLogs['master_sequence_number'] && ! hash_equals((string) $existingLogs['master_sequence_number']->raw_payload_hash, $hash)) {
            $types[] = OfflineScannerImportConflict::TYPE_INVALID_MASTER_SEQUENCE;
            $types[] = OfflineScannerImportConflict::TYPE_ALREADY_IMPORTED_DIFFERENT_PAYLOAD;
        }

        return array_values(array_unique($types));
    }

    private function identicalDuplicateReason(ResultsAdmissionLog $log, array $existingLogs): ?string
    {
        $hash = $this->logHash($log);

        if ($existingLogs['log_uuid'] && hash_equals((string) $existingLogs['log_uuid']->raw_payload_hash, $hash)) {
            return 'This log UUID has already been uploaded for this session.';
        }

        if ($existingLogs['request_uuid'] && hash_equals((string) $existingLogs['request_uuid']->raw_payload_hash, $hash)) {
            return 'This request UUID has already been uploaded for this session.';
        }

        if ($existingLogs['master_sequence_number'] && hash_equals((string) $existingLogs['master_sequence_number']->raw_payload_hash, $hash)) {
            return 'This Master sequence number has already been uploaded for this session.';
        }

        return null;
    }

    private function conflictReason(array $types): string
    {
        $labels = [
            OfflineScannerImportConflict::TYPE_GUEST_NOT_FOUND => 'The result log references a guest that was not in the exported package snapshot.',
            OfflineScannerImportConflict::TYPE_GUEST_DELETED => 'The exported guest record has been deleted from Laravel.',
            OfflineScannerImportConflict::TYPE_GUEST_DISABLED_AFTER_EXPORT => 'The guest was disabled after package export.',
            OfflineScannerImportConflict::TYPE_ALLOWED_ENTRIES_CHANGED => 'Allowed entries changed after package export.',
            OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED => 'Laravel used entries changed after package export.',
            OfflineScannerImportConflict::TYPE_SERVER_REMAINING_CHANGED => 'Laravel remaining entries changed after package export.',
            OfflineScannerImportConflict::TYPE_MANUAL_CODE_CHANGED => 'The manual code changed after package export.',
            OfflineScannerImportConflict::TYPE_QR_TOKEN_CHANGED => 'The QR token changed after package export.',
            OfflineScannerImportConflict::TYPE_RECORD_VERSION_MISMATCH => 'The log does not reference the guest record version contained in the package.',
            OfflineScannerImportConflict::TYPE_INVALID_MASTER_SEQUENCE => 'The Master sequence conflicts with an existing imported log.',
            OfflineScannerImportConflict::TYPE_DUPLICATE_LOG_CONFLICT => 'The log UUID was reused with different payload content.',
            OfflineScannerImportConflict::TYPE_DUPLICATE_REQUEST_CONFLICT => 'The request UUID was reused with different payload content.',
            OfflineScannerImportConflict::TYPE_IMPOSSIBLE_COUNT_TRANSITION => 'The offline count transition is impossible.',
            OfflineScannerImportConflict::TYPE_WRONG_EVENT => 'The offline log points at a guest from another event.',
            OfflineScannerImportConflict::TYPE_WRONG_SESSION => 'The offline log belongs to a different session.',
            OfflineScannerImportConflict::TYPE_UNAUTHORIZED_SCANNER => 'The offline log references an unauthorized scanner.',
            OfflineScannerImportConflict::TYPE_SESSION_CANCELLED => 'The offline scanner session was cancelled.',
            OfflineScannerImportConflict::TYPE_ALREADY_IMPORTED_DIFFERENT_PAYLOAD => 'An imported offline identifier was reused with different content.',
            OfflineScannerImportConflict::TYPE_PASS_TYPE_CHANGED => 'The pass type changed after package export.',
            OfflineScannerImportConflict::TYPE_CORRUPTED_FINAL_GUEST_STATE => 'The final guest state does not match the Master sequence ledger.',
        ];

        return collect($types)
            ->map(fn (string $type): string => $labels[$type] ?? str_replace('_', ' ', $type))
            ->implode(' ');
    }

    private function statusCounts(OfflineScannerResultImport $import): array
    {
        $counts = $import->importedLogs()
            ->select('import_status', DB::raw('count(*) as aggregate'))
            ->groupBy('import_status')
            ->pluck('aggregate', 'import_status');

        return [
            'duplicate_logs' => (int) ($counts[OfflineScannerImportedLog::STATUS_DUPLICATE] ?? 0),
            'conflict_logs' => (int) ($counts[OfflineScannerImportedLog::STATUS_CONFLICT] ?? 0),
            'rejected_logs' => (int) ($counts[OfflineScannerImportedLog::STATUS_REJECTED] ?? 0),
            'unknown_guest_logs' => (int) ($counts[OfflineScannerImportedLog::STATUS_UNKNOWN_GUEST] ?? 0),
        ];
    }

    private function logHash(ResultsAdmissionLog $log): string
    {
        return hash('sha256', $this->json->encode([
            'log_uuid' => $log->logUuid,
            'request_uuid' => $log->requestUuid,
            'master_sequence_number' => $log->masterSequenceNumber,
            'external_guest_id' => $log->externalGuestId,
            'scanner_user_id' => $log->scannerUserId,
            'source_device_id' => $log->sourceDeviceId,
            'source_gate_name' => $log->sourceGateName,
            'admission_method' => $log->admissionMethod,
            'qr_payload_hash' => $log->qrPayloadHash,
            'added_entries' => $log->addedEntries,
            'used_before' => $log->usedBefore,
            'remaining_before' => $log->remainingBefore,
            'used_after' => $log->usedAfter,
            'remaining_after' => $log->remainingAfter,
            'result_code' => $log->resultCode,
            'device_timestamp' => $log->deviceTimestamp,
            'master_timestamp' => $log->masterTimestamp,
            'monotonic_sequence' => $log->monotonicSequence,
            'base_guest_record_version' => $log->baseGuestRecordVersion,
        ]));
    }

    private function expectedFinalStateFromPayload(ResultsFinalGuestState $state): array
    {
        return [
            'external_guest_id' => $state->externalGuestId,
            'final_used' => $state->finalUsed,
            'final_remaining' => $state->finalRemaining,
            'final_record_version' => $state->finalRecordVersion,
            'last_master_sequence_number' => $state->lastMasterSequenceNumber,
        ];
    }

    private function externalGuestIdToInt(string $externalGuestId): ?int
    {
        return PositiveIntegerId::parse($externalGuestId);
    }
}
