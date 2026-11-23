<?php

namespace App\Services\OfflineScannerResults;

use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Guest;
use App\Models\OfflineScannerImportedLog;
use Illuminate\Support\Carbon;

class OfflineScannerAdmissionApplier
{
    public function upsertSuccessfulAdmission(OfflineScannerImportedLog $log, Guest $guest): void
    {
        $guest->loadMissing('qrCode');
        $log->loadMissing('scannerUser');

        Admission::query()->updateOrCreate(
            ['offline_scanner_imported_log_id' => $log->id],
            [
                'event_id' => $guest->event_id,
                'guest_id' => $guest->id,
                'quantity' => (int) $log->added_entries,
                'admitted_by' => $log->scannerUser?->name,
                'device_label' => 'Offline Android scanner '.$log->source_device_id,
                'ip_address' => null,
                'user_agent' => 'offline-android-results-import',
                'admitted_at' => Carbon::parse($log->master_timestamp),
            ],
        );

        Checkin::query()->updateOrCreate(
            ['offline_scanner_imported_log_id' => $log->id],
            [
                'event_id' => $guest->event_id,
                'guest_id' => $guest->id,
                'qr_code_id' => $guest->qrCode?->id,
                'user_id' => $log->scanner_user_id,
                'gate_name' => $log->source_gate_name,
                'entries_added' => (int) $log->added_entries,
                'used_entries_after_scan' => (int) $log->used_after,
                'remaining_entries_after_scan' => (int) $log->remaining_after,
                'scan_result' => Checkin::RESULT_ADMITTED,
                'device_info' => 'offline-android:'.$log->source_device_id,
                'ip_address' => null,
                'checked_in_at' => Carbon::parse($log->master_timestamp),
                'offline_log_uuid' => $log->log_uuid,
                'offline_request_uuid' => $log->request_uuid,
                'offline_device_id' => $log->source_device_id,
                'offline_admission_method' => $log->admission_method,
                'offline_master_sequence_number' => $log->master_sequence_number,
            ],
        );
    }
}