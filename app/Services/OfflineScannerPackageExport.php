<?php

namespace App\Services;

use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerSession;

class OfflineScannerPackageExport
{
    public function __construct(
        public readonly OfflineScannerSession $session,
        public readonly OfflineScannerPackage $package,
        public readonly string $contents,
        public readonly array $envelope,
    ) {
    }
}