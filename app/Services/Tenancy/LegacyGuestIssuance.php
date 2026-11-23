<?php

namespace App\Services\Tenancy;

use App\Exceptions\LegacyGuestIssuanceFailed;
use App\Exceptions\LegacyGuestIssuanceInterrupted;
use App\Exceptions\OfflineEventLockedException;
use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use App\Services\GuestQrImageService;
use App\Services\GuestQrStorage;
use App\Services\ManualCodeService;
use App\Services\OfflineEventLockService;
use App\Support\PositiveIntegerId;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Current legacy authority and one local attempt for Guest/QR issuance effects. */
final class LegacyGuestIssuance
{
    public function __construct(
        private readonly OfflineEventLockService $offlineLocks,
        private readonly GuestQrImageService $qrImages,
        private readonly GuestQrStorage $storage,
    ) {}

    public function create(array $validated, User $actor, mixed $selectedEventId, bool $generateQr = true): Guest
    {
        $eventId = $this->eventId($selectedEventId);
        $actorId = $this->actorId($actor);
        $payload = $this->payload($validated);

        return $this->attempt(function () use ($payload, $actorId, $eventId, $generateQr): Guest {
            $event = $this->prefix($eventId, $actorId, true);

            return $this->createCurrent($payload, $event, $generateQr);
        });
    }

    public function importRow(array $validated, string $duplicatePolicy, User $actor, mixed $selectedEventId, bool $generateQr): array
    {
        $eventId = $this->eventId($selectedEventId);
        $actorId = $this->actorId($actor);
        $payload = $this->payload($validated);
        if (! in_array($duplicatePolicy, ['skip', 'reject_if_match', 'add_anyway'], true)) {
            throw new LegacyGuestIssuanceFailed;
        }

        return $this->attempt(function () use ($payload, $duplicatePolicy, $actorId, $eventId, $generateQr): array {
            $event = $this->prefix($eventId, $actorId, true);
            if ($duplicatePolicy === 'skip') {
                return ['outcome' => 'skipped', 'guest' => null, 'generated_qr' => false];
            }
            if ($duplicatePolicy === 'reject_if_match' && $this->duplicate($payload, $eventId) !== null) {
                return ['outcome' => 'conflict', 'guest' => null, 'generated_qr' => false];
            }

            $guest = $this->createCurrent($payload, $event, $generateQr);

            return ['outcome' => 'created', 'guest' => $guest, 'generated_qr' => $generateQr];
        });
    }

    public function generate(Guest $guest, User $actor, mixed $selectedEventId, bool $missingOnly = false): array
    {
        $eventId = $this->eventId($selectedEventId);
        $guestId = $this->guestId($guest, $eventId);
        $actorId = $this->actorId($actor);

        return $this->attempt(function () use ($guestId, $actorId, $eventId, $missingOnly): array {
            $event = $this->prefix($eventId, $actorId, true);
            $guest = $this->currentGuest($guestId, $eventId);
            $qrCode = $this->currentQr($guestId);
            if ($missingOnly && $qrCode !== null) {
                return ['qr' => $qrCode, 'generated' => false, 'replaced' => false];
            }

            return ['qr' => $this->issue($guest, $event, $qrCode), 'generated' => true, 'replaced' => $qrCode !== null];
        });
    }

    public function ensureImage(Guest $guest, User $actor, mixed $selectedEventId): QrCode
    {
        $eventId = $this->eventId($selectedEventId);
        $guestId = $this->guestId($guest, $eventId);
        $actorId = $this->actorId($actor);
        $compatible = $this->attempt(function () use ($guestId, $actorId, $eventId): ?QrCode {
            $event = $this->prefix($eventId, $actorId, false);
            $guest = $this->currentGuest($guestId, $eventId);
            $qrCode = $this->currentQr($guestId);
            if ($qrCode === null) {
                return null;
            }
            $this->existingToken($qrCode);
            $path = $this->rawPath($qrCode);
            if ($this->hasPath($path)) {
                // Unsafe or shared keys are fixed failures, never a missing-image signal for repair,
                // and are refused before the compatible-image probe reads any bytes.
                $this->requireSafeKey($path);
                $this->vetoReferences(null, $path, PositiveIntegerId::parse($qrCode->getRawOriginal($qrCode->getKeyName())));
            }
            $manualCode = $this->manualCode($guest);
            if (! $this->hasPath($path) || $manualCode === null) {
                return null;
            }
            $disk = $this->operation(fn () => $this->storage->disk());

            return $this->compatible($disk, $path, $manualCode) ? $this->withGuestSnapshot($qrCode, $guest, $event) : null;
        });
        if ($compatible !== null) {
            return $compatible;
        }

        // A new composition discards every read-probe authority/path observation.
        return $this->attempt(function () use ($guestId, $actorId, $eventId): QrCode {
            $event = $this->prefix($eventId, $actorId, true);
            $guest = $this->currentGuest($guestId, $eventId);
            $qrCode = $this->currentQr($guestId);

            $qrCode = $qrCode === null ? $this->issue($guest, $event, null) : $this->repair($guest, $event, $qrCode);

            return $this->withGuestSnapshot($qrCode, $guest, $event);
        });
    }

