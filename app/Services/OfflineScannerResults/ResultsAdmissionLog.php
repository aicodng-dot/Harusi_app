<?php

namespace App\Services\OfflineScannerResults;

readonly class ResultsAdmissionLog
{
    public function __construct(
        public string $logUuid,
        public string $requestUuid,
        public int $masterSequenceNumber,
        public string $externalGuestId,
        public int $scannerUserId,
        public string $sourceDeviceId,
        public string $sourceGateName,
        public string $admissionMethod,
        public ?string $qrPayloadHash,
        public int $addedEntries,
        public int $usedBefore,
        public int $remainingBefore,
        public int $usedAfter,
        public int $remainingAfter,
        public string $resultCode,
        public string $deviceTimestamp,
        public string $masterTimestamp,
        public int $monotonicSequence,
        public string $baseGuestRecordVersion,
    ) {
    }
}