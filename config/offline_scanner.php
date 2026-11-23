<?php

return [
    'disk' => env('OFFLINE_SCANNER_DISK', 'offline_scanner'),

    'formats' => [
        'package' => 'harusi.offline-scanner.package',
        'results' => 'harusi.offline-scanner.results',
    ],

    'retention' => [
        'retain_completed_days' => (int) env('OFFLINE_SCANNER_RETAIN_COMPLETED_DAYS', 365),
        'retain_cancelled_days' => (int) env('OFFLINE_SCANNER_RETAIN_CANCELLED_DAYS', 365),
        'allow_secure_deletion' => filter_var(env('OFFLINE_SCANNER_ALLOW_SECURE_DELETION', false), FILTER_VALIDATE_BOOL),
    ],
];