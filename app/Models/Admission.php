<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Admission extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'guest_id',
        'quantity',
        'admitted_by',
        'device_label',
        'ip_address',
        'user_agent',
        'admitted_at',
    ];

    protected function casts(): array
    {
        return [
            'admitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Admission $admission): void {
            if ($admission->event_id) {
                return;
            }

            if ($admission->guest_id) {
                $admission->event_id = Guest::query()->whereKey($admission->guest_id)->value('event_id');
            }

            $admission->event_id ??= Event::defaultEvent()->id;
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
