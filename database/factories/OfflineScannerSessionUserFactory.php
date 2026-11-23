<?php

namespace Database\Factories;

use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfflineScannerSessionUser>
 */
class OfflineScannerSessionUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offline_scanner_session_id' => OfflineScannerSession::factory(),
            'scanner_user_id' => User::factory()->state([
                'role' => User::ROLE_SCANNER,
                'gate_name' => 'Gate '.fake()->randomDigitNotNull(),
            ]),
            'assigned_gate' => 'Gate '.fake()->randomDigitNotNull(),
            'device_role' => OfflineScannerSessionUser::DEVICE_ROLE_CLIENT,
            'offline_pin_verifier' => hash('sha256', fake()->password()),
            'offline_pin_salt' => bin2hex(random_bytes(16)),
            'offline_pin_algorithm' => 'argon2id',
            'active' => true,
        ];
    }

    public function master(): static
    {
        return $this->state(fn (): array => [
            'device_role' => OfflineScannerSessionUser::DEVICE_ROLE_MASTER,
        ]);
    }
}
