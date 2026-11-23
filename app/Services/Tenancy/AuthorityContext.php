<?php

namespace App\Services\Tenancy;

use InvalidArgumentException;

/** Observed facts only; this value does not authorize an action. */
final readonly class AuthorityContext
{
    public function __construct(
        private bool $accountEligible,
        private bool $platformCapability,
        private ?string $workspaceRole,
    ) {
        if (! in_array($workspaceRole, [null, 'owner', 'manager'], true)
            || (! $accountEligible && ($platformCapability || $workspaceRole !== null))) {
            throw new InvalidArgumentException('Invalid authority context.');
        }
    }

    public function isAccountEligible(): bool
    {
        return $this->accountEligible;
    }

    public function hasPlatformCapability(): bool
    {
        return $this->platformCapability;
    }

    public function workspaceRole(): ?string
    {
        return $this->workspaceRole;
    }
}
