<?php

namespace App\Services\OfflineScannerResults;

use App\Models\Guest;
use App\Models\OfflineScannerFinalGuestState;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerResultDevice;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\OfflineScannerSessionUser;
use App\Models\User;
use App\Services\OfflineScannerSessionAuditService;
use App\Support\PositiveIntegerId;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfflineScannerResultsImporter
{
    private const ELIGIBLE_SESSION_STATUSES = [
        OfflineScannerSession::STATUS_ACTIVE,
        OfflineScannerSession::STATUS_AWAITING_RESULTS,
        OfflineScannerSession::STATUS_RESULTS_UPLOADED,
    ];

    public function __construct(
        private readonly OfflineScannerSessionAuditService $audits,
        private readonly OfflineScannerAdmissionApplier $admissions,
        private readonly OfflineScannerResultConflictRecorder $conflicts,
    ) {}

    public function import(OfflineScannerResultImport $resultImport, User $actor): OfflineScannerResultImport
    {
        return DB::transaction(function () use ($resultImport, $actor): OfflineScannerResultImport {
            // The session is the transaction root. Every subsequent lock is acquired in a stable order.
            $session = OfflineScannerSession::query()
                ->whereKey($resultImport->offline_scanner_session_id)
                ->lockForUpdate()
                ->firstOrFail();

            $import = OfflineScannerResultImport::query()
                ->whereKey($resultImport->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $import->offline_scanner_session_id !== (int) $session->id) {
                throw ValidationException::withMessages([
                    'offline_results' => 'The result import does not belong to the locked offline scanner session.',
                ]);
            }

            $eventId = PositiveIntegerId::parse($session->getRawOriginal('event_id'));
            if ($eventId === null || PositiveIntegerId::parse($import->getRawOriginal('event_id')) !== $eventId) {
                throw ValidationException::withMessages([
                    'offline_results' => 'The result import event does not match the locked offline scanner session.',
                ]);
            }

            // A retried confirmation of an already committed import is intentionally a no-op.
            if (in_array($import->status, [
                OfflineScannerResultImport::STATUS_COMPLETED,
                OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS,
            ], true)) {
                return $this->freshImport($import);
            }

            $this->assertSessionEligible($session, $import);

            $import->update(['status' => OfflineScannerResultImport::STATUS_IMPORTING]);

            $logs = OfflineScannerImportedLog::query()
                ->where('offline_scanner_result_import_id', $import->id)
                ->orderBy('master_sequence_number')
                ->lockForUpdate()
                ->get();
            $sessionLogs = OfflineScannerImportedLog::query()
                ->where('offline_scanner_session_id', $session->id)
                ->orderBy('master_sequence_number')
                ->lockForUpdate()
                ->get();

            $this->assertResultExportIdentity($import);
            $duplicateLogIds = $this->assertIdentifierReuseIsSafe($import, $logs, $sessionLogs);
            $this->assertMasterSequenceStream($session, $import, $sessionLogs);

            $externalGuestIds = $logs->pluck('external_guest_id')
                ->merge($import->finalGuestStates()->lockForUpdate()->pluck('external_guest_id'))
                ->filter(fn (?string $id): bool => $this->isExternalGuestId($id))
                ->uniqueStrict()
                ->sort()
                ->values();
            $snapshots = OfflineScannerPackageGuestSnapshot::query()
                ->where('offline_scanner_session_id', $session->id)
                ->whereIn('external_guest_id', $externalGuestIds)
                ->orderBy('external_guest_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('external_guest_id');

            if ($logs->isNotEmpty() && $snapshots->isEmpty()) {
                throw ValidationException::withMessages([
                    'offline_results' => 'This package has no persisted guest snapshot, so its admissions cannot be reconciled safely.',
                ]);
            }

            $guestIds = $snapshots
                ->map(fn (OfflineScannerPackageGuestSnapshot $snapshot): ?int => PositiveIntegerId::parse($snapshot->getRawOriginal('guest_id')))
                ->filter()
                ->unique()
                ->sort()
                ->values();
            // Preserve the ascending Guest lock phase without hydrating foreign models/QR state.
            $existingGuestIds = DB::table('guests')
                ->whereIn('id', $guestIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->flip();
            $guests = Guest::query()
                ->with('qrCode')
                ->where('event_id', $eventId)
                ->whereIn('id', $guestIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $sessionUsers = OfflineScannerSessionUser::query()
                ->where('offline_scanner_session_id', $session->id)
                ->where('active', true)
                ->orderBy('scanner_user_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('scanner_user_id');
            $devices = OfflineScannerResultDevice::query()
                ->where('offline_scanner_result_import_id', $import->id)
                ->orderBy('device_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('device_id');
            $states = OfflineScannerFinalGuestState::query()
                ->where('offline_scanner_result_import_id', $import->id)
                ->orderBy('external_guest_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('external_guest_id');

            $this->assertDevicesAreAuthorized($session, $import, $devices, $sessionUsers);
            $this->assertLogsAreStructurallyValid($logs, $devices, $sessionUsers);

            $ledger = $this->buildGuestLedger($sessionLogs, $snapshots);
            $baselineConflicts = $this->baselineConflicts($snapshots, $guests);
            $blockedGuests = [];
            $appliedLogs = [];

            foreach ($logs as $log) {
                $externalGuestId = (string) $log->external_guest_id;
                $snapshot = $snapshots->get($externalGuestId);
                $guest = $this->guestForSnapshot($snapshot, $guests);
                $state = $states->get($externalGuestId);

                if (isset($duplicateLogIds[$log->id])) {
                    $log->update([
                        'import_status' => OfflineScannerImportedLog::STATUS_DUPLICATE,
                        'conflict_reason' => 'An identical authoritative offline log was already imported.',
                    ]);

                    continue;
                }

                if (in_array($log->import_status, [
                    OfflineScannerImportedLog::STATUS_CONFLICT,
                    OfflineScannerImportedLog::STATUS_UNKNOWN_GUEST,
                ], true)) {
                    $blockedGuests[$externalGuestId] = true;

                    continue;
                }

                if ($log->import_status === OfflineScannerImportedLog::STATUS_DUPLICATE) {
                    continue;
                }

                $snapshotGuestId = $snapshot ? PositiveIntegerId::parse($snapshot->getRawOriginal('guest_id')) : null;
                if ($snapshot && ! $guest && $snapshotGuestId !== null && $existingGuestIds->has($snapshotGuestId)) {
                    $blockedGuests[$externalGuestId] = true;
                    $reason = 'The offline result references a guest assigned to another event.';
                    $log->update([
                        'import_status' => OfflineScannerImportedLog::STATUS_CONFLICT,
                        'conflict_reason' => $reason,
                    ]);
                    $this->recordConflict($import, $log, null, OfflineScannerImportConflict::TYPE_WRONG_EVENT, $reason, $snapshot, $state);

                    continue;
                }

                if (! $snapshot || ! $guest) {
                    $blockedGuests[$externalGuestId] = true;
                    $type = $snapshot
                        ? OfflineScannerImportConflict::TYPE_GUEST_DELETED
                        : OfflineScannerImportConflict::TYPE_GUEST_NOT_FOUND;
                    $reason = $snapshot
                        ? 'The exported guest record has been deleted from Laravel.'
                        : 'Guest is absent from the exported package snapshot.';
                    $log->update([
                        'import_status' => $snapshot ? OfflineScannerImportedLog::STATUS_UNKNOWN_GUEST : OfflineScannerImportedLog::STATUS_CONFLICT,
                        'conflict_reason' => $reason,
                    ]);
                    $this->recordConflict($import, $log, $guest, $type, $reason, $snapshot, $state);

                    continue;
                }

                if ($log->result_code !== OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY) {
                    $log->update([
                        'import_status' => OfflineScannerImportedLog::STATUS_REJECTED,
                        'conflict_reason' => 'The Android result is non-admission audit activity.',
                    ]);

                    continue;
                }

                $conflictTypes = [];
                if ((string) $log->base_guest_record_version !== (string) $snapshot->record_version) {
                    $conflictTypes[] = OfflineScannerImportConflict::TYPE_RECORD_VERSION_MISMATCH;
                }

                $conflictTypes = array_values(array_unique([
                    ...$conflictTypes,
                    ...($baselineConflicts[$externalGuestId] ?? []),
                ]));

                if ($conflictTypes !== []) {
                    $blockedGuests[$externalGuestId] = true;
                    $reason = $this->conflictReason($conflictTypes);
                    $log->update([
                        'import_status' => OfflineScannerImportedLog::STATUS_CONFLICT,
                        'conflict_reason' => $reason,
                    ]);
                    foreach ($conflictTypes as $type) {
                        $this->recordConflict($import, $log, $guest, $type, $reason, $snapshot, $state);
                    }

                    continue;
                }

                $appliedLogs[] = $log;
            }

            $statesToApply = $this->validatedFinalStates($states, $ledger, $snapshots, $guests, $blockedGuests, $import);

            foreach ($appliedLogs as $log) {
                $guest = $this->guestForSnapshot($snapshots->get((string) $log->external_guest_id), $guests);
                $this->upsertSuccessfulAdmission($log, $guest);
                $log->update([
                    'import_status' => OfflineScannerImportedLog::STATUS_IMPORTED,
                    'conflict_reason' => null,
                ]);
            }

            foreach ($statesToApply as $state) {
                $guest = $this->guestForSnapshot($snapshots->get((string) $state->external_guest_id), $guests);
                // Offline Master values are authoritative once the locked snapshot and sequence ledger agree.
                $guest->used_entries = (int) $state->final_used;
                $guest->save();
            }

            $counts = $this->statusCounts($import);
            $hasOpenConflicts = $counts['conflict_logs'] > 0
                || $counts['unknown_guest_logs'] > 0
                || $import->conflicts()
                    ->where('resolution_status', OfflineScannerImportConflict::RESOLUTION_OPEN)
                    ->exists();

            $import->update([
                'status' => $hasOpenConflicts
                    ? OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS
                    : OfflineScannerResultImport::STATUS_COMPLETED,
                'imported_logs' => $counts['imported_logs'],
                'duplicate_logs' => max((int) $import->duplicate_logs, $counts['duplicate_logs']),
                'conflict_logs' => $counts['conflict_logs'],
                'rejected_logs' => $counts['rejected_logs'],
                'unknown_guest_logs' => $counts['unknown_guest_logs'],
                'imported_at' => now(),
            ]);

            if ($hasOpenConflicts) {
                $this->markSessionConflicted($session, $import, $actor);
            } else {
                $this->closeSessionAfterImport($session, $import, $actor);
            }

            return $this->freshImport($import);
        }, 3);
    }

    private function assertSessionEligible(OfflineScannerSession $session, OfflineScannerResultImport $import): void
    {
        if ($session->status === OfflineScannerSession::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['offline_results' => 'This offline scanner session was cancelled and cannot import results.']);
        }

        if ($session->results_imported_at || ! in_array($session->status, self::ELIGIBLE_SESSION_STATUSES, true)) {
            throw ValidationException::withMessages(['offline_results' => 'This offline scanner session is not eligible for result import.']);
        }

        if ($import->status !== OfflineScannerResultImport::STATUS_PREVIEW_READY) {
            throw ValidationException::withMessages(['offline_results' => 'Only a verified result preview can be imported.']);
        }
    }

    private function assertResultExportIdentity(OfflineScannerResultImport $import): void
    {
        if (OfflineScannerResultImport::query()
            ->where('results_export_id', $import->results_export_id)
            ->whereKeyNot($import->id)
            ->lockForUpdate()
            ->exists()) {
            throw ValidationException::withMessages(['offline_results' => 'The Android results export ID has already been used by another import.']);
        }
    }

    /**
     * @return array<int, true>
     */
    private function assertIdentifierReuseIsSafe(
        OfflineScannerResultImport $import,
        Collection $logs,
        Collection $sessionLogs,
    ): array {
        $duplicateLogIds = [];
        $otherLogs = $sessionLogs->where('offline_scanner_result_import_id', '!=', $import->id);
        $byLogUuid = $otherLogs->keyBy('log_uuid');
        $byRequestUuid = $otherLogs->keyBy('request_uuid');
        $bySequence = $otherLogs->keyBy('master_sequence_number');
        $seenLogUuids = [];
        $seenRequestUuids = [];
        $seenSequences = [];

        foreach ($logs as $log) {
            $this->assertUniqueIdentifier($seenLogUuids, (string) $log->log_uuid, 'log UUID');
            $this->assertUniqueIdentifier($seenRequestUuids, (string) $log->request_uuid, 'request UUID');
            $this->assertUniqueIdentifier($seenSequences, (string) $log->master_sequence_number, 'Master sequence number');

            if ($log->import_status === OfflineScannerImportedLog::STATUS_CONFLICT) {
                continue;
            }

            $matches = collect([
                $byLogUuid->get($log->log_uuid),
                $byRequestUuid->get($log->request_uuid),
                $bySequence->get($log->master_sequence_number),
            ])->filter();

            if ($matches->isEmpty()) {
                continue;
            }

            if ($matches->pluck('id')->unique()->count() !== 1 || $matches->contains(
                fn (OfflineScannerImportedLog $existing): bool => ! hash_equals((string) $existing->raw_payload_hash, (string) $log->raw_payload_hash)
            )) {
                throw ValidationException::withMessages([
                    'offline_results' => 'A log UUID, request UUID, or Master sequence number was reused with different offline payload content.',
                ]);
            }

            $duplicateLogIds[$log->id] = true;
        }

        return $duplicateLogIds;
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function assertUniqueIdentifier(array &$seen, string $value, string $label): void
    {
        if ($value === '' || isset($seen[$value])) {
            throw ValidationException::withMessages(['offline_results' => "Duplicate or missing $label in the verified results import."]);
        }

        $seen[$value] = true;
    }

    private function assertMasterSequenceStream(
        OfflineScannerSession $session,
        OfflineScannerResultImport $import,
        Collection $sessionLogs,
    ): void {
        $finalSequence = (int) $import->final_master_sequence_number;
        if ($finalSequence < 0 || (int) $session->last_master_sequence > $finalSequence) {
            throw ValidationException::withMessages(['offline_results' => 'The Master sequence regresses from an already imported sequence.']);
        }

        $bySequence = $sessionLogs->groupBy('master_sequence_number');
        for ($sequence = 1; $sequence <= $finalSequence; $sequence++) {
            $records = $bySequence->get($sequence, collect());
            if ($records->isEmpty()) {
                throw ValidationException::withMessages(['offline_results' => "Master sequence $sequence is missing from the verified results import."]);
            }

            $hasCurrentConflict = $records->contains(
                fn (OfflineScannerImportedLog $log): bool => (int) $log->offline_scanner_result_import_id === (int) $import->id
                    && $log->import_status === OfflineScannerImportedLog::STATUS_CONFLICT
            );
            if ($records->pluck('raw_payload_hash')->filter()->unique()->count() > 1 && ! $hasCurrentConflict) {
                throw ValidationException::withMessages(['offline_results' => "Master sequence $sequence conflicts with another result log."]);
            }
        }

        foreach ($sessionLogs as $log) {
            if ((int) $log->master_sequence_number > $finalSequence) {
                throw ValidationException::withMessages(['offline_results' => 'The results file contains a log beyond final_master_sequence_number.']);
            }
        }

        $currentSequences = OfflineScannerImportedLog::query()
            ->where('offline_scanner_result_import_id', $import->id)
            ->orderBy('master_sequence_number')
            ->pluck('master_sequence_number');
        $previous = 0;
        foreach ($currentSequences as $sequence) {
            $sequence = (int) $sequence;
            if ($sequence <= $previous || $sequence > $finalSequence) {
                throw ValidationException::withMessages(['offline_results' => 'Master sequence numbers must be strictly increasing and within the final sequence.']);
            }
            $previous = $sequence;
        }
    }

    private function assertDevicesAreAuthorized(
        OfflineScannerSession $session,
        OfflineScannerResultImport $import,
        Collection $devices,
        Collection $sessionUsers,
    ): void {
        $masterDevices = $devices->where('device_role', 'master');
        if ($masterDevices->count() !== 1) {
            throw ValidationException::withMessages(['offline_results' => 'The authoritative results export must contain exactly one Master device.']);
        }

        $masterDevice = $masterDevices->first();
        if ($masterDevice->device_id !== $import->source_master_device_id
            || (int) $masterDevice->scanner_user_id !== (int) $session->master_scanner_user_id) {
            throw ValidationException::withMessages(['offline_results' => 'The results export Master device does not match the authorized session Master.']);
        }

        foreach ($devices as $device) {
            $sessionUser = $sessionUsers->get((int) $device->scanner_user_id);
            if (! $sessionUser
                || $sessionUser->assigned_gate !== $device->gate_name
                || $sessionUser->device_role !== $device->device_role) {
                throw ValidationException::withMessages(['offline_results' => 'A device in the results export is not authorized for this offline scanner session.']);
            }
        }
    }

    private function assertLogsAreStructurallyValid(Collection $logs, Collection $devices, Collection $sessionUsers): void
    {
        foreach ($logs as $log) {
            if (! in_array($log->result_code, OfflineScannerResultSchema::resultCodes(), true)
                || ! in_array($log->admission_method, OfflineScannerResultSchema::admissionMethods(), true)
                || (int) $log->master_sequence_number < 1
                || (int) $log->monotonic_sequence < 1
                || (int) $log->used_before < 0
                || (int) $log->remaining_before < 0
                || (int) $log->used_after < 0
                || (int) $log->remaining_after < 0
                || (int) $log->added_entries < 0) {
                throw ValidationException::withMessages(['offline_results' => 'A stored admission log contains invalid controlled values or numeric data.']);
            }

            if ((int) $log->used_before + (int) $log->added_entries !== (int) $log->used_after
                || (int) $log->remaining_before - (int) $log->added_entries !== (int) $log->remaining_after) {
                throw ValidationException::withMessages(['offline_results' => 'An offline admission log has inconsistent used or remaining values.']);
            }

            if ($log->result_code === OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY && (int) $log->added_entries < 1) {
                throw ValidationException::withMessages(['offline_results' => 'A successful offline admission must add at least one entry.']);
            }

            if ($log->result_code !== OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY && (int) $log->added_entries !== 0) {
                throw ValidationException::withMessages(['offline_results' => 'A non-admission offline result cannot add entries.']);
            }

            $device = $devices->get($log->source_device_id);
            $sessionUser = $sessionUsers->get((int) $log->scanner_user_id);
            if (! $device || ! $sessionUser
                || (int) $device->scanner_user_id !== (int) $log->scanner_user_id
                || $device->gate_name !== $log->source_gate_name
                || $sessionUser->assigned_gate !== $log->source_gate_name) {
                throw ValidationException::withMessages(['offline_results' => 'An admission log references an unauthorized device, scanner user, or gate.']);
            }
        }
    }

    /**
     * @return array<string, array{used: int, remaining: int, last_change_sequence: int}>
     */
    private function buildGuestLedger(Collection $logs, Collection $snapshots): array
    {
        $ledger = [];
        $blockedGuests = [];

        foreach ($logs as $log) {
            $externalGuestId = (string) $log->external_guest_id;

            if (isset($blockedGuests[$externalGuestId])) {
                continue;
            }

            if (in_array($log->import_status, [
                OfflineScannerImportedLog::STATUS_CONFLICT,
                OfflineScannerImportedLog::STATUS_UNKNOWN_GUEST,
            ], true)) {
                $blockedGuests[$externalGuestId] = true;

                continue;
            }

            if ($log->import_status === OfflineScannerImportedLog::STATUS_DUPLICATE) {
                continue;
            }

            $snapshot = $snapshots->get($externalGuestId);
            if (! $snapshot) {
                continue;
            }

            $state = $ledger[$externalGuestId] ?? [
                'used' => (int) $snapshot->used_entries,
                'remaining' => (int) $snapshot->remaining_entries,
                'last_change_sequence' => 0,
            ];
            if ((int) $log->used_before !== $state['used'] || (int) $log->remaining_before !== $state['remaining']) {
                throw ValidationException::withMessages(['offline_results' => 'An offline guest log does not continue from the prior authoritative Master state.']);
            }

            if ((int) $log->used_after > (int) $snapshot->allowed_entries
                || (int) $log->remaining_after < 0
                || (int) $log->used_after + (int) $log->remaining_after !== (int) $snapshot->allowed_entries) {
                throw ValidationException::withMessages(['offline_results' => 'An offline guest log exceeds the package snapshot allowance.']);
            }

            $state['used'] = (int) $log->used_after;
            $state['remaining'] = (int) $log->remaining_after;
            if ($log->result_code === OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY) {
                $state['last_change_sequence'] = (int) $log->master_sequence_number;
            }
            $ledger[$externalGuestId] = $state;
        }

        return $ledger;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function baselineConflicts(Collection $snapshots, Collection $guests): array
    {
        $conflicts = [];
        foreach ($snapshots as $snapshot) {
            $guest = $this->guestForSnapshot($snapshot, $guests);
            if (! $guest) {
                continue;
            }

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

            if ($types !== []) {
                $conflicts[(string) $snapshot->external_guest_id] = array_values(array_unique($types));
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, array{used: int, remaining: int, last_change_sequence: int}>  $ledger
     * @param  array<string, true>  $blockedGuests
     * @return Collection<int, OfflineScannerFinalGuestState>
     */
    private function validatedFinalStates(
        Collection $states,
        array $ledger,
        Collection $snapshots,
        Collection $guests,
        array &$blockedGuests,
        OfflineScannerResultImport $import,
    ): Collection {
        foreach ($ledger as $externalGuestId => $state) {
            if ($state['last_change_sequence'] > 0 && ! $states->has($externalGuestId)) {
                throw ValidationException::withMessages(['offline_results' => 'A guest changed by the Master sequence is missing from final_guest_states.']);
            }
        }

        $apply = collect();
        foreach ($states as $state) {
            $externalGuestId = (string) $state->external_guest_id;
            $snapshot = $snapshots->get($externalGuestId);
            $guest = $this->guestForSnapshot($snapshot, $guests);
            $ledgerState = $ledger[$externalGuestId] ?? null;

            if (! $snapshot || ! $guest) {
                $blockedGuests[$externalGuestId] = true;

                continue;
            }

            if (isset($blockedGuests[$externalGuestId])) {
                continue;
            }

            if (! $ledgerState
                || (int) $state->final_used !== $ledgerState['used']
                || (int) $state->final_remaining !== $ledgerState['remaining']
                || (int) $state->last_master_sequence_number !== $ledgerState['last_change_sequence']) {
                throw ValidationException::withMessages(['offline_results' => 'A final guest state does not match the authoritative Master sequence ledger.']);
            }

            $apply->push($state);
        }

        return $apply->sortBy('guest_id')->values();
    }

    private function upsertSuccessfulAdmission(
        OfflineScannerImportedLog $log,
        Guest $guest,
    ): void {
        $this->admissions->upsertSuccessfulAdmission($log, $guest);
    }

    private function recordConflict(
        OfflineScannerResultImport $import,
        OfflineScannerImportedLog $log,
        ?Guest $guest,
        string $type,
        string $reason,
        ?OfflineScannerPackageGuestSnapshot $snapshot = null,
        ?OfflineScannerFinalGuestState $state = null,
    ): void {
        $this->conflicts->record($import, $log, $guest, $type, $reason, $snapshot, $state);
    }

    private function conflictReason(array $types): string
    {
        $labels = [
            OfflineScannerImportConflict::TYPE_GUEST_NOT_FOUND => 'Guest is absent from the exported package snapshot.',
            OfflineScannerImportConflict::TYPE_GUEST_DELETED => 'The exported guest record has been deleted from Laravel.',
            OfflineScannerImportConflict::TYPE_GUEST_DISABLED_AFTER_EXPORT => 'The guest was disabled after package export.',
            OfflineScannerImportConflict::TYPE_ALLOWED_ENTRIES_CHANGED => 'Allowed entries changed after package export.',
            OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED => 'Laravel used entries changed after package export.',
            OfflineScannerImportConflict::TYPE_SERVER_REMAINING_CHANGED => 'Laravel remaining entries changed after package export.',
            OfflineScannerImportConflict::TYPE_MANUAL_CODE_CHANGED => 'The manual code changed after package export.',
            OfflineScannerImportConflict::TYPE_QR_TOKEN_CHANGED => 'The QR token changed after package export.',
            OfflineScannerImportConflict::TYPE_RECORD_VERSION_MISMATCH => 'The log does not reference the package guest record version.',
            OfflineScannerImportConflict::TYPE_INVALID_MASTER_SEQUENCE => 'The Master sequence conflicts with another log.',
            OfflineScannerImportConflict::TYPE_DUPLICATE_LOG_CONFLICT => 'The log UUID was reused with different payload content.',
            OfflineScannerImportConflict::TYPE_DUPLICATE_REQUEST_CONFLICT => 'The request UUID was reused with different payload content.',
            OfflineScannerImportConflict::TYPE_IMPOSSIBLE_COUNT_TRANSITION => 'The offline count transition is impossible.',
            OfflineScannerImportConflict::TYPE_WRONG_EVENT => 'The offline result points at another event.',
            OfflineScannerImportConflict::TYPE_WRONG_SESSION => 'The offline result points at another session.',
            OfflineScannerImportConflict::TYPE_UNAUTHORIZED_SCANNER => 'The offline result references an unauthorized scanner.',
            OfflineScannerImportConflict::TYPE_SESSION_CANCELLED => 'The offline session was cancelled.',
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
            'imported_logs' => (int) ($counts[OfflineScannerImportedLog::STATUS_IMPORTED] ?? 0),
            'duplicate_logs' => (int) ($counts[OfflineScannerImportedLog::STATUS_DUPLICATE] ?? 0),
            'conflict_logs' => (int) ($counts[OfflineScannerImportedLog::STATUS_CONFLICT] ?? 0),
            'rejected_logs' => (int) ($counts[OfflineScannerImportedLog::STATUS_REJECTED] ?? 0),
            'unknown_guest_logs' => (int) ($counts[OfflineScannerImportedLog::STATUS_UNKNOWN_GUEST] ?? 0),
        ];
    }

    private function markSessionConflicted(
        OfflineScannerSession $session,
        OfflineScannerResultImport $import,
        User $actor,
    ): void {
        $previousStatus = $session->status;
        $metadata = [
            'previous_status' => $previousStatus,
            'status' => OfflineScannerSession::STATUS_CONFLICT,
            'result_import_uuid' => $import->import_uuid,
            'results_export_id' => $import->results_export_id,
            'final_master_sequence_number' => $import->final_master_sequence_number,
            'imported_logs' => $import->imported_logs,
            'duplicate_logs' => $import->duplicate_logs,
            'conflict_logs' => $import->conflict_logs,
        ];

        if ($session->status !== OfflineScannerSession::STATUS_CONFLICT) {
            $session->update(['status' => OfflineScannerSession::STATUS_CONFLICT]);
        }

        $this->audits->record($session, OfflineScannerSessionAudit::ACTION_CONFLICT_DETECTED, $actor, null, $metadata);
    }

    private function closeSessionAfterImport(
        OfflineScannerSession $session,
        OfflineScannerResultImport $import,
        User $actor,
    ): void {
        $now = now();
        $previousStatus = $session->status;
        $importedMetadata = [
            'previous_status' => $previousStatus,
            'status' => OfflineScannerSession::STATUS_RESULTS_IMPORTED,
            'result_import_uuid' => $import->import_uuid,
            'results_export_id' => $import->results_export_id,
            'final_master_sequence_number' => $import->final_master_sequence_number,
            'imported_logs' => $import->imported_logs,
            'duplicate_logs' => $import->duplicate_logs,
            'conflict_logs' => $import->conflict_logs,
        ];
        $closedMetadata = array_merge($importedMetadata, [
            'previous_status' => OfflineScannerSession::STATUS_RESULTS_IMPORTED,
            'status' => OfflineScannerSession::STATUS_CLOSED,
        ]);

        $session->update([
            'last_master_sequence' => (int) $import->final_master_sequence_number,
            'results_imported_at' => $now,
            'status' => OfflineScannerSession::STATUS_RESULTS_IMPORTED,
        ]);

        $this->audits->record($session, OfflineScannerSessionAudit::ACTION_RESULTS_IMPORTED, $actor, null, $importedMetadata);
        if (in_array($previousStatus, OfflineScannerSession::blockingStatuses(), true)) {
            $this->audits->record($session, OfflineScannerSessionAudit::ACTION_EVENT_UNLOCKED, $actor, null, $importedMetadata);
        }

        $session->update([
            'session_closed_at' => $now,
            'status' => OfflineScannerSession::STATUS_CLOSED,
        ]);
        $this->audits->record($session, OfflineScannerSessionAudit::ACTION_SESSION_CLOSED, $actor, null, $closedMetadata);
    }

    private function freshImport(OfflineScannerResultImport $import): OfflineScannerResultImport
    {
        return $import->fresh([
            'session.event',
            'devices',
            'importedLogs.scannerUser',
            'finalGuestStates.guest',
            'conflicts.importedLog',
            'conflicts.guest',
        ]);
    }

    private function isExternalGuestId(?string $externalGuestId): bool
    {
        return PositiveIntegerId::parse($externalGuestId) !== null;
    }

    private function guestForSnapshot(?OfflineScannerPackageGuestSnapshot $snapshot, Collection $guests): ?Guest
    {
        $guestId = $snapshot ? PositiveIntegerId::parse($snapshot->getRawOriginal('guest_id')) : null;

        return $guestId === null ? null : $guests->get($guestId);
    }
}
