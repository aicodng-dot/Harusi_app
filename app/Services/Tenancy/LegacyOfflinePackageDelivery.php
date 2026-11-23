<?php

namespace App\Services\Tenancy;

use App\Models\Event;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\User;
use App\Services\OfflineScannerFileStorage;
use App\Services\OfflineScannerSessionAuditService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Current row authorization through buffered delivery; file writers need their own fences. */
class LegacyOfflinePackageDelivery
{
    public function __construct(
        private readonly OfflineScannerFileStorage $files,
        private readonly OfflineScannerSessionAuditService $audits,
    ) {}

    public function download(
        OfflineScannerPackage $package,
        User $actor,
        mixed $selectedEventId,
        ?OfflineScannerSession $currentExportOf = null,
    ): Response {
        $packageId = $this->id($package->getRawOriginal($package->getKeyName()));
        $sessionId = $this->id($package->getRawOriginal('offline_scanner_session_id'));
        $eventId = $this->id($selectedEventId);
        abort_unless($packageId !== null && $sessionId !== null && $eventId !== null, 404);
        if ($currentExportOf !== null) {
            abort_unless($this->id($currentExportOf->getRawOriginal($currentExportOf->getKeyName())) === $sessionId
                && $this->id($currentExportOf->getRawOriginal('event_id')) === $eventId, 404);
        }
        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = $this->id($actor->getAttributes()[$actor->getKeyName()] ?? null);
        abort_unless($actorId !== null && $actorId === $this->id($actor->getRawOriginal($actor->getKeyName())), 403);
        $requireCurrent = $currentExportOf !== null;

        try {
            return DB::connection()->transaction(function () use ($packageId, $sessionId, $eventId, $actorId, $requireCurrent): Response {
                $event = Event::query()->useWritePdo()->whereKey($eventId)->lockForUpdate()->first();
                abort_unless($event instanceof Event && $this->id($event->getRawOriginal($event->getKeyName())) === $eventId
                    && $event->getRawOriginal('workspace_id') === null, 404);
                $actor = User::query()->useWritePdo()->whereKey($actorId)->lockForUpdate()->first();
                abort_unless($actor instanceof User && $this->id($actor->getRawOriginal($actor->getKeyName())) === $actorId
                    && $actor->getRawOriginal('role') === User::ROLE_ADMIN && in_array($actor->getRawOriginal('is_active'), [true, 1, '1'], true), 403);
                $session = OfflineScannerSession::query()->useWritePdo()->whereKey($sessionId)->lockForUpdate()->first();
                abort_unless($session instanceof OfflineScannerSession && $this->id($session->getRawOriginal($session->getKeyName())) === $sessionId
                    && $this->id($session->getRawOriginal('event_id')) === $eventId, 404);
                $package = OfflineScannerPackage::query()->useWritePdo()->whereKey($packageId)->lockForUpdate()->first();
                abort_unless($package instanceof OfflineScannerPackage && $this->id($package->getRawOriginal($package->getKeyName())) === $packageId
                    && $this->id($package->getRawOriginal('offline_scanner_session_id')) === $sessionId
                    && (! $requireCurrent || $this->id($session->getRawOriginal('package_export_id')) === $packageId), 404);
                abort_unless($package->getRawOriginal('revoked_at') === null && $package->getRawOriginal('secure_deleted_at') === null
                    && $package->getRawOriginal('file_path') !== null, 403);

                // Read all bytes before recording delivery; no stream escapes the locks.
                $response = $this->files->packageDownloadResponse($package);
                $package->update(['downloaded_at' => now(), 'downloaded_by_user_id' => $actorId]);
                $this->audits->record($session, OfflineScannerSessionAudit::ACTION_PACKAGE_DOWNLOADED, $actor, null, [
                    'package_uuid' => $package->package_uuid,
                    'package_fingerprint' => $package->package_fingerprint,
                    'file_hash' => $package->file_hash,
                    'file_size' => $package->encrypted_file_size,
                    'storage_disk' => $package->storage_disk,
                    'file_path' => $package->file_path,
                ]);

                return $response;
            }, 3);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            return response('Unable to download the offline scanner package. Try the existing package again.', 503, [
                'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
            ]);
        }
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
