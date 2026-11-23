<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Throwable;

/** Dormant preparation: no command, route, job or application caller invokes this service. */
class LegacyWorkspaceBackfill
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly OwnershipBackfillSchema $schema,
        private readonly OwnershipBackfillState $state = new OwnershipBackfillState,
    ) {}

    public function apply(Connection $connection, OwnershipManifest $manifest): array
    {
        try {
            if ($manifest->platformAdminUserIds() !== []) {
                $this->fail('platform_proposal_unsupported');
            }
            $this->schema->assertReady($connection);
            $groups = $manifest->workspaces();
            usort($groups, fn (array $left, array $right): int => strcmp($left['reference'], $right['reference']));
            $digest = OwnershipPlanIdentity::fromManifest($manifest)->digest();

            // Validate the whole proposal before even inserting a private plan.
            $plan = $this->state->plan($connection, $digest);
            $this->state->validateProposal($connection, $groups, $plan);
            $counts = ['workspaces_created' => 0, 'workspaces_verified' => 0, 'memberships_created' => 0, 'events_assigned' => 0];
            if ($groups !== []) {
                $planId = $connection->transaction(function () use ($connection, $digest): int {
                    $existing = $this->state->plan($connection, $digest, true);
                    if ($existing !== null) {
                        return $this->state->id($existing->id);
                    }

                    return $this->insertedId($connection, 'legacy_ownership_plans', [
                        'schema_version' => 1, 'manifest_hash' => $digest,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                });

                foreach ($groups as $group) {
                    // This cannot fence unrelated writers creating new events.
                    // Actual execution still requires a verified maintenance fence.
                    $this->state->coverage($connection, $groups);
                    $result = $connection->transaction(fn (): array => $this->applyGroup($connection, $digest, $planId, $group));
                    foreach ($counts as $key => $count) {
                        $counts[$key] += $result[$key];
                    }
                }
                // A final conflict deliberately retains earlier group commits.
                $this->state->validateProposal($connection, $groups, $this->state->plan($connection, $digest));
            }

            return [
                'schema_version' => 1, 'status' => 'complete', ...$counts,
                'platform_grants' => 0, 'rollout_ready' => false,
                'gates' => [
                    'ownership_mapping' => 'unverified', 'platform_grants' => 'unverified',
                    'backup_restore' => 'unverified', 'mysql_rehearsal' => 'unverified',
                ],
            ];
        } catch (OwnershipBackfillException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Never retain/log query exceptions or private proposal context.
            throw OwnershipBackfillException::because('database_unavailable');
        }
    }

    private function applyGroup(Connection $connection, string $digest, int $planId, array $group): array
    {
        $plan = $this->state->plan($connection, $digest, true);
        if ($plan === null || $this->state->id($plan->id) !== $planId) {
            $this->fail('ledger_conflict');
        }
        $mapping = $this->mapping($connection, $planId, $group['reference']);
        $this->state->owners($connection, $group['owner_user_ids'], true);
        $events = $this->state->rows($connection, 'events', $group['event_ids'], ['id', 'workspace_id'], true);
        if (count($events) !== count($group['event_ids'])) {
            $this->fail('coverage_conflict');
        }
        if ($mapping !== null) {
            $this->state->verifyCommitted($connection, $group, $this->state->id($mapping->workspace_id), true);

            return ['workspaces_created' => 0, 'workspaces_verified' => 1, 'memberships_created' => 0, 'events_assigned' => 0];
        }
        foreach ($events as $event) {
            if ($event->workspace_id !== null) {
                $this->fail('ledger_conflict');
            }
        }
        $timestamp = now();
        $workspaceId = $this->insertedId($connection, 'workspaces', [
            'name' => $group['name'], 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $ownerIds = $this->state->sortedIds($group['owner_user_ids']);
        foreach (array_chunk($ownerIds, self::BATCH_SIZE) as $batch) {
            $connection->table('workspace_users')->insert(array_map(fn (int $id): array => [
                'workspace_id' => $workspaceId, 'user_id' => $id, 'role' => 'owner', 'is_active' => false,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ], $batch));
        }
        foreach (array_chunk($this->state->sortedIds($group['event_ids']), self::BATCH_SIZE) as $batch) {
            // Preserve every pre-existing event field, including timestamps.
            $changed = $connection->table('events')->whereIn('id', $batch)->whereNull('workspace_id')
                ->update(['workspace_id' => $workspaceId]);
            if ($changed !== count($batch)) {
                $this->fail('ledger_conflict');
            }
        }
        $connection->table('legacy_workspace_mappings')->insert([
            'ownership_plan_id' => $planId, 'manifest_reference' => $group['reference'], 'workspace_id' => $workspaceId,
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $resolution = $this->mapping($connection, $planId, $group['reference']);
        if ($resolution === null || $this->state->id($resolution->workspace_id) !== $workspaceId) {
            $this->fail('ledger_conflict');
        }
        $this->state->verifyCommitted($connection, $group, $workspaceId, true);

        return [
            'workspaces_created' => 1, 'workspaces_verified' => 0,
            'memberships_created' => count($ownerIds), 'events_assigned' => count($group['event_ids']),
        ];
    }

    private function mapping(Connection $connection, int $planId, string $reference): ?object
    {
        $matches = $connection->table('legacy_workspace_mappings')->select(['id', 'manifest_reference', 'workspace_id'])
            ->where('ownership_plan_id', $planId)->where('manifest_reference', $reference)
            ->orderBy('id')->limit(2)->lockForUpdate()->get();
        if ($matches->count() > 1) {
            $this->fail('ledger_conflict');
        }
        $mapping = $matches->first();
        if ($mapping !== null) {
            $this->state->id($mapping->id);
            $this->state->id($mapping->workspace_id);
            if ($mapping->manifest_reference !== $reference) {
                $this->fail('ledger_conflict');
            }
        }

        return $mapping;
    }

    private function insertedId(Connection $connection, string $table, array $values): int
    {
        $processed = $connection->table($table)->insertGetId($values);
        // Laravel's processor casts numeric IDs to int, which can clamp an
        // unsigned BIGINT above PHP_INT_MAX. Validate the uncoerced value before
        // using it as a workspace/plan identity or committing the insertion.
        $raw = $connection instanceof MySqlConnection
            ? $connection->getLastInsertId()
            : $connection->getPdo()->lastInsertId();
        $id = $this->state->id($raw);
        if ($processed !== $id) {
            $this->fail('invalid_prerequisites');
        }

        return $id;
    }

    private function fail(string $reason): never
    {
        throw OwnershipBackfillException::because($reason);
    }
}
