<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Tenancy\LegacyWorkspaceBackfill;
use App\Services\Tenancy\OwnershipBackfillException;
use App\Services\Tenancy\OwnershipBackfillSchema;
use App\Services\Tenancy\OwnershipManifest;
use App\Services\Tenancy\OwnershipPlanIdentity;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar as SQLiteSchemaGrammar;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LegacyWorkspaceBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const INSERTED_TABLES = ['workspaces', 'workspace_users', 'legacy_ownership_plans', 'legacy_workspace_mappings'];

    private array $ownedConnections = [];

    protected function tearDown(): void
    {
        $this->travelBack();
        foreach ($this->ownedConnections as $name) {
            DB::purge($name);
        }

        parent::tearDown();
    }

    public function test_first_apply_and_equivalent_order_retry_preserve_identity_timestamps_authority_and_legacy_records(): void
    {
        $connection = $this->fixture();
        $manifest = $this->manifest($connection);
        $before = $this->snapshot($connection);
        $default = DB::getDefaultConnection();
        $defaultBefore = $this->snapshot(DB::connection());
        $this->travelTo(now()->setDate(2026, 10, 18)->startOfDay());
        Log::spy();

        $report = $this->apply($connection, $manifest);

        $this->assertReport($report, 1, 0, 2, 4);
        $workspace = $connection->table('workspaces')->sole();
        $this->assertSame('Private workspace / Arusi ya Asha', $workspace->name);
        $members = $connection->table('workspace_users')->orderBy('user_id')->get();
        $this->assertSame([211, 212], $members->pluck('user_id')->all());
        foreach ($members as $member) {
            $this->assertSame($workspace->id, $member->workspace_id);
            $this->assertSame('owner', $member->role);
            $this->assertSame(0, $member->is_active);
            $this->assertNotNull($member->created_at);
            $this->assertNotNull($member->updated_at);
        }
        $this->assertSame([$workspace->id], $connection->table('events')->distinct()->pluck('workspace_id')->all());
        $plan = $connection->table('legacy_ownership_plans')->sole();
        $this->assertSame(1, $plan->schema_version);
        $this->assertSame(OwnershipPlanIdentity::fromManifest($manifest)->digest(), $plan->manifest_hash);
        $mapping = $connection->table('legacy_workspace_mappings')->sole();
        $this->assertSame($plan->id, $mapping->ownership_plan_id);
        $this->assertSame('private-reference', $mapping->manifest_reference);
        $this->assertSame($workspace->id, $mapping->workspace_id);
        $this->assertConservation($before, $this->snapshot($connection));
        $scanner = (new User)->setRawAttributes((array) $connection->table('users')->find(211), true);
        $admin = (new User)->setRawAttributes((array) $connection->table('users')->find(212), true);
        $this->assertTrue($scanner->isScanner());
        $this->assertFalse($scanner->isAdmin());
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isScanner());
        $this->assertSame(201, $scanner->event_id);
        $this->assertSame('Legacy gate', $scanner->gate_name);
        $this->assertFalse($scanner->is_platform_admin);
        $this->assertFalse($admin->is_platform_admin);
        $this->assertSame(1, $connection->table('users')->where('id', 213)->value('is_platform_admin'));
        $after = $this->snapshot($connection);
        $this->travelTo(now()->addDay());
        $reordered = OwnershipManifest::fromJson(json_encode([
            'platform_admin_user_ids' => [],
            'workspaces' => [[
                'event_ids' => [203, 202, 201, 1], 'owner_user_ids' => [212, 211],
                'name' => 'Private workspace / Arusi ya Asha', 'reference' => 'private-reference',
            ]],
            'schema_version' => 1,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $retry = $this->apply($connection, $reordered);

        $this->assertReport($retry, 0, 1, 0, 0);
        $this->assertSame($after, $this->snapshot($connection));
        $this->assertSame($default, DB::getDefaultConnection());
        $this->assertSame($defaultBefore, $this->snapshot(DB::connection()));
        $this->assertSame([], $connection->select('PRAGMA foreign_key_check'));
        $this->assertNoLogs();
    }

    public function test_equivalent_group_order_retries_by_canonical_reference_without_any_rewrite(): void
    {
        $connection = $this->fixture();
        $groups = $this->twoGroups();
        $this->assertReport($this->apply($connection, $this->manifest($connection, array_reverse($groups))), 2, 0, 3, 4);
        $this->assertSame(['alpha', 'zeta'], $connection->table('legacy_workspace_mappings')->orderBy('id')->pluck('manifest_reference')->all());
        $before = $this->snapshot($connection);

        $this->assertReport($this->apply($connection, $this->manifest($connection, $groups)), 0, 2, 0, 0);

        $this->assertSame($before, $this->snapshot($connection));
    }

    public function test_empty_proposal_with_no_unowned_events_makes_no_plan_or_other_write(): void
    {
        $connection = $this->isolatedSchema();
        $connection->table('events')->delete();
        $before = $this->snapshot($connection);

        $report = $this->apply($connection, $this->manifest($connection, []));

        $this->assertReport($report, 0, 0, 0, 0);
        $this->assertSame($before, $this->snapshot($connection));
    }

    #[DataProvider('earlyConflicts')]
    public function test_eligibility_coverage_and_platform_conflicts_refuse_before_any_database_write(string $conflict, string $reason): void
    {
        $connection = $this->fixture();
        $groups = $this->singleGroup($connection);
        $platformIds = [];
        if ($conflict === 'platform intent') {
            $platformIds = [212];
        } elseif ($conflict === 'missing owner') {
            $groups[0]['owner_user_ids'][] = 999999;
        } elseif ($conflict === 'inactive owner') {
            $connection->table('users')->where('id', 211)->update(['is_active' => false]);
        } elseif ($conflict === 'noncanonical active owner') {
            $connection->table('users')->where('id', 211)->update(['is_active' => 2]);
        } elseif ($conflict === 'unsupported owner') {
            $connection->table('users')->where('id', 211)->update(['role' => 'future-role']);
        } elseif ($conflict === 'missing event') {
            $groups[0]['event_ids'][] = 999999;
        } elseif ($conflict === 'uncovered archived event') {
            $groups[0]['event_ids'] = array_values(array_diff($groups[0]['event_ids'], [202]));
        } elseif ($conflict === 'uncovered default event') {
            $groups[0]['event_ids'] = array_values(array_diff($groups[0]['event_ids'], [1]));
        } elseif ($conflict === 'uncovered cancelled event') {
            $groups[0]['event_ids'] = array_values(array_diff($groups[0]['event_ids'], [203]));
        } elseif ($conflict === 'owned without ledger') {
            $connection->table('workspaces')->insert(['id' => 51, 'name' => $groups[0]['name']]);
            $connection->table('events')->where('id', 201)->update(['workspace_id' => 51]);
        }
        $manifest = $this->manifest($connection, $groups, $platformIds);
        $before = $this->snapshot($connection);
        $writes = [];
        Log::spy();

        $this->withQueryListener($connection, function (QueryExecuted $query) use (&$writes): void {
            if ($this->isWrite($query->sql)) {
                $writes[] = $query->sql;
            }
        }, fn () => $this->assertReason($reason, fn () => $this->apply($connection, $manifest)));

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertNoLogs();
    }

    public static function earlyConflicts(): array
    {
        return [
            'platform intent' => ['platform intent', 'platform_proposal_unsupported'],
            'missing owner' => ['missing owner', 'eligibility_changed'],
            'inactive owner' => ['inactive owner', 'eligibility_changed'],
            'noncanonical active owner' => ['noncanonical active owner', 'eligibility_changed'],
            'unsupported owner' => ['unsupported owner', 'eligibility_changed'],
            'missing event' => ['missing event', 'coverage_conflict'],
            'uncovered archived event' => ['uncovered archived event', 'coverage_conflict'],
            'uncovered default event' => ['uncovered default event', 'coverage_conflict'],
            'uncovered cancelled event' => ['uncovered cancelled event', 'coverage_conflict'],
            'owned without ledger' => ['owned without ledger', 'ledger_conflict'],
        ];
    }

    public function test_changed_proposal_never_adopts_an_existing_workspace_by_name_or_previous_event_ownership(): void
    {
        $connection = $this->fixture();
        $this->apply($connection, $this->manifest($connection));
        $groups = $this->singleGroup($connection);
        $groups[0]['name'] .= ' changed';
        $before = $this->snapshot($connection);

        $this->assertReason('ledger_conflict', fn () => $this->apply($connection, $this->manifest($connection, $groups)));

        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame(1, $connection->table('legacy_ownership_plans')->count());
    }

    public function test_unused_workspace_with_identical_name_is_preserved_without_being_adopted(): void
    {
        $connection = $this->fixture();
        $groups = $this->singleGroup($connection);
        $connection->table('workspaces')->insert(['id' => 51, 'name' => $groups[0]['name'], 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00']);
        $existing = (array) $connection->table('workspaces')->find(51);

        $this->assertReport($this->apply($connection, $this->manifest($connection, $groups)), 1, 0, 2, 4);

        $target = $connection->table('legacy_workspace_mappings')->value('workspace_id');
        $this->assertNotSame(51, $target);
        $this->assertSame(2, $connection->table('workspaces')->count());
        $this->assertSame($existing, (array) $connection->table('workspaces')->find(51));
        $this->assertSame(0, $connection->table('workspace_users')->where('workspace_id', 51)->count());
        $this->assertSame(0, $connection->table('events')->where('workspace_id', 51)->count());
    }

    #[DataProvider('changedOwnerEligibility')]
    public function test_same_proposal_retry_rechecks_current_owner_eligibility_without_rewriting_committed_state(array $change): void
    {
        $connection = $this->fixture();
        $manifest = $this->manifest($connection);
        $this->apply($connection, $manifest);
        $connection->table('users')->where('id', 211)->update($change);
        $before = $this->snapshot($connection);

        $this->assertReason('eligibility_changed', fn () => $this->apply($connection, $manifest));

        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function changedOwnerEligibility(): array
    {
        return ['deactivated owner' => [['is_active' => false]], 'unsupported role' => [['role' => 'future-role']]];
    }

    #[DataProvider('mappedDrift')]
    public function test_existing_mapping_requires_exact_name_membership_and_event_sets_without_repair(string $drift): void
    {
        $connection = $this->fixture();
        $manifest = $this->manifest($connection);
        $this->apply($connection, $manifest);
        $workspace = $connection->table('workspaces')->sole();
        if ($drift === 'name') {
            $connection->table('workspaces')->where('id', $workspace->id)->update(['name' => 'Changed private name']);
        } elseif ($drift === 'member role') {
            $connection->table('workspace_users')->where('user_id', 211)->update(['role' => 'manager']);
        } elseif ($drift === 'active member') {
            $connection->table('workspace_users')->where('user_id', 211)->update(['is_active' => true]);
        } elseif ($drift === 'extra member') {
            $connection->table('workspace_users')->insert(['workspace_id' => $workspace->id, 'user_id' => 213, 'role' => 'owner', 'is_active' => false]);
        } elseif ($drift === 'missing member') {
            $connection->table('workspace_users')->where('user_id', 211)->delete();
        } elseif ($drift === 'unassigned event') {
            $connection->table('events')->where('id', 201)->update(['workspace_id' => null]);
        } elseif ($drift === 'reassigned event') {
            $connection->table('workspaces')->insert(['id' => 51, 'name' => 'Other owner']);
            $connection->table('events')->where('id', 201)->update(['workspace_id' => 51]);
        } elseif ($drift === 'extra event') {
            $connection->table('events')->insert(['id' => 999999, 'event_name' => 'Unexpected owned event', 'status' => 'archived', 'workspace_id' => $workspace->id]);
        } elseif ($drift === 'reference') {
            $connection->table('legacy_workspace_mappings')->update(['manifest_reference' => 'unknown-reference']);
        } elseif ($drift === 'unknown mapping') {
            $connection->table('workspaces')->insert(['id' => 51, 'name' => 'Unknown mapping']);
            $connection->table('legacy_workspace_mappings')->insert(['ownership_plan_id' => $connection->table('legacy_ownership_plans')->value('id'), 'manifest_reference' => 'unknown-reference', 'workspace_id' => 51]);
        } else {
            $connection->table('legacy_ownership_plans')->update(['schema_version' => 2]);
        }
        $before = $this->snapshot($connection);
        $writes = [];

        $this->withQueryListener($connection, function (QueryExecuted $query) use (&$writes): void {
            if ($this->isWrite($query->sql)) {
                $writes[] = $query->sql;
            }
        }, fn () => $this->assertReason('ledger_conflict', fn () => $this->apply($connection, $manifest)));

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function mappedDrift(): array
    {
        return array_map(fn (string $value): array => [$value], [
            'name', 'member role', 'active member', 'extra member', 'missing member',
            'unassigned event', 'reassigned event', 'extra event', 'reference', 'unknown mapping', 'plan version',
        ]);
    }

    #[DataProvider('writeStages')]
    public function test_failure_after_each_write_stage_rolls_back_the_complete_current_group(string $stage): void
    {
        $connection = $this->fixture();
        $manifest = $this->manifest($connection);
        $before = $this->snapshot($connection);
        $injected = false;
        Log::spy();

        $this->withQueryListener($connection, function (QueryExecuted $query) use ($stage, &$injected): void {
            if (! $injected && $this->writesStage($query->sql, $stage)) {
                $injected = true;
                throw new RuntimeException('Private injected driver credentials and manifest marker.');
            }
        }, fn () => $this->assertReason('database_unavailable', fn () => $this->apply($connection, $manifest)));

        $this->assertTrue($injected);
        $this->assertSame($this->withoutPlanState($before), $this->withoutPlanState($this->snapshot($connection)));
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame(0, $connection->table('legacy_workspace_mappings')->count());
        $this->assertSame(0, $connection->table('workspaces')->count());
        $this->assertSame(0, $connection->table('workspace_users')->count());
        $this->assertSame(0, $connection->table('events')->whereNotNull('workspace_id')->count());
        $this->assertNoLogs();

        $this->assertReport($this->apply($connection, $manifest), 1, 0, 2, 4);
    }

    public static function writeStages(): array
    {
        return ['workspace' => ['workspace'], 'membership' => ['membership'], 'event' => ['event'], 'mapping' => ['mapping']];
    }

    public function test_later_group_failure_retains_only_earlier_commit_and_same_proposal_retry_verifies_it(): void
    {
        $connection = $this->fixture();
        $manifest = $this->manifest($connection, $this->twoGroups());
        $mappingWrites = 0;
        $this->travelTo(now()->setDate(2026, 10, 18)->startOfDay());

        $this->withQueryListener($connection, function (QueryExecuted $query) use (&$mappingWrites): void {
            if ($this->writesStage($query->sql, 'mapping') && ++$mappingWrites === 2) {
                throw new RuntimeException('Private second-group failure marker.');
            }
        }, fn () => $this->assertReason('database_unavailable', fn () => $this->apply($connection, $manifest)));

        $this->assertSame(2, $mappingWrites);
        $this->assertSame(['alpha'], $connection->table('legacy_workspace_mappings')->pluck('manifest_reference')->all());
        $firstWorkspace = $connection->table('workspaces')->sole();
        $firstMembers = $connection->table('workspace_users')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $firstEvents = $connection->table('events')->where('workspace_id', $firstWorkspace->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $this->assertSame([1, 201, 203], array_column($firstEvents, 'id'));
        $this->assertNull($connection->table('events')->where('id', 202)->value('workspace_id'));
        $this->travelTo(now()->addDay());

        $retry = $this->apply($connection, $manifest);

        $this->assertReport($retry, 1, 1, 1, 1);
        $this->assertSame((array) $firstWorkspace, (array) $connection->table('workspaces')->find($firstWorkspace->id));
        $this->assertSame($firstMembers, $connection->table('workspace_users')->where('workspace_id', $firstWorkspace->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertSame($firstEvents, $connection->table('events')->where('workspace_id', $firstWorkspace->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertSame(1, $connection->table('legacy_ownership_plans')->count());
        $this->assertSame(2, $connection->table('legacy_workspace_mappings')->count());
        $this->assertSame([], $connection->select('PRAGMA foreign_key_check'));
    }

    public function test_original_query_exception_is_discarded_without_private_context_or_logs(): void
    {
        $connection = $this->fixture();
        $manifest = $this->manifest($connection);
        $before = $this->snapshot($connection);
        $injected = false;
        Log::spy();

        $this->withQueryListener($connection, function (QueryExecuted $query) use ($connection, &$injected): void {
            if (! $injected && $this->writesStage($query->sql, 'workspace')) {
                $injected = true;
                throw new QueryException($connection->getName(), 'insert into private_query_payload values (?)', ['private-query-secret'], new RuntimeException('private-driver-password-and-host'));
            }
        }, fn () => $this->assertReason('database_unavailable', fn () => $this->apply($connection, $manifest)));

        $this->assertTrue($injected);
        $this->assertSame($this->withoutPlanState($before), $this->withoutPlanState($this->snapshot($connection)));
        $this->assertNoLogs();
    }

    public function test_simulated_same_connection_new_event_after_mapping_is_a_final_coverage_conflict_with_committed_group_preserved(): void
    {
        // This query hook is deterministic interleaving, not separate-connection
        // concurrency or evidence of MySQL row/gap locking behavior.
        $connection = $this->fixture();
        $manifest = $this->manifest($connection);
        $injected = false;

        $this->withQueryListener($connection, function (QueryExecuted $query) use ($connection, &$injected): void {
            if (! $injected && $this->writesStage($query->sql, 'mapping')) {
                $injected = true;
                $connection->table('events')->insert(['id' => 999999, 'event_name' => 'Simulated new unowned event', 'status' => 'active']);
            }
        }, fn () => $this->assertReason('coverage_conflict', fn () => $this->apply($connection, $manifest)));

        $this->assertTrue($injected);
        $this->assertSame(1, $connection->table('legacy_workspace_mappings')->count());
        $this->assertSame(1, $connection->table('workspaces')->count());
        $this->assertSame(4, $connection->table('events')->whereNotNull('workspace_id')->count());
        $this->assertNull($connection->table('events')->where('id', 999999)->value('workspace_id'));
        $before = $this->snapshot($connection);

        $this->assertReason('coverage_conflict', fn () => $this->apply($connection, $manifest));

        $this->assertSame($before, $this->snapshot($connection));
    }

    public function test_simulated_in_transaction_owner_collision_cannot_be_overwritten_by_conditional_event_update(): void
    {
        // A same-transaction hook exercises the compare-and-set guard only.
        // It does not simulate independent committed database transactions.
        $connection = $this->fixture();
        $connection->table('workspaces')->insert(['id' => 51, 'name' => 'Existing unrelated owner']);
        $manifest = $this->manifest($connection);
        $before = $this->snapshot($connection);
        $injected = false;

        $this->withQueryListener($connection, function (QueryExecuted $query) use ($connection, &$injected): void {
            if (! $injected && $this->writesStage($query->sql, 'membership')) {
                $injected = true;
                $connection->table('events')->where('id', 201)->update(['workspace_id' => 51]);
            }
        }, fn () => $this->assertReason('ledger_conflict', fn () => $this->apply($connection, $manifest)));

        $this->assertTrue($injected);
        $this->assertSame($this->withoutPlanState($before), $this->withoutPlanState($this->snapshot($connection)));
    }

    #[DataProvider('silentMappingChanges')]
    public function test_simulated_silent_change_to_inserted_mapping_is_refused_before_group_commit(string $change): void
    {
        // A same-connection query hook changes an already executed INSERT.
        // This proves commit-time reconciliation, not real concurrent locking.
        $connection = $this->fixture();
        $connection->table('workspaces')->insert(['id' => 51, 'name' => 'Existing unrelated target']);
        $manifest = $this->manifest($connection);
        $before = $this->snapshot($connection);
        $injected = false;
        Log::spy();

        $this->withQueryListener($connection, function (QueryExecuted $query) use ($connection, $change, &$injected): void {
            if (! $injected && $this->writesStage($query->sql, 'mapping')) {
                $injected = true;
                if ($change === 'delete') {
                    $connection->table('legacy_workspace_mappings')->delete();
                } else {
                    $connection->table('legacy_workspace_mappings')->update(['workspace_id' => 51]);
                }
            }
        }, fn () => $this->assertReason('ledger_conflict', fn () => $this->apply($connection, $manifest)));

        $this->assertTrue($injected);
        $this->assertSame($this->withoutPlanState($before), $this->withoutPlanState($this->snapshot($connection)));
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame(0, $connection->table('legacy_workspace_mappings')->count());
        $this->assertSame(1, $connection->table('workspaces')->count());
        $this->assertSame(0, $connection->table('workspace_users')->count());
        $this->assertSame(0, $connection->table('events')->whereNotNull('workspace_id')->count());
        $this->assertNoLogs();
    }

    public static function silentMappingChanges(): array
    {
        return ['deleted mapping' => ['delete'], 'retargeted mapping' => ['retarget']];
    }

    public function test_selected_accounts_and_events_cross_batch_boundary_without_unbounded_id_queries(): void
    {
        $connection = $this->fixture();
        $users = [];
        $events = [];
        $password = $connection->table('users')->where('id', 211)->value('password');
        for ($offset = 0; $offset < 501; $offset++) {
            $id = 10000 + $offset;
            $users[] = ['id' => $id, 'name' => 'Batched private owner', 'email' => 'batch-'.$id.'@example.invalid', 'password' => $password, 'role' => 'admin', 'is_active' => true];
            $events[] = ['id' => 20000 + $offset, 'event_name' => 'Batched private event', 'status' => 'archived'];
        }
        foreach (array_chunk($users, 100) as $chunk) {
            $connection->table('users')->insert($chunk);
        }
        foreach (array_chunk($events, 100) as $chunk) {
            $connection->table('events')->insert($chunk);
        }
        $groups = $this->singleGroup($connection);
        $groups[0]['owner_user_ids'] = array_merge([211, 212], range(10000, 10500));
        $manifest = $this->manifest($connection, $groups);
        $before = $this->snapshot($connection);
        $selectedQueries = [];

        $this->withQueryListener($connection, function (QueryExecuted $query) use (&$selectedQueries): void {
            if (preg_match('/\b(?:from|update)\s+["`]?((?:users|events))["`]?\b/i', $query->sql) === 1
                && preg_match('/\bin\s*\(/i', $query->sql) === 1) {
                $selectedQueries[] = $query;
            }
        }, fn () => $this->assertReport($this->apply($connection, $manifest), 1, 0, 503, 505));

        $this->assertNotEmpty($selectedQueries);
        foreach ($selectedQueries as $query) {
            // Event UPDATE binds the target workspace as well as selected IDs.
            $maximum = preg_match('/^\s*update\b/i', $query->sql) === 1 ? 501 : 500;
            $this->assertLessThanOrEqual($maximum, count($query->bindings), $query->sql);
        }
        $this->assertSame(503, $connection->table('workspace_users')->count());
        $this->assertSame(505, $connection->table('events')->whereNotNull('workspace_id')->count());
        $this->assertConservation($before, $this->snapshot($connection));
    }

    public function test_ambient_transaction_refuses_without_any_write_or_nested_commit(): void
    {
        $connection = $this->fixture();
        $manifest = $this->manifest($connection);
        $before = $this->snapshot($connection);
        $connection->beginTransaction();
        try {
            $this->assertReason('invalid_prerequisites', fn () => $this->apply($connection, $manifest));
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertSame($before, $this->snapshot($connection));
        } finally {
            $connection->rollBack();
        }
        $this->assertSame($before, $this->snapshot($connection));
    }

    #[DataProvider('overflowAllocators')]
    public function test_simulated_raw_mysql_insert_id_overflow_is_rejected_before_clamped_identity_is_trusted(string $table): void
    {
        // This uses Laravel's actual MySqlProcessor over the independent SQLite
        // fixture. Only the raw last-insert ID is simulated; engine metadata is
        // bypassed after proving the real SQLite fixture prerequisites. It is
        // not evidence of MySQL storage, unsigned allocation or row locking.
        $base = $this->fixture();
        app(OwnershipBackfillSchema::class)->assertReady($base);
        $manifest = $this->manifest($base);
        $before = $this->snapshot($base);
        $connection = new class($base->getPdo(), ':memory:', '', ['driver' => 'mysql', 'name' => 'backfill_raw_id_simulation']) extends MySqlConnection
        {
            public string $overflowTable = '';

            public int $overflowReads = 0;

            private bool $lastInsertIsTarget = false;

            public function insert($query, $bindings = [], $sequence = null)
            {
                $this->lastInsertIsTarget = preg_match('/^\s*insert\s+into\s+["`]?'.preg_quote($this->overflowTable, '/').'["`]?\s/i', $query) === 1;

                return parent::insert($query, $bindings, $sequence);
            }

            public function getLastInsertId()
            {
                if ($this->lastInsertIsTarget) {
                    $this->overflowReads++;

                    return (string) PHP_INT_MAX.'0';
                }

                return parent::getLastInsertId();
            }
        };
        $connection->overflowTable = $table;
        $connection->setQueryGrammar(new SQLiteGrammar($connection));
        $connection->setSchemaGrammar(new SQLiteSchemaGrammar($connection));
        $this->assertInstanceOf(MySqlProcessor::class, $connection->getPostProcessor());
        $schema = new class extends OwnershipBackfillSchema
        {
            public function assertReady(Connection $connection): void {}
        };
        $service = new LegacyWorkspaceBackfill($schema);
        Log::spy();

        $this->assertReason('invalid_prerequisites', fn () => $service->apply($connection, $manifest));

        // The processor consumes the simulated numeric raw ID once, then the
        // service must read it again rather than trust the processor's int cast.
        $this->assertSame(2, $connection->overflowReads);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($base->getPdo()->inTransaction());
        if ($table === 'legacy_ownership_plans') {
            $this->assertSame($before, $this->snapshot($base));
            $this->assertSame(0, $base->table('legacy_ownership_plans')->count());
        } else {
            $this->assertSame($this->withoutPlanState($before), $this->withoutPlanState($this->snapshot($base)));
            $this->assertSame(1, $base->table('legacy_ownership_plans')->count());
        }
        $this->assertSame(0, $base->table('workspaces')->count());
        $this->assertSame(0, $base->table('workspace_users')->count());
        $this->assertSame(0, $base->table('legacy_workspace_mappings')->count());
        $this->assertSame(0, $base->table('events')->whereNotNull('workspace_id')->count());
        $this->assertNoLogs();
    }

    public static function overflowAllocators(): array
    {
        return ['plan allocation' => ['legacy_ownership_plans'], 'workspace allocation' => ['workspaces']];
    }

    private function apply(Connection $connection, OwnershipManifest $manifest): array
    {
        return app(LegacyWorkspaceBackfill::class)->apply($connection, $manifest);
    }

    private function assertReport(array $report, int $created, int $verified, int $memberships, int $events): void
    {
        $this->assertSame([
            'schema_version' => 1,
            'status' => 'complete',
            'workspaces_created' => $created,
            'workspaces_verified' => $verified,
            'memberships_created' => $memberships,
            'events_assigned' => $events,
            'platform_grants' => 0,
            'rollout_ready' => false,
            'gates' => [
                'ownership_mapping' => 'unverified', 'platform_grants' => 'unverified',
                'backup_restore' => 'unverified', 'mysql_rehearsal' => 'unverified',
            ],
        ], $report);
        $output = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['Private workspace', 'private-reference', 'manifest_hash', 'owner_user_ids', 'private-query', 'example.invalid'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
    }

    private function assertReason(string $reason, callable $operation): void
    {
        $exception = null;
        try {
            $operation();
        } catch (OwnershipBackfillException $caught) {
            $exception = $caught;
        }
        $this->assertNotNull($exception, 'Expected a fixed safe backfill refusal.');
        $this->assertSame($reason, $exception->reason());
        $this->assertNull($exception->getPrevious());
        foreach (['Private', 'private', 'example.invalid', '211', '212', '201', 'password', 'insert into'] as $private) {
            $this->assertStringNotContainsString($private, $exception->getMessage());
        }
    }

    private function assertNoLogs(): void
    {
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    private function withQueryListener(Connection $connection, callable $listener, callable $operation): mixed
    {
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, $listener);
        $connection->setEventDispatcher($dispatcher);
        try {
            return $operation();
        } finally {
            if ($original !== null) {
                $connection->setEventDispatcher($original);
            } else {
                $connection->unsetEventDispatcher();
            }
        }
    }

    private function isWrite(string $sql): bool
    {
        return preg_match('/^\s*(?:insert|update|delete|create|alter|drop|replace|truncate)\b/i', $sql) === 1;
    }

    private function writesStage(string $sql, string $stage): bool
    {
        $tables = ['workspace' => 'workspaces', 'membership' => 'workspace_users', 'mapping' => 'legacy_workspace_mappings'];
        if ($stage === 'event') {
            return preg_match('/^\s*update\s+["`]?events["`]?\s/i', $sql) === 1;
        }

        return preg_match('/^\s*insert\s+into\s+["`]?'.$tables[$stage].'["`]?\s/i', $sql) === 1;
    }

    private function singleGroup(Connection $connection): array
    {
        return [[
            'reference' => 'private-reference', 'name' => 'Private workspace / Arusi ya Asha',
            'owner_user_ids' => [211, 212], 'event_ids' => $connection->table('events')->orderBy('id')->pluck('id')->all(),
        ]];
    }

    private function twoGroups(): array
    {
        return [
            ['reference' => 'alpha', 'name' => 'Private first workspace', 'owner_user_ids' => [211, 212], 'event_ids' => [1, 201, 203]],
            ['reference' => 'zeta', 'name' => 'Private second workspace', 'owner_user_ids' => [212], 'event_ids' => [202]],
        ];
    }

    private function manifest(Connection $connection, ?array $groups = null, array $platformIds = []): OwnershipManifest
    {
        return OwnershipManifest::fromJson(json_encode([
            'schema_version' => 1, 'workspaces' => $groups ?? $this->singleGroup($connection),
            'platform_admin_user_ids' => $platformIds,
        ], JSON_THROW_ON_ERROR));
    }

    private function fixture(): Connection
    {
        $connection = $this->isolatedSchema();
        $timestamp = '2026-09-01 10:15:00';
        $connection->table('events')->insert([
            ['id' => 201, 'event_name' => 'Private active event', 'status' => 'active', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['id' => 202, 'event_name' => 'Private archived event', 'status' => 'archived', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['id' => 203, 'event_name' => 'Private cancelled event', 'status' => 'cancelled', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ]);
        $password = Hash::make('private legacy password');
        $connection->table('users')->insert([
            ['id' => 211, 'name' => 'Private legacy scanner', 'email' => 'private-scanner@example.invalid', 'password' => $password, 'remember_token' => 'private-remember-marker', 'role' => 'scanner', 'event_id' => 201, 'gate_name' => 'Legacy gate', 'is_active' => true, 'is_platform_admin' => false, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['id' => 212, 'name' => 'Private legacy admin', 'email' => 'private-admin@example.invalid', 'password' => $password, 'remember_token' => null, 'role' => 'admin', 'event_id' => null, 'gate_name' => null, 'is_active' => true, 'is_platform_admin' => false, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['id' => 213, 'name' => 'Unlisted platform admin', 'email' => 'private-unlisted@example.invalid', 'password' => $password, 'remember_token' => null, 'role' => 'admin', 'event_id' => null, 'gate_name' => null, 'is_active' => true, 'is_platform_admin' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ]);
        $connection->table('guests')->insert(['id' => 301, 'event_id' => 201, 'name' => 'Private legacy guest', 'phone_number' => '+255712345679', 'manual_code' => '0042', 'pass_type' => 'special', 'allowed_entries' => 6, 'used_entries' => 2, 'status' => 'partially_used']);
        $connection->table('qr_codes')->insert(['id' => 401, 'guest_id' => 301, 'qr_token' => str_repeat('a', 64), 'qr_image_path' => 'private/legacy-qr.png']);
        $connection->table('admissions')->insert(['id' => 501, 'event_id' => 201, 'guest_id' => 301, 'quantity' => 2, 'admitted_by' => 'Private legacy scanner', 'admitted_at' => $timestamp]);
        $connection->table('checkins')->insert(['id' => 601, 'event_id' => 201, 'guest_id' => 301, 'qr_code_id' => 401, 'user_id' => 211, 'scan_result' => 'allowed', 'entries_added' => 2, 'used_entries_after_scan' => 2, 'remaining_entries_after_scan' => 4]);
        $connection->table('offline_scanner_sessions')->insert(['id' => 701, 'session_uuid' => '2bdc3388-28ab-471b-92a5-90c8fdc67aa1', 'event_id' => 201, 'created_by_user_id' => 212, 'master_scanner_user_id' => 211, 'master_gate_name' => 'Legacy gate', 'status' => 'draft', 'notes' => 'Private offline provenance', 'session_bootstrap_secret_encrypted' => 'private-encrypted-marker']);
        $connection->table('offline_scanner_session_users')->insert(['id' => 801, 'offline_scanner_session_id' => 701, 'scanner_user_id' => 211, 'assigned_gate' => 'Legacy gate', 'device_role' => 'master']);

        return $connection;
    }

    private function isolatedSchema(): Connection
    {
        $name = 'legacy_backfill_'.count($this->ownedConnections);
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        $this->ownedConnections[] = $name;
        $connection = DB::connection($name);
        $source = DB::connection('sqlite');
        $objects = $source->select("SELECT type, name, sql FROM sqlite_master WHERE type IN ('table', 'index') AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name");
        foreach ($objects as $object) {
            $connection->statement($object->sql);
        }
        foreach ($objects as $object) {
            if ($object->type === 'table') {
                foreach ($source->table($object->name)->get() as $row) {
                    $connection->table($object->name)->insert((array) $row);
                }
            }
        }
        $connection->getSchemaBuilder()->enableForeignKeyConstraints();
        $this->assertSame(0, $connection->transactionLevel());

        return $connection;
    }

    private function assertConservation(array $before, array $after): void
    {
        foreach (self::INSERTED_TABLES as $table) {
            unset($before['rows'][$table], $after['rows'][$table]);
        }
        foreach (['before', 'after'] as $variable) {
            foreach (${$variable}['rows']['events'] as &$event) {
                unset($event['workspace_id']);
            }
            unset($event);
            ${$variable}['rows']['sqlite_sequence'] = array_values(array_filter(
                ${$variable}['rows']['sqlite_sequence'], fn (array $row): bool => ! in_array($row['name'], self::INSERTED_TABLES, true),
            ));
        }
        $this->assertSame($before, $after);
    }

    private function withoutPlanState(array $snapshot): array
    {
        $snapshot['rows']['legacy_ownership_plans'] = [];
        $snapshot['rows']['sqlite_sequence'] = array_values(array_filter(
            $snapshot['rows']['sqlite_sequence'], fn (array $row): bool => $row['name'] !== 'legacy_ownership_plans',
        ));

        return $snapshot;
    }

    private function snapshot(Connection $connection): array
    {
        $rows = [];
        foreach ($connection->select("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $table) {
            $contents = $connection->table($table->name)->get()->map(fn (object $row): array => (array) $row)->all();
            usort($contents, fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));
            $rows[$table->name] = $contents;
        }

        return [
            'schema' => array_map(fn (object $row): array => (array) $row, $connection->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')),
            'rows' => $rows,
        ];
    }
}
