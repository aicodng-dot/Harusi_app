<?php

namespace App\Services\Tenancy;

use App\Models\Event;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\User;
use App\Services\OfflineScannerFileStorage;
use Illuminate\Support\Facades\DB;

/** Existing archive metadata only; no file bytes or secure deletion. */
class LegacyOfflineFileArchives
{
    public function __construct(private readonly OfflineScannerFileStorage $files) {}

    public function archive(
        OfflineScannerPackage|OfflineScannerResultImport $resource,
        User $actor,
        mixed $selectedEventId,
        string $reason,
    ): OfflineScannerPackage|OfflineScannerResultImport {
        $id = $this->id($resource->getRawOriginal($resource->getKeyName()));
        $sessionId = $this->id($resource->getRawOriginal('offline_scanner_session_id'));
        $eventId = $this->id($selectedEventId);
        $isImport = $resource instanceof OfflineScannerResultImport;
        abort_unless($id !== null && $sessionId !== null && $eventId !== null
            && (! $isImport || $this->id($resource->getRawOriginal('event_id')) === $eventId), 404);
        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = $this->id($actor->getAttributes()[$actor->getKeyName()] ?? null);
        abort_unless($actorId !== null && $actorId === $this->id($actor->getRawOriginal($actor->getKeyName())), 403);
        $class = $isImport ? OfflineScannerResultImport::class : OfflineScannerPackage::class;

        return DB::connection()->transaction(function () use ($id, $sessionId, $eventId, $actorId, $isImport, $class, $reason): OfflineScannerPackage|OfflineScannerResultImport {
            $event = Event::query()->useWritePdo()->whereKey($eventId)->lockForUpdate()->first();
            abort_unless($event instanceof Event && $this->id($event->getRawOriginal($event->getKeyName())) === $eventId
                && $event->getRawOriginal('workspace_id') === null, 404);
            $actor = User::query()->useWritePdo()->whereKey($actorId)->lockForUpdate()->first();
            abort_unless($actor instanceof User && $this->id($actor->getRawOriginal($actor->getKeyName())) === $actorId
                && $actor->getRawOriginal('role') === User::ROLE_ADMIN && in_array($actor->getRawOriginal('is_active'), [true, 1, '1'], true), 403);
            $session = OfflineScannerSession::query()->useWritePdo()->whereKey($sessionId)->lockForUpdate()->first();
            abort_unless($session instanceof OfflineScannerSession && $this->id($session->getRawOriginal($session->getKeyName())) === $sessionId
                && $this->id($session->getRawOriginal('event_id')) === $eventId, 404);
            $resource = $class::query()->useWritePdo()->whereKey($id)->lockForUpdate()->first();
            abort_unless($resource instanceof $class && $this->id($resource->getRawOriginal($resource->getKeyName())) === $id
                && $this->id($resource->getRawOriginal('offline_scanner_session_id')) === $sessionId
                && (! $isImport || $this->id($resource->getRawOriginal('event_id')) === $eventId), 404);
            abort_unless($resource->getRawOriginal('archived_at') === null && $resource->getRawOriginal('secure_deleted_at') === null, 403);

            // The helper uses this current locked parent for the audit, never
            // an ordinary relation read from a caller's older snapshot.
            return $isImport ? $this->files->archiveResultImport($resource, $actor, $reason, $session)
                : $this->files->archivePackage($resource, $actor, $reason, $session);
        }, 3);
    }

    private function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id === false ? null : $id;
    }
}
