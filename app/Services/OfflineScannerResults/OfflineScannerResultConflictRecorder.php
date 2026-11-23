<?php

namespace App\Services\OfflineScannerResults;

use App\Models\Guest;
use App\Models\OfflineScannerFinalGuestState;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerResultImport;
use App\Services\QrTokenCanonicalizer;

class OfflineScannerResultConflictRecorder
{
    public function __construct(private readonly QrTokenCanonicalizer $tokens)
    {
    }

    public function record(
        OfflineScannerResultImport $import,
        ?OfflineScannerImportedLog $log,
        ?Guest $guest,
        string $type,
        string $reason,
        ?OfflineScannerPackageGuestSnapshot $snapshot = null,
        ?OfflineScannerFinalGuestState $finalState = null,
        ?array $offlineRequest = null,
        ?array $expectedFinalState = null,
    ): OfflineScannerImportConflict {
        $identity = [
            'offline_scanner_result_import_id' => $import->id,
            'offline_scanner_imported_log_id' => $log?->id,
            'conflict_type' => $type,
        ];

        return OfflineScannerImportConflict::query()->updateOrCreate($identity, [
            'event_id' => $import->event_id,
            'guest_id' => $guest?->id,
            'conflict_reason' => $reason,
            'server_used' => $guest?->used_entries,
            'server_remaining' => $guest?->remainingEntries(),
            'offline_used_before' => $log?->used_before ?? $offlineRequest['used_before'] ?? null,
            'offline_remaining_before' => $log?->remaining_before ?? $offlineRequest['remaining_before'] ?? null,
            'offline_added' => $log?->added_entries ?? $offlineRequest['added_entries'] ?? null,
            'offline_used_after' => $log?->used_after ?? $offlineRequest['used_after'] ?? null,
            'offline_remaining_after' => $log?->remaining_after ?? $offlineRequest['remaining_after'] ?? null,
            'server_state' => $this->serverState($guest),
            'snapshot_state' => $this->snapshotState($snapshot),
            'offline_request' => $offlineRequest ?: $this->offlineRequestFromLog($log),
            'expected_final_state' => $expectedFinalState ?: $this->expectedFinalState($finalState, $log),
            'allowed_actions' => OfflineScannerImportConflict::safeActionsForType($type),
            'recommended_action' => $reason,
            'resolution_status' => OfflineScannerImportConflict::RESOLUTION_OPEN,
        ]);
    }

    public function offlineRequestFromResultsLog(ResultsAdmissionLog $log): array
    {
        return [
            'log_uuid' => $log->logUuid,
            'request_uuid' => $log->requestUuid,
            'master_sequence_number' => $log->masterSequenceNumber,
            'external_guest_id' => $log->externalGuestId,
            'scanner_user_id' => $log->scannerUserId,
            'source_device_id' => $log->sourceDeviceId,
            'source_gate_name' => $log->sourceGateName,
            'admission_method' => $log->admissionMethod,
            'qr_payload_hash' => $log->qrPayloadHash,
            'added_entries' => $log->addedEntries,
            'used_before' => $log->usedBefore,
            'remaining_before' => $log->remainingBefore,
            'used_after' => $log->usedAfter,
            'remaining_after' => $log->remainingAfter,
            'result_code' => $log->resultCode,
            'device_timestamp' => $log->deviceTimestamp,
            'master_timestamp' => $log->masterTimestamp,
            'monotonic_sequence' => $log->monotonicSequence,
            'base_guest_record_version' => $log->baseGuestRecordVersion,
        ];
    }

    public function serverState(?Guest $guest): ?array
    {
        if (! $guest) {
            return null;
        }

        $guest->loadMissing('qrCode');
        $qrToken = (string) $guest->qrCode?->qr_token;

        return [
            'guest_id' => $guest->id,
            'event_id' => $guest->event_id,
            'name' => $guest->name,
            'pass_type' => $guest->pass_type,
            'allowed_entries' => (int) $guest->allowed_entries,
            'used_entries' => (int) $guest->used_entries,
            'remaining_entries' => $guest->remainingEntries(),
            'status' => $guest->status,
            'manual_code_hash' => $this->manualCodeHash($guest->manual_code),
            'qr_token_hash' => $qrToken === '' ? '' : $this->tokens->hash($qrToken),
            'qr_active' => (bool) $guest->qrCode?->is_active,
            'qr_revoked_at' => $guest->qrCode?->revoked_at?->toJSON(),
            'updated_at' => $guest->updated_at?->toJSON(),
        ];
    }

    public function snapshotState(?OfflineScannerPackageGuestSnapshot $snapshot): ?array
    {
        if (! $snapshot) {
            return null;
        }

        return [
            'snapshot_id' => $snapshot->id,
            'guest_id' => $snapshot->guest_id,
            'external_guest_id' => $snapshot->external_guest_id,
            'record_version' => $snapshot->record_version,
            'pass_type' => $snapshot->pass_type,
            'guest_status' => $snapshot->guest_status,
            'manual_code_hash' => $snapshot->manual_code_hash,
            'qr_token_hash' => $snapshot->qr_token_hash,
            'allowed_entries' => (int) $snapshot->allowed_entries,
            'used_entries' => (int) $snapshot->used_entries,
            'remaining_entries' => (int) $snapshot->remaining_entries,
        ];
    }

    public function expectedFinalState(?OfflineScannerFinalGuestState $finalState, ?OfflineScannerImportedLog $log = null): ?array
    {
        if (! $finalState && ! $log) {
            return null;
        }

        return [
            'external_guest_id' => $finalState?->external_guest_id ?? $log?->external_guest_id,
            'final_used' => $finalState?->final_used ?? $log?->used_after,
            'final_remaining' => $finalState?->final_remaining ?? $log?->remaining_after,
            'final_record_version' => $finalState?->final_record_version,
            'last_master_sequence_number' => $finalState?->last_master_sequence_number ?? $log?->master_sequence_number,
        ];
    }

    public function offlineRequestFromLog(?OfflineScannerImportedLog $log): ?array
    {
        if (! $log) {
            return null;
        }

        return [
            'log_uuid' => $log->log_uuid,
            'request_uuid' => $log->request_uuid,
            'master_sequence_number' => $log->master_sequence_number,
            'external_guest_id' => $log->external_guest_id,
            'scanner_user_id' => $log->scanner_user_id,
            'source_device_id' => $log->source_device_id,
            'source_gate_name' => $log->source_gate_name,
            'admission_method' => $log->admission_method,
            'qr_payload_hash' => $log->qr_payload_hash,
            'added_entries' => $log->added_entries,
            'used_before' => $log->used_before,
            'remaining_before' => $log->remaining_before,
            'used_after' => $log->used_after,
            'remaining_after' => $log->remaining_after,
            'result_code' => $log->result_code,
            'device_timestamp' => $log->device_timestamp?->toJSON(),
            'master_timestamp' => $log->master_timestamp?->toJSON(),
            'monotonic_sequence' => $log->monotonic_sequence,
            'base_guest_record_version' => $log->base_guest_record_version,
        ];
    }

    public function manualCodeHash(?string $manualCode): string
    {
        $manualCode = trim((string) $manualCode);

        return $manualCode === '' ? '' : hash('sha256', $manualCode);
    }
}