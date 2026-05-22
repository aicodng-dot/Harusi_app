<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'token_hash',
        'action',
        'result',
        'quantity',
        'message',
        'ip_address',
        'user_agent',
        'metadata',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'scanned_at' => 'datetime',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
