<?php

namespace App\Services;

use App\Exceptions\OfflineEventLockedException;
use App\Exceptions\OfflineScannerSessionAlreadyActiveException;
use App\Models\Event;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class OfflineEventLockService
{
    public function __construct(
        private readonly OfflineScannerSessionService $sessions,
        private readonly OfflineScannerSessionAuditService $audits,
    ) {
    }

    public function assertEventCanStartOfflineSession(Event|int $event): void
    {
        if ($this->getActiveSessionForEvent($event)) {
            throw new OfflineScannerSessionAlreadyActiveException(
                'This event already has an active offline Android scanner session.'
            );
        }
    }

    public function assertEventAdmissionDataEditable(Event|int $event, bool $lockForUpdate = false): void
    {
        if ($lockForUpdate) {
            Event::query()->whereKey($this->eventId($event))->lockForUpdate()->firstOrFail();
        }

        if ($this->activeSessionQuery($this->eventId($event), $lockForUpdate)->exists()) {
            throw new OfflineEventLockedException();
        }
    }

    public function assertLaravelAdmissionAllowed(Event|int $event, bool $lockForUpdate = false): void
    {
        $this->assertEventAdmissionDataEditable($event, $lockForUpdate);
    }

    public function activateLock(OfflineScannerSession $session, ?User $actor = null): OfflineScannerSession
    {
        if (! in_array($session->status, [
            OfflineScannerSession::STATUS_PACKAGE_GENERATED,
            OfflineScannerSession::STATUS_ACTIVE,
        ], true)) {
            throw new InvalidArgumentException('Generate an offline scanner package before activating its event lock.');
        }

        return $this->sessions->transition($session, OfflineScannerSession::STATUS_ACTIVE, $actor);
    }

    public function markResultsImported(OfflineScannerSession $session, ?User $actor = null): OfflineScannerSession
    {
        return $this->sessions->transition($session, OfflineScannerSession::STATUS_RESULTS_IMPORTED, $actor);
    }

    public function releaseLock(OfflineScannerSession $session, ?User $actor = null): OfflineScannerSession
    {
        $freshSession = $session->fresh();

        if (! $freshSession) {
            throw new LogicException('Offline scanner session no longer exists.');
        }

        if (! $freshSession->results_imported_at && $freshSession->status !== OfflineScannerSession::STATUS_RESULTS_IMPORTED) {
            throw new LogicException('Offline scanner results must be imported before this event can be unlocked.');
        }

        return $this->sessions->transition($freshSession, OfflineScannerSession::STATUS_CLOSED, $actor);
    }

    public function cancelLock(
        OfflineScannerSession $session,
        User $actor,
        bool $confirmedUnused,
        string $reason,
    ): OfflineScannerSession {
        $reason = trim($reason);

        if (! $confirmedUnused) {
            throw new InvalidArgumentException('Confirm that the Android scanner session was not used before cancelling it.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('Provide a reason for cancelling the offline scanner session.');
        }

        return DB::transaction(function () use ($session, $actor, $reason): OfflineScannerSession {
            Event::query()->whereKey($session->event_id)->lockForUpdate()->firstOrFail();

            $lockedSession = OfflineScannerSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasImportedResults = $lockedSession->results_imported_at !== null
                || $lockedSession->resultImports()
                    ->where(function ($query): void {
                        $query->whereIn('status', [
                            OfflineScannerResultImport::STATUS_COMPLETED,
                            OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS,
                        ])->orWhere('imported_logs', '>', 0);
                    })
                    ->exists();

            if ($hasImportedResults) {
                throw new LogicException('This offline scanner session already has imported results and cannot be cancelled.');
            }

            if (! $lockedSession->isBlockingAdmissionChanges()) {
                throw new LogicException('Only an active offline scanner lock can be cancelled.');
            }

            $lockedSession->update([
                'status' => OfflineScannerSession::STATUS_CANCELLED,
                'session_closed_at' => now(),
            ]);

            $this->audits->record($lockedSession, OfflineScannerSessionAudit::ACTION_SESSION_CANCELLED, $actor, $reason, [
                'confirmed_unused' => true,
            ]);
            $this->audits->record($lockedSession, OfflineScannerSessionAudit::ACTION_EVENT_UNLOCKED, $actor, $reason, [
                'unlock_reason' => 'session_cancelled',
            ]);

            return $lockedSession->fresh();
        }, 3);
    }

    public function getActiveSessionForEvent(Event|int $event): ?OfflineScannerSession
    {
        return $this->activeSessionQuery($this->eventId($event))
            ->with(['createdBy', 'packageExport'])
            ->first();
    }

    private function activeSessionQuery(int $eventId, bool $lockForUpdate = false)
    {
        $query = OfflineScannerSession::query()
            ->where('event_id', $eventId)
            ->whereIn('status', OfflineScannerSession::blockingStatuses());

        return $lockForUpdate ? $query->lockForUpdate() : $query;
    }

    private function eventId(Event|int $event): int
    {
        return $event instanceof Event ? (int) $event->id : $event;
    }
}
