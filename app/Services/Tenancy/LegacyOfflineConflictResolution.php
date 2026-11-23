<?php

namespace App\Services\Tenancy;

use App\Models\Event;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\User;
use App\Services\OfflineScannerResults\OfflineScannerImportConflictResolver;
use App\Support\PositiveIntegerId;
use Illuminate\Support\Facades\DB;

/** Current legacy HTTP authority and head lineage only; resolver effects retain their existing rules. */
final class LegacyOfflineConflictResolution
{
    public function __construct(private readonly OfflineScannerImportConflictResolver $resolver) {}

    public function resolve(
        OfflineScannerImportConflict $conflict,
        User $actor,
        mixed $selectedEventId,
        array $input,
    ): OfflineScannerImportConflict {
        $conflictId = PositiveIntegerId::parse($conflict->getRawOriginal($conflict->getKeyName()));
        $importId = PositiveIntegerId::parse($conflict->getRawOriginal('offline_scanner_result_import_id'));
        $eventId = PositiveIntegerId::parse($conflict->getRawOriginal('event_id'));
        $attributes = $conflict->getAttributes();
        abort_unless($conflictId !== null && $importId !== null && $eventId !== null
            && PositiveIntegerId::parse($selectedEventId) === $eventId
            && PositiveIntegerId::parse($attributes[$conflict->getKeyName()] ?? null) === $conflictId
            && PositiveIntegerId::parse($attributes['offline_scanner_result_import_id'] ?? null) === $importId
            && PositiveIntegerId::parse($attributes['event_id'] ?? null) === $eventId, 404);

        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = PositiveIntegerId::parse($actor->getAttributes()[$actor->getKeyName()] ?? null);
        abort_unless($actorId !== null
            && PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())) === $actorId, 403);

        $connection = DB::connection();
        // Capture only identity metadata before choosing the Session lock. A
        // later locked mismatch refuses rather than following a different parent.
        $importHead = $connection->table('offline_scanner_result_imports')->useWritePdo()
            ->where('id', $importId)->first(['id', 'offline_scanner_session_id', 'event_id']);
        $sessionId = PositiveIntegerId::parse($importHead?->offline_scanner_session_id);
        abort_unless($importHead !== null && $sessionId !== null
            && PositiveIntegerId::parse($importHead->id) === $importId
            && PositiveIntegerId::parse($importHead->event_id) === $eventId, 404);

        return $connection->transaction(function () use ($conflictId, $importId, $sessionId, $eventId, $actorId, $input): OfflineScannerImportConflict {
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

            $import = OfflineScannerResultImport::query()->useWritePdo()->whereKey($importId)->lockForUpdate()->first();
            abort_unless($import instanceof OfflineScannerResultImport
                && PositiveIntegerId::parse($import->getRawOriginal($import->getKeyName())) === $importId
                && PositiveIntegerId::parse($import->getRawOriginal('offline_scanner_session_id')) === $sessionId
                && PositiveIntegerId::parse($import->getRawOriginal('event_id')) === $eventId, 404);

            $conflict = OfflineScannerImportConflict::query()->useWritePdo()->whereKey($conflictId)->lockForUpdate()->first();
            abort_unless($conflict instanceof OfflineScannerImportConflict
                && PositiveIntegerId::parse($conflict->getRawOriginal($conflict->getKeyName())) === $conflictId
                && PositiveIntegerId::parse($conflict->getRawOriginal('offline_scanner_result_import_id')) === $importId
                && PositiveIntegerId::parse($conflict->getRawOriginal('event_id')) === $eventId, 404);

            // Use the unchanged resolver on the same default connection. Bound
            // log, Guest/replacement and sibling effects are separate boundaries.
            return $this->resolver->resolve($conflict, $actor, $input);
        }, 3);
    }
}
