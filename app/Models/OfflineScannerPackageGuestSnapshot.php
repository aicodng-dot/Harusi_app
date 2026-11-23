<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineScannerPackageGuestSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'offline_scanner_session_id', 'offline_scanner_package_id', 'guest_id', 'external_guest_id',
        'record_version', 'pass_type', 'guest_status', 'manual_code_hash', 'qr_token_hash',
        'allowed_entries', 'used_entries', 'remaining_entries',
    ];

    protected function casts(): array
    {
        return [
            'guest_id' => 'integer',
            'allowed_entries' => 'integer',
            'used_entries' => 'integer',
            'remaining_entries' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OfflineScannerSession::class, 'offline_scanner_session_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(OfflineScannerPackage::class, 'offline_scanner_package_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
