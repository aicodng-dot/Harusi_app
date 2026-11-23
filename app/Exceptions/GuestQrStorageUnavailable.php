<?php

namespace App\Exceptions;

use RuntimeException;

/** Fixed refusal of the private ordinary-QR disk; carries no path, root or cause. */
final class GuestQrStorageUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Guest QR storage is unavailable.');
    }
}
