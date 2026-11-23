<?php

namespace App\Exceptions;

use RuntimeException;

/** Fixed refusal of an administrator read projection; carries no row, path or cause. */
final class LegacyAdminReadUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The administrator view could not be prepared.');
    }
}
