<?php

namespace App\Services\OfflineScannerResults;

readonly class ResultsSession
{
    public function __construct(
        public string $sessionUuid,
        public string $packageExportId,
        public string $resultsExportId,
        public int $eventId,
        public string $masterDeviceId,
        public ?string $sessionStartedAt,
        public ?string $sessionClosedAt,
        public int $finalMasterSequenceNumber,
        public string $exportedAt,
    ) {
    }
}