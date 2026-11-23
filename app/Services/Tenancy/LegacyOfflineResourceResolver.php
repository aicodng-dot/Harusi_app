<?php

namespace App\Services\Tenancy;

use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Request-entry observations only; downstream writes/files need their own fences. */
class LegacyOfflineResourceResolver
{
    public function session(mixed $id, mixed $selectedEventId): ?OfflineScannerSession
    {
        return $this->find(OfflineScannerSession::class, 'session', $id, $selectedEventId);
    }

    public function package(mixed $id, mixed $selectedEventId): ?OfflineScannerPackage
    {
        return $this->find(OfflineScannerPackage::class, 'package', $id, $selectedEventId);
    }

    public function resultImport(mixed $id, mixed $selectedEventId): ?OfflineScannerResultImport
    {
        return $this->find(OfflineScannerResultImport::class, 'import', $id, $selectedEventId);
    }

    public function conflict(mixed $id, mixed $selectedEventId): ?OfflineScannerImportConflict
    {
        return $this->find(OfflineScannerImportConflict::class, 'conflict', $id, $selectedEventId);
    }

    private function find(string $class, string $kind, mixed $rawId, mixed $rawEventId): ?Model
    {
        $id = $this->id($rawId);
        $eventId = $this->id($rawEventId);
        if ($id === null || $eventId === null) {
            return null;
        }

        try {
            $model = new $class;
            $query = $class::query()->useWritePdo()->from($model->getTable().' as r')->select('r.*')->where('r.id', $id);
            $session = $kind === 'session' ? 'r' : 's';
            if ($kind === 'conflict') {
                $query->join('offline_scanner_result_imports as i', 'i.id', '=', 'r.offline_scanner_result_import_id')
                    ->join('offline_scanner_sessions as s', 's.id', '=', 'i.offline_scanner_session_id')
                    ->whereColumn('r.event_id', 'i.event_id')->whereColumn('i.event_id', 's.event_id')
                    ->leftJoin('offline_scanner_imported_logs as l', 'l.id', '=', 'r.offline_scanner_imported_log_id')
                    ->where(function ($query): void {
                        $query->whereNull('r.offline_scanner_imported_log_id')->orWhere(function ($query): void {
                            $query->whereColumn('l.offline_scanner_result_import_id', 'i.id')
                                ->whereColumn('l.offline_scanner_session_id', 's.id');
                        });
                    })->addSelect(['i.id as _legacy_import_id', 'i.offline_scanner_session_id as _legacy_import_session_id',
                        'i.event_id as _legacy_import_event_id', 'l.id as _legacy_log_id',
                        'l.offline_scanner_result_import_id as _legacy_log_import_id', 'l.offline_scanner_session_id as _legacy_log_session_id']);
            } elseif ($kind !== 'session') {
                $query->join('offline_scanner_sessions as s', 's.id', '=', 'r.offline_scanner_session_id');
                if ($kind === 'import') {
                    $query->whereColumn('r.event_id', 's.event_id');
                }
            }
            $query->join('events as e', 'e.id', '=', $session.'.event_id')->where('e.id', $eventId)->whereNull('e.workspace_id')
                ->addSelect([$session.'.id as _legacy_session_id', $session.'.event_id as _legacy_session_event_id', 'e.id as _legacy_event_id']);
            $row = $query->first();
            if ($row === null || $this->id($row->getRawOriginal('id')) !== $id
                || $this->id($row->getRawOriginal('_legacy_event_id')) !== $eventId
                || $this->id($row->getRawOriginal('_legacy_session_event_id')) !== $eventId) {
                return null;
            }
            $sessionId = $this->id($row->getRawOriginal('_legacy_session_id'));
            if ($sessionId === null || ($kind === 'session' && $sessionId !== $id)
                || (in_array($kind, ['package', 'import'], true) && $this->id($row->getRawOriginal('offline_scanner_session_id')) !== $sessionId)
                || (in_array($kind, ['import', 'conflict'], true) && $this->id($row->getRawOriginal('event_id')) !== $eventId)) {
                return null;
            }
            if ($kind === 'conflict') {
                $importId = $this->id($row->getRawOriginal('_legacy_import_id'));
                $logId = $row->getRawOriginal('offline_scanner_imported_log_id');
                if ($importId === null || $this->id($row->getRawOriginal('offline_scanner_result_import_id')) !== $importId
                    || $this->id($row->getRawOriginal('_legacy_import_session_id')) !== $sessionId
                    || $this->id($row->getRawOriginal('_legacy_import_event_id')) !== $eventId
                    || ($logId !== null && ($this->id($logId) === null || $this->id($logId) !== $this->id($row->getRawOriginal('_legacy_log_id'))
                        || $this->id($row->getRawOriginal('_legacy_log_import_id')) !== $importId
                        || $this->id($row->getRawOriginal('_legacy_log_session_id')) !== $sessionId))) {
                    return null;
                }
            }
            // Joined integrity aliases are internal observations, never model data.
            $row->setRawAttributes(array_filter($row->getAttributes(), static fn ($key): bool => ! str_starts_with($key, '_legacy_'), ARRAY_FILTER_USE_KEY), true);

            return $row;
        } catch (Throwable) {
            return null;
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
