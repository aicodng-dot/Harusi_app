<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class OfflineScannerSession extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PACKAGE_GENERATED = 'package_generated';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_AWAITING_RESULTS = 'awaiting_results';
    public const STATUS_RESULTS_UPLOADED = 'results_uploaded';
    public const STATUS_RESULTS_IMPORTED = 'results_imported';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'session_uuid', 'event_id', 'created_by_user_id', 'package_export_id', 'schema_version',
        'status', 'master_scanner_user_id', 'master_gate_name', 'package_fingerprint',
        'package_created_at', 'package_expires_at', 'session_started_at', 'session_closed_at',
        'results_imported_at', 'last_master_sequence', 'guest_snapshot_version',
        'session_bootstrap_secret_encrypted', 'result_file_encryption_secret_encrypted',
        'result_file_authentication_secret_encrypted', 'device_pairing_secret_encrypted',
        'notes',
    ];

    protected $hidden = [
        'session_bootstrap_secret_encrypted',
        'result_file_encryption_secret_encrypted',
        'result_file_authentication_secret_encrypted',
        'device_pairing_secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'package_created_at' => 'datetime',
            'package_expires_at' => 'datetime',
            'session_started_at' => 'datetime',
            'session_closed_at' => 'datetime',
            'results_imported_at' => 'datetime',
            'last_master_sequence' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            $session->session_uuid ??= (string) Str::uuid();
        });
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT, self::STATUS_PACKAGE_GENERATED, self::STATUS_ACTIVE,
            self::STATUS_AWAITING_RESULTS, self::STATUS_RESULTS_UPLOADED, self::STATUS_RESULTS_IMPORTED,
            self::STATUS_CONFLICT, self::STATUS_CLOSED, self::STATUS_CANCELLED, self::STATUS_EXPIRED,
        ];
    }

    public static function blockingStatuses(): array
    {
        return [
            self::STATUS_PACKAGE_GENERATED,
            self::STATUS_ACTIVE,
            self::STATUS_AWAITING_RESULTS,
            self::STATUS_RESULTS_UPLOADED,
            self::STATUS_CONFLICT,
        ];
    }

    public function isBlockingAdmissionChanges(): bool
    {
        return in_array($this->status, self::blockingStatuses(), true);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function masterScannerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'master_scanner_user_id');
    }

    public function packageExport(): BelongsTo
    {
        return $this->belongsTo(OfflineScannerPackage::class, 'package_export_id');
    }

    public function sessionUsers(): HasMany
    {
        return $this->hasMany(OfflineScannerSessionUser::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(OfflineScannerPackage::class);
    }

    public function resultImports(): HasMany
    {
        return $this->hasMany(OfflineScannerResultImport::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(OfflineScannerSessionAudit::class);
    }

    public function importedLogs(): HasManyThrough
    {
        return $this->hasManyThrough(OfflineScannerImportedLog::class, OfflineScannerResultImport::class);
    }

    public function resultDevices(): HasManyThrough
    {
        return $this->hasManyThrough(OfflineScannerResultDevice::class, OfflineScannerResultImport::class);
    }

    public function finalGuestStates(): HasManyThrough
    {
        return $this->hasManyThrough(OfflineScannerFinalGuestState::class, OfflineScannerResultImport::class);
    }

    public function importConflicts(): HasManyThrough
    {
        return $this->hasManyThrough(OfflineScannerImportConflict::class, OfflineScannerResultImport::class);
    }
}
