<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfflineScannerSessionAudit>
 */
class OfflineScannerSessionAuditFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offline_scanner_session_id' => OfflineScannerSession::factory(),
            'event_id' => Event::factory(),
            'actor_user_id' => User::factory(),
            'action' => OfflineScannerSessionAudit::ACTION_SESSION_CREATED,
            'metadata' => [],
            'occurred_at' => now(),
        ];
    }
}
