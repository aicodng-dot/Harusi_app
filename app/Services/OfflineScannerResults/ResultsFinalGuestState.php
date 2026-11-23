<?php

namespace App\Services\OfflineScannerResults;

readonly class ResultsFinalGuestState
{
    public function __construct(
        public string $externalGuestId,
        public int $finalUsed,
        public int $finalRemaining,
        public string $finalRecordVersion,
        public int $lastMasterSequenceNumber,
    ) {
    }
}