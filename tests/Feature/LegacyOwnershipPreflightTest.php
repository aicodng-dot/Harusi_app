<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tenancy\LegacyOwnershipPreflight;
use App\Services\Tenancy\OwnershipManifest;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyOwnershipPreflightTest extends TestCase
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

    public function test_complete_explicit_proposal_is_repeatable_read_only_private_and_never_certifies_rollout(): void
    {
        $event = Event::factory()->create(['id' => 877701, 'event_name' => 'private-event-name-marker']);
        $owner = User::factory()->create([
            'id' => 877702, 'name' => 'private-owner-name-marker', 'email' => 'private-owner@example.invalid',
            'phone_number' => '+255712345678', 'role' => 'scanner', 'event_id' => $event->id,
            'remember_token' => 'private-remember-marker',
        ]);
        $platform = User::factory()->create(['id' => 877703, 'role' => 'admin']);
        $unlisted = User::factory()->create(['id' => 877704, 'role' => 'admin']);
        DB::table('users')->where('id', $unlisted->id)->update(['is_platform_admin' => true]);
        $manifest = $this->manifest([
            $this->group([$owner->id], $this->unownedEventIds()),
        ], [$platform->id]);
        $connection = DB::connection('sqlite');
        $before = $this->snapshot($connection);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $first = $this->inspect($connection, $manifest);
        $second = $this->inspect($connection, $manifest);

        $this->assertSame($first, $second);
        $this->assertSame('complete', $first['status']);
        $this->assertSame([], $first['missing_schema']);
        $this->assertSame([], $first['findings']);
        $this->assertSame(['workspaces' => 1, 'owner_memberships' => 1, 'events' => 2, 'platform_admins' => 1], $first['proposal']);
        $this->assertSame(['unowned_events' => 2, 'mapped_unowned_events' => 2], $first['coverage']);
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace|create|alter|drop|truncate|attach|detach|vacuum)\b/i', $query);
        }
        $this->assertSame($before, $this->snapshot($connection));
        $encoded = json_encode($first, JSON_THROW_ON_ERROR);
        foreach ([
            '877701', '877702', '877703', '877704', 'private-event-name-marker', 'private-owner-name-marker',
            'private-owner@example.invalid', '+255712345678', 'private-remember-marker', $owner->password,
            'private_workspace_reference', 'Private proposed workspace marker',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $encoded);
        }
        $this->assertSame(0, DB::table('workspaces')->count());
        $this->assertSame(0, DB::table('workspace_users')->count());
        $this->assertSame(0, DB::table('users')->where('id', $platform->id)->value('is_platform_admin'));
        $this->assertSame(1, DB::table('users')->where('id', $unlisted->id)->value('is_platform_admin'));
    }

    public function test_account_findings_count_distinct_ids_while_membership_counts_keep_each_workspace_occurrence(): void
    {
        Event::factory()->create();
        $events = $this->unownedEventIds();
        $scanner = User::factory()->create(['role' => 'scanner', 'event_id' => $events[0]]);
        $inactive = User::factory()->create(['role' => 'admin']);
        $unsupported = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $inactive->id)->update(['is_active' => 2, 'is_platform_admin' => true]);
        DB::table('users')->where('id', $unsupported->id)->update(['role' => 'ADMIN']);
        $manifest = $this->manifest([
            $this->group([$scanner->id, $inactive->id, $unsupported->id, 959595], [$events[0]]),
            $this->group([$inactive->id], array_slice($events, 1), 'second_workspace'),
        ], [$scanner->id, $inactive->id, $unsupported->id, 959595]);
        $connection = DB::connection('sqlite');
        $before = $this->snapshot($connection);

        $report = $this->inspect($connection, $manifest);

        $this->assertSame('complete', $report['status']);
        $this->assertSame(['workspaces' => 2, 'owner_memberships' => 5, 'events' => 2, 'platform_admins' => 4], $report['proposal']);
        $this->assertSame([
            'owners_inactive' => ['severity' => 'blocker', 'count' => 1],
            'owners_missing' => ['severity' => 'blocker', 'count' => 1],
            'owners_unsupported_role' => ['severity' => 'blocker', 'count' => 1],
            'platform_admins_already_granted' => ['severity' => 'review', 'count' => 1],
            'platform_admins_inactive' => ['severity' => 'blocker', 'count' => 1],
            'platform_admins_missing' => ['severity' => 'blocker', 'count' => 1],
            'platform_admins_unsupported_role' => ['severity' => 'blocker', 'count' => 2],
        ], $report['findings']);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public function test_default_archived_and_cancelled_events_require_explicit_coverage_and_owned_events_cannot_be_remapped(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $default = DB::table('events')->value('id');
        Event::factory()->create(['status' => 'archived']);
        Event::factory()->create(['status' => 'cancelled']);
        $workspace = Workspace::query()->create(['name' => 'Private existing workspace marker']);
        $owned = Event::factory()->create(['workspace_id' => $workspace->id]);
        $manifest = $this->manifest([$this->group([$owner->id], [$default, $owned->id, 959595])]);
        $connection = DB::connection('sqlite');
        $before = $this->snapshot($connection);

        $report = $this->inspect($connection, $manifest);

        $this->assertSame(['unowned_events' => 3, 'mapped_unowned_events' => 1], $report['coverage']);
        $this->assertSame([
            'events_already_owned' => ['severity' => 'blocker', 'count' => 1],
            'events_missing' => ['severity' => 'blocker', 'count' => 1],
            'unowned_events_unmapped' => ['severity' => 'blocker', 'count' => 2],
        ], $report['findings']);
        $this->assertSame(0, $report['proposal']['platform_admins']);
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertStringNotContainsString('Private existing workspace marker', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_omitting_the_default_event_does_not_create_or_infer_a_catch_all_workspace(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $selected = Event::factory()->create();
        $manifest = $this->manifest([$this->group([$owner->id], [$selected->id])]);
        $connection = DB::connection('sqlite');
        $before = $this->snapshot($connection);

        $report = $this->inspect($connection, $manifest);

        $this->assertSame(['unowned_events' => 2, 'mapped_unowned_events' => 1], $report['coverage']);
        $this->assertSame(['unowned_events_unmapped' => ['severity' => 'blocker', 'count' => 1]], $report['findings']);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public function test_requested_existing_platform_flags_are_review_only_and_do_not_change_unlisted_accounts(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $requested = User::factory()->create(['role' => 'admin']);
        $unlisted = User::factory()->create(['role' => 'admin']);
        DB::table('users')->whereIn('id', [$requested->id, $unlisted->id])->update(['is_platform_admin' => true]);
        $manifest = $this->manifest([$this->group([$owner->id], $this->unownedEventIds())], [$requested->id]);
        $connection = DB::connection('sqlite');
        $before = $this->snapshot($connection);

        $report = $this->inspect($connection, $manifest);

        $this->assertSame(['platform_admins_already_granted' => ['severity' => 'review', 'count' => 1]], $report['findings']);
        $this->assertSame(1, $report['proposal']['platform_admins']);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public function test_missing_schema_reports_only_known_gaps_without_using_or_changing_the_default_connection(): void
    {
        $source = DB::connection('sqlite');
        $beforeSource = $this->snapshot($source);
        $connection = $this->newConnection();
        $connection->getSchemaBuilder()->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('private_extra_column');
        });
        $connection->table('users')->insert(['private_extra_column' => 'private-database-value-marker']);
        $before = $this->snapshot($connection);
        $manifest = $this->manifest([$this->group([877702], [877701])]);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('private-unconfigured-default-marker');
        try {
            $report = $this->inspect($connection, $manifest);
            $this->assertSame('private-unconfigured-default-marker', DB::getDefaultConnection());
        } finally {
            DB::setDefaultConnection($default);
        }

        $this->assertSame('incomplete', $report['status']);
        $this->assertSame([
            'users' => ['role', 'is_active', 'is_platform_admin'],
            'events' => ['id', 'workspace_id'],
            'workspaces' => ['id', 'name'],
            'workspace_users' => ['id', 'workspace_id', 'user_id', 'role', 'is_active'],
        ], $report['missing_schema']);
        $this->assertSame(['unowned_events' => null, 'mapped_unowned_events' => null], $report['coverage']);
        $this->assertSame([], $report['findings']);
        foreach (['private_extra_column', 'private-database-value-marker', 'private-unconfigured-default-marker', '877701', '877702'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, json_encode($report, JSON_THROW_ON_ERROR));
        }
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame($beforeSource, $this->snapshot($source));
    }

    #[DataProvider('unsafePrimaryKeys')]
    public function test_unsafe_parent_keys_make_the_report_incomplete_before_any_selected_id_lookup(string $tableName, string $mode): void
    {
        $connection = $this->minimalSchema($tableName, $mode);
        if ($mode === 'nullable primary') {
            $connection->table($tableName)->insert($tableName === 'users'
                ? ['id' => null, 'role' => 'admin', 'is_active' => true, 'is_platform_admin' => false]
                : ['id' => null, 'workspace_id' => null]);
        }
        $before = $this->snapshot($connection);
        $lookups = [];
        DB::listen(function (QueryExecuted $query) use (&$lookups, $connection): void {
            if ($query->connectionName === $connection->getName() && str_contains($query->sql, 'where "id" in (')) {
                $lookups[] = $query->sql;
            }
        });

        $report = $this->inspect($connection, $this->manifest([$this->group([1], [1])]));

        $this->assertSame('incomplete', $report['status']);
        $this->assertSame([], $report['missing_schema']);
        $this->assertSame([$tableName.'_invalid_primary_key' => ['severity' => 'blocker', 'count' => 1]], $report['findings']);
        $this->assertSame(['unowned_events' => null, 'mapped_unowned_events' => null], $report['coverage']);
        $this->assertSame([], $lookups);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function unsafePrimaryKeys(): array
    {
        $cases = [];
        foreach (['users', 'events'] as $table) {
            foreach (['unique without primary', 'composite primary', 'nullable primary'] as $mode) {
                $cases[$table.' '.$mode] = [$table, $mode];
            }
        }

        return $cases;
    }

    public function test_selected_accounts_and_events_beyond_one_batch_are_checked_with_at_most_500_bindings(): void
    {
        $ownerIds = [];
        $rows = [];
        for ($index = 1; $index <= 512; $index++) {
            $ownerIds[] = 100000 + $index;
            $rows[] = [
                'id' => 100000 + $index, 'name' => 'Private batch account', 'email' => 'batch-'.$index.'@example.invalid',
                'password' => 'private-batch-password-hash-marker', 'role' => $index === 512 ? 'unsupported' : 'admin', 'is_active' => true,
            ];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('users')->insert($batch);
        }
        $rows = [];
        for ($index = 1; $index <= 511; $index++) {
            $rows[] = ['id' => 200000 + $index, 'event_name' => 'Private batch event', 'status' => 'active'];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('events')->insert($batch);
        }
        $workspace = Workspace::query()->create(['name' => 'Private owned batch event']);
        DB::table('events')->where('id', 200511)->update(['workspace_id' => $workspace->id]);
        $eventIds = DB::table('events')->orderBy('id')->pluck('id')->all();
        $this->assertCount(512, $eventIds);
        $manifest = $this->manifest([$this->group($ownerIds, $eventIds)]);
        $connection = DB::connection('sqlite');
        $before = $this->snapshot($connection);
        $bindings = [];
        DB::listen(function (QueryExecuted $query) use (&$bindings): void {
            if (str_contains($query->sql, 'where "id" in (')) {
                $bindings[] = count($query->bindings);
            }
        });

        $report = $this->inspect($connection, $manifest);

        $this->assertSame([
            'events_already_owned' => ['severity' => 'blocker', 'count' => 1],
            'owners_unsupported_role' => ['severity' => 'blocker', 'count' => 1],
        ], $report['findings']);
        $this->assertSame(['unowned_events' => 511, 'mapped_unowned_events' => 511], $report['coverage']);
        $this->assertSame([500, 12, 500, 12], $bindings);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public function test_a_changed_observation_cannot_be_reported_as_a_clean_zero_uncovered_count(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        DB::table('events')->delete();
        $manifest = $this->manifest([$this->group([$owner->id], [900001])]);
        $connection = DB::connection('sqlite');
        $armed = true;
        // Emulate another writer between the aggregate observation and the
        // selected-event read. This is an isolated fixture injection, not a
        // claim about real-engine concurrency or a write by the service.
        DB::listen(function (QueryExecuted $query) use (&$armed, $connection): void {
            if ($armed && $query->connectionName === $connection->getName()
                && str_contains($query->sql, 'count(*)') && str_contains($query->sql, 'from "events"')) {
                $armed = false;
                $connection->table('events')->insert(['id' => 900001, 'event_name' => 'Private concurrent event marker', 'status' => 'active']);
            }
        });

        $report = $this->inspect($connection, $manifest);

        $this->assertFalse($armed);
        $this->assertSame('complete', $report['status']);
        $this->assertSame(['unowned_events' => 0, 'mapped_unowned_events' => 1], $report['coverage']);
        $this->assertSame(['observation_changed' => ['severity' => 'review', 'count' => 1]], $report['findings']);
        $this->assertStringNotContainsString('Private concurrent event marker', json_encode($report, JSON_THROW_ON_ERROR));
    }

    private function inspect(Connection $connection, OwnershipManifest $manifest): array
    {
        $report = (new LegacyOwnershipPreflight)->inspect($connection, $manifest);
        $this->assertSame(1, $report['schema_version']);
        $this->assertSame('read_only', $report['mode']);
        $this->assertFalse($report['rollout_ready']);
        $this->assertSame([
            'ownership_mapping' => 'unverified', 'platform_grants' => 'unverified',
            'backup_restore' => 'unverified', 'mysql_rehearsal' => 'unverified',
        ], $report['gates']);
        $this->assertNotEmpty($report['limitations']);

        return $report;
    }

    private function manifest(array $groups, array $platform = []): OwnershipManifest
    {
        return OwnershipManifest::fromJson(json_encode([
            'schema_version' => 1, 'workspaces' => $groups, 'platform_admin_user_ids' => $platform,
        ], JSON_THROW_ON_ERROR));
    }

    private function group(array $owners, array $events, string $reference = 'private_workspace_reference'): array
    {
        return [
            'reference' => $reference, 'name' => 'Private proposed workspace marker',
            'owner_user_ids' => $owners, 'event_ids' => $events,
        ];
    }

    private function unownedEventIds(): array
    {
        return DB::table('events')->whereNull('workspace_id')->orderBy('id')->pluck('id')->all();
    }

    private function newConnection(): Connection
    {
        $name = 'ownership_preflight_'.count($this->ownedConnections);
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->ownedConnections[] = $name;

        return DB::connection($name);
    }

    private function minimalSchema(string $unsafeTable, string $mode): Connection
    {
        $connection = $this->newConnection();
        $schema = $connection->getSchemaBuilder();
        foreach (['users', 'events'] as $name) {
            $schema->create($name, function (Blueprint $table) use ($name, $unsafeTable, $mode): void {
                if ($name !== $unsafeTable) {
                    $table->id();
                } elseif ($mode === 'unique without primary') {
                    $table->integer('id')->unique();
                } elseif ($mode === 'composite primary') {
                    $table->integer('id');
                    $table->string('private_partition_column');
                    $table->primary(['id', 'private_partition_column']);
                } else {
                    $table->string('id')->nullable()->primary();
                }
                if ($name === 'users') {
                    $table->string('role');
                    $table->integer('is_active');
                    $table->boolean('is_platform_admin');
                } else {
                    $table->integer('workspace_id')->nullable();
                }
            });
        }
        $schema->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('workspace_users', function (Blueprint $table): void {
            $table->id();
            $table->integer('workspace_id');
            $table->integer('user_id');
            $table->string('role');
            $table->boolean('is_active');
        });

        return $connection;
    }

    private function snapshot(Connection $connection): array
    {
        $schema = $connection->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name');
        $contents = [];
        foreach ($schema as $object) {
            if ($object->type !== 'table') {
                continue;
            }
            $rows = $connection->table($object->name)->get()->map(fn (object $row): array => (array) $row)->all();
            usort($rows, fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR),
            ));
            $contents[$object->name] = $rows;
        }

        return ['schema' => array_map(fn (object $row): array => (array) $row, $schema), 'contents' => $contents];
    }
}
