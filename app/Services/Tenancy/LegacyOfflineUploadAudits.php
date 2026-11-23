<?php

namespace App\Services\Tenancy;

use App\Models\Event;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\User;
use App\Services\OfflineScannerSessionAuditService;
use App\Support\PositiveIntegerId;
use Illuminate\Support\Facades\DB;

/** Current authority for preliminary audits, independently of preview storage. */
final class LegacyOfflineUploadAudits
{
    public function __construct(private readonly OfflineScannerSessionAuditService $audits) {}

    /** @return array{session: OfflineScannerSession, actor: User} */
    public function record(
        OfflineScannerSession $session,
        User $actor,
        mixed $selectedEventId,
        string $action,
        array $metadata,
    ): array {
        abort_unless(in_array($action, [
            OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED,
            OfflineScannerSessionAudit::ACTION_RESULTS_VERIFICATION_FAILED,
            OfflineScannerSessionAudit::ACTION_REIMPORT_ATTEMPTED,
        ], true), 404);

        $sessionId = PositiveIntegerId::parse($session->getRawOriginal($session->getKeyName()));
        $eventId = PositiveIntegerId::parse($session->getRawOriginal('event_id'));
        $attributes = $session->getAttributes();
        abort_unless($sessionId !== null && $eventId !== null
            && PositiveIntegerId::parse($selectedEventId) === $eventId
            && PositiveIntegerId::parse($attributes[$session->getKeyName()] ?? null) === $sessionId
            && PositiveIntegerId::parse($attributes['event_id'] ?? null) === $eventId, 404);

        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = PositiveIntegerId::parse($actor->getAttributes()[$actor->getKeyName()] ?? null);
        abort_unless($actorId !== null
            && PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())) === $actorId, 403);

        return DB::connection()->transaction(function () use ($sessionId, $eventId, $actorId, $action, $metadata): array {
            $event = Event::query()->useWritePdo()->whereKey($eventId)->lockForUpdate()->first();
            abort_unless($event instanceof Event
                && PositiveIntegerId::parse($event->getRawOriginal($event->getKeyName())) === $eventId
                && $event->getRawOriginal('workspace_id') === null, 404);

            $actor = User::query()->useWritePdo()->whereKey($actorId)->lockForUpdate()->first();
            abort_unless($actor instanceof User
                && PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())) === $actorId
                && $actor->getRawOriginal('role') === User::ROLE_ADMIN
                && in_array($actor->getRawOriginal('is_active'), [true, 1, '1'], true), 403);

            $session = OfflineScannerSession::query()->useWritePdo()->whereKey($sessionId)->lockForUpdate()->first();
            abort_unless($session instanceof OfflineScannerSession
                && PositiveIntegerId::parse($session->getRawOriginal($session->getKeyName())) === $sessionId
                && PositiveIntegerId::parse($session->getRawOriginal('event_id')) === $eventId, 404);

            $this->audits->record($session, $action, $actor, null, $metadata);

            return ['session' => $session, 'actor' => $actor];
        }, 3);
    }
}
