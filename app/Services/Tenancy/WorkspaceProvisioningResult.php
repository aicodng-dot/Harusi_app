<?php

namespace App\Services\Tenancy;

/** Creation receipt only: never an authorization token or permission grant. */
final readonly class WorkspaceProvisioningResult
{
    public function __construct(private int $workspaceId, private bool $created) {}

    public function workspaceId(): int
    {
        return $this->workspaceId;
    }

    public function wasCreated(): bool
    {
        return $this->created;
    }

    public function __debugInfo(): array
    {
        return [];
    }
}
