<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerResultImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfflineScannerImportConflict>
 */
class OfflineScannerImportConflictFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offline_scanner_result_import_id' => OfflineScannerResultImport::factory(),
            'offline_scanner_imported_log_id' => OfflineScannerImportedLog::factory(),
            'event_id' => Event::factory(),
            'conflict_type' => 'counter_mismatch',
            'server_used' => 1,
            'server_remaining' => 1,
            'offline_used_before' => 0,
            'offline_remaining_before' => 2,
            'offline_added' => 1,
            'offline_used_after' => 1,
            'offline_remaining_after' => 1,
            'recommended_action' => 'review',
            'resolution_status' => OfflineScannerImportConflict::RESOLUTION_OPEN,
        ];
    }
}
