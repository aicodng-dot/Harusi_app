<?php

namespace App\Services\Tenancy;

final readonly class OwnershipPlanIdentity
{
    private function __construct(private string $digest) {}

    public static function fromManifest(OwnershipManifest $manifest): self
    {
        $groups = $manifest->workspaces();
        usort($groups, fn (array $left, array $right): int => strcmp($left['reference'], $right['reference']));
        $canonicalGroups = [];
        foreach ($groups as $group) {
            $owners = $group['owner_user_ids'];
            $events = $group['event_ids'];
            usort($owners, fn (int $left, int $right): int => $left <=> $right);
            usort($events, fn (int $left, int $right): int => $left <=> $right);
            $canonicalGroups[] = [
                'reference' => $group['reference'],
                'name' => $group['name'],
                'owner_user_ids' => $owners,
                'event_ids' => $events,
            ];
        }
        $platformIds = $manifest->platformAdminUserIds();
        usort($platformIds, fn (int $left, int $right): int => $left <=> $right);
        $canonical = json_encode([
            'schema_version' => 1,
            'workspaces' => $canonicalGroups,
            'platform_admin_user_ids' => $platformIds,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new self(hash('sha256', "kadify:legacy-ownership-plan:v1\0".$canonical));
    }

    public function digest(): string
    {
        return $this->digest;
    }
}
