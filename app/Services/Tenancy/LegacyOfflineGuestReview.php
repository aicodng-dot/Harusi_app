<?php

namespace App\Services\Tenancy;

use App\Models\Guest;
use App\Models\OfflineScannerFinalGuestState;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Support\PositiveIntegerId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Read projections only; callers must authorize the current legacy resource head first. */
final class LegacyOfflineGuestReview
{
    public function projectImport(OfflineScannerResultImport $import, OfflineScannerSession $session): OfflineScannerResultImport
    {
        [$importId, $sessionId, $eventId] = $this->context($import, $session);
        $import->loadMissing(['finalGuestStates', 'conflicts']);
        $this->projectRows($import->finalGuestStates, $import->conflicts, $importId, $sessionId, $eventId);

        return $import;
    }

    public function projectConflict(
        OfflineScannerImportConflict $conflict,
        OfflineScannerResultImport $import,
        OfflineScannerSession $session,
    ): OfflineScannerImportConflict {
        [$importId, $sessionId, $eventId] = $this->context($import, $session);
        abort_unless($this->childMatches($conflict, $importId, $sessionId, $eventId), 404);
        $this->projectRows(collect(), collect([$conflict]), $importId, $sessionId, $eventId);

        return $conflict;
    }

    private function context(OfflineScannerResultImport $import, OfflineScannerSession $session): array
    {
        $importId = PositiveIntegerId::parse($import->getRawOriginal($import->getKeyName()));
        $sessionId = PositiveIntegerId::parse($session->getRawOriginal($session->getKeyName()));
        $eventId = PositiveIntegerId::parse($session->getRawOriginal('event_id'));
        abort_unless($importId !== null && $sessionId !== null && $eventId !== null
            && PositiveIntegerId::parse($import->getRawOriginal('offline_scanner_session_id')) === $sessionId
            && PositiveIntegerId::parse($import->getRawOriginal('event_id')) === $eventId, 404);

        return [$importId, $sessionId, $eventId];
    }

    private function projectRows(
        Collection $states,
        Collection $conflicts,
        int $importId,
        int $sessionId,
        int $eventId,
    ): void {
        $guestIds = collect();
        foreach ($states->concat($conflicts) as $row) {
            if (! $this->childMatches($row, $importId, $sessionId, $eventId)) {
                continue;
            }
            $guestIds->push(PositiveIntegerId::parse($row->getRawOriginal('guest_id')));
            if ($row instanceof OfflineScannerImportConflict) {
                $guestIds->push(PositiveIntegerId::parse($row->getRawOriginal('replacement_guest_id')));
            }
        }
        $guestIds = $guestIds->filter()->unique()->values();
        $guests = $guestIds->isEmpty()
            ? collect()
            : Guest::query()->useWritePdo()->where('event_id', $eventId)->whereIn('id', $guestIds)->get()->keyBy('id');

        foreach ($states as $state) {
            $matches = $this->childMatches($state, $importId, $sessionId, $eventId);
            $this->currentGuest($state, 'guest', 'guest_id', $matches, $guests);
        }
        foreach ($conflicts as $conflict) {
            $matches = $this->childMatches($conflict, $importId, $sessionId, $eventId);
            if (! $matches || ! $this->historicalStateMatches($conflict, $eventId)) {
                $conflict->setAttribute('server_state', null);
                $conflict->setAttribute('server_used', null);
                $conflict->setAttribute('server_remaining', null);
                $conflict->syncOriginalAttributes(['server_state', 'server_used', 'server_remaining']);
            }
            $this->currentGuest($conflict, 'guest', 'guest_id', $matches, $guests);
            $this->currentGuest($conflict, 'replacementGuest', 'replacement_guest_id', $matches, $guests);
        }
    }

    private function childMatches(Model $row, int $importId, int $sessionId, int $eventId): bool
    {
        return PositiveIntegerId::parse($row->getRawOriginal('offline_scanner_result_import_id')) === $importId
            && PositiveIntegerId::parse($row->getRawOriginal('event_id')) === $eventId
            && (! $row instanceof OfflineScannerFinalGuestState
                || PositiveIntegerId::parse($row->getRawOriginal('offline_scanner_session_id')) === $sessionId);
    }

    private function historicalStateMatches(OfflineScannerImportConflict $conflict, int $eventId): bool
    {
        $state = $conflict->server_state;
        if (! is_array($state) || PositiveIntegerId::parse($state['event_id'] ?? null) !== $eventId) {
            return false;
        }
        $stateGuestId = PositiveIntegerId::parse($state['guest_id'] ?? null);
        $rawGuestId = $conflict->getRawOriginal('guest_id');

        return $stateGuestId !== null && ($rawGuestId === null || PositiveIntegerId::parse($rawGuestId) === $stateGuestId);
    }

    private function currentGuest(Model $row, string $relation, string $foreignKey, bool $matches, Collection $guests): void
    {
        $id = $matches ? PositiveIntegerId::parse($row->getRawOriginal($foreignKey)) : null;
        $guest = $id === null ? null : $guests->get($id);
        $row->setRelation($relation, $guest);
        if ($guest === null) {
            $row->setAttribute($foreignKey, null);
            $row->syncOriginalAttributes([$foreignKey]);
        }
    }
}
