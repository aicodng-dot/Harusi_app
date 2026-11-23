<?php

namespace App\Services\Tenancy;

use InvalidArgumentException;
use RuntimeException;

final class AuthorityContextException extends RuntimeException
{
    private const REASONS = [
        'invalid_identifiers',
        'invalid_prerequisites',
        'database_unavailable',
    ];

    private function __construct(private readonly string $reasonCode)
    {
        parent::__construct('Authority context could not be resolved.');
    }

    public static function because(string $reason): self
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Invalid authority context reason.');
        }

        return new self($reason);
    }

    public function reason(): string
    {
        return $this->reasonCode;
    }
}
