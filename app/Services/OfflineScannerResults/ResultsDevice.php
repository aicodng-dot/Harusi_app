<?php

namespace App\Services\OfflineScannerResults;

readonly class ResultsDevice
{
    public function __construct(
        public string $deviceId,
        public string $deviceName,
        public string $deviceRole,
        public int $scannerUserId,
        public string $scannerUserName,
        public string $gateName,
        public ?string $pairedAt,
        public ?string $lastSeenAt,
        public int $admissionsCount,
    ) {
    }
}