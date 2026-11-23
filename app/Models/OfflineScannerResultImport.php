<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OfflineScannerResultImport extends Model
{
    use HasFactory;

    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_VALIDATION_FAILED = 'validation_failed';
    public const STATUS_PREVIEW_READY = 'preview_ready';
    public const STATUS_IMPORTING = 'importing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_CONFLICTS = 'completed_with_conflicts';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'import_uuid', 'offline_scanner_session_id', 'event_id', 'uploaded_by_user_id',
        'uploaded_at', 'source_results_export_id', 'source_package_export_id', 'results_export_id',
        'result_schema_version', 'source_master_device_id', 'file_name', 'file_hash',
        'encrypted_file_path', 'storage_disk', 'internal_format', 'encrypted_file_size',
        'encryption_algorithm', 'initialization_vector', 'authentication_tag', 'payload_hash',
        'final_master_sequence_number', 'metrics', 'exported_at', 'result_session_started_at',
        'result_session_closed_at', 'status', 'total_logs', 'imported_logs', 'duplicate_logs',
        'conflict_logs', 'rejected_logs', 'unknown_guest_logs', 'verification_error', 'verified_at',
        'imported_at', 'archived_at', 'archived_by_user_id', 'secure_deleted_at',
        'secure_deleted_by_user_id', 'secure_deletion_reason',
    ];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'encrypted_file_size' => 'integer',
            'total_logs' => 'integer',
            'imported_logs' => 'integer',
            'duplicate_logs' => 'integer',
            'conflict_logs' => 'integer',
            'rejected_logs' => 'integer',
            'unknown_guest_logs' => 'integer',
            'final_master_sequence_number' => 'integer',
            'metrics' => 'array',
            'uploaded_at' => 'datetime',
            'exported_at' => 'datetime',
            'result_session_started_at' => 'datetime',
            'result_session_closed_at' => 'datetime',
            'verified_at' => 'datetime',
            'imported_at' => 'datetime',
            'archived_at' => 'datetime',
            'secure_deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $resultImport): void {
            $resultImport->import_uuid ??= (string) Str::uuid();
            $resultImport->storage_disk ??= (string) config('offline_scanner.disk', 'offline_scanner');
            $resultImport->internal_format ??= (string) config('offline_scanner.formats.results', 'harusi.offline-scanner.results');
        });
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_UPLOADED, self::STATUS_VERIFIED, self::STATUS_VALIDATION_FAILED,
            self::STATUS_PREVIEW_READY, self::STATUS_IMPORTING, self::STATUS_COMPLETED,
            self::STATUS_COMPLETED_WITH_CONFLICTS, self::STATUS_FAILED,
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OfflineScannerSession::class, 'offline_scanner_session_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function secureDeletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'secure_deleted_by_user_id');
    }

    public function importedLogs(): HasMany
    {
        return $this->hasMany(OfflineScannerImportedLog::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(OfflineScannerResultDevice::class);
    }

    public function finalGuestStates(): HasMany
    {
        return $this->hasMany(OfflineScannerFinalGuestState::class);
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(OfflineScannerImportConflict::class);
    }
}