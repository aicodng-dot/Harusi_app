<?php

namespace App\Services\OfflineScannerResults;

readonly class ResultsMetrics
{
    public function __construct(
        public int $totalScans,
        public int $totalAdmittedPeople,
        public int $totalInvalidScans,
        public int $totalManualAdmissions,
        public int $totalQrAdmissions,
    ) {
    }
}