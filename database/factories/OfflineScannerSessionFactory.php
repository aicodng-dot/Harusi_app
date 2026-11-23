<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\OfflineScannerSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfflineScannerSession>
 */
class OfflineScannerSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'created_by_user_id' => User::factory(),
            'schema_version' => '1.0',
            'status' => OfflineScannerSession::STATUS_DRAFT,
            'package_fingerprint' => hash('sha256', fake()->uuid()),
            'last_master_sequence' => 0,
            'guest_snapshot_version' => 'snapshot-'.fake()->uuid(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => OfflineScannerSession::STATUS_ACTIVE,
            'session_started_at' => now(),
        ]);
    }
}
