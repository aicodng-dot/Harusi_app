<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineScannerResultDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'offline_scanner_result_import_id', 'offline_scanner_session_id', 'device_id',
        'device_name', 'device_role', 'scanner_user_id', 'scanner_user_name', 'gate_name',
        'paired_at', 'last_seen_at', 'admissions_count',
    ];

    protected function casts(): array
    {
        return [
            'scanner_user_id' => 'integer',
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'admissions_count' => 'integer',
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
}