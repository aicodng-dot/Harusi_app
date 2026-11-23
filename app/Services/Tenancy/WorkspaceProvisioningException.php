<?php

namespace App\Services\Tenancy;

use InvalidArgumentException;
use RuntimeException;

final class WorkspaceProvisioningException extends RuntimeException
{
    private function __construct(private readonly string $reasonCode)
    {
        parent::__construct('Workspace could not be created.');
    }

    public static function because(string $reason): self
    {
        if (! in_array($reason, ['invalid_input', 'invalid_prerequisites', 'creator_unavailable', 'request_conflict', 'database_unavailable'], true)) {
            throw new InvalidArgumentException('Invalid workspace provisioning reason.');
        }

        return new self($reason);
    }

    public function reason(): string
    {
        return $this->reasonCode;
    }
}
