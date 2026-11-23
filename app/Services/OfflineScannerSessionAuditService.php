<?php

namespace App\Services;

use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\User;

class OfflineScannerSessionAuditService
{
    public function record(
        OfflineScannerSession $session,
        string $action,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = [],
    ): OfflineScannerSessionAudit {
        return OfflineScannerSessionAudit::query()->create([
            'offline_scanner_session_id' => $session->id,
            'event_id' => $session->event_id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'reason' => $reason,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
