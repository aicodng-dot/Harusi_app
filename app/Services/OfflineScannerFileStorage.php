<?php

namespace App\Services;

use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\User;
use Closure;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OfflineScannerFileStorage
{
    public const PACKAGE_EXTENSION = '.wqrpkg';

    public const RESULTS_EXTENSION = '.wqrresults';

    public function __construct(private readonly OfflineScannerSessionAuditService $audits) {}

    public function diskName(): string
    {
        return (string) config('offline_scanner.disk', 'offline_scanner');
    }

    public function packageFormat(): string
    {
        return (string) config('offline_scanner.formats.package', 'harusi.offline-scanner.package');
    }

    public function resultsFormat(): string
    {
        return (string) config('offline_scanner.formats.results', 'harusi.offline-scanner.results');
    }

    public function packagePath(OfflineScannerSession $session, string $fileName): string
    {
        return $this->assertSafeRelativePath('offline-packages/'.$this->safeUuid($session->session_uuid).'/'.$this->safeDownloadName($fileName, self::PACKAGE_EXTENSION));
    }

    public function resultsPath(OfflineScannerSession $session, string $importUuid): string
    {
        return $this->assertSafeRelativePath('offline-results/'.$this->safeUuid($session->session_uuid).'/'.$this->safeUuid($importUuid).self::RESULTS_EXTENSION);
    }

    public function put(string $path, string $contents, ?string $disk = null): void
    {
        $disk = $disk ?: $this->diskName();
        $this->assertPrivateDisk($disk);
        $path = $this->assertSafeRelativePath($path);

        if (! Storage::disk($disk)->put($path, $contents)) {
            throw new RuntimeException('Unable to store encrypted offline scanner file.');
        }
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        $disk = $disk ?: $this->diskName();
        $this->assertPrivateDisk($disk);

        return Storage::disk($disk)->exists($this->assertSafeRelativePath($path));
    }

    public function get(string $path, ?string $disk = null): string
    {
        $disk = $disk ?: $this->diskName();
        $this->assertPrivateDisk($disk);
        $path = $this->assertSafeRelativePath($path);

        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('The encrypted offline scanner file is not available.');
        }

        $contents = Storage::disk($disk)->get($path);
        if (! is_string($contents)) {
            throw new RuntimeException('Unable to read encrypted offline scanner file.');
        }

        return $contents;
    }

    public function delete(string $path, ?string $disk = null): bool
    {
        $disk = $disk ?: $this->diskName();
        $this->assertPrivateDisk($disk);

        return Storage::disk($disk)->delete($this->assertSafeRelativePath($path));
    }

    public function packageDownloadResponse(OfflineScannerPackage $package): Response
    {
        if ($package->secure_deleted_at !== null) {
            abort(404);
        }

        $contents = $this->get($package->file_path, $package->storage_disk ?: $this->diskName());
        $downloadName = $this->safeDownloadName((string) $package->file_name, self::PACKAGE_EXTENSION);

        return response($contents, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$downloadName.'"',
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Offline-Package-Fingerprint' => (string) $package->package_fingerprint,
        ]);
    }

    public function archivePackage(OfflineScannerPackage $package, User $actor, string $reason, ?OfflineScannerSession $currentSession = null): OfflineScannerPackage
    {
        $session = $currentSession ?? $package->session()->firstOrFail();
        $package->update([
            'archived_at' => now(),
            'archived_by_user_id' => $actor->id,
        ]);

        $this->audits->record($session, OfflineScannerSessionAudit::ACTION_FILE_ARCHIVED, $actor, $reason, [
            'file_type' => 'package',
            'package_uuid' => $package->package_uuid,
            'file_hash' => $package->file_hash,
            'file_size' => $package->encrypted_file_size,
            'storage_disk' => $package->storage_disk,
            'file_path' => $package->file_path,
            'retention_days' => (int) config('offline_scanner.retention.retain_completed_days', 365),
        ]);

        return $package->fresh();
    }

    public function secureDeletePackage(
        OfflineScannerPackage $package,
        User $actor,
        string $reason,
        ?Closure $byteContextProvider = null,
        ?Closure $metadataContextProvider = null,
        ?Closure $auditContextProvider = null,
    ): OfflineScannerPackage {
        $this->assertSecureDeletionAllowed();
        if ($byteContextProvider !== null) {
            $context = $byteContextProvider();
            $package = $context['resource'];
            $session = $context['session'];
            $actor = $context['actor'];
            $path = $context['path'];
            $disk = $context['disk'];
        } else {
            $session = $package->session()->firstOrFail();
            $path = $package->file_path;
            $disk = $package->storage_disk ?: $this->diskName();
            $this->deleteEncryptedArtifactBytes($path, $disk);
        }

        $context = ['resource' => $package, 'session' => $session, 'actor' => $actor, 'path' => $path, 'disk' => $disk];
        $writeMetadata = static function (array $current) use ($reason): bool {
            $package = $current['resource'];
            $actor = $current['actor'];

            return $package->update([
                'secure_deleted_at' => now(),
                'secure_deleted_by_user_id' => $actor->id,
                'secure_deletion_reason' => $reason,
            ]);
        };
        if ($metadataContextProvider !== null) {
            $context = $metadataContextProvider($context, $writeMetadata);
        } else {
            $writeMetadata($context);
        }

        $writeAudit = function (array $current) use ($reason): OfflineScannerSessionAudit {
            $package = $current['resource'];
            $session = $current['session'];
            $actor = $current['actor'];
            $path = $current['path'];
            $disk = $current['disk'];

            return $this->audits->record($session, OfflineScannerSessionAudit::ACTION_FILE_DELETED, $actor, $reason, [
                'file_type' => 'package',
                'package_uuid' => $package->package_uuid,
                'file_hash' => $package->file_hash,
                'file_size' => $package->encrypted_file_size,
                'storage_disk' => $disk,
                'file_path' => $path,
            ]);
        };
        if ($auditContextProvider !== null) {
            $context = $auditContextProvider($context, $writeAudit);
        } else {
            $writeAudit($context);
        }

        return $context['resource']->fresh();
    }

    public function archiveResultImport(OfflineScannerResultImport $resultImport, User $actor, string $reason, ?OfflineScannerSession $currentSession = null): OfflineScannerResultImport
    {
        $session = $currentSession ?? $resultImport->session()->firstOrFail();
        $resultImport->update([
            'archived_at' => now(),
            'archived_by_user_id' => $actor->id,
        ]);

        $this->audits->record($session, OfflineScannerSessionAudit::ACTION_FILE_ARCHIVED, $actor, $reason, [
            'file_type' => 'results',
            'import_uuid' => $resultImport->import_uuid,
            'results_export_id' => $resultImport->results_export_id,
            'file_hash' => $resultImport->file_hash,
            'file_size' => $resultImport->encrypted_file_size,
            'storage_disk' => $resultImport->storage_disk,
            'file_path' => $resultImport->encrypted_file_path,
            'retention_days' => (int) config('offline_scanner.retention.retain_completed_days', 365),
        ]);

        return $resultImport->fresh();
    }

    public function secureDeleteResultImport(
        OfflineScannerResultImport $resultImport,
        User $actor,
        string $reason,
        ?Closure $byteContextProvider = null,
        ?Closure $metadataContextProvider = null,
        ?Closure $auditContextProvider = null,
    ): OfflineScannerResultImport {
        $this->assertSecureDeletionAllowed();
        if ($byteContextProvider !== null) {
            $context = $byteContextProvider();
            $resultImport = $context['resource'];
            $session = $context['session'];
            $actor = $context['actor'];
            $path = $context['path'];
            $disk = $context['disk'];
        } else {
            $session = $resultImport->session()->firstOrFail();
            $path = $resultImport->encrypted_file_path;
            $disk = $resultImport->storage_disk ?: $this->diskName();
            $this->deleteEncryptedArtifactBytes($path, $disk);
        }

        $context = ['resource' => $resultImport, 'session' => $session, 'actor' => $actor, 'path' => $path, 'disk' => $disk];
        $writeMetadata = static function (array $current) use ($reason): bool {
            $resultImport = $current['resource'];
            $actor = $current['actor'];

            return $resultImport->update([
                'secure_deleted_at' => now(),
                'secure_deleted_by_user_id' => $actor->id,
                'secure_deletion_reason' => $reason,
            ]);
        };
        if ($metadataContextProvider !== null) {
            $context = $metadataContextProvider($context, $writeMetadata);
        } else {
            $writeMetadata($context);
        }

        $writeAudit = function (array $current) use ($reason): OfflineScannerSessionAudit {
            $resultImport = $current['resource'];
            $session = $current['session'];
            $actor = $current['actor'];
            $path = $current['path'];
            $disk = $current['disk'];

            return $this->audits->record($session, OfflineScannerSessionAudit::ACTION_FILE_DELETED, $actor, $reason, [
                'file_type' => 'results',
                'import_uuid' => $resultImport->import_uuid,
                'results_export_id' => $resultImport->results_export_id,
                'file_hash' => $resultImport->file_hash,
                'file_size' => $resultImport->encrypted_file_size,
                'storage_disk' => $disk,
                'file_path' => $path,
            ]);
        };
        if ($auditContextProvider !== null) {
            $context = $auditContextProvider($context, $writeAudit);
        } else {
            $writeAudit($context);
        }

        return $context['resource']->fresh();
    }

    public function deleteEncryptedArtifactBytes(?string $path, string $disk): void
    {
        $this->assertSecureDeletionAllowed();

        if ($path && $this->exists($path, $disk)) {
            if (! $this->delete($path, $disk)) {
                throw new RuntimeException('Unable to delete encrypted offline scanner file.');
            }
        }
    }

    public function assertSafeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === ''
            || str_contains($path, "\0")
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path)
            || preg_match('#(^|/)\.\.(/|$)#', $path)
            || str_contains($path, '//')) {
            throw new RuntimeException('Unsafe offline scanner storage path rejected.');
        }

        return $path;
    }

    private function assertPrivateDisk(string $disk): void
    {
        if ($disk === 'public') {
            throw new RuntimeException('Offline scanner files cannot be stored on the public disk.');
        }

        $root = config('filesystems.disks.'.$disk.'.root');
        if (! is_string($root) || trim($root) === '') {
            return;
        }

        $normalizedRoot = $this->normalizePath($root);
        foreach ([public_path(), public_path('storage')] as $publicRoot) {
            $normalizedPublic = $this->normalizePath($publicRoot);
            if ($normalizedRoot === $normalizedPublic || str_starts_with($normalizedRoot.'/', $normalizedPublic.'/')) {
                throw new RuntimeException('Offline scanner files cannot be stored in a publicly browsable directory.');
            }
        }
    }

    private function safeUuid(string $uuid): string
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            throw new RuntimeException('Invalid offline scanner file identifier.');
        }

        return strtolower($uuid);
    }

    private function safeDownloadName(string $fileName, string $requiredExtension): string
    {
        $fileName = basename(str_replace('\\', '/', trim($fileName)));
        $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $fileName) ?: '';
        $fileName = trim($fileName, '.-_');

        if ($fileName === '') {
            $fileName = 'offline-scanner-file'.ltrim($requiredExtension, '.');
        }

        if (! str_ends_with(strtolower($fileName), $requiredExtension)) {
            $fileName .= $requiredExtension;
        }

        return substr($fileName, 0, 255);
    }

    private function assertSecureDeletionAllowed(): void
    {
        if (! (bool) config('offline_scanner.retention.allow_secure_deletion', false)) {
            throw new RuntimeException('Secure deletion is disabled by the offline scanner retention policy.');
        }
    }

    private function normalizePath(string $path): string
    {
        return strtolower(str_replace('\\', '/', rtrim($path, '\\/')));
    }
}
