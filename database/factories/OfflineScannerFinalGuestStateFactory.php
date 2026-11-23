<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerFinalGuestState;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfflineScannerFinalGuestState>
 */
class OfflineScannerFinalGuestStateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offline_scanner_result_import_id' => OfflineScannerResultImport::factory(),
            'offline_scanner_session_id' => OfflineScannerSession::factory(),
            'event_id' => Event::factory(),
            'external_guest_id' => (string) fake()->numberBetween(1, 999999),
            'guest_id' => null,
            'final_used' => fake()->numberBetween(0, 5),
            'final_remaining' => fake()->numberBetween(0, 5),
            'final_record_version' => hash('sha256', fake()->uuid()),
            'last_master_sequence_number' => fake()->numberBetween(1, 1000),
        ];
    }
}