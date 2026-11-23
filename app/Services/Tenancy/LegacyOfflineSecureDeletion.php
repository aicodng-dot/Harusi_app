<?php

namespace App\Services\Tenancy;

use App\Models\Event;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\User;
use App\Services\OfflineScannerFileStorage;
use App\Support\PositiveIntegerId;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Current legacy authority in separate byte, metadata and audit transactions. */
class LegacyOfflineSecureDeletion
{
    public function __construct(private readonly OfflineScannerFileStorage $files) {}

    public function delete(
        OfflineScannerPackage|OfflineScannerResultImport $resource,
        User $actor,
        mixed $selectedEventId,
        string $reason,
    ): OfflineScannerPackage|OfflineScannerResultImport {
        $attributes = $resource->getAttributes();
        $id = PositiveIntegerId::parse($resource->getRawOriginal($resource->getKeyName()));
        $sessionId = PositiveIntegerId::parse($resource->getRawOriginal('offline_scanner_session_id'));
        $eventId = PositiveIntegerId::parse($selectedEventId);
        $isImport = $resource instanceof OfflineScannerResultImport;
        abort_unless($id !== null && $sessionId !== null && $eventId !== null
            && PositiveIntegerId::parse($attributes[$resource->getKeyName()] ?? null) === $id
            && PositiveIntegerId::parse($attributes['offline_scanner_session_id'] ?? null) === $sessionId
            && (! $isImport || (PositiveIntegerId::parse($resource->getRawOriginal('event_id')) === $eventId
                && PositiveIntegerId::parse($attributes['event_id'] ?? null) === $eventId)), 404);
        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = PositiveIntegerId::parse($actor->getAttributes()[$actor->getKeyName()] ?? null);
        abort_unless($actorId !== null && $actorId === PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())), 403);

        $pathField = $isImport ? 'encrypted_file_path' : 'file_path';
        $path = $resource->getRawOriginal($pathField);
        $rawDisk = $resource->getRawOriginal('storage_disk');
        abort_unless(($path === null || is_string($path)) && ($rawDisk === null || is_string($rawDisk))
            && array_key_exists($pathField, $attributes) && $attributes[$pathField] === $path
            && array_key_exists('storage_disk', $attributes) && $attributes['storage_disk'] === $rawDisk, 404);
        $disk = $rawDisk ?: $this->files->diskName();
        $class = $isImport ? OfflineScannerResultImport::class : OfflineScannerPackage::class;

        $identity = [
            'class' => $class, 'id' => $id, 'session_id' => $sessionId, 'event_id' => $eventId,
            'actor_id' => $actorId, 'is_import' => $isImport, 'path_field' => $pathField,
            'path' => $path, 'raw_disk' => $rawDisk, 'disk' => $disk, 'reason' => $reason,
        ];
        $assetReceipt = null;
        $markerReceipt = null;
        $byteContextProvider = function () use ($identity, &$assetReceipt): array {
            if ($assetReceipt !== null) {
                throw new RuntimeException('The offline scanner deletion byte stage has already completed.');
            }
            // No retry: byte removal cannot be rolled back with this transaction.
            $result = DB::connection()->transaction(function () use ($identity): array {
                $context = $this->currentContext($identity);
                $asset = $this->assetContext($context);
                $this->files->deleteEncryptedArtifactBytes($identity['path'], $identity['disk']);

                return ['context' => $context, 'asset' => $asset];
            }, 1);
            $assetReceipt = $result['asset'];

            return $result['context'];
        };
        $metadataContextProvider = function (array $context, Closure $write) use ($identity, &$assetReceipt, &$markerReceipt): array {
            if ($assetReceipt === null || $markerReceipt !== null) {
                throw new RuntimeException('The offline scanner deletion metadata stage is not available.');
            }
            $asset = $assetReceipt;
            $result = DB::connection()->transaction(function () use ($identity, $asset, $write): array {
                $current = $this->currentContext($identity, $asset);
                if ($write($current) !== true) {
                    throw new RuntimeException('Unable to persist offline scanner deletion metadata.');
                }
                // Callbacks can change this connection's own locked rows. Query
                // again with captured scalar IDs, never the callback's models.
                $current = $this->currentContext($identity, $asset, null, false);
                $marker = $this->deletionMarker($current['resource']);
                if ($marker === null || $marker['actor_id'] !== $identity['actor_id'] || $marker['reason'] !== $identity['reason']) {
                    throw new RuntimeException('Unable to verify persisted offline scanner deletion metadata.');
                }

                return ['context' => $current, 'marker' => $marker];
            }, 1);
            $markerReceipt = $result['marker'];

            return $result['context'];
        };
        $auditContextProvider = function (array $context, Closure $write) use ($identity, &$assetReceipt, &$markerReceipt): array {
            if ($assetReceipt === null || $markerReceipt === null) {
                throw new RuntimeException('The offline scanner deletion audit stage is not available.');
            }
            $asset = $assetReceipt;
            $marker = $markerReceipt;

            return DB::connection()->transaction(function () use ($identity, $asset, $marker, $write): array {
                $current = $this->currentContext($identity, $asset, $marker, false);
                $auditId = $this->insertedAuditId($write($current));
                $current = $this->currentContext($identity, $asset, $marker, false);
                $this->assertPersistedAudit($auditId, $identity, $asset);

                return $current;
            }, 1);
        };

        return $isImport ? $this->files->secureDeleteResultImport($resource, $actor, $reason, $byteContextProvider, $metadataContextProvider, $auditContextProvider)
            : $this->files->secureDeletePackage($resource, $actor, $reason, $byteContextProvider, $metadataContextProvider, $auditContextProvider);
    }

    private function currentContext(array $identity, ?array $asset = null, ?array $marker = null, bool $requireUndeleted = true): array
    {
        $event = Event::query()->useWritePdo()->whereKey($identity['event_id'])->whereNull('workspace_id')->lockForUpdate()->first();
        abort_unless($event instanceof Event && PositiveIntegerId::parse($event->getRawOriginal($event->getKeyName())) === $identity['event_id']
            && $event->getRawOriginal('workspace_id') === null, 404);
        $actor = User::query()->useWritePdo()->whereKey($identity['actor_id'])->lockForUpdate()->first();
        abort_unless($actor instanceof User && PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())) === $identity['actor_id']
            && $actor->getRawOriginal('role') === User::ROLE_ADMIN && in_array($actor->getRawOriginal('is_active'), [true, 1, '1'], true), 403);
        $session = OfflineScannerSession::query()->useWritePdo()->whereKey($identity['session_id'])->where('event_id', $identity['event_id'])->lockForUpdate()->first();
        abort_unless($session instanceof OfflineScannerSession && PositiveIntegerId::parse($session->getRawOriginal($session->getKeyName())) === $identity['session_id']
            && PositiveIntegerId::parse($session->getRawOriginal('event_id')) === $identity['event_id'], 404);
        $class = $identity['class'];
        $resourceQuery = $class::query()->useWritePdo()->whereKey($identity['id'])->where('offline_scanner_session_id', $identity['session_id']);
        if ($identity['is_import']) {
            $resourceQuery->where('event_id', $identity['event_id']);
        }
        $resource = $resourceQuery->lockForUpdate()->first();
        abort_unless($resource instanceof $class && PositiveIntegerId::parse($resource->getRawOriginal($resource->getKeyName())) === $identity['id']
            && PositiveIntegerId::parse($resource->getRawOriginal('offline_scanner_session_id')) === $identity['session_id']
            && (! $identity['is_import'] || PositiveIntegerId::parse($resource->getRawOriginal('event_id')) === $identity['event_id'])
            && $resource->getRawOriginal($identity['path_field']) === $identity['path'] && $resource->getRawOriginal('storage_disk') === $identity['raw_disk'], 404);
        $context = ['resource' => $resource, 'session' => $session, 'actor' => $actor, 'path' => $identity['path'], 'disk' => $identity['disk']];
        abort_unless($asset === null || $this->assetContext($context) === $asset, 404);
        if ($requireUndeleted) {
            abort_unless($resource->getRawOriginal('secure_deleted_at') === null, 403);
        }
        if ($marker !== null) {
            abort_unless($this->deletionMarker($resource) === $marker, 403);
        }

        return $context;
    }

    private function assetContext(array $context): array
    {
        $resource = $context['resource'];
        $session = $context['session'];
        $isImport = $resource instanceof OfflineScannerResultImport;
        $asset = [
            'class' => $resource::class,
            'id' => $resource->getRawOriginal($resource->getKeyName()),
            'offline_scanner_session_id' => $resource->getRawOriginal('offline_scanner_session_id'),
            'session_id' => $session->getRawOriginal($session->getKeyName()),
            'event_id' => $session->getRawOriginal('event_id'),
            'import_event_id' => $isImport ? $resource->getRawOriginal('event_id') : null,
            'path' => $resource->getRawOriginal($isImport ? 'encrypted_file_path' : 'file_path'),
            'raw_disk' => $resource->getRawOriginal('storage_disk'),
            'disk' => $context['disk'],
            'file_hash' => $resource->getRawOriginal('file_hash'),
            'encrypted_file_size' => $resource->getRawOriginal('encrypted_file_size'),
        ];

        return $asset + ($isImport ? [
            'import_uuid' => $resource->getRawOriginal('import_uuid'),
            'results_export_id' => $resource->getRawOriginal('results_export_id'),
            'source_package_export_id' => $resource->getRawOriginal('source_package_export_id'),
        ] : ['package_uuid' => $resource->getRawOriginal('package_uuid')]);
    }

    private function deletionMarker(OfflineScannerPackage|OfflineScannerResultImport $resource): ?array
    {
        $timestamp = $resource->getRawOriginal('secure_deleted_at');
        $actorId = PositiveIntegerId::parse($resource->getRawOriginal('secure_deleted_by_user_id'));
        $reason = $resource->getRawOriginal('secure_deletion_reason');
        if (! is_string($timestamp) || $timestamp === '' || $actorId === null || ! is_string($reason)) {
            return null;
        }

        return ['timestamp' => $timestamp, 'actor_id' => $actorId, 'reason' => $reason];
    }

    private function insertedAuditId(mixed $audit): int
    {
        // Creation evidence belongs to the closed trusted record()->create()
        // callback; it does not certify forged flags or a substituted model.
        if (! $audit instanceof OfflineScannerSessionAudit || $audit->exists !== true || $audit->wasRecentlyCreated !== true) {
            throw new RuntimeException('Unable to persist the offline scanner deletion audit.');
        }
        $id = PositiveIntegerId::parse($audit->getRawOriginal($audit->getKeyName()));
        if ($id === null || PositiveIntegerId::parse($audit->getAttributes()[$audit->getKeyName()] ?? null) !== $id) {
            throw new RuntimeException('Unable to verify the offline scanner deletion audit identity.');
        }

        return $id;
    }

    private function assertPersistedAudit(int $auditId, array $identity, array $asset): void
    {
        $audit = OfflineScannerSessionAudit::query()->useWritePdo()->whereKey($auditId)
            ->where('offline_scanner_session_id', $identity['session_id'])->where('event_id', $identity['event_id'])
            ->where('actor_user_id', $identity['actor_id'])->where('action', OfflineScannerSessionAudit::ACTION_FILE_DELETED)->lockForUpdate()->first();
        if (! $audit instanceof OfflineScannerSessionAudit || PositiveIntegerId::parse($audit->getRawOriginal($audit->getKeyName())) !== $auditId
            || PositiveIntegerId::parse($audit->getRawOriginal('offline_scanner_session_id')) !== $identity['session_id']
            || PositiveIntegerId::parse($audit->getRawOriginal('event_id')) !== $identity['event_id']
            || PositiveIntegerId::parse($audit->getRawOriginal('actor_user_id')) !== $identity['actor_id']
            || $audit->getRawOriginal('action') !== OfflineScannerSessionAudit::ACTION_FILE_DELETED
            || $audit->getRawOriginal('reason') !== $identity['reason']
            || ! is_string($audit->getRawOriginal('occurred_at')) || $audit->getRawOriginal('occurred_at') === '') {
            throw new RuntimeException('Unable to verify the persisted offline scanner deletion audit.');
        }
        $rawMetadata = $audit->getRawOriginal('metadata');
        $metadata = is_string($rawMetadata) ? json_decode($rawMetadata, true) : null;
        $expected = $this->auditMetadata($identity, $asset);
        if (! is_array($metadata) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Unable to verify the persisted offline scanner deletion audit metadata.');
        }
        ksort($metadata);
        ksort($expected);
        if ($metadata !== $expected) {
            throw new RuntimeException('Unable to verify the persisted offline scanner deletion audit metadata.');
        }
    }

    private function auditMetadata(array $identity, array $asset): array
    {
        $metadata = $identity['is_import'] ? [
            'file_type' => 'results', 'import_uuid' => $asset['import_uuid'], 'results_export_id' => $asset['results_export_id'],
        ] : ['file_type' => 'package', 'package_uuid' => $asset['package_uuid']];

        return $metadata + [
            'file_hash' => $asset['file_hash'],
            'file_size' => $asset['encrypted_file_size'] === null ? null : (int) $asset['encrypted_file_size'],
            'storage_disk' => $identity['disk'],
            'file_path' => $identity['path'],
        ];
    }
}
