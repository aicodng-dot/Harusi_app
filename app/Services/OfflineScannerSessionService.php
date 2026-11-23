<?php

namespace App\Services;

use App\Exceptions\OfflineScannerSessionAlreadyActiveException;
use App\Models\Event;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OfflineScannerSessionService
{
    public function __construct(private readonly OfflineScannerSessionAuditService $audits)
    {
    }

    public function createDraft(Event $event, User $createdBy, array $attributes = []): OfflineScannerSession
    {
        return DB::transaction(function () use ($event, $createdBy, $attributes): OfflineScannerSession {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $session = OfflineScannerSession::query()->create([
                ...$attributes,
                'event_id' => $event->id,
                'created_by_user_id' => $createdBy->id,
                'status' => OfflineScannerSession::STATUS_DRAFT,
            ]);

            $this->audits->record($session, OfflineScannerSessionAudit::ACTION_SESSION_CREATED, $createdBy);

            return $session;
        }, 3);
    }

    public function transition(
        OfflineScannerSession $session,
        string $status,
        ?User $actor = null,
    ): OfflineScannerSession
    {
        if (! in_array($status, OfflineScannerSession::statuses(), true)) {
            throw new InvalidArgumentException('Invalid offline scanner session status.');
        }

        return DB::transaction(function () use ($session, $status, $actor): OfflineScannerSession {
            Event::query()->whereKey($session->event_id)->lockForUpdate()->firstOrFail();

            $lockedSession = OfflineScannerSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $previousStatus = $lockedSession->status;

            if (in_array($status, OfflineScannerSession::blockingStatuses(), true)) {
                $hasAnotherBlockingSession = OfflineScannerSession::query()
                    ->where('event_id', $lockedSession->event_id)
                    ->whereKeyNot($lockedSession->id)
                    ->whereIn('status', OfflineScannerSession::blockingStatuses())
                    ->lockForUpdate()
                    ->exists();

                if ($hasAnotherBlockingSession) {
                    throw new OfflineScannerSessionAlreadyActiveException(
                        'This event already has an offline scanner session that blocks admission changes.'
                    );
                }
            }

            $lockedSession->status = $status;

            if ($status === OfflineScannerSession::STATUS_ACTIVE && ! $lockedSession->session_started_at) {
                $lockedSession->session_started_at = now();
            }

            if ($status === OfflineScannerSession::STATUS_RESULTS_IMPORTED && ! $lockedSession->results_imported_at) {
                $lockedSession->results_imported_at = now();
            }

            if (in_array($status, [
                OfflineScannerSession::STATUS_CLOSED,
                OfflineScannerSession::STATUS_CANCELLED,
                OfflineScannerSession::STATUS_EXPIRED,
            ], true) && ! $lockedSession->session_closed_at) {
                $lockedSession->session_closed_at = now();
            }

            $lockedSession->save();

            $metadata = [
                'previous_status' => $previousStatus,
                'status' => $status,
            ];

            if ($status === OfflineScannerSession::STATUS_PACKAGE_GENERATED
                && $previousStatus !== OfflineScannerSession::STATUS_PACKAGE_GENERATED) {
                $this->audits->record($lockedSession, OfflineScannerSessionAudit::ACTION_PACKAGE_GENERATED, $actor, null, $metadata);
                $this->audits->record($lockedSession, OfflineScannerSessionAudit::ACTION_EVENT_LOCKED, $actor, null, $metadata);
            }

            if ($status === OfflineScannerSession::STATUS_ACTIVE
                && $previousStatus !== OfflineScannerSession::STATUS_ACTIVE) {
                $this->audits->record($lockedSession, OfflineScannerSessionAudit::ACTION_SESSION_ACTIVATED, $actor, null, $metadata);
            }

            if ($status === OfflineScannerSession::STATUS_RESULTS_IMPORTED
                && $previousStatus !== OfflineScannerSession::STATUS_RESULTS_IMPORTED) {
                $this->audits->record($lockedSession, OfflineScannerSessionAudit::ACTION_RESULTS_IMPORTED, $actor, null, $metadata);

                if (in_array($previousStatus, OfflineScannerSession::blockingStatuses(), true)) {
                    $this->audits->record($lockedSession, OfflineScannerSessionAudit::ACTION_EVENT_UNLOCKED, $actor, null, $metadata);
                }
            }

            if ($status === OfflineScannerSession::STATUS_CLOSED
                && $previousStatus !== OfflineScannerSession::STATUS_CLOSED) {
                if (in_array($previousStatus, OfflineScannerSession::blockingStatuses(), true)) {
                    $this->audits->record($lockedSession, OfflineScannerSessionAudit::ACTION_EVENT_UNLOCKED, $actor, null, $metadata);
                }

                $this->audits->record($lockedSession, OfflineScannerSessionAudit::ACTION_SESSION_CLOSED, $actor, null, $metadata);
            }

            return $lockedSession->fresh();
        }, 3);
    }
}
