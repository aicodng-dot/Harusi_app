<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineScannerImportConflict extends Model
{
    use HasFactory;

    public const TYPE_GUEST_NOT_FOUND = 'guest_not_found';
    public const TYPE_GUEST_DELETED = 'guest_deleted';
    public const TYPE_GUEST_DISABLED_AFTER_EXPORT = 'guest_disabled_after_export';
    public const TYPE_ALLOWED_ENTRIES_CHANGED = 'allowed_entries_changed';
    public const TYPE_SERVER_USED_CHANGED = 'server_used_changed';
    public const TYPE_SERVER_REMAINING_CHANGED = 'server_remaining_changed';
    public const TYPE_MANUAL_CODE_CHANGED = 'manual_code_changed';
    public const TYPE_QR_TOKEN_CHANGED = 'qr_token_changed';
    public const TYPE_RECORD_VERSION_MISMATCH = 'record_version_mismatch';
    public const TYPE_INVALID_MASTER_SEQUENCE = 'invalid_master_sequence';
    public const TYPE_DUPLICATE_LOG_CONFLICT = 'duplicate_log_conflict';
    public const TYPE_DUPLICATE_REQUEST_CONFLICT = 'duplicate_request_conflict';
    public const TYPE_IMPOSSIBLE_COUNT_TRANSITION = 'impossible_count_transition';
    public const TYPE_WRONG_EVENT = 'wrong_event';
    public const TYPE_WRONG_SESSION = 'wrong_session';
    public const TYPE_UNAUTHORIZED_SCANNER = 'unauthorized_scanner';
    public const TYPE_SESSION_CANCELLED = 'session_cancelled';
    public const TYPE_ALREADY_IMPORTED_DIFFERENT_PAYLOAD = 'already_imported_different_payload';
    public const TYPE_PASS_TYPE_CHANGED = 'pass_type_changed';
    public const TYPE_CORRUPTED_FINAL_GUEST_STATE = 'corrupted_final_guest_state';

    public const ACTION_ACCEPT_OFFLINE_ADMISSION = 'accept_offline_admission';
    public const ACTION_KEEP_SERVER_STATE = 'keep_server_state';
    public const ACTION_ADJUST_SERVER_COUNT_MANUALLY = 'adjust_server_count_manually';
    public const ACTION_MAP_TO_REPLACEMENT_GUEST = 'map_to_replacement_guest';
    public const ACTION_REJECT_IMPORTED_LOG = 'reject_imported_log';
    public const ACTION_ESCALATE_ADMIN_REVIEW = 'escalate_admin_review';

    public const RESOLUTION_OPEN = 'open';
    public const RESOLUTION_RESOLVED = 'resolved';
    public const RESOLUTION_DISMISSED = 'dismissed';

    protected $fillable = [
        'offline_scanner_result_import_id', 'offline_scanner_imported_log_id', 'event_id', 'guest_id',
        'conflict_type', 'conflict_reason', 'server_used', 'server_remaining', 'offline_used_before',
        'offline_remaining_before', 'offline_added', 'offline_used_after', 'offline_remaining_after',
        'server_state', 'snapshot_state', 'offline_request', 'expected_final_state', 'allowed_actions',
        'recommended_action', 'resolution_status', 'resolution_action', 'resolved_by_user_id',
        'replacement_guest_id', 'manual_used_entries', 'resolution_notes', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'server_used' => 'integer',
            'server_remaining' => 'integer',
            'offline_used_before' => 'integer',
            'offline_remaining_before' => 'integer',
            'offline_added' => 'integer',
            'offline_used_after' => 'integer',
            'offline_remaining_after' => 'integer',
            'server_state' => 'array',
            'snapshot_state' => 'array',
            'offline_request' => 'array',
            'expected_final_state' => 'array',
            'allowed_actions' => 'array',
            'replacement_guest_id' => 'integer',
            'manual_used_entries' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public static function types(): array
    {
        return [
            self::TYPE_GUEST_NOT_FOUND,
            self::TYPE_GUEST_DELETED,
            self::TYPE_GUEST_DISABLED_AFTER_EXPORT,
            self::TYPE_ALLOWED_ENTRIES_CHANGED,
            self::TYPE_SERVER_USED_CHANGED,
            self::TYPE_SERVER_REMAINING_CHANGED,
            self::TYPE_MANUAL_CODE_CHANGED,
            self::TYPE_QR_TOKEN_CHANGED,
            self::TYPE_RECORD_VERSION_MISMATCH,
            self::TYPE_INVALID_MASTER_SEQUENCE,
            self::TYPE_DUPLICATE_LOG_CONFLICT,
            self::TYPE_DUPLICATE_REQUEST_CONFLICT,
            self::TYPE_IMPOSSIBLE_COUNT_TRANSITION,
            self::TYPE_WRONG_EVENT,
            self::TYPE_WRONG_SESSION,
            self::TYPE_UNAUTHORIZED_SCANNER,
            self::TYPE_SESSION_CANCELLED,
            self::TYPE_ALREADY_IMPORTED_DIFFERENT_PAYLOAD,
            self::TYPE_PASS_TYPE_CHANGED,
            self::TYPE_CORRUPTED_FINAL_GUEST_STATE,
        ];
    }

    public static function resolutionActions(): array
    {
        return [
            self::ACTION_ACCEPT_OFFLINE_ADMISSION,
            self::ACTION_KEEP_SERVER_STATE,
            self::ACTION_ADJUST_SERVER_COUNT_MANUALLY,
            self::ACTION_MAP_TO_REPLACEMENT_GUEST,
            self::ACTION_REJECT_IMPORTED_LOG,
            self::ACTION_ESCALATE_ADMIN_REVIEW,
        ];
    }

    public static function resolutionActionLabels(): array
    {
        return [
            self::ACTION_ACCEPT_OFFLINE_ADMISSION => 'Accept offline admission',
            self::ACTION_KEEP_SERVER_STATE => 'Keep server state',
            self::ACTION_ADJUST_SERVER_COUNT_MANUALLY => 'Adjust server count manually',
            self::ACTION_MAP_TO_REPLACEMENT_GUEST => 'Map to a replacement guest',
            self::ACTION_REJECT_IMPORTED_LOG => 'Reject imported log',
            self::ACTION_ESCALATE_ADMIN_REVIEW => 'Escalate for administrator review',
        ];
    }

    public static function resolutionStatuses(): array
    {
        return [self::RESOLUTION_OPEN, self::RESOLUTION_RESOLVED, self::RESOLUTION_DISMISSED];
    }

    public function allowedResolutionActions(): array
    {
        return $this->allowed_actions ?: self::safeActionsForType((string) $this->conflict_type);
    }

    public static function safeActionsForType(string $type): array
    {
        return match ($type) {
            self::TYPE_GUEST_NOT_FOUND,
            self::TYPE_GUEST_DELETED => [
                self::ACTION_MAP_TO_REPLACEMENT_GUEST,
                self::ACTION_REJECT_IMPORTED_LOG,
                self::ACTION_ESCALATE_ADMIN_REVIEW,
            ],
            self::TYPE_SERVER_USED_CHANGED,
            self::TYPE_SERVER_REMAINING_CHANGED,
            self::TYPE_ALLOWED_ENTRIES_CHANGED,
            self::TYPE_MANUAL_CODE_CHANGED,
            self::TYPE_QR_TOKEN_CHANGED,
            self::TYPE_RECORD_VERSION_MISMATCH,
            self::TYPE_PASS_TYPE_CHANGED,
            self::TYPE_GUEST_DISABLED_AFTER_EXPORT,
            self::TYPE_CORRUPTED_FINAL_GUEST_STATE => [
                self::ACTION_ACCEPT_OFFLINE_ADMISSION,
                self::ACTION_KEEP_SERVER_STATE,
                self::ACTION_ADJUST_SERVER_COUNT_MANUALLY,
                self::ACTION_REJECT_IMPORTED_LOG,
                self::ACTION_ESCALATE_ADMIN_REVIEW,
            ],
            self::TYPE_IMPOSSIBLE_COUNT_TRANSITION,
            self::TYPE_INVALID_MASTER_SEQUENCE,
            self::TYPE_DUPLICATE_LOG_CONFLICT,
            self::TYPE_DUPLICATE_REQUEST_CONFLICT,
            self::TYPE_ALREADY_IMPORTED_DIFFERENT_PAYLOAD,
            self::TYPE_WRONG_EVENT,
            self::TYPE_WRONG_SESSION,
            self::TYPE_UNAUTHORIZED_SCANNER,
            self::TYPE_SESSION_CANCELLED => [
                self::ACTION_REJECT_IMPORTED_LOG,
                self::ACTION_ESCALATE_ADMIN_REVIEW,
            ],
            default => [
                self::ACTION_REJECT_IMPORTED_LOG,
                self::ACTION_ESCALATE_ADMIN_REVIEW,
            ],
        };
    }

    public function resultImport(): BelongsTo
    {
        return $this->belongsTo(OfflineScannerResultImport::class, 'offline_scanner_result_import_id');
    }

    public function importedLog(): BelongsTo
    {
        return $this->belongsTo(OfflineScannerImportedLog::class, 'offline_scanner_imported_log_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function replacementGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'replacement_guest_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}