<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineScannerSessionAudit extends Model
{
    use HasFactory;

    public const ACTION_SESSION_CREATED = 'session_created';
    public const ACTION_PACKAGE_GENERATED = 'package_generated';
    public const ACTION_PACKAGE_DOWNLOADED = 'package_downloaded';
    public const ACTION_EVENT_LOCKED = 'event_locked';
    public const ACTION_SESSION_ACTIVATED = 'session_activated';
    public const ACTION_SESSION_CANCELLED = 'session_cancelled';
    public const ACTION_EVENT_UNLOCKED = 'event_unlocked';
    public const ACTION_RESULTS_UPLOADED = 'results_uploaded';
    public const ACTION_RESULTS_VERIFIED = 'results_verified';
    public const ACTION_RESULTS_VERIFICATION_FAILED = 'results_verification_failed';
    public const ACTION_REIMPORT_ATTEMPTED = 'reimport_attempted';
    public const ACTION_RESULTS_IMPORTED = 'results_imported';
    public const ACTION_SESSION_CLOSED = 'session_closed';
    public const ACTION_CONFLICT_DETECTED = 'conflict_detected';
    public const ACTION_CONFLICT_RESOLVED = 'conflict_resolved';
    public const ACTION_FILE_ARCHIVED = 'file_archived';
    public const ACTION_FILE_DELETED = 'file_deleted';

    protected $fillable = [
        'offline_scanner_session_id',
        'event_id',
        'actor_user_id',
        'action',
        'reason',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public static function actions(): array
    {
        return [
            self::ACTION_SESSION_CREATED,
            self::ACTION_PACKAGE_GENERATED,
            self::ACTION_PACKAGE_DOWNLOADED,
            self::ACTION_EVENT_LOCKED,
            self::ACTION_SESSION_ACTIVATED,
            self::ACTION_SESSION_CANCELLED,
            self::ACTION_EVENT_UNLOCKED,
            self::ACTION_RESULTS_UPLOADED,
            self::ACTION_RESULTS_VERIFIED,
            self::ACTION_RESULTS_VERIFICATION_FAILED,
            self::ACTION_REIMPORT_ATTEMPTED,
            self::ACTION_RESULTS_IMPORTED,
            self::ACTION_SESSION_CLOSED,
            self::ACTION_CONFLICT_DETECTED,
            self::ACTION_CONFLICT_RESOLVED,
            self::ACTION_FILE_ARCHIVED,
            self::ACTION_FILE_DELETED,
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OfflineScannerSession::class, 'offline_scanner_session_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}