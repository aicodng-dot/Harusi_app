<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineScannerFinalGuestState extends Model
{
    use HasFactory;

    protected $fillable = [
        'offline_scanner_result_import_id', 'offline_scanner_session_id', 'event_id',
        'external_guest_id', 'guest_id', 'final_used', 'final_remaining',
        'final_record_version', 'last_master_sequence_number',
    ];

    protected function casts(): array
    {
        return [
            'final_used' => 'integer',
            'final_remaining' => 'integer',
            'last_master_sequence_number' => 'integer',
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

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}