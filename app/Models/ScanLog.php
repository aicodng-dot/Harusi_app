<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
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

    protected static function booted(): void
    {
        static::creating(function (ScanLog $scanLog): void {
            if ($scanLog->event_id) {
                return;
            }

            if ($scanLog->guest_id) {
                $scanLog->event_id = Guest::query()->whereKey($scanLog->guest_id)->value('event_id');
            }

            $scanLog->event_id ??= Event::defaultEvent()->id;
        });
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
