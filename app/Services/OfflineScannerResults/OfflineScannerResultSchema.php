<?php

namespace App\Services\OfflineScannerResults;

class OfflineScannerResultSchema
{
    public const FILE_EXTENSION = '.wqrresults';
    public const FORMAT = 'harusi.offline-scanner.results';
    public const SCHEMA_VERSION = '1.0';
    public const ENCRYPTION_ALGORITHM = 'AES-256-GCM';

    public const RESULT_ADMITTED_SUCCESSFULLY = 'admitted_successfully';
    public const RESULT_VALID_PASS = 'valid_pass';
    public const RESULT_ALREADY_FULLY_USED = 'already_fully_used';
    public const RESULT_INVALID_QR = 'invalid_qr';
    public const RESULT_WRONG_EVENT = 'wrong_event';
    public const RESULT_DISABLED_PASS = 'disabled_pass';
    public const RESULT_INSUFFICIENT_REMAINING = 'insufficient_remaining';
    public const RESULT_DUPLICATE_REQUEST = 'duplicate_request';
    public const RESULT_REJECTED = 'rejected';
    public const RESULT_CANCELLED = 'cancelled';

    public static function resultCodes(): array
    {
        return [
            self::RESULT_ADMITTED_SUCCESSFULLY,
            self::RESULT_VALID_PASS,
            self::RESULT_ALREADY_FULLY_USED,
            self::RESULT_INVALID_QR,
            self::RESULT_WRONG_EVENT,
            self::RESULT_DISABLED_PASS,
            self::RESULT_INSUFFICIENT_REMAINING,
            self::RESULT_DUPLICATE_REQUEST,
            self::RESULT_REJECTED,
            self::RESULT_CANCELLED,
        ];
    }

    public static function invalidResultCodes(): array
    {
        return [
            self::RESULT_ALREADY_FULLY_USED,
            self::RESULT_INVALID_QR,
            self::RESULT_WRONG_EVENT,
            self::RESULT_DISABLED_PASS,
            self::RESULT_INSUFFICIENT_REMAINING,
            self::RESULT_REJECTED,
            self::RESULT_CANCELLED,
        ];
    }

    public static function admissionMethods(): array
    {
        return ['qr', 'manual', 'phone'];
    }

    public static function deviceRoles(): array
    {
        return ['master', 'client'];
    }
}
