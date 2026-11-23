<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/** Shared exact-state reads; lock requests are reserved for the dormant writer. */
class OwnershipBackfillState
{
    private const BATCH_SIZE = 500;

    public function validateProposal(Connection $connection, array $groups, ?object $plan): array
    {
        $verified = $assigned = 0;
        $mappings = $this->mappings($connection, $groups, $plan);
        $owners = $events = [];
        foreach ($groups as $group) {
            array_push($owners, ...$group['owner_user_ids']);
            array_push($events, ...$group['event_ids']);
        }
        $this->owners($connection, $owners);
        $selected = $this->rows($connection, 'events', $events, ['id', 'workspace_id']);
        foreach ($groups as $group) {
            $mapping = $mappings[$group['reference']] ?? null;
            foreach ($group['event_ids'] as $id) {
                if (! isset($selected[$id])) {
                    $this->fail('coverage_conflict');
                }
                $owned = $selected[$id]->workspace_id;
                if ($mapping === null) {
                    if ($owned !== null) {
                        $this->fail('ledger_conflict');
                    }
                } elseif ($owned === null || $this->id($owned) !== $this->id($mapping->workspace_id)) {
                    $this->fail('ledger_conflict');
                }
            }
            if ($mapping !== null) {
                $this->verifyCommitted($connection, $group, $this->id($mapping->workspace_id));
                $verified++;
                $assigned += count($group['event_ids']);
            }
        }
        $this->coverage($connection, $groups);

        return ['workspaces_verified' => $verified, 'events_assigned' => $assigned];
    }

    public function verifyCommitted(Connection $connection, array $group, int $workspaceId, bool $lock = false): void
    {
        $target = $connection->table('workspaces')->select(['id', 'name'])->where('id', $workspaceId);
        $workspace = $this->locked($target, $lock)->first();
        if ($workspace === null || $workspace->name !== $group['name']) {
            $this->fail('ledger_conflict');
        }
        $expectedOwners = $this->sortedIds($group['owner_user_ids']);
        $members = $connection->table('workspace_users')->select(['id', 'user_id', 'role', 'is_active'])
            ->where('workspace_id', $workspaceId)->orderBy('user_id')->limit(count($expectedOwners) + 1);
        $actualOwners = [];
        foreach ($this->locked($members, $lock)->get() as $member) {
            $this->id($member->id);
            if ($member->role !== 'owner' || ! in_array($member->is_active, [false, 0, '0'], true)) {
                $this->fail('ledger_conflict');
            }
            $actualOwners[] = $this->id($member->user_id);
        }
        if ($this->sortedIds($actualOwners) !== $expectedOwners || count($actualOwners) !== count($expectedOwners)) {
            $this->fail('ledger_conflict');
        }
        $expectedEvents = $this->sortedIds($group['event_ids']);
        $owned = $connection->table('events')->select('id')->where('workspace_id', $workspaceId)
            ->orderBy('id')->limit(count($expectedEvents) + 1);
        $actualEvents = [];
        foreach ($this->locked($owned, $lock)->get() as $event) {
            $actualEvents[] = $this->id($event->id);
        }
        if ($this->sortedIds($actualEvents) !== $expectedEvents || count($actualEvents) !== count($expectedEvents)) {
            $this->fail('ledger_conflict');
        }
    }

    public function plan(Connection $connection, string $digest, bool $lock = false): ?object
    {
        $query = $connection->table('legacy_ownership_plans')->select(['id', 'schema_version', 'manifest_hash'])
            ->where('manifest_hash', $digest)->orderBy('id')->limit(2);
        $plans = $this->locked($query, $lock)->get();
        if ($plans->count() > 1) {
            $this->fail('ledger_conflict');
        }
        $plan = $plans->first();
        if ($plan !== null) {
            $this->id($plan->id);
            if (! in_array($plan->schema_version, [1, '1'], true) || ! is_string($plan->manifest_hash)
                || ! hash_equals($digest, $plan->manifest_hash)) {
                $this->fail('ledger_conflict');
            }
        }

        return $plan;
    }

    private function mappings(Connection $connection, array $groups, ?object $plan): array
    {
        if ($plan === null) {
            return [];
        }
        $references = array_fill_keys(array_column($groups, 'reference'), true);
        $mappings = [];
        $rows = $connection->table('legacy_workspace_mappings')->select(['id', 'manifest_reference', 'workspace_id'])
            ->where('ownership_plan_id', $this->id($plan->id))->orderBy('id')->limit(OwnershipManifest::MAX_WORKSPACES + 1)->get();
        foreach ($rows as $row) {
            $this->id($row->id);
            $this->id($row->workspace_id);
            if (! is_string($row->manifest_reference) || ! isset($references[$row->manifest_reference])
                || isset($mappings[$row->manifest_reference])) {
                $this->fail('ledger_conflict');
            }
            $mappings[$row->manifest_reference] = $row;
        }

        return $mappings;
    }

    public function owners(Connection $connection, array $ids, bool $lock = false): void
    {
        $ids = $this->sortedIds($ids);
        $accounts = $this->rows($connection, 'users', $ids, ['id', 'role', 'is_active'], $lock);
        foreach ($ids as $id) {
            if (! isset($accounts[$id]) || ! in_array($accounts[$id]->is_active, [true, 1, '1'], true)
                || ! in_array($accounts[$id]->role, ['admin', 'scanner'], true)) {
                $this->fail('eligibility_changed');
            }
        }
    }

    public function coverage(Connection $connection, array $groups): void
    {
        $ids = [];
        foreach ($groups as $group) {
            array_push($ids, ...$group['event_ids']);
        }
        $unowned = $connection->table('events')->whereNull('workspace_id')->count();
        $covered = 0;
        foreach (array_chunk($this->sortedIds($ids), self::BATCH_SIZE) as $batch) {
            $covered += $connection->table('events')->whereIn('id', $batch)->whereNull('workspace_id')->count();
        }
        if ($covered !== $unowned) {
            $this->fail('coverage_conflict');
        }
    }

    public function rows(Connection $connection, string $table, array $ids, array $columns, bool $lock = false): array
    {
        $rows = [];
        foreach (array_chunk($this->sortedIds($ids), self::BATCH_SIZE) as $batch) {
            $query = $connection->table($table)->select($columns)->whereIn('id', $batch)->orderBy('id');
            foreach ($this->locked($query, $lock)->get() as $row) {
                $id = $this->id($row->id);
                if (isset($rows[$id])) {
                    $this->fail('invalid_prerequisites');
                }
                $rows[$id] = $row;
            }
        }

        return $rows;
    }

    private function locked(Builder $query, bool $lock): Builder
    {
        return $lock ? $query->lockForUpdate() : $query;
    }

    public function sortedIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        usort($ids, fn (int $left, int $right): int => $left <=> $right);

        return $ids;
    }

    public function id(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id !== false) {
                return $id;
            }
        }
        $this->fail('invalid_prerequisites');
    }

    private function fail(string $reason): never
    {
        throw OwnershipBackfillException::because($reason);
    }
}
