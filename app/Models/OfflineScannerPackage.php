<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OfflineScannerPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_uuid', 'offline_scanner_session_id', 'file_name', 'file_path', 'storage_disk',
        'internal_format', 'file_hash', 'encrypted_file_size', 'encryption_algorithm',
        'password_kdf_algorithm', 'password_kdf_parameters', 'signature_algorithm',
        'signature_key_id', 'package_fingerprint', 'generated_by_user_id', 'generated_at',
        'downloaded_at', 'downloaded_by_user_id', 'revoked_at', 'archived_at',
        'archived_by_user_id', 'secure_deleted_at', 'secure_deleted_by_user_id',
        'secure_deletion_reason',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_file_size' => 'integer',
            'password_kdf_parameters' => 'array',
            'generated_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'revoked_at' => 'datetime',
            'archived_at' => 'datetime',
            'secure_deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $package): void {
            $package->package_uuid ??= (string) Str::uuid();
            $package->storage_disk ??= (string) config('offline_scanner.disk', 'offline_scanner');
            $package->internal_format ??= (string) config('offline_scanner.formats.package', 'harusi.offline-scanner.package');
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OfflineScannerSession::class, 'offline_scanner_session_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function downloadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'downloaded_by_user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }


    public function secureDeletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'secure_deleted_by_user_id');
    }
}