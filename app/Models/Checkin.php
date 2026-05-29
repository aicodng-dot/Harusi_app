<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Checkin extends Model
{
    use HasFactory;

    public const RESULT_VALID = 'valid';
    public const RESULT_ADMITTED = 'admitted';
    public const RESULT_INVALID = 'invalid';
    public const RESULT_ALREADY_USED = 'already_used';
    public const RESULT_CANCELLED = 'cancelled';
    public const RESULT_REVOKED = 'revoked';
    public const RESULT_WRONG_EVENT = 'wrong_event';
    public const RESULT_ERROR = 'error';

    protected $fillable = [
        'event_id',
        'guest_id',
        'qr_code_id',
        'user_id',
        'gate_name',
        'entries_added',
        'used_entries_after_scan',
        'remaining_entries_after_scan',
        'scan_result',
        'device_info',
        'ip_address',
        'checked_in_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Checkin $checkin): void {
            if ($checkin->event_id) {
                return;
            }

            if ($checkin->guest_id) {
                $checkin->event_id = Guest::query()->whereKey($checkin->guest_id)->value('event_id');
            }

            if (! $checkin->event_id && $checkin->user_id) {
                $checkin->event_id = User::query()->whereKey($checkin->user_id)->value('event_id');
            }

            $checkin->event_id ??= Event::defaultEvent()->id;
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

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