    /** Return guarded display context without a circular Guest -> QR -> Guest relation. */
    private function withGuestSnapshot(QrCode $qrCode, Guest $guest, Event $event): QrCode
    {
        $snapshot = clone $guest;
        $snapshot->unsetRelation('qrCode');
        $snapshot->setRelation('event', clone $event);
        $qrCode->setRelation('guest', $snapshot);

        return $qrCode;
    }

    /** Correlate only the exact inner failure after framework rollback and context restoration. */
    private function attempt(Closure $body): mixed
    {
        $failure = null;
        $connection = null;
        $pdo = null;
        $level = null;
        $open = null;
        try {
            $connection = DB::connection();
            $pdo = $connection->getPdo();
            $level = $connection->transactionLevel();
            $open = $pdo->inTransaction();

            return $connection->transaction(function () use ($body, &$failure): mixed {
                try {
                    return $body();
                } catch (PDOException|HttpExceptionInterface|OfflineEventLockedException $exception) {
                    throw $exception;
                } catch (Throwable) {
                    $failure = new LegacyGuestIssuanceFailed;
                    throw $failure;
                }
            }, 1);
        } catch (HttpExceptionInterface|OfflineEventLockedException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($failure !== null && $exception === $failure) {
                $restored = false;
                try {
                    $restored = DB::connection() === $connection && $connection->getPdo() === $pdo
                        && $connection->transactionLevel() === $level && $pdo->inTransaction() === $open;
                } catch (Throwable) {
                    // Unknown rollback/connection outcomes cannot become continuable row failures.
                }
                if ($restored) {
                    throw $failure;
                }
            }
            throw new LegacyGuestIssuanceInterrupted;
        }
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

    private function payload(array $validated): array
    {
        $allowance = PositiveIntegerId::parse($validated['allowed_entries'] ?? null);
        if (! isset($validated['name'], $validated['phone_number'], $validated['pass_type'])
            || ! is_string($validated['name']) || trim($validated['name']) === ''
            || ! is_string($validated['phone_number']) || $validated['phone_number'] === ''
            || ! in_array($validated['pass_type'], [Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL], true)
            || $allowance === null || $allowance > Guest::MAX_ALLOWED_ENTRIES
            || Guest::allowedEntriesForPassType($validated['pass_type'], $allowance) !== $allowance) {
            throw new LegacyGuestIssuanceFailed;
        }

        return ['name' => $validated['name'], 'phone_number' => $validated['phone_number'],
            'pass_type' => $validated['pass_type'], 'allowed_entries' => $allowance];
    }

    private function prefix(int $eventId, int $actorId, bool $mutation): Event
    {
        $event = Event::query()->useWritePdo()->whereKey($eventId)->whereNull('workspace_id')->lockForUpdate()->first();
        abort_unless($event instanceof Event
            && PositiveIntegerId::parse($event->getRawOriginal($event->getKeyName())) === $eventId
            && $event->getRawOriginal('workspace_id') === null, 404);
        $actor = User::query()->useWritePdo()->whereKey($actorId)->lockForUpdate()->first();
        abort_unless($actor instanceof User
            && PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())) === $actorId
            && $actor->getRawOriginal('role') === User::ROLE_ADMIN
            && in_array($actor->getRawOriginal('is_active'), [true, 1, '1'], true), 403);
        if ($mutation) {
            $this->offlineLocks->assertEventAdmissionDataEditable($event, true);
        }

