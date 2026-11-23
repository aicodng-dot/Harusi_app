<?php

namespace App\Exceptions;

use RuntimeException;

final class LegacyGuestIssuanceFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Guest issuance failed.');
    }
}
