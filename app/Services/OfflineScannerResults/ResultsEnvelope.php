<?php

namespace App\Services\OfflineScannerResults;

readonly class ResultsEnvelope
{
    public function __construct(
        public string $format,
        public string $resultSchemaVersion,
        public string $sourcePackageExportId,
        public string $sessionUuid,
        public string $resultsExportId,
        public int $eventId,
        public string $masterDeviceId,
        public string $encryptionAlgorithm,
        public string $initializationVector,
        public string $encryptedPayload,
        public string $authenticationTag,
        public string $payloadHash,
    ) {
    }

    public function bindingData(): array
    {
        return [
            'event_id' => $this->eventId,
            'format' => $this->format,
            'master_device_id' => $this->masterDeviceId,
            'payload_hash' => $this->payloadHash,
            'result_schema_version' => $this->resultSchemaVersion,
            'results_export_id' => $this->resultsExportId,
            'session_uuid' => $this->sessionUuid,
            'source_package_export_id' => $this->sourcePackageExportId,
        ];
    }
}