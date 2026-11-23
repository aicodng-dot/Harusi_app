<?php

namespace App\Services\OfflineScannerResults;

use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Guest;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\User;
use App\Services\OfflineScannerSessionAuditService;
use App\Support\PositiveIntegerId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfflineScannerImportConflictResolver
{
    public function __construct(
        private readonly OfflineScannerAdmissionApplier $admissions,
        private readonly OfflineScannerSessionAuditService $audits,
    ) {}

    public function resolve(OfflineScannerImportConflict $conflict, User $actor, array $input): OfflineScannerImportConflict
    {
        $conflictId = PositiveIntegerId::parse($conflict->getRawOriginal($conflict->getKeyName()));
        if ($conflictId === null || PositiveIntegerId::parse($conflict->getAttributes()[$conflict->getKeyName()] ?? null) !== $conflictId) {
            throw ValidationException::withMessages(['conflict_resolution' => 'The conflict result context is invalid.']);
        }

        return DB::transaction(function () use ($conflictId, $actor, $input): OfflineScannerImportConflict {
            $lockedConflict = OfflineScannerImportConflict::query()
                ->useWritePdo()
                ->whereKey($conflictId)
                ->lockForUpdate()
                ->firstOrFail();

            $importId = PositiveIntegerId::parse($lockedConflict->getRawOriginal('offline_scanner_result_import_id'));
            $eventId = PositiveIntegerId::parse($lockedConflict->getRawOriginal('event_id'));
            if (PositiveIntegerId::parse($lockedConflict->getRawOriginal($lockedConflict->getKeyName())) !== $conflictId
                || $importId === null || $eventId === null) {
                throw ValidationException::withMessages(['conflict_resolution' => 'The conflict result context is invalid.']);
            }

            if ($lockedConflict->resolution_status !== OfflineScannerImportConflict::RESOLUTION_OPEN) {
                throw ValidationException::withMessages(['conflict_resolution' => 'This conflict has already been resolved.']);
            }

            $action = (string) ($input['resolution_action'] ?? '');
            $notes = trim((string) ($input['resolution_notes'] ?? ''));

            if ($notes === '') {
                throw ValidationException::withMessages(['resolution_notes' => 'Resolution notes are required.']);
            }

            if (! in_array($action, $lockedConflict->allowedResolutionActions(), true)) {
                throw ValidationException::withMessages(['resolution_action' => 'That resolution is not safe for this conflict type.']);
            }

            $import = OfflineScannerResultImport::query()
                ->useWritePdo()
                ->whereKey($importId)
                ->lockForUpdate()
                ->firstOrFail();
            $sessionId = PositiveIntegerId::parse($import->getRawOriginal('offline_scanner_session_id'));
            if (PositiveIntegerId::parse($import->getRawOriginal($import->getKeyName())) !== $importId
                || PositiveIntegerId::parse($import->getRawOriginal('event_id')) !== $eventId || $sessionId === null) {
                throw ValidationException::withMessages(['conflict_resolution' => 'The conflict result context is invalid.']);
            }
            $session = OfflineScannerSession::query()
                ->useWritePdo()
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->firstOrFail();
            if (PositiveIntegerId::parse($session->getRawOriginal($session->getKeyName())) !== $sessionId
                || PositiveIntegerId::parse($session->getRawOriginal('event_id')) !== $eventId) {
                throw ValidationException::withMessages(['conflict_resolution' => 'The conflict result context is invalid.']);
            }
            $import->setRelation('session', $session);
            $lockedConflict->setRelation('resultImport', $import);
            $log = $this->lockedLogForConflict($lockedConflict, $import, $session);
            $siblingIds = $action === OfflineScannerImportConflict::ACTION_ESCALATE_ADMIN_REVIEW || $log === null
                ? [] : $this->lockedSiblingIds($lockedConflict, $log);

            match ($action) {
                OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION => $this->acceptOfflineAdmission($lockedConflict, $log),
                OfflineScannerImportConflict::ACTION_KEEP_SERVER_STATE => $this->keepServerState($log),
                OfflineScannerImportConflict::ACTION_ADJUST_SERVER_COUNT_MANUALLY => $this->adjustServerCount($lockedConflict, $input),
                OfflineScannerImportConflict::ACTION_MAP_TO_REPLACEMENT_GUEST => $this->mapToReplacementGuest($lockedConflict, $log, $input),
                OfflineScannerImportConflict::ACTION_REJECT_IMPORTED_LOG => $this->rejectImportedLog($log),
                OfflineScannerImportConflict::ACTION_ESCALATE_ADMIN_REVIEW => $this->escalate($lockedConflict, $actor, $notes, $session),
                default => throw ValidationException::withMessages(['resolution_action' => 'Choose a valid conflict resolution action.']),
            };

            if ($action === OfflineScannerImportConflict::ACTION_ESCALATE_ADMIN_REVIEW) {
                return $lockedConflict->fresh(['importedLog', 'guest', 'replacementGuest', 'resolvedBy']);
            }

            $resolutionStatus = in_array($action, [
                OfflineScannerImportConflict::ACTION_KEEP_SERVER_STATE,
                OfflineScannerImportConflict::ACTION_REJECT_IMPORTED_LOG,
            ], true)
                ? OfflineScannerImportConflict::RESOLUTION_DISMISSED
                : OfflineScannerImportConflict::RESOLUTION_RESOLVED;

            $lockedConflict->update([
                'resolution_status' => $resolutionStatus,
                'resolution_action' => $action,
                'resolved_by_user_id' => $actor->id,
                'resolution_notes' => $notes,
                'resolved_at' => now(),
            ]);
            $this->resolveSiblingConflicts($lockedConflict, $actor, $action, $resolutionStatus, $notes, $siblingIds);

            $this->audits->record($session, OfflineScannerSessionAudit::ACTION_CONFLICT_RESOLVED, $actor, $notes, [
                'conflict_id' => $lockedConflict->id,
                'conflict_type' => $lockedConflict->conflict_type,
                'resolution_action' => $action,
                'result_import_uuid' => $import->import_uuid,
                'log_uuid' => $log?->log_uuid,
                'request_uuid' => $log?->request_uuid,
            ]);

            $this->completeImportIfResolved($session, $import, $actor);

            return $lockedConflict->fresh(['importedLog', 'guest', 'replacementGuest', 'resolvedBy']);
        }, 3);
    }

    private function lockedLogForConflict(
        OfflineScannerImportConflict $conflict,
        OfflineScannerResultImport $import,
        OfflineScannerSession $session,
    ): ?OfflineScannerImportedLog {
        $rawId = $conflict->getRawOriginal('offline_scanner_imported_log_id');
        if ($rawId === null) {
            $conflict->setRelation('importedLog', null);

            return null;
        }
        $logId = PositiveIntegerId::parse($rawId);
        $importId = PositiveIntegerId::parse($import->getRawOriginal($import->getKeyName()));
        $sessionId = PositiveIntegerId::parse($session->getRawOriginal($session->getKeyName()));
        $log = $logId === null ? null : OfflineScannerImportedLog::query()->useWritePdo()
            ->whereKey($logId)
            ->where('offline_scanner_result_import_id', $importId)
            ->where('offline_scanner_session_id', $sessionId)
            ->lockForUpdate()->first();
        if (! $log instanceof OfflineScannerImportedLog
            || PositiveIntegerId::parse($log->getRawOriginal($log->getKeyName())) !== $logId
            || PositiveIntegerId::parse($log->getRawOriginal('offline_scanner_result_import_id')) !== $importId
            || PositiveIntegerId::parse($log->getRawOriginal('offline_scanner_session_id')) !== $sessionId) {
            throw ValidationException::withMessages(['conflict_resolution' => 'The conflict log does not belong to this result import and scanner session.']);
        }
        $log->setRelation('resultImport', $import);
        $log->setRelation('session', $session);
        $conflict->setRelation('importedLog', $log);

        return $log;
    }

    private function acceptOfflineAdmission(OfflineScannerImportConflict $conflict, ?OfflineScannerImportedLog $log): void
    {
        $guest = $this->lockedGuestForConflict($conflict);
        $this->applyOfflineAdmissionToGuest($log, $guest);
    }

    private function mapToReplacementGuest(OfflineScannerImportConflict $conflict, ?OfflineScannerImportedLog $log, array $input): void
    {
        $replacementId = PositiveIntegerId::parse($input['replacement_guest_id'] ?? null);
        if ($replacementId === null) {
            throw ValidationException::withMessages(['replacement_guest_id' => 'Choose the replacement guest.']);
        }

        $eventId = PositiveIntegerId::parse($conflict->getRawOriginal('event_id'));
        if ($eventId === null) {
            throw ValidationException::withMessages(['conflict_resolution' => 'The conflict result context is invalid.']);
        }
        $guest = $this->lockedGuestInEvent($replacementId, $eventId, 'replacement_guest_id', 'The replacement guest must belong to the same event.');

        $this->applyOfflineAdmissionToGuest($log, $guest);

        $conflict->update(['replacement_guest_id' => $guest->id]);
    }

    private function adjustServerCount(OfflineScannerImportConflict $conflict, array $input): void
    {
        $guest = $this->lockedGuestForConflict($conflict);
        $usedEntries = $input['manual_used_entries'] ?? null;

        if ($usedEntries === null || $usedEntries === '') {
            throw ValidationException::withMessages(['manual_used_entries' => 'Enter the corrected used-entry count.']);
        }

        $usedEntries = (int) $usedEntries;
        if ($usedEntries < 0 || $usedEntries > (int) $guest->allowed_entries) {
            throw ValidationException::withMessages(['manual_used_entries' => 'The corrected count must be between zero and the guest allowed entries.']);
        }

        $guest->used_entries = $usedEntries;
        $guest->save();

        $conflict->update(['manual_used_entries' => $usedEntries]);
        $this->markLogRejected($conflict->importedLog, 'Resolved by manual server-count adjustment.');
    }

    private function keepServerState(?OfflineScannerImportedLog $log): void
    {
        $this->markLogRejected($log, 'Resolved by keeping the existing Laravel server state.');
    }

    private function rejectImportedLog(?OfflineScannerImportedLog $log): void
    {
        $this->markLogRejected($log, 'Resolved by rejecting the imported offline log.');
    }

    private function escalate(
        OfflineScannerImportConflict $conflict,
        User $actor,
        string $notes,
        OfflineScannerSession $session,
    ): void {
        $conflict->update([
            'resolution_action' => OfflineScannerImportConflict::ACTION_ESCALATE_ADMIN_REVIEW,
            'resolved_by_user_id' => $actor->id,
            'resolution_notes' => $notes,
            'resolved_at' => now(),
        ]);

        $this->audits->record($session, OfflineScannerSessionAudit::ACTION_CONFLICT_RESOLVED, $actor, $notes, [
            'conflict_id' => $conflict->id,
            'conflict_type' => $conflict->conflict_type,
            'resolution_action' => OfflineScannerImportConflict::ACTION_ESCALATE_ADMIN_REVIEW,
            'status' => 'open',
        ]);
    }

    private function applyOfflineAdmissionToGuest(?OfflineScannerImportedLog $log, Guest $guest): void
    {
        if (! $log) {
            throw ValidationException::withMessages(['conflict_resolution' => 'This conflict is not tied to an imported offline log.']);
        }

        if ($log->result_code !== OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY || (int) $log->added_entries < 1) {
            throw ValidationException::withMessages(['conflict_resolution' => 'Only successful offline admissions can be accepted.']);
        }

        if ((int) $guest->event_id !== (int) $log->resultImport?->event_id) {
            throw ValidationException::withMessages(['conflict_resolution' => 'The offline admission cannot be applied to a guest from another event.']);
        }

        if ($this->successfulOfflineAdmissionExists($log)) {
            throw ValidationException::withMessages(['conflict_resolution' => 'This offline admission has already produced a successful Laravel admission.']);
        }

        $usedAfter = (int) $guest->used_entries + (int) $log->added_entries;
        if ($usedAfter > (int) $guest->allowed_entries) {
            throw ValidationException::withMessages(['conflict_resolution' => 'Accepting this offline admission would make Used greater than Allowed.']);
        }

        $guest->used_entries = $usedAfter;
        $guest->save();

        $this->admissions->upsertSuccessfulAdmission($log, $guest);
        $log->update([
            'import_status' => OfflineScannerImportedLog::STATUS_IMPORTED,
            'conflict_reason' => null,
        ]);
    }

    private function successfulOfflineAdmissionExists(OfflineScannerImportedLog $log): bool
    {
        return Admission::query()
            ->where('offline_scanner_imported_log_id', $log->id)
            ->exists()
            || Checkin::query()
                ->where(function ($query) use ($log): void {
                    $query->where('offline_scanner_imported_log_id', $log->id)
                        ->orWhere('offline_log_uuid', $log->log_uuid)
                        ->orWhere('offline_request_uuid', $log->request_uuid);
                })
                ->where('scan_result', Checkin::RESULT_ADMITTED)
                ->exists();
    }

    private function lockedGuestForConflict(OfflineScannerImportConflict $conflict): Guest
    {
        $guestId = PositiveIntegerId::parse($conflict->getRawOriginal('guest_id'));
        if ($guestId === null) {
            throw ValidationException::withMessages(['conflict_resolution' => 'Choose a replacement guest for this conflict.']);
        }

        $eventId = PositiveIntegerId::parse($conflict->getRawOriginal('event_id'));
        if ($eventId === null) {
            throw ValidationException::withMessages(['conflict_resolution' => 'The conflict result context is invalid.']);
        }

        return $this->lockedGuestInEvent($guestId, $eventId, 'conflict_resolution', 'The conflict guest no longer belongs to this event.');
    }

    private function lockedGuestInEvent(int $guestId, int $eventId, string $field, string $wrongEventMessage): Guest
    {
        // Keep the Guest lock phase and existing missing/wrong-Event distinction
        // without loading another Event's current Guest fields or QR relation.
        $row = DB::table('guests')->useWritePdo()->where('id', $guestId)->lockForUpdate()->first(['id']);
        if ($row === null) {
            throw (new ModelNotFoundException)->setModel(Guest::class);
        }
        $guest = PositiveIntegerId::parse($row->id) === $guestId
            ? Guest::query()->useWritePdo()->whereKey($guestId)->where('event_id', $eventId)->lockForUpdate()->first()
            : null;
        if (! $guest instanceof Guest
            || PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName())) !== $guestId
            || PositiveIntegerId::parse($guest->getRawOriginal('event_id')) !== $eventId) {
            throw ValidationException::withMessages([$field => $wrongEventMessage]);
        }
        $guest->setRelation('qrCode', $guest->qrCode()->useWritePdo()->first());

        return $guest;
    }

    private function markLogRejected(?OfflineScannerImportedLog $log, string $reason): void
    {
        if (! $log) {
            return;
        }

        $log->update([
            'import_status' => OfflineScannerImportedLog::STATUS_REJECTED,
            'conflict_reason' => $reason,
        ]);
    }

    private function lockedSiblingIds(OfflineScannerImportConflict $conflict, OfflineScannerImportedLog $log): array
    {
        $conflictId = PositiveIntegerId::parse($conflict->getRawOriginal($conflict->getKeyName()));
        $importId = PositiveIntegerId::parse($conflict->getRawOriginal('offline_scanner_result_import_id'));
        $eventId = PositiveIntegerId::parse($conflict->getRawOriginal('event_id'));
        $logId = PositiveIntegerId::parse($log->getRawOriginal($log->getKeyName()));
        if ($conflictId === null || $importId === null || $eventId === null || $logId === null) {
            throw ValidationException::withMessages(['conflict_resolution' => 'The conflict sibling context is invalid.']);
        }
        $rows = DB::table('offline_scanner_import_conflicts')->useWritePdo()
            ->where('offline_scanner_result_import_id', $importId)
            ->where('offline_scanner_imported_log_id', $logId)
            ->where('id', '!=', $conflictId)
            ->where('resolution_status', OfflineScannerImportConflict::RESOLUTION_OPEN)
            ->orderBy('id')->lockForUpdate()
            ->get(['id', 'offline_scanner_result_import_id', 'offline_scanner_imported_log_id', 'event_id', 'resolution_status']);
        $ids = [];
        foreach ($rows as $row) {
            $id = PositiveIntegerId::parse($row->id);
            if ($id === null || $id === $conflictId
                || PositiveIntegerId::parse($row->offline_scanner_result_import_id) !== $importId
                || PositiveIntegerId::parse($row->offline_scanner_imported_log_id) !== $logId
                || PositiveIntegerId::parse($row->event_id) !== $eventId
                || $row->resolution_status !== OfflineScannerImportConflict::RESOLUTION_OPEN) {
                throw ValidationException::withMessages(['conflict_resolution' => 'The conflict sibling context is invalid.']);
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function resolveSiblingConflicts(
        OfflineScannerImportConflict $conflict,
        User $actor,
        string $action,
        string $resolutionStatus,
        string $notes,
        array $siblingIds,
    ): void {
        if ($siblingIds === []) {
            return;
        }

        OfflineScannerImportConflict::query()
            ->whereIn('id', $siblingIds)
            ->where('event_id', $conflict->getRawOriginal('event_id'))
            ->where('offline_scanner_result_import_id', $conflict->getRawOriginal('offline_scanner_result_import_id'))
            ->where('offline_scanner_imported_log_id', $conflict->getRawOriginal('offline_scanner_imported_log_id'))
            ->where('resolution_status', OfflineScannerImportConflict::RESOLUTION_OPEN)
            ->update([
                'resolution_status' => $resolutionStatus,
                'resolution_action' => $action,
                'resolved_by_user_id' => $actor->id,
                'resolution_notes' => $notes,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function completeImportIfResolved(
        OfflineScannerSession $session,
        OfflineScannerResultImport $import,
        User $actor,
    ): void {
        if ($import->conflicts()
            ->where('resolution_status', OfflineScannerImportConflict::RESOLUTION_OPEN)
            ->exists()) {
            return;
        }

        $counts = $this->statusCounts($import);
        $import->update([
            'status' => OfflineScannerResultImport::STATUS_COMPLETED,
            'imported_logs' => $counts['imported_logs'],
            'duplicate_logs' => $counts['duplicate_logs'],
            'conflict_logs' => $counts['conflict_logs'],
            'rejected_logs' => $counts['rejected_logs'],
            'unknown_guest_logs' => $counts['unknown_guest_logs'],
            'imported_at' => $import->imported_at ?: now(),
        ]);

        if ($session->status === OfflineScannerSession::STATUS_CANCELLED) {
            return;
        }

        $previousStatus = $session->status;
        $now = now();
        $importedMetadata = [
            'previous_status' => $previousStatus,
            'status' => OfflineScannerSession::STATUS_RESULTS_IMPORTED,
            'result_import_uuid' => $import->import_uuid,
            'results_export_id' => $import->results_export_id,
            'final_master_sequence_number' => $import->final_master_sequence_number,
            'imported_logs' => $counts['imported_logs'],
            'duplicate_logs' => $counts['duplicate_logs'],
            'conflict_logs' => $counts['conflict_logs'],
        ];
        $closedMetadata = array_merge($importedMetadata, [
            'previous_status' => OfflineScannerSession::STATUS_RESULTS_IMPORTED,
            'status' => OfflineScannerSession::STATUS_CLOSED,
        ]);

        $session->update([
            'last_master_sequence' => max((int) $session->last_master_sequence, (int) $import->final_master_sequence_number),
            'results_imported_at' => $session->results_imported_at ?: $now,
            'status' => OfflineScannerSession::STATUS_RESULTS_IMPORTED,
        ]);

        $this->audits->record($session, OfflineScannerSessionAudit::ACTION_RESULTS_IMPORTED, $actor, null, $importedMetadata);
        if (in_array($previousStatus, OfflineScannerSession::blockingStatuses(), true)) {
            $this->audits->record($session, OfflineScannerSessionAudit::ACTION_EVENT_UNLOCKED, $actor, null, $importedMetadata);
        }

        $session->update([
            'session_closed_at' => $session->session_closed_at ?: $now,
            'status' => OfflineScannerSession::STATUS_CLOSED,
        ]);
        $this->audits->record($session, OfflineScannerSessionAudit::ACTION_SESSION_CLOSED, $actor, null, $closedMetadata);
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
}
