<?php

namespace App\Services\OfflineScannerResults;

readonly class ResultsPayload
{
    /**
     * @param array<int, ResultsDevice> $devices
     * @param array<int, ResultsAdmissionLog> $admissionLogs
     * @param array<int, ResultsFinalGuestState> $finalGuestStates
     */
    public function __construct(
        public ResultsSession $session,
        public array $devices,
        public array $admissionLogs,
        public array $finalGuestStates,
        public ResultsMetrics $metrics,
    ) {
    }
}