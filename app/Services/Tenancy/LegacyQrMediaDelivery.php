<?php

namespace App\Services\Tenancy;

use App\Exceptions\LegacyQrMediaUnavailable;
use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use App\Services\GuestQrStorage;
use App\Support\PositiveIntegerId;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Buffered administrator QR image/ZIP delivery (P4-047, family 3).
 *
 * Any needed image repair is the separate, already guarded family-1
 * LegacyGuestIssuance::ensureImage composition; its returned model is not
 * delivery authority. Delivery then freshly share-locks the current unowned Event,
 * exact active administrator and the complete frozen Event-scoped Guest/QR set
 * in one read-only transaction attempt, validates every exact key and global
 * path reference, and reads every byte before returning. No offline write veto
 * applies to these reads, no public fallback exists and nothing is retried.
 *
 * Shared locks hold off the writers of those rows while every byte is read, and
 * downloads and page views do not block each other. The global path reference is
 * an unlocked primary read: qr_image_path has no index, so a locking read would
 * hold every QR row of every Event, and scanner admission locks its QR row, which
 * would stop every gate for as long as an archive takes to read.
 */
final class LegacyQrMediaDelivery
{
    public function __construct(
        private readonly LegacyGuestIssuance $issuance,
        private readonly GuestQrStorage $storage,
    ) {}

    /** @return array{event: Event, guest: Guest, bytes: string} */
    public function single(Guest $guest, User $actor, mixed $selectedEventId): array
    {
        $eventId = $this->eventId($selectedEventId);
        $actorId = $this->actorId($actor);
        $guestId = $this->guestId($guest, $eventId);
        $this->issuance->ensureImage($guest, $actor, $selectedEventId);
        $delivery = $this->deliver([$guestId], $eventId, $actorId);

        return ['event' => $delivery['event'], ...$delivery['items'][0]];
    }

    /**
     * Freeze the all-ZIP membership before any repair: existing-QR Guests of the
     * selected Event in the existing name order. Later newcomers wait for the
     * next request; a removed or moved member refuses the whole archive.
     *
     * @return list<int>
     */
    public function eligibleGuestIds(User $actor, mixed $selectedEventId): array
    {
        $eventId = $this->eventId($selectedEventId);
        $this->actorId($actor);

        // A primary entry read, as the existing listing was: it grants nothing. Each member's
        // guarded repair composition and the locked delivery recheck everything current.
        $ids = [];
        foreach (Guest::query()->useWritePdo()->where('event_id', $eventId)
            ->whereExists(static function ($query): void {
                $query->selectRaw('1')->from('qr_codes')->whereColumn('qr_codes.guest_id', 'guests.id');
            })->orderBy('name')->orderBy('id')->get(['id', 'event_id']) as $guest) {
            $id = PositiveIntegerId::parse($guest->getRawOriginal('id'));
            abort_unless($id !== null && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId, 404);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Repair each frozen member through its own guarded composition, then deliver
     * the complete frozen set or refuse the whole archive. Earlier separately
     * authorized repairs can remain when a later member or delivery fails.
     *
     * @param  list<int>  $guestIds  frozen output order
     * @return array{event: Event, items: list<array{guest: Guest, bytes: string}>}
     */
    public function archive(array $guestIds, User $actor, mixed $selectedEventId): array
    {
        $eventId = $this->eventId($selectedEventId);
        $actorId = $this->actorId($actor);
        $frozen = [];
        foreach ($guestIds as $rawId) {
            $guestId = PositiveIntegerId::parse($rawId);
            abort_unless($guestId !== null && ! in_array($guestId, $frozen, true), 404);
            $frozen[] = $guestId;
        }
        foreach ($frozen as $guestId) {
            $this->issuance->ensureImage($this->identity($guestId, $eventId), $actor, $selectedEventId);
        }

        return $this->deliver($frozen, $eventId, $actorId);
    }

    /** @return array{event: Event, items: list<array{guest: Guest, bytes: string}>} */
    private function deliver(array $guestIds, int $eventId, int $actorId): array
    {
        return $this->attempt(function () use ($guestIds, $eventId, $actorId): array {
            $event = $this->prefix($eventId, $actorId);
            if ($guestIds === []) {
                return ['event' => clone $event, 'items' => []];
            }
            $lockOrder = $guestIds;
            sort($lockOrder, SORT_NUMERIC);

            $guests = [];
            foreach (Guest::query()->useWritePdo()->forceIndex(LegacyAdminReadProjection::GUESTS_BY_EVENT)->whereIn('id', $lockOrder)->where('event_id', $eventId)
                ->orderBy('id')->sharedLock()->get() as $guest) {
                $id = PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName()));
                abort_unless($id !== null && in_array($id, $lockOrder, true) && ! isset($guests[$id])
                    && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId, 404);
                $guests[$id] = $guest;
            }
            abort_unless(count($guests) === count($lockOrder), 404);

            $targets = [];
            foreach (QrCode::query()->useWritePdo()->forceIndex(LegacyAdminReadProjection::QR_BY_GUEST)->whereIn('guest_id', $lockOrder)->orderBy('id')->sharedLock()->get() as $qrCode) {
                $qrId = PositiveIntegerId::parse($qrCode->getRawOriginal($qrCode->getKeyName()));
                $guestId = PositiveIntegerId::parse($qrCode->getRawOriginal('guest_id'));
                abort_unless($qrId !== null && $guestId !== null && isset($guests[$guestId]) && ! isset($targets[$guestId]), 404);
                $path = $qrCode->getRawOriginal('qr_image_path');
                if (! GuestQrStorage::isSafeKey($path)) {
                    throw new LegacyQrMediaUnavailable;
                }
                $targets[$guestId] = ['qr_id' => $qrId, 'path' => $path];
            }
            if (count($targets) !== count($guests)) {
                throw new LegacyQrMediaUnavailable;
            }

            // Any other QR referencing an exact delivery key vetoes the whole response,
            // with no Event filter; only the member's own current QR is excluded.
            $vetoes = array_values($targets);
            usort($vetoes, static fn (array $left, array $right): int => strcmp($left['path'], $right['path'])
                ?: $left['qr_id'] <=> $right['qr_id']);
            foreach ($vetoes as $target) {
                $reference = DB::connection()->table('qr_codes')->useWritePdo()
                    ->where('qr_image_path', $target['path'])->where('id', '<>', $target['qr_id'])
                    ->orderBy('id')->first(['id']);
                if ($reference !== null) {
                    throw new LegacyQrMediaUnavailable;
                }
            }

            $disk = $this->storage->disk();
            $items = [];
            foreach ($guestIds as $guestId) {
                $bytes = $disk->get($targets[$guestId]['path']);
                if (! is_string($bytes) || $bytes === '') {
                    throw new LegacyQrMediaUnavailable;
                }
                $snapshot = clone $guests[$guestId];
                $snapshot->unsetRelation('qrCode');
                $snapshot->setRelation('event', clone $event);
                $items[] = ['guest' => $snapshot, 'bytes' => $bytes];
            }

            return ['event' => clone $event, 'items' => $items];
        });
    }

