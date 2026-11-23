<?php

namespace App\Services\Tenancy;

use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerResultDevice;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\User;
use App\Support\PositiveIntegerId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Read projections only; callers must first authorize the current legacy resource head. */
final class LegacyOfflineScannerReview
{
    public function projectSession(
        OfflineScannerSession $session,
        mixed $expectedEventId,
        bool $withAssignments = false,
    ): OfflineScannerSession {
        [$sessionId, $eventId] = $this->sessionContext($session, $expectedEventId);
        if ($withAssignments) {
            $session->loadMissing('sessionUsers');
        }
        $this->projectReferences($this->sessionReferences($session, $sessionId), $eventId);

        return $session;
    }

    public function projectImport(
        OfflineScannerResultImport $import,
        OfflineScannerSession $session,
        Collection $pageLogs,
    ): OfflineScannerResultImport {
        [$importId, $sessionId, $eventId] = $this->importContext($import, $session);
        $import->loadMissing(['devices', 'importedLogs', 'conflicts.importedLog']);
        $references = $this->sessionReferences($session, $sessionId);
        foreach ($import->devices as $device) {
            $references[] = [$device, 'scannerUser', 'scanner_user_id', $this->deviceMatches($device, $importId, $sessionId)];
        }
        foreach ($import->importedLogs->concat($pageLogs) as $log) {
            $references[] = [$log, 'scannerUser', 'scanner_user_id', $this->logMatches($log, $importId, $sessionId)];
        }
        foreach ($import->conflicts as $conflict) {
            $reference = $this->conflictLogReference($conflict, $importId, $sessionId, $eventId);
            if ($reference !== null) {
                $references[] = $reference;
            }
        }
        $this->projectReferences($references, $eventId);

        return $import;
    }

    public function projectConflict(
        OfflineScannerImportConflict $conflict,
        OfflineScannerResultImport $import,
        OfflineScannerSession $session,
    ): OfflineScannerImportConflict {
        [$importId, $sessionId, $eventId] = $this->importContext($import, $session);
        abort_unless($this->conflictMatches($conflict, $importId, $eventId), 404);
        $import->loadMissing('devices');
        $conflict->loadMissing('importedLog');
        $references = $this->sessionReferences($session, $sessionId);
        foreach ($import->devices as $device) {
            $references[] = [$device, 'scannerUser', 'scanner_user_id', $this->deviceMatches($device, $importId, $sessionId)];
        }
        $reference = $this->conflictLogReference($conflict, $importId, $sessionId, $eventId);
        if ($reference !== null) {
            $references[] = $reference;
        }
        $this->projectReferences($references, $eventId);

        return $conflict;
    }

    private function sessionContext(OfflineScannerSession $session, mixed $expectedEventId): array
    {
        $sessionId = PositiveIntegerId::parse($session->getRawOriginal($session->getKeyName()));
        $eventId = PositiveIntegerId::parse($session->getRawOriginal('event_id'));
        abort_unless($sessionId !== null && $eventId !== null
            && PositiveIntegerId::parse($expectedEventId) === $eventId, 404);

        return [$sessionId, $eventId];
    }

    private function importContext(OfflineScannerResultImport $import, OfflineScannerSession $session): array
    {
        [$sessionId, $eventId] = $this->sessionContext($session, $import->getRawOriginal('event_id'));
        $importId = PositiveIntegerId::parse($import->getRawOriginal($import->getKeyName()));
        abort_unless($importId !== null
            && PositiveIntegerId::parse($import->getRawOriginal('offline_scanner_session_id')) === $sessionId, 404);

        return [$importId, $sessionId, $eventId];
    }

    private function sessionReferences(OfflineScannerSession $session, int $sessionId): array
    {
        $references = [[$session, 'masterScannerUser', 'master_scanner_user_id', true]];
        if ($session->relationLoaded('sessionUsers')) {
            foreach ($session->sessionUsers as $assignment) {
                $matches = PositiveIntegerId::parse($assignment->getRawOriginal($assignment->getKeyName())) !== null
                    && PositiveIntegerId::parse($assignment->getRawOriginal('offline_scanner_session_id')) === $sessionId;
                $references[] = [$assignment, 'scannerUser', 'scanner_user_id', $matches];
            }
        }

        return $references;
    }

