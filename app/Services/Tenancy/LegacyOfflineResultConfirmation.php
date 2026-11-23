<?php

namespace App\Services\Tenancy;

use App\Models\Event;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\User;
use App\Services\OfflineScannerResults\OfflineScannerResultsImportService;
use App\Support\PositiveIntegerId;
use Illuminate\Support\Facades\DB;

/** Current legacy HTTP confirmation authority; the existing importer keeps its own reconciliation rules. */
final class LegacyOfflineResultConfirmation
{
    public function __construct(private readonly OfflineScannerResultsImportService $resultImports) {}

    public function confirm(
        OfflineScannerResultImport $resultImport,
        User $actor,
        mixed $selectedEventId,
    ): OfflineScannerResultImport {
        $importId = PositiveIntegerId::parse($resultImport->getRawOriginal($resultImport->getKeyName()));
        $sessionId = PositiveIntegerId::parse($resultImport->getRawOriginal('offline_scanner_session_id'));
        $eventId = PositiveIntegerId::parse($resultImport->getRawOriginal('event_id'));
        $attributes = $resultImport->getAttributes();
        abort_unless($importId !== null && $sessionId !== null && $eventId !== null
            && PositiveIntegerId::parse($selectedEventId) === $eventId
            && PositiveIntegerId::parse($attributes[$resultImport->getKeyName()] ?? null) === $importId
            && PositiveIntegerId::parse($attributes['offline_scanner_session_id'] ?? null) === $sessionId
            && PositiveIntegerId::parse($attributes['event_id'] ?? null) === $eventId, 404);

        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = PositiveIntegerId::parse($actor->getAttributes()[$actor->getKeyName()] ?? null);
        abort_unless($actorId !== null
            && PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())) === $actorId, 403);

        return DB::connection()->transaction(function () use ($importId, $sessionId, $eventId, $actorId): OfflineScannerResultImport {
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

            // The nested importer uses the same default connection. Its existing
            // downstream locks, lifecycle checks and completed retry remain intact.
            return $this->resultImports->importVerified($import, $actor);
        }, 3);
    }
}
