<?php

namespace Database\Factories;

use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfflineScannerImportedLog>
 */
class OfflineScannerImportedLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offline_scanner_result_import_id' => OfflineScannerResultImport::factory(),
            'offline_scanner_session_id' => OfflineScannerSession::factory(),
            'request_uuid' => fake()->uuid(),
            'master_sequence_number' => fake()->unique()->numberBetween(1, 1000000),
            'external_guest_id' => fake()->uuid(),
            'scanner_user_id' => User::factory()->state([
                'role' => User::ROLE_SCANNER,
                'gate_name' => 'Gate '.fake()->randomDigitNotNull(),
            ]),
            'source_device_id' => 'client-'.fake()->uuid(),
            'source_gate_name' => 'Main Gate',
            'admission_method' => 'qr',
            'added_entries' => 1,
            'used_before' => 0,
            'remaining_before' => 2,
            'used_after' => 1,
            'remaining_after' => 1,
            'result_code' => 'admitted',
            'device_timestamp' => now(),
            'master_timestamp' => now(),
            'import_status' => OfflineScannerImportedLog::STATUS_PENDING,
            'raw_payload_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}
