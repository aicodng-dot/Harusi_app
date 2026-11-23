<?php

namespace App\Services\Tenancy;

use InvalidArgumentException;
use RuntimeException;

final class OwnershipBackfillException extends RuntimeException
{
    private const REASONS = [
        'platform_proposal_unsupported',
        'invalid_prerequisites',
        'eligibility_changed',
        'coverage_conflict',
        'ledger_conflict',
        'database_unavailable',
    ];

    private function __construct(private readonly string $reasonCode)
    {
        parent::__construct('Ownership backfill could not be completed.');
    }

    public static function because(string $reason): self
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Invalid ownership backfill reason.');
        }

        return new self($reason);
    }

    public function reason(): string
    {
        return $this->reasonCode;
    }
}