    /** Exactly one read-only attempt; actual SQL keeps framework handling before fixed feedback. */
    private function attempt(\Closure $body): mixed
    {
        try {
            return DB::connection()->transaction(function () use ($body): mixed {
                try {
                    return $body();
                } catch (HttpExceptionInterface|LegacyQrMediaUnavailable|\PDOException $exception) {
                    throw $exception;
                } catch (Throwable) {
                    throw new LegacyQrMediaUnavailable;
                }
            }, 1);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LegacyQrMediaUnavailable;
        }
    }

    private function prefix(int $eventId, int $actorId): Event
    {
        $event = Event::query()->useWritePdo()->whereKey($eventId)->whereNull('workspace_id')->sharedLock()->first();
        abort_unless($event instanceof Event
            && PositiveIntegerId::parse($event->getRawOriginal($event->getKeyName())) === $eventId
            && $event->getRawOriginal('workspace_id') === null, 404);
        $actor = User::query()->useWritePdo()->whereKey($actorId)->sharedLock()->first();
        abort_unless($actor instanceof User
            && PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())) === $actorId
            && $actor->getRawOriginal('role') === User::ROLE_ADMIN
            && in_array($actor->getRawOriginal('is_active'), [true, 1, '1'], true), 403);

        return $event;
    }

    private function eventId(mixed $selectedEventId): int
    {
        $eventId = PositiveIntegerId::parse($selectedEventId);
        abort_unless($eventId !== null, 404);

        return $eventId;
    }

    private function actorId(User $actor): int
    {
        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName()));
        abort_unless($actorId !== null
            && PositiveIntegerId::parse($actor->getAttributes()[$actor->getKeyName()] ?? null) === $actorId, 403);

        return $actorId;
    }

    private function guestId(Guest $guest, int $eventId): int
    {
        $guestId = PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName()));
        $attributes = $guest->getAttributes();
        abort_unless($guestId !== null
            && PositiveIntegerId::parse($attributes[$guest->getKeyName()] ?? null) === $guestId
            && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId
            && PositiveIntegerId::parse($attributes['event_id'] ?? null) === $eventId, 404);

        return $guestId;
    }

    /** Identity carrier for the guarded repair; it reloads and locks current state itself. */
    private function identity(int $guestId, int $eventId): Guest
    {
        $guest = new Guest;
        $guest->setRawAttributes(['id' => $guestId, 'event_id' => $eventId], true);
        $guest->exists = true;

        return $guest;
    }
}
