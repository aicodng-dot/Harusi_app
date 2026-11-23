<?php

namespace Database\Factories;

use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfflineScannerResultImport>
 */
class OfflineScannerResultImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offline_scanner_session_id' => OfflineScannerSession::factory(),
            'uploaded_by_user_id' => User::factory(),
            'uploaded_at' => now(),
            'source_master_device_id' => 'master-'.fake()->uuid(),
            'results_export_id' => fake()->uuid(),
            'source_package_export_id' => fake()->uuid(),
            'result_schema_version' => '1.0',
            'encryption_algorithm' => 'AES-256-GCM',
            'payload_hash' => hash('sha256', fake()->uuid()),
            'file_name' => 'admission-results.wqrresults',
            'file_hash' => hash('sha256', fake()->uuid()),
            'encrypted_file_path' => 'offline-results/'.fake()->uuid().'.wqrresults',
            'storage_disk' => config('offline_scanner.disk', 'offline_scanner'),
            'internal_format' => config('offline_scanner.formats.results', 'harusi.offline-scanner.results'),
            'encrypted_file_size' => fake()->numberBetween(2048, 1000000),
            'status' => OfflineScannerResultImport::STATUS_UPLOADED,
        ];
    }
}