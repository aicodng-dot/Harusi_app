<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class OfflineScannerImportedLog extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_UNKNOWN_GUEST = 'unknown_guest';

    protected $fillable = [
        'offline_scanner_result_import_id', 'offline_scanner_session_id', 'log_uuid', 'request_uuid',
        'master_sequence_number', 'external_guest_id', 'scanner_user_id', 'source_device_id',
        'source_gate_name', 'admission_method', 'qr_payload_hash', 'added_entries', 'used_before',
        'remaining_before', 'used_after', 'remaining_after', 'result_code', 'device_timestamp',
        'master_timestamp', 'monotonic_sequence', 'base_guest_record_version', 'import_status',
        'conflict_reason', 'raw_payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'master_sequence_number' => 'integer',
            'added_entries' => 'integer',
            'used_before' => 'integer',
            'remaining_before' => 'integer',
            'used_after' => 'integer',
            'remaining_after' => 'integer',
            'monotonic_sequence' => 'integer',
            'device_timestamp' => 'datetime',
            'master_timestamp' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->log_uuid ??= (string) Str::uuid();
        });
    }

    public static function importStatuses(): array
    {
        return [
            self::STATUS_PENDING, self::STATUS_IMPORTED, self::STATUS_DUPLICATE,
            self::STATUS_CONFLICT, self::STATUS_REJECTED, self::STATUS_UNKNOWN_GUEST,
        ];
    }

    public function resultImport(): BelongsTo
    {
        return $this->belongsTo(OfflineScannerResultImport::class, 'offline_scanner_result_import_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OfflineScannerSession::class, 'offline_scanner_session_id');
    }

    public function scannerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanner_user_id');
    }

    public function conflict(): HasOne
    {
        return $this->hasOne(OfflineScannerImportConflict::class);
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(OfflineScannerImportConflict::class);
    }
}