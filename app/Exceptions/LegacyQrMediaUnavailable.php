<?php

namespace App\Exceptions;

use RuntimeException;

/** Fixed operational refusal of an administrator QR image/ZIP delivery; no path, SQL or cause. */
final class LegacyQrMediaUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Guest QR media could not be prepared.');
    }
}
