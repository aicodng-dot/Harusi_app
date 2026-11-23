<?php

namespace App\Services\Tenancy;

use App\Exceptions\LegacyGuestDeletionFailed;
use App\Exceptions\OfflineEventLockedException;
use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use App\Services\GuestQrStorage;
use App\Services\OfflineEventLockService;
use App\Support\PositiveIntegerId;
use Illuminate\Support\Facades\DB;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** One-attempt current legacy authority for ordinary Guest and QR-file deletion. */
final class LegacyGuestDeletion
{
    public function __construct(
        private readonly OfflineEventLockService $offlineLocks,
        private readonly GuestQrStorage $storage,
    ) {}

    public function delete(Guest $guest, User $actor, mixed $selectedEventId): void
    {
        $guestId = PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName()));
        $eventId = PositiveIntegerId::parse($guest->getRawOriginal('event_id'));
        $attributes = $guest->getAttributes();
        abort_unless($guestId !== null && $eventId !== null
            && PositiveIntegerId::parse($selectedEventId) === $eventId
            && PositiveIntegerId::parse($attributes[$guest->getKeyName()] ?? null) === $guestId
            && PositiveIntegerId::parse($attributes['event_id'] ?? null) === $eventId, 404);

        $this->deleteCaptured([$guestId], $eventId, $this->actorId($actor));
    }

    public function deleteMany(array $guestIds, User $actor, mixed $selectedEventId): int
    {
        $eventId = PositiveIntegerId::parse($selectedEventId);
        abort_unless($eventId !== null && count($guestIds) >= 1 && count($guestIds) <= 50, 404);

        $capturedIds = [];
        foreach ($guestIds as $rawId) {
            $guestId = PositiveIntegerId::parse($rawId);
            abort_unless($guestId !== null && ! in_array($guestId, $capturedIds, true), 404);
            $capturedIds[] = $guestId;
        }
        usort($capturedIds, static fn (int $left, int $right): int => $left <=> $right);

        return $this->deleteCaptured($capturedIds, $eventId, $this->actorId($actor));
    }

    private function actorId(User $actor): int
    {
        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = PositiveIntegerId::parse($actor->getAttributes()[$actor->getKeyName()] ?? null);
        abort_unless($actorId !== null
            && PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())) === $actorId, 403);

        return $actorId;
    }

    private function deleteCaptured(array $guestIds, int $eventId, int $actorId): int
    {
        try {
            $connection = DB::connection();

            return $connection->transaction(function () use ($connection, $guestIds, $eventId, $actorId): int {
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

                $guests = Guest::query()->useWritePdo()->whereIn('id', $guestIds)
                    ->where('event_id', $eventId)->orderBy('id')->lockForUpdate()->get();
                abort_unless($guests->count() === count($guestIds), 404);

                $currentIds = [];
                $guestsById = [];
                foreach ($guests as $guest) {
                    $guestId = PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName()));
                    abort_unless($guestId !== null && in_array($guestId, $guestIds, true)
                        && ! isset($guestsById[$guestId])
                        && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId, 404);
                    $currentIds[] = $guestId;
                    $guestsById[$guestId] = $guest;
                }
                abort_unless($currentIds === $guestIds, 404);

                $qrCodes = QrCode::query()->useWritePdo()->whereIn('guest_id', $guestIds)
                    ->orderBy('id')->lockForUpdate()->get();
                $qrByGuest = [];
                $qrIds = [];
                $pathTargets = [];
                foreach ($qrCodes as $qrCode) {
                    $qrId = PositiveIntegerId::parse($qrCode->getRawOriginal($qrCode->getKeyName()));
                    $guestId = PositiveIntegerId::parse($qrCode->getRawOriginal('guest_id'));
                    abort_unless($qrId !== null && ! in_array($qrId, $qrIds, true)
                        && $guestId !== null && in_array($guestId, $guestIds, true)
                        && ! isset($qrByGuest[$guestId]), 404);

                    $path = $qrCode->getRawOriginal('qr_image_path');
                    if ($path !== null && ! is_string($path)) {
                        throw new LegacyGuestDeletionFailed;
                    }

                    $qrIds[] = $qrId;
                    $qrByGuest[$guestId] = ['model' => $qrCode, 'path' => $path];
                    if ($path !== null && $path !== '' && $path !== '0') {
                        // An unsafe exact key refuses the whole operation before vetoes/adapter.
                        if (! GuestQrStorage::isSafeKey($path)) {
                            throw new LegacyGuestDeletionFailed;
                        }
                        $pathTargets[] = ['qr_id' => $qrId, 'path' => $path];
                    }
                }

                usort($pathTargets, static fn (array $left, array $right): int => strcmp($left['path'], $right['path'])
                    ?: $left['qr_id'] <=> $right['qr_id']);
                foreach ($pathTargets as $target) {
                    $reference = $connection->table('qr_codes')->useWritePdo()
                        ->where('qr_image_path', $target['path'])->where('id', '<>', $target['qr_id'])
                        ->orderBy('id')->lockForUpdate()->first(['id']);
                    if ($reference !== null) {
                        throw new LegacyGuestDeletionFailed;
                    }
                }

                // Freeze every byte target before any model callback or storage call.
                $schedule = [];
                foreach ($guestIds as $guestId) {
                    $guest = $guestsById[$guestId];
                    $guest->setRelation('qrCode', $qrByGuest[$guestId]['model'] ?? null);
                    $schedule[] = ['guest' => $guest, 'path' => $qrByGuest[$guestId]['path'] ?? null];
                }

                $disk = null;
                if ($pathTargets !== []) {
                    try {
                        $disk = $this->storage->disk();
                    } catch (Throwable) {
                        throw new LegacyGuestDeletionFailed;
                    }
                }

                foreach ($schedule as $target) {
                    $path = $target['path'];
                    if ($path !== null && $path !== '' && $path !== '0') {
                        // Do not let adapter messages become framework SQL retry/deadlock signals.
                        try {
                            $deleted = $disk->delete($path);
                        } catch (Throwable) {
                            throw new LegacyGuestDeletionFailed;
                        }
                        if ($deleted !== true) {
                            throw new LegacyGuestDeletionFailed;
                        }
                    }

                    try {
                        $deleted = $target['guest']->delete();
                    } catch (PDOException|HttpExceptionInterface|OfflineEventLockedException $exception) {
                        // Real SQL failures retain Laravel's transaction handling before sanitation.
                        throw $exception;
                    } catch (Throwable) {
                        throw new LegacyGuestDeletionFailed;
                    }
                    if ($deleted !== true) {
                        throw new LegacyGuestDeletionFailed;
                    }
                }

                return count($guestIds);
            }, 1);
        } catch (HttpExceptionInterface|OfflineEventLockedException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LegacyGuestDeletionFailed;
        }
    }
}