        return $event;
    }

    private function currentGuest(int $guestId, int $eventId): Guest
    {
        $guest = Guest::query()->useWritePdo()->whereKey($guestId)->where('event_id', $eventId)->lockForUpdate()->first();
        abort_unless($guest instanceof Guest
            && PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName())) === $guestId
            && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId, 404);

        return $guest;
    }

    private function currentQr(int $guestId): ?QrCode
    {
        $qrCode = QrCode::query()->useWritePdo()->where('guest_id', $guestId)->lockForUpdate()->first();
        abort_unless($qrCode === null || (PositiveIntegerId::parse($qrCode->getRawOriginal($qrCode->getKeyName())) !== null
            && PositiveIntegerId::parse($qrCode->getRawOriginal('guest_id')) === $guestId), 404);

        return $qrCode;
    }

    private function duplicate(array $payload, int $eventId): ?Guest
    {
        $name = Str::lower(preg_replace('/\s+/', ' ', trim($payload['name'])) ?? '');
        $phone = $payload['phone_number'];
        $guest = Guest::query()->useWritePdo()->where('event_id', $eventId)
            ->where(function (Builder $query) use ($name, $phone): void {
                if ($phone !== '') {
                    $query->where('phone_number', $phone);
                }
                if ($name !== '') {
                    $method = $phone !== '' ? 'orWhereRaw' : 'whereRaw';
                    $query->{$method}('LOWER(TRIM(name)) = ?', [$name]);
                }
            })->orderBy('id')->lockForUpdate()->first();
        if ($guest !== null) {
            abort_unless(PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName())) !== null
                && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId, 404);
        }

        return $guest;
    }

    private function createCurrent(array $payload, Event $event, bool $generateQr): Guest
    {
        $eventId = PositiveIntegerId::parse($event->getRawOriginal($event->getKeyName()));
        $guest = new Guest([...$payload, 'event_id' => $eventId, 'used_entries' => 0, 'status' => Guest::STATUS_ACTIVE]);
        if ($guest->save() !== true || ! $guest->exists) {
            throw new LegacyGuestIssuanceFailed;
        }
        $guestId = PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName()));
        abort_unless($guestId !== null
            && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId, 404);
        $guest->setRelation('qrCode', null);
        $guest->setRelation('event', $event);
        if ($generateQr) {
            $guest->setRelation('qrCode', $this->issue($guest, $event, null));
        }

        return $guest;
    }

    private function issue(Guest $guest, Event $event, ?QrCode $qrCode): QrCode
    {
        $guestId = PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName()));
        $oldPath = $qrCode === null ? null : $this->rawPath($qrCode);
        do {
            $token = Guest::makeSecureToken();
        } while (QrCode::query()->useWritePdo()->where('qr_token', $token)->exists());
        $path = $this->imagePath($event, $guestId, $token);
        $this->provisionManualCode($guest);
        $qrCode ??= new QrCode(['guest_id' => $guestId]);
        $qrCode->fill(['qr_token' => $token, 'qr_image_path' => $path, 'is_active' => true,
            'generated_at' => now(), 'revoked_at' => null]);
        if ($qrCode->save() !== true || ! $qrCode->exists) {
            throw new LegacyGuestIssuanceFailed;
        }
        $qrId = PositiveIntegerId::parse($qrCode->getRawOriginal($qrCode->getKeyName()));
        abort_unless($qrId !== null
            && PositiveIntegerId::parse($qrCode->getRawOriginal('guest_id')) === $guestId, 404);
        if ($qrCode->getRawOriginal('qr_token') !== $token || $qrCode->getRawOriginal('qr_image_path') !== $path) {
            throw new LegacyGuestIssuanceFailed;
        }
        $this->requireSafeKey($path);
        if ($this->hasPath($oldPath)) {
            $this->requireSafeKey($oldPath);
        }
        $this->vetoReferences($oldPath, $path, $qrId);
        $disk = $this->operation(fn () => $this->storage->disk());
        $this->requireAbsentDestination($disk, $path);
        $contents = $this->render($guest, $qrCode, $event, $token);
        if ($this->operation(fn () => $disk->put($path, $contents)) !== true) {
            throw new LegacyGuestIssuanceFailed;
        }
        if ($this->hasPath($oldPath) && $oldPath !== $path
            && $this->operation(fn () => $disk->delete($oldPath)) !== true) {
            throw new LegacyGuestIssuanceFailed;
        }
        $guest->setRelation('qrCode', $qrCode);

        return $qrCode;
    }

    private function repair(Guest $guest, Event $event, QrCode $qrCode): QrCode
    {
        $token = $this->existingToken($qrCode);
        $oldPath = $this->rawPath($qrCode);
        $guestId = PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName()));
        $qrId = PositiveIntegerId::parse($qrCode->getRawOriginal($qrCode->getKeyName()));
        $path = $this->imagePath($event, $guestId, $token);
        $manualCode = $this->provisionManualCode($guest);
        $this->requireSafeKey($path);
        if ($this->hasPath($oldPath)) {
            $this->requireSafeKey($oldPath);
        }
        $this->vetoReferences($oldPath, $path, $qrId);
        $disk = $this->operation(fn () => $this->storage->disk());
        if ($this->hasPath($oldPath) && $this->compatible($disk, $oldPath, $manualCode)) {
            return $qrCode;
        }
        if ($oldPath !== $path) {
            $this->requireAbsentDestination($disk, $path);
        }
        $contents = $this->render($guest, $qrCode, $event, $token);
        if ($this->operation(fn () => $disk->put($path, $contents)) !== true) {
            throw new LegacyGuestIssuanceFailed;
        }
        $qrCode->qr_image_path = $path;
        $qrCode->generated_at ??= now();
        if ($qrCode->save() !== true) {
            throw new LegacyGuestIssuanceFailed;
        }
        $guest->setRelation('qrCode', $qrCode);

        return $qrCode;
    }

    private function existingToken(QrCode $qrCode): string
    {
        $token = $qrCode->getRawOriginal('qr_token');
        if (! is_string($token) || preg_match('/^[A-Za-z0-9]{40,128}$/', $token) !== 1) {
            throw new LegacyGuestIssuanceFailed;
        }

        return $token;
    }

    private function rawPath(QrCode $qrCode): ?string
    {
        $path = $qrCode->getRawOriginal('qr_image_path');
        if ($path !== null && ! is_string($path)) {
            throw new LegacyGuestIssuanceFailed;
        }

        return $path;
    }

    private function hasPath(?string $path): bool
    {
        return $path !== null && $path !== '' && $path !== '0';
    }

    /** Every truthy stored or computed key must be exact and safe before any adapter effect. */
    private function requireSafeKey(string $path): void
    {
        if (! GuestQrStorage::isSafeKey($path)) {
            throw new LegacyGuestIssuanceFailed;
        }
    }

    private function manualCode(Guest $guest): ?string
    {
        $code = $guest->getRawOriginal('manual_code');
        if ($code === null || $code === '') {
            return null;
        }
        if (! is_string($code) || preg_match('/\A[0-9]{4}\z/', $code) !== 1) {
            throw new LegacyGuestIssuanceFailed;
        }

        return $code;
    }

    private function provisionManualCode(Guest $guest): string
    {
        $code = $this->manualCode($guest);
        if ($code !== null) {
            return $code;
        }
        $guestId = PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName()));
        $eventId = PositiveIntegerId::parse($guest->getRawOriginal('event_id'));
        $preserved = array_intersect_key($guest->getRawOriginal(), array_flip(['pass_type', 'allowed_entries', 'used_entries', 'status']));
        $guest->manual_code = app(ManualCodeService::class)->generate($eventId);
        if ($guest->save() !== true) {
            throw new LegacyGuestIssuanceFailed;
        }
        abort_unless(PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName())) === $guestId
            && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId, 404);
        // A manual-code repair must not normalize existing admission state through saving hooks.
        foreach ($preserved as $attribute => $value) {
            if ($guest->getRawOriginal($attribute) !== $value) {
                throw new LegacyGuestIssuanceFailed;
            }
        }
        $code = $this->manualCode($guest);
        if ($code === null) {
            throw new LegacyGuestIssuanceFailed;
        }

        return $code;
    }

    private function imagePath(Event $event, int $guestId, string $token): string
    {
        $safeToken = preg_replace('/[^A-Za-z0-9]/', '', $token) ?: hash('sha256', $token);
        // A slug can equal a Windows device name ("Con", "LPT1"), which the exact-key policy
        // refuses on every host; a fixed suffix keeps such Events issuable.
        $segment = $event->safeSlug();
        if (! GuestQrStorage::isSafeKey($segment)) {
            $segment .= '_event';
        }

        return 'qr-codes/'.$segment.'/guest-'.$guestId.'-'.$safeToken.'.png';
    }

    private function vetoReferences(?string $oldPath, string $destination, int $qrId): void
    {
        $paths = [$destination];
        if ($this->hasPath($oldPath)) {
            $paths[] = $oldPath;
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $reference = DB::connection()->table('qr_codes')->useWritePdo()
                ->where('qr_image_path', $path)->where('id', '<>', $qrId)->orderBy('id')
                ->lockForUpdate()->first(['id']);
            if ($reference !== null) {
                throw new LegacyGuestIssuanceFailed;
            }
        }
    }

    private function requireAbsentDestination(mixed $disk, string $path): void
    {
        if ($this->operation(fn () => $disk->exists($path)) !== false) {
            throw new LegacyGuestIssuanceFailed;
        }
    }

    private function compatible(mixed $disk, string $path, string $manualCode): bool
    {
        return $this->operation(fn () => $disk->exists($path)) === true
            && $this->operation(fn () => str_contains($disk->get($path), "Manual admission code\x00".$manualCode));
    }

    private function render(Guest $guest, QrCode $qrCode, Event $event, string $token): string
    {
        // Give the injected renderer separate models; byte/SQL targets remain local scalars/models.
        $snapshot = clone $guest;
        $snapshot->setRelation('qrCode', clone $qrCode);
        $snapshot->setRelation('event', clone $event);
        $payload = route('scanner.verify-token', ['token' => $token]);

        return $this->operation(fn () => $this->qrImages->png($snapshot, $payload));
    }

    private function operation(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (PDOException|HttpExceptionInterface|OfflineEventLockedException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LegacyGuestIssuanceFailed;
        }
    }
}
