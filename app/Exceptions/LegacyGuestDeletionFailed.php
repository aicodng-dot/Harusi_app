<?php

namespace App\Exceptions;

use RuntimeException;

final class LegacyGuestDeletionFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Guest deletion failed.');
    }
}