    private function deviceMatches(OfflineScannerResultDevice $device, int $importId, int $sessionId): bool
    {
        return PositiveIntegerId::parse($device->getRawOriginal($device->getKeyName())) !== null
            && PositiveIntegerId::parse($device->getRawOriginal('offline_scanner_result_import_id')) === $importId
            && PositiveIntegerId::parse($device->getRawOriginal('offline_scanner_session_id')) === $sessionId;
    }

    private function logMatches(OfflineScannerImportedLog $log, int $importId, int $sessionId): bool
    {
        return PositiveIntegerId::parse($log->getRawOriginal($log->getKeyName())) !== null
            && PositiveIntegerId::parse($log->getRawOriginal('offline_scanner_result_import_id')) === $importId
            && PositiveIntegerId::parse($log->getRawOriginal('offline_scanner_session_id')) === $sessionId;
    }

    private function conflictMatches(OfflineScannerImportConflict $conflict, int $importId, int $eventId): bool
    {
        return PositiveIntegerId::parse($conflict->getRawOriginal($conflict->getKeyName())) !== null
            && PositiveIntegerId::parse($conflict->getRawOriginal('offline_scanner_result_import_id')) === $importId
            && PositiveIntegerId::parse($conflict->getRawOriginal('event_id')) === $eventId;
    }

    private function conflictLogReference(OfflineScannerImportConflict $conflict, int $importId, int $sessionId, int $eventId): ?array
    {
        $log = $conflict->importedLog;
        if (! $log instanceof OfflineScannerImportedLog) {
            return null;
        }
        $matches = $this->conflictMatches($conflict, $importId, $eventId)
            && $this->logMatches($log, $importId, $sessionId)
            && PositiveIntegerId::parse($conflict->getRawOriginal('offline_scanner_imported_log_id'))
                === PositiveIntegerId::parse($log->getRawOriginal($log->getKeyName()));
        if (! $matches) {
            // A preloaded log may also be shared by a valid association. Keep
            // that projection independent without discarding historical data.
            $log = clone $log;
            $conflict->setRelation('importedLog', $log);
        }

        return [$log, 'scannerUser', 'scanner_user_id', $matches];
    }

    private function projectReferences(array $references, int $eventId): void
    {
        $ids = collect($references)->filter(static fn (array $reference): bool => $reference[3])
            ->map(static fn (array $reference): ?int => PositiveIntegerId::parse($reference[0]->getRawOriginal($reference[2])))
            ->filter()->unique()->values();
        $users = collect();
        if ($ids->isNotEmpty()) {
            $requestedIds = array_fill_keys($ids->all(), true);
            $users = User::query()->useWritePdo()->whereIn('users.id', $ids)
                ->where('users.event_id', $eventId)->where('users.role', User::ROLE_SCANNER)
                ->whereRaw('HEX(users.role) = ?', [strtoupper(bin2hex(User::ROLE_SCANNER))])
                ->where('users.is_platform_admin', false)
                ->whereNotExists(static function ($memberships): void {
                    $memberships->selectRaw('1')->from('workspace_users')->whereColumn('workspace_users.user_id', 'users.id');
                })
                ->get()->filter(static function (User $user) use ($eventId, $requestedIds): bool {
                    $id = PositiveIntegerId::parse($user->getRawOriginal($user->getKeyName()));

                    return $id !== null && isset($requestedIds[$id])
                        && PositiveIntegerId::parse($user->getRawOriginal('event_id')) === $eventId
                        && $user->getRawOriginal('role') === User::ROLE_SCANNER
                        && in_array($user->getRawOriginal('is_platform_admin'), [false, 0, '0'], true);
                })->keyBy(static fn (User $user): int => PositiveIntegerId::parse($user->getRawOriginal($user->getKeyName())));
        }
        foreach ($references as [$row, $relation, $foreignKey, $matches]) {
            $this->currentScanner($row, $relation, $foreignKey, $matches, $users);
        }
    }

    private function currentScanner(Model $row, string $relation, string $foreignKey, bool $matches, Collection $users): void
    {
        $raw = $row->getRawOriginal($foreignKey);
        $id = PositiveIntegerId::parse($raw);
        $row->setRelation($relation, $matches && $id !== null ? $users->get($id) : null);
        if ($raw !== null && $id === null) {
            $row->setAttribute($foreignKey, null);
            $row->syncOriginalAttributes([$foreignKey]);
        }
    }
}
