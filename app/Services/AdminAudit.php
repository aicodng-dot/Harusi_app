<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;

/**
 * Writes administrator audit entries. Call inside the same transaction as the change
 * so an audited action and its record commit (or roll back) together. Details must
 * never contain passwords, tokens or other secrets.
 */
class AdminAudit
{
    public function record(User $actor, string $action, string $subjectType, ?int $subjectId, ?string $label, array $details = []): AdminAuditLog
    {
        return AdminAuditLog::query()->create([
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $label === null ? null : mb_substr($label, 0, 200),
            'details' => $details === [] ? null : $details,
            'ip_address' => request()?->ip(),
        ]);
    }
}
