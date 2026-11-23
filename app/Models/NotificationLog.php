<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use HasFactory;

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const TYPE_PLEDGE_CREATED = 'pledge_created';

    public const TYPE_PLEDGE_REMINDER = 'pledge_reminder';

    public const TYPE_PLEDGE_COMPLETED = 'pledge_completed';

    public const TYPE_NEXT_MEETING = 'next_meeting';

    public const TYPE_DIGITAL_CARD = 'digital_card';

    public const TYPE_THANK_YOU = 'thank_you';

    public const TYPE_CONGRATULATIONS = 'congratulations';

    public const TYPE_GUEST_CODE = 'guest_code';

    public const TYPE_GENERAL_UPDATE = 'general_update';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_SIMULATED = 'simulated';

    // Provider-reported delivery states (Meta status webhooks); `sent` means accepted.
    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const PROVIDER_META = 'meta';

    protected $fillable = [
        'guest_id',
        'event_id',
        'channel',
        'message_type',
        'template_name',
        'recipient_phone',
        'message',
        'status',
        'provider',
        'provider_message_id',
        'error_message',
        'context',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public static function messageTypes(): array
    {
        return [
            self::TYPE_PLEDGE_CREATED,
            self::TYPE_PLEDGE_REMINDER,
            self::TYPE_PLEDGE_COMPLETED,
            self::TYPE_NEXT_MEETING,
            self::TYPE_DIGITAL_CARD,
            self::TYPE_THANK_YOU,
            self::TYPE_CONGRATULATIONS,
            self::TYPE_GUEST_CODE,
            self::TYPE_GENERAL_UPDATE,
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_SENT,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
            self::STATUS_SIMULATED,
            self::STATUS_DELIVERED,
            self::STATUS_READ,
        ];
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
