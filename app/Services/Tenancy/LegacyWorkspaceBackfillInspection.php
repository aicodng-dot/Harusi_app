<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Connection;
use Throwable;

class LegacyWorkspaceBackfillInspection
{
    public function __construct(
        private readonly OwnershipBackfillSchema $schema,
        private readonly OwnershipBackfillState $state,
    ) {}

    public function inspect(Connection $connection, OwnershipManifest $manifest): array
    {
        try {
            if ($manifest->platformAdminUserIds() !== []) {
                throw OwnershipBackfillException::because('platform_proposal_unsupported');
            }
            $this->schema->assertReady($connection);
            $groups = $manifest->workspaces();
            usort($groups, fn (array $left, array $right): int => strcmp($left['reference'], $right['reference']));
            $digest = OwnershipPlanIdentity::fromManifest($manifest)->digest();

            // Both shared calls use their non-locking read path. This service
            // neither depends on the writer nor starts any transaction.
            $plan = $this->state->plan($connection, $digest);
            $verified = $this->state->validateProposal($connection, $groups, $plan);
            $memberships = $events = 0;
            foreach ($groups as $group) {
                $memberships += count($group['owner_user_ids']);
                $events += count($group['event_ids']);
            }
            $workspaces = count($groups);
            $remaining = $workspaces - $verified['workspaces_verified'];
            $progress = $remaining === 0 ? 'exact'
                : ($verified['workspaces_verified'] === 0 ? 'unstarted' : 'partial');

            return [
                'schema_version' => 1,
                'status' => 'complete',
                'mode' => 'read_only',
                'progress' => $progress,
                'proposal' => [
                    'workspaces' => $workspaces,
                    'owner_memberships' => $memberships,
                    'events' => $events,
                ],
                'observed' => [
                    'plan_present' => $plan !== null,
                    'workspaces_verified' => $verified['workspaces_verified'],
                    'workspaces_remaining' => $remaining,
                    'events_assigned' => $verified['events_assigned'],
                    'events_remaining' => $events - $verified['events_assigned'],
                ],
                'rollout_ready' => false,
                'gates' => [
                    'ownership_mapping' => 'unverified',
                    'platform_grants' => 'unverified',
                    'backup_restore' => 'unverified',
                    'mysql_rehearsal' => 'unverified',
                ],
            ];
        } catch (OwnershipBackfillException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Never retain or log raw query exceptions or private proposal data.
            throw OwnershipBackfillException::because('database_unavailable');
        }
    }
}
