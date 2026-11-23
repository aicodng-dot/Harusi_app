<?php

namespace App\Services\Tenancy;

use App\Models\Event;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\User;
use App\Services\OfflineEventLockService;
use App\Services\OfflineScannerSessionService;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Fences the existing legacy administrator actions, not internal import transitions. */
class LegacyOfflineSessionActions
{
    public function __construct(
        private readonly OfflineEventLockService $locks,
        private readonly OfflineScannerSessionService $sessions,
    ) {}

    public function cancel(
        OfflineScannerSession $session,
        User $actor,
        mixed $selectedEventId,
        bool $confirmedUnused,
        string $reason,
    ): OfflineScannerSession {
        return $this->withLockedSession($session, $actor, $selectedEventId,
            function (OfflineScannerSession $current, User $currentActor, Connection $connection, int $eventId) use ($confirmedUnused, $reason): OfflineScannerSession {
                abort_unless($current->isBlockingAdmissionChanges() && $current->getRawOriginal('results_imported_at') === null, 403);
                $this->currentImports($connection, $current, $eventId);
                // Current rows, including partial imports, decide cancellation.
                $imported = $current->resultImports()->useWritePdo()
                    ->where(function ($query): void {
                        $query->whereIn('status', [OfflineScannerResultImport::STATUS_COMPLETED, OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS])
                            ->orWhere('imported_logs', '>', 0);
                    })->orderBy('id')->lockForUpdate()->get(['id']);
                if ($imported->isNotEmpty()) {
                    throw new LogicException('This offline scanner session already has imported results and cannot be cancelled.');
                }

                return $this->locks->cancelLock($current, $currentActor, $confirmedUnused, $reason);
            });
    }

    public function close(OfflineScannerSession $session, User $actor, mixed $selectedEventId): OfflineScannerSession
    {
        return $this->withLockedSession($session, $actor, $selectedEventId,
            function (OfflineScannerSession $current, User $currentActor, Connection $connection, int $eventId): OfflineScannerSession {
                abort_unless(! in_array($current->getRawOriginal('status'), [OfflineScannerSession::STATUS_CLOSED, OfflineScannerSession::STATUS_CANCELLED], true)
                    && ($current->getRawOriginal('results_imported_at') !== null || $current->getRawOriginal('status') === OfflineScannerSession::STATUS_RESULTS_IMPORTED), 403);
                foreach ($this->currentImports($connection, $current, $eventId) as $importId) {
                    $open = $connection->table('offline_scanner_import_conflicts')->useWritePdo()
                        ->where('offline_scanner_result_import_id', $importId)
                        ->where('resolution_status', OfflineScannerImportConflict::RESOLUTION_OPEN)
                        ->orderBy('id')->lockForUpdate()->get(['id']);
                    if ($open->isNotEmpty()) {
                        throw new LogicException('Resolve all offline result conflicts before closing this session.');
                    }
                }

                // Pass the current locked parent to the unchanged transition;
                // releaseLock()->fresh() could observe a caller's old RR parent.
                return $this->sessions->transition($current, OfflineScannerSession::STATUS_CLOSED, $currentActor);
            });
    }

    private function withLockedSession(OfflineScannerSession $session, User $actor, mixed $selectedEventId, Closure $action): OfflineScannerSession
    {
        $sessionId = $this->id($session->getRawOriginal($session->getKeyName()));
        $eventId = $this->id($session->getRawOriginal('event_id'));
        $selected = $this->id($selectedEventId);
        abort_unless($sessionId !== null && $eventId !== null && $selected === $eventId, 404);
        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = $this->id($actor->getAttributes()[$actor->getKeyName()] ?? null);
        $originalActorId = $this->id($actor->getRawOriginal($actor->getKeyName()));
        abort_unless($actorId !== null && $actorId === $originalActorId, 403);

        $connection = DB::connection();

        return $connection->transaction(function () use ($sessionId, $eventId, $actorId, $action, $connection): OfflineScannerSession {
            $event = Event::query()->useWritePdo()->whereKey($eventId)->lockForUpdate()->first();
            abort_unless($event instanceof Event && $this->id($event->getRawOriginal($event->getKeyName())) === $eventId
                && $event->getRawOriginal('workspace_id') === null, 404);
            $actor = User::query()->useWritePdo()->whereKey($actorId)->lockForUpdate()->first();
            abort_unless($actor instanceof User && $this->id($actor->getRawOriginal($actor->getKeyName())) === $actorId
                && $actor->getRawOriginal('role') === User::ROLE_ADMIN && in_array($actor->getRawOriginal('is_active'), [true, 1, '1'], true), 403);
            $session = OfflineScannerSession::query()->useWritePdo()->whereKey($sessionId)->lockForUpdate()->first();
            abort_unless($session instanceof OfflineScannerSession && $this->id($session->getRawOriginal($session->getKeyName())) === $sessionId
                && $this->id($session->getRawOriginal('event_id')) === $eventId, 404);

            return $action($session, $actor, $connection, $eventId);
        }, 3);
    }

    private function currentImports(Connection $connection, OfflineScannerSession $session, int $eventId): array
    {
        $sessionId = $this->id($session->getRawOriginal($session->getKeyName()));
        $rows = $connection->table('offline_scanner_result_imports')->useWritePdo()
            ->where('offline_scanner_session_id', $sessionId)->orderBy('id')->lockForUpdate()
            ->get(['id', 'offline_scanner_session_id', 'event_id']);
        $ids = [];
        foreach ($rows as $row) {
            $id = $this->id($row->id);
            abort_unless($id !== null && $this->id($row->offline_scanner_session_id) === $sessionId && $this->id($row->event_id) === $eventId, 404);
            $ids[] = $id;
        }

        return $ids;
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
