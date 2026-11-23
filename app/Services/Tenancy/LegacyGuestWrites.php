<?php

namespace App\Services\Tenancy;

use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use App\Services\OfflineEventLockService;
use App\Support\PositiveIntegerId;
use Closure;
use Illuminate\Support\Facades\DB;

/** Current legacy authority for trusted single-Guest metadata/cancellation callbacks. */
final class LegacyGuestWrites
{
    public function __construct(private readonly OfflineEventLockService $offlineLocks) {}

    public function mutate(Guest $guest, User $actor, mixed $selectedEventId, Closure $write): void
    {
        $guestId = PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName()));
        $eventId = PositiveIntegerId::parse($guest->getRawOriginal('event_id'));
        $attributes = $guest->getAttributes();
        abort_unless($guestId !== null && $eventId !== null
            && PositiveIntegerId::parse($selectedEventId) === $eventId
            && PositiveIntegerId::parse($attributes[$guest->getKeyName()] ?? null) === $guestId
            && PositiveIntegerId::parse($attributes['event_id'] ?? null) === $eventId, 404);

        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = PositiveIntegerId::parse($actor->getAttributes()[$actor->getKeyName()] ?? null);
        abort_unless($actorId !== null
            && PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())) === $actorId, 403);

        DB::connection()->transaction(function () use ($guestId, $eventId, $actorId, $write): void {
            $event = Event::query()->useWritePdo()->whereKey($eventId)->whereNull('workspace_id')->lockForUpdate()->first();
            abort_unless($event instanceof Event
                && PositiveIntegerId::parse($event->getRawOriginal($event->getKeyName())) === $eventId
                && $event->getRawOriginal('workspace_id') === null, 404);

            $actor = User::query()->useWritePdo()->whereKey($actorId)->lockForUpdate()->first();
            abort_unless($actor instanceof User
                && PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())) === $actorId
                && $actor->getRawOriginal('role') === User::ROLE_ADMIN
                && in_array($actor->getRawOriginal('is_active'), [true, 1, '1'], true), 403);

            // Retain the existing service's Event reread and blocking-Session query.
            $this->offlineLocks->assertEventAdmissionDataEditable($event, true);

            $guest = Guest::query()->useWritePdo()->whereKey($guestId)
                ->where('event_id', $eventId)->lockForUpdate()->first();
            abort_unless($guest instanceof Guest
                && PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName())) === $guestId
                && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId, 404);

            $write($guest);
        }, 3);
    }

    public function mutateWithQr(Guest $guest, User $actor, mixed $selectedEventId, Closure $write): void
    {
        $this->mutate($guest, $actor, $selectedEventId, function (Guest $guardedGuest) use ($write): void {
            $guestId = PositiveIntegerId::parse($guardedGuest->getRawOriginal($guardedGuest->getKeyName()));
            abort_unless($guestId !== null, 404);

            $qrCode = QrCode::query()->useWritePdo()->where('guest_id', $guestId)->lockForUpdate()->first();
            abort_unless($qrCode === null
                || (PositiveIntegerId::parse($qrCode->getRawOriginal($qrCode->getKeyName())) !== null
                    && PositiveIntegerId::parse($qrCode->getRawOriginal('guest_id')) === $guestId), 404);

            $write($guardedGuest, $qrCode);
        });
    }
}
