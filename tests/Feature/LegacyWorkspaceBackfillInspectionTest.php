<?php

namespace Tests\Feature;

use App\Services\Tenancy\LegacyWorkspaceBackfill;
use App\Services\Tenancy\LegacyWorkspaceBackfillInspection;
use App\Services\Tenancy\OwnershipBackfillException;
use App\Services\Tenancy\OwnershipManifest;
use App\Services\Tenancy\OwnershipPlanIdentity;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LegacyWorkspaceBackfillInspectionTest extends TestCase
{
    use RefreshDatabase;

    private array $ownedConnections = [];

    protected function tearDown(): void
    {
        foreach ($this->ownedConnections as $name) {
            DB::purge($name);
        }
        parent::tearDown();
    }

    #[DataProvider('progressStates')]
    public function test_exact_progress_is_repeatable_private_and_read_only(string $state, int $verified, int $assigned): void
    {
        $connection = $this->fixture();
        $manifest = $this->manifest($connection, $this->twoGroups());
        if ($state === 'plan only') {
            $connection->table('legacy_ownership_plans')->insert([
                'schema_version' => 1, 'manifest_hash' => OwnershipPlanIdentity::fromManifest($manifest)->digest(),
                'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
            ]);
        } elseif ($state === 'partial') {
            $writes = 0;
            $this->withQueryListener($connection, function (QueryExecuted $query) use (&$writes): void {
                if (preg_match('/^insert into ["\x60]?legacy_workspace_mappings["\x60]? /i', $query->sql) === 1 && ++$writes === 2) {
                    throw new RuntimeException('Private synthetic later group failure.');
                }
            }, fn () => $this->assertReason('database_unavailable', fn () => $this->apply($connection, $manifest)));
            $this->assertSame(1, $connection->table('legacy_workspace_mappings')->count());
        } elseif ($state === 'exact') {
            $this->apply($connection, $manifest);
        }
        $progress = $state === 'plan only' ? 'unstarted' : $state;
        $reordered = array_reverse($this->twoGroups());
        foreach ($reordered as &$group) {
            $group['event_ids'] = array_reverse($group['event_ids']);
            $group['owner_user_ids'] = array_reverse($group['owner_user_ids']);
        }
        unset($group);
        $first = $this->inspect($connection, $manifest);
        $again = $this->inspect($connection, $this->manifest($connection, $reordered));
        $this->assertSame($first, $again);
        $this->assertSame([
            'schema_version' => 1, 'status' => 'complete', 'mode' => 'read_only', 'progress' => $progress,
            'proposal' => ['workspaces' => 2, 'owner_memberships' => 3, 'events' => 4],
            'observed' => ['plan_present' => $state !== 'unstarted', 'workspaces_verified' => $verified,
                'workspaces_remaining' => 2 - $verified, 'events_assigned' => $assigned, 'events_remaining' => 4 - $assigned],
            'rollout_ready' => false,
            'gates' => ['ownership_mapping' => 'unverified', 'platform_grants' => 'unverified',
                'backup_restore' => 'unverified', 'mysql_rehearsal' => 'unverified'],
        ], $first);
        $output = json_encode($first, JSON_THROW_ON_ERROR);
        foreach (['Private', 'private-', 'example.invalid', 'manifest_hash', 'owner_user_ids', 'event_ids',
            '211', '212', '201', OwnershipPlanIdentity::fromManifest($manifest)->digest()] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
    }

    public static function progressStates(): array
    {
        return ['unstarted' => ['unstarted', 0, 0], 'retained empty plan' => ['plan only', 0, 0],
            'partial commit' => ['partial', 1, 3], 'completed exact' => ['exact', 2, 4]];
    }

    public function test_empty_proposal_is_exact_without_a_required_plan(): void
    {
        $connection = $this->isolatedSchema();
        $connection->table('events')->delete();
        $report = $this->inspect($connection, $this->manifest($connection, []));
        $this->assertSame('exact', $report['progress']);
        $this->assertSame(['workspaces' => 0, 'owner_memberships' => 0, 'events' => 0], $report['proposal']);
        $this->assertSame(['plan_present' => false, 'workspaces_verified' => 0, 'workspaces_remaining' => 0,
            'events_assigned' => 0, 'events_remaining' => 0], $report['observed']);
    }

    public function test_schema_and_enforcement_refusals_are_read_only(): void
    {
        $connection = $this->fixture();
        $connection->getSchemaBuilder()->disableForeignKeyConstraints();
        $this->assertReason('invalid_prerequisites', fn () => $this->inspect($connection, $this->manifest($connection)));
        $connection->getSchemaBuilder()->enableForeignKeyConstraints();
        $connection->getSchemaBuilder()->drop('legacy_workspace_mappings');
        $this->assertReason('invalid_prerequisites', fn () => $this->inspect($connection, $this->manifest($connection)));
    }

    public function test_ambient_transaction_is_not_finished_or_changed(): void
    {
        $connection = $this->fixture();
        $connection->beginTransaction();
        try {
            $this->assertReason('invalid_prerequisites', fn () => $this->inspect($connection, $this->manifest($connection)));
            $this->assertSame(1, $connection->transactionLevel());
        } finally {
            $connection->rollBack();
        }
    }

    public function test_original_query_failure_is_discarded_without_previous_context_or_logs(): void
    {
        $connection = $this->fixture();
        $this->withQueryListener($connection, function (QueryExecuted $q): void {
            if (str_contains($q->sql, 'manifest_hash')) {
                throw new RuntimeException('Private SQL password@example.invalid marker');
            }
        }, fn () => $this->assertReason('database_unavailable', fn () => $this->inspect($connection, $this->manifest($connection))));
    }

    public function test_selected_ids_cross_batches_without_losing_exact_counts(): void
    {
        $connection = $this->fixture();
        $password = $connection->table('users')->where('id', 211)->value('password');
        $owners = [211, 212];
        $events = [1, 201, 202, 203];
        for ($i = 0; $i < 501; $i++) {
            $owners[] = $uid = 1000 + $i;
            $events[] = $eid = 2000 + $i;
            $connection->table('users')->insert(['id' => $uid, 'name' => 'Private batch owner',
                'email' => 'private-batch-'.$i.'@example.invalid', 'password' => $password, 'role' => 'admin', 'is_active' => true]);
            $connection->table('events')->insert(['id' => $eid, 'event_name' => 'Private batch event', 'status' => 'archived']);
        }
        $manifest = $this->manifest($connection, [['reference' => 'private-batch', 'name' => 'Private batch workspace',
            'owner_user_ids' => $owners, 'event_ids' => $events]]);
        $queries = [];
        $report = $this->withQueryListener($connection, function (QueryExecuted $q) use (&$queries): void {
            if (preg_match('/where ["\x60]?id["\x60]? in /i', $q->sql)) {
                $queries[] = count($q->bindings);
            }
        }, fn () => $this->inspect($connection, $manifest));
        $this->assertSame(['workspaces' => 1, 'owner_memberships' => 503, 'events' => 505], $report['proposal']);
        $this->assertSame(505, $report['observed']['events_remaining']);
        $this->assertContains(500, $queries);
        $this->assertLessThanOrEqual(500, max($queries));
    }

    private function inspect(Connection $connection, OwnershipManifest $manifest): array
    {
        $before = $this->snapshot($connection);
        $default = DB::getDefaultConnection();
        $primaryBefore = $this->snapshot(DB::connection('sqlite'));
        $level = $connection->transactionLevel();
        $queries = $transactions = [];
        $dispatcher = $connection->getEventDispatcher();
        $queryCallback = function (QueryExecuted $q) use (&$queries): void {
            $queries[] = $q->sql;
        };
        // Keep any outer injected listener active and observe all transaction events.
        $local = new Dispatcher($this->app);
        $local->listen(QueryExecuted::class, function (QueryExecuted $q) use ($dispatcher, $queryCallback): void {
            $queryCallback($q);
            $dispatcher?->dispatch($q);
        });
        foreach ([TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class] as $event) {
            $local->listen($event, function ($e) use (&$transactions): void {
                $transactions[] = get_class($e);
            });
        }
        $connection->setEventDispatcher($local);
        DB::setDefaultConnection('private-unconfigured-default');
        Log::spy();
        $writer = \Mockery::mock(LegacyWorkspaceBackfill::class);
        $writer->shouldNotReceive('apply');
        $this->app->instance(LegacyWorkspaceBackfill::class, $writer);
        try {
            return app(LegacyWorkspaceBackfillInspection::class)->inspect($connection, $manifest);
        } finally {
            $this->assertSame('private-unconfigured-default', DB::getDefaultConnection());
            $this->assertSame($level, $connection->transactionLevel());
            $this->assertSame([], $transactions);
            foreach ($queries as $sql) {
                $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace|create|alter|drop|truncate|attach|detach|vacuum|begin|commit|rollback|lock)\b/i', $sql);
            }
            DB::setDefaultConnection($default);
            $connection->setEventDispatcher($dispatcher);
            $this->app->forgetInstance(LegacyWorkspaceBackfill::class);
            $this->assertSame($before, $this->snapshot($connection));
            $this->assertSame($primaryBefore, $this->snapshot(DB::connection('sqlite')));
            $this->assertNoLogs();
        }
    }

    // Isolated legacy fixture and selected conflict cases appended by staging script.

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

    private function assertNoLogs(): void
    {
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $method) {
            Log::shouldNotHaveReceived($method);
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

    private function apply(Connection $connection, OwnershipManifest $manifest): array
    {
        return app(LegacyWorkspaceBackfill::class)->apply($connection, $manifest);
    }

    private function isWrite(string $sql): bool
    {
        return preg_match('/^\s*(?:insert|update|delete|create|alter|drop|replace|truncate)\b/i', $sql) === 1;
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

    public static function mappedDrift(): array
    {
        return array_map(fn (string $value): array => [$value], [
            'name', 'member role', 'active member', 'extra member', 'missing member',
            'unassigned event', 'reassigned event', 'extra event', 'reference', 'unknown mapping', 'plan version',
        ]);
    }

    public static function changedOwnerEligibility(): array
    {
        return ['deactivated owner' => [['is_active' => false]], 'unsupported role' => [['role' => 'future-role']]];
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
        }, fn () => $this->assertReason($reason, fn () => $this->inspect($connection, $manifest)));

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertNoLogs();
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
        }, fn () => $this->assertReason('ledger_conflict', fn () => $this->inspect($connection, $manifest)));

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public function test_changed_proposal_never_adopts_an_existing_workspace_by_name_or_previous_event_ownership(): void
    {
        $connection = $this->fixture();
        $this->apply($connection, $this->manifest($connection));
        $groups = $this->singleGroup($connection);
        $groups[0]['name'] .= ' changed';
        $before = $this->snapshot($connection);

        $this->assertReason('ledger_conflict', fn () => $this->inspect($connection, $this->manifest($connection, $groups)));

        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame(1, $connection->table('legacy_ownership_plans')->count());
    }

    #[DataProvider('changedOwnerEligibility')]
    public function test_same_proposal_retry_rechecks_current_owner_eligibility_without_rewriting_committed_state(array $change): void
    {
        $connection = $this->fixture();
        $manifest = $this->manifest($connection);
        $this->apply($connection, $manifest);
        $connection->table('users')->where('id', 211)->update($change);
        $before = $this->snapshot($connection);

        $this->assertReason('eligibility_changed', fn () => $this->inspect($connection, $manifest));

        $this->assertSame($before, $this->snapshot($connection));
    }
}
