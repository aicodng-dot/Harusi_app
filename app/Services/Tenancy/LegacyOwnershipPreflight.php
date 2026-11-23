<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Connection;
use InvalidArgumentException;

class LegacyOwnershipPreflight
{
    private const REQUIRED_SCHEMA = [
        'users' => ['id', 'role', 'is_active', 'is_platform_admin'],
        'events' => ['id', 'workspace_id'],
        'workspaces' => ['id', 'name'],
        'workspace_users' => ['id', 'workspace_id', 'user_id', 'role', 'is_active'],
    ];

    private const BATCH_SIZE = 500;

    public function inspect(Connection $connection, OwnershipManifest $manifest): array
    {
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException('Unsupported ownership preflight database driver.');
        }

        $version = $connection->getServerVersion();
        $groups = $manifest->workspaces();
        $ownerIds = [];
        $eventIds = [];
        $ownerMemberships = 0;
        foreach ($groups as $group) {
            $ownerMemberships += count($group['owner_user_ids']);
            array_push($ownerIds, ...$group['owner_user_ids']);
            array_push($eventIds, ...$group['event_ids']);
        }
        $ownerIds = array_values(array_unique($ownerIds));
        $eventIds = array_values(array_unique($eventIds));
        $platformIds = $manifest->platformAdminUserIds();
        $schema = $connection->getSchemaBuilder();
        $missing = [];
        foreach (self::REQUIRED_SCHEMA as $table => $required) {
            $available = $schema->hasTable($table) ? $schema->getColumnListing($table) : [];
            $gaps = array_values(array_diff($required, $available));
            if ($gaps !== []) {
                $missing[$table] = $gaps;
            }
        }

        $findings = [];
        $coverage = ['unowned_events' => null, 'mapped_unowned_events' => null];
        $complete = $missing === [];
        if ($complete) {
            foreach (['users', 'events'] as $table) {
                // A primary key cannot contain functional index expressions.
                // Avoid lossy generic unique-index metadata and require exactly
                // this declared key, plus observed non-nullness, before lookups.
                $primary = false;
                foreach ($schema->getIndexes($table) as $index) {
                    if ($index['primary'] && $index['unique'] && $index['columns'] === ['id']) {
                        $primary = true;
                        break;
                    }
                }
                if (! $primary || $connection->table($table)->whereNull('id')->exists()) {
                    $complete = false;
                    $this->finding($findings, $table.'_invalid_primary_key', 1);
                }
            }
        }

        if ($complete) {
            $unowned = $connection->table('events')->whereNull('workspace_id')->count();
            $accountIds = array_values(array_unique(array_merge($ownerIds, $platformIds)));
            $accounts = [];
            foreach (array_chunk($accountIds, self::BATCH_SIZE) as $batch) {
                foreach ($connection->table('users')->select(['id', 'role', 'is_active', 'is_platform_admin'])->whereIn('id', $batch)->get() as $row) {
                    $id = $this->positiveId($row->id);
                    if ($id !== null) {
                        $accounts[$id] = $row;
                    }
                }
            }
            $this->accounts($ownerIds, $accounts, false, $findings);
            $this->accounts($platformIds, $accounts, true, $findings);

            $events = [];
            foreach (array_chunk($eventIds, self::BATCH_SIZE) as $batch) {
                foreach ($connection->table('events')->select(['id', 'workspace_id'])->whereIn('id', $batch)->get() as $row) {
                    $id = $this->positiveId($row->id);
                    if ($id !== null) {
                        $events[$id] = $row;
                    }
                }
            }
            $missingEvents = $ownedEvents = $mappedUnowned = 0;
            foreach ($eventIds as $id) {
                if (! isset($events[$id])) {
                    $missingEvents++;
                } elseif ($events[$id]->workspace_id !== null) {
                    $ownedEvents++;
                } else {
                    $mappedUnowned++;
                }
            }
            $coverage = ['unowned_events' => $unowned, 'mapped_unowned_events' => $mappedUnowned];
            $this->finding($findings, 'events_missing', $missingEvents);
            $this->finding($findings, 'events_already_owned', $ownedEvents);
            $this->finding($findings, 'unowned_events_unmapped', max(0, $unowned - $mappedUnowned));
            if ($mappedUnowned > $unowned) {
                $this->finding($findings, 'observation_changed', 1, 'review');
            }
        }
        ksort($findings);

        return [
            'schema_version' => 1,
            'status' => $complete ? 'complete' : 'incomplete',
            'mode' => 'read_only',
            'database' => ['driver' => $driver, 'version' => $version],
            'proposal' => [
                'workspaces' => count($groups),
                'owner_memberships' => $ownerMemberships,
                'events' => count($eventIds),
                'platform_admins' => count($platformIds),
            ],
            'coverage' => $coverage,
            'missing_schema' => $missing,
            'findings' => $findings,
            'gates' => [
                'ownership_mapping' => 'unverified',
                'platform_grants' => 'unverified',
                'backup_restore' => 'unverified',
                'mysql_rehearsal' => 'unverified',
            ],
            'rollout_ready' => false,
            'limitations' => [
                'Eligibility and coverage observations are best effort, not an atomic snapshot or a backup.',
                'A valid explicit proposal does not establish legitimate ownership or authorization to grant platform access.',
                'Only the required schema subset, selected account eligibility and event coverage are checked.',
                'Account findings count distinct selected identities per category; proposed memberships count occurrences across workspaces.',
                'Event findings count selected or uncovered events; key and observation findings count failed prerequisites.',
                'Existing membership provenance, downstream resource consistency, files and exhaustive offline constraints are not audited.',
                'Unlisted platform flags are neither approved nor changed; current web/scanner authority is unchanged.',
                'No workspace, membership, event owner, platform grant, backup restoration or real MySQL rehearsal is created or established.',
            ],
        ];
    }

    private function accounts(array $ids, array $accounts, bool $platform, array &$findings): void
    {
        $missing = $inactive = $unsupported = $alreadyGranted = 0;
        foreach ($ids as $id) {
            if (! isset($accounts[$id])) {
                $missing++;

                continue;
            }
            $account = $accounts[$id];
            if (! in_array($account->is_active, [true, 1, '1'], true)) {
                $inactive++;
            }
            if (! in_array($account->role, $platform ? ['admin'] : ['admin', 'scanner'], true)) {
                $unsupported++;
            }
            if ($platform && (bool) $account->is_platform_admin) {
                $alreadyGranted++;
            }
        }
        $prefix = $platform ? 'platform_admins_' : 'owners_';
        $this->finding($findings, $prefix.'missing', $missing);
        $this->finding($findings, $prefix.'inactive', $inactive);
        $this->finding($findings, $prefix.'unsupported_role', $unsupported);
        if ($platform) {
            $this->finding($findings, 'platform_admins_already_granted', $alreadyGranted, 'review');
        }
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id === false ? null : $id;
    }

    private function finding(array &$findings, string $key, int $count, string $severity = 'blocker'): void
    {
        if ($count > 0) {
            $findings[$key] = ['severity' => $severity, 'count' => $count];
        }
    }
}
