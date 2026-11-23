<?php

namespace App\Services\Tenancy;

use RuntimeException;

/** Carries only a reason code; never SQL, bindings or account details. */
class CustomerEventCreationException extends RuntimeException
{
    private function __construct(private readonly string $reason)
    {
        parent::__construct('Customer event creation failed: '.$reason);
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
