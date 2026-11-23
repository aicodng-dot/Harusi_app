<?php

namespace Database\Factories;

use App\Models\OfflineScannerResultDevice;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfflineScannerResultDevice>
 */
class OfflineScannerResultDeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offline_scanner_result_import_id' => OfflineScannerResultImport::factory(),
            'offline_scanner_session_id' => OfflineScannerSession::factory(),
            'device_id' => 'device-'.fake()->uuid(),
            'device_name' => 'Android Scanner '.fake()->numberBetween(1, 9),
            'device_role' => 'client',
            'scanner_user_id' => User::factory()->state(['role' => User::ROLE_SCANNER]),
            'scanner_user_name' => fake()->name(),
            'gate_name' => 'Gate '.fake()->numberBetween(1, 9),
            'paired_at' => now()->subHour(),
            'last_seen_at' => now(),
            'admissions_count' => fake()->numberBetween(0, 20),
        ];
    }

    public function master(): static
    {
        return $this->state(fn (): array => ['device_role' => 'master']);
    }
}