<?php

namespace Tests\Feature;

use App\Services\Tenancy\WorkspaceProvisioner;
use App\Services\Tenancy\WorkspaceProvisioningException;
use App\Services\Tenancy\WorkspaceProvisioningResult;
use App\Services\Tenancy\WorkspaceProvisioningSchema;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar as SQLiteSchemaGrammar;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkspaceProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

    private const OTHER_KEY = '4f2504e0-4f89-41d3-9a0c-0305e82c3302';

    private const NAME = 'Synthetic creator workspace 家族';

    private const INSERTED_TABLES = ['workspaces', 'workspace_users', 'workspace_provisionings'];

    private array $ownedConnections = [];

    protected function tearDown(): void
    {
        $this->travelBack();
        foreach ($this->ownedConnections as $name) {
            DB::purge($name);
        }

        parent::tearDown();
    }

    public function test_new_setup_and_normalized_retry_preserve_existing_data_and_global_authority(): void
    {
        $connection = $this->fixture();
        $before = $this->snapshot($connection);
        $default = DB::getDefaultConnection();
        $defaultBefore = $this->snapshot(DB::connection());
        $this->travelTo(now()->setDate(2026, 10, 21)->startOfDay());
        Log::spy();

        $writes = [];
        $result = $this->recordWrites($connection, $writes, fn () => $this->provision(
            $connection, 211, " \u{2003}".self::NAME."\u{00a0} ",
        ));

        $this->assertInstanceOf(WorkspaceProvisioningResult::class, $result);
        $this->assertTrue($result->wasCreated());
        $this->assertGreaterThan(51, $result->workspaceId());
        $this->assertSame(self::INSERTED_TABLES, $writes);
        $workspace = $connection->table('workspaces')->where('id', $result->workspaceId())->sole();
        $this->assertSame(self::NAME, $workspace->name);
        $member = $connection->table('workspace_users')->where('workspace_id', $result->workspaceId())->sole();
        $this->assertSame([211, 'owner', 1], [$member->user_id, $member->role, $member->is_active]);
        $ledger = $connection->table('workspace_provisionings')->sole();
        $this->assertSame([211, $result->workspaceId()], [$ledger->creator_user_id, $ledger->workspace_id]);
        $this->assertSame(hash('sha256', "harusi.workspace.request.v1\0".self::KEY), $ledger->request_hash);
        $this->assertSame(hash('sha256', "harusi.workspace.name.v1\0".self::NAME), $ledger->name_hash);
        foreach ([$workspace, $member, $ledger] as $row) {
            $this->assertSame(now()->format('Y-m-d H:i:s'), $row->created_at);
            $this->assertSame($row->created_at, $row->updated_at);
        }
        $this->assertOnlyNewSetup($before, $this->snapshot($connection), $result->workspaceId());
        $this->assertSame('{}', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('workspaceId', print_r($result, true));
        $this->assertStringNotContainsString((string) $result->workspaceId(), print_r($result, true));

        $after = $this->snapshot($connection);
        $this->travelTo(now()->addDay());
        $writes = [];
        $retry = $this->recordWrites($connection, $writes, fn () => $this->provision($connection, 211));

        $this->assertSame($result->workspaceId(), $retry->workspaceId());
        $this->assertFalse($retry->wasCreated());
        $this->assertSame([], $writes);
        $this->assertSame($after, $this->snapshot($connection));
        $this->assertSame($default, DB::getDefaultConnection());
        $this->assertSame($defaultBefore, $this->snapshot(DB::connection()));
        $this->assertSame([], $connection->selectFromWriteConnection('PRAGMA foreign_key_check'));
        $this->assertNoLogs();
    }

    public function test_request_keys_are_creator_scoped_and_names_never_adopt_existing_workspaces(): void
    {
        $connection = $this->fixture();
        $before = $this->snapshot($connection);
        $first = $this->provision($connection, 211);
        $afterFirst = $this->snapshot($connection);
        $writes = [];
        $this->recordWrites($connection, $writes, fn () => $this->assertReason('request_conflict',
            fn () => $this->provision($connection, 211, 'Different private request payload')));
        $this->assertSame([], $writes);
        $this->assertSame($afterFirst, $this->snapshot($connection));

        $second = $this->provision($connection, 211, self::NAME, self::OTHER_KEY);
        $otherCreator = $this->provision($connection, 212);

        $this->assertTrue($second->wasCreated());
        $this->assertTrue($otherCreator->wasCreated());
        $this->assertCount(3, array_unique([$first->workspaceId(), $second->workspaceId(), $otherCreator->workspaceId()]));
        $this->assertNotContains(51, [$first->workspaceId(), $second->workspaceId(), $otherCreator->workspaceId()]);
        $this->assertSame(3, $connection->table('workspace_provisionings')->count());
        $this->assertSame([211, 211, 212], $connection->table('workspace_provisionings')->orderBy('id')->pluck('creator_user_id')->all());
        foreach ([[$first, 211], [$second, 211], [$otherCreator, 212]] as [$result, $creator]) {
            $member = $connection->table('workspace_users')->where('workspace_id', $result->workspaceId())->sole();
            $this->assertSame([$creator, 'owner', 1], [$member->user_id, $member->role, $member->is_active]);
        }
        $after = $this->snapshot($connection);
        foreach (['users', 'events', 'guests', 'qr_codes', 'admissions', 'checkins', 'scan_logs',
            'legacy_ownership_plans', 'legacy_workspace_mappings'] as $table) {
            $this->assertSame($before['rows'][$table], $after['rows'][$table]);
        }
    }

    public function test_original_creation_request_survives_a_workspace_rename_without_rewriting_it(): void
    {
        $connection = $this->fixture();
        $created = $this->provision($connection, 211);
        $connection->table('workspaces')->where('id', $created->workspaceId())
            ->update(['name' => 'Legitimate subsequent workspace name', 'updated_at' => '2026-10-22 10:00:00']);
        $before = $this->snapshot($connection);
        $writes = [];

        $retry = $this->recordWrites($connection, $writes, fn () => $this->provision($connection, 211));

        $this->assertFalse($retry->wasCreated());
        $this->assertSame($created->workspaceId(), $retry->workspaceId());
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public function test_supported_scanner_and_platform_accounts_are_never_promoted_or_reassigned(): void
    {
        $connection = $this->fixture();
        $before = $this->snapshot($connection);

        $scanner = $this->provision($connection, 211);
        $platform = $this->provision($connection, 213, 'Synthetic platform creator workspace', self::OTHER_KEY);

        $this->assertTrue($scanner->wasCreated());
        $this->assertTrue($platform->wasCreated());
        $after = $this->snapshot($connection);
        $this->assertSame($before['rows']['users'], $after['rows']['users']);
        $this->assertSame($before['rows']['events'], $after['rows']['events']);
        $this->assertSame('scanner', $connection->table('users')->where('id', 211)->value('role'));
        $this->assertNull($connection->table('users')->where('id', 211)->value('event_id'));
        $this->assertSame(0, $connection->table('users')->where('id', 211)->value('is_platform_admin'));
        $this->assertSame(1, $connection->table('users')->where('id', 213)->value('is_platform_admin'));
    }

    public function test_unicode_name_at_the_storage_boundary_is_accepted_without_truncation(): void
    {
        $connection = $this->fixture();
        $name = str_repeat('界', 255);

        $result = $this->provision($connection, 212, $name);

        $this->assertSame($name, $connection->table('workspaces')->where('id', $result->workspaceId())->value('name'));
        $this->assertSame(255, mb_strlen($name, 'UTF-8'));
    }

    #[DataProvider('unavailableCreators')]
    public function test_fresh_creator_eligibility_is_required_without_any_write(string $state): void
    {
        $connection = $this->fixture();
        $creator = 211;
        if ($state === 'missing') {
            $creator = 999999;
        } elseif ($state === 'inactive') {
            $connection->table('users')->where('id', $creator)->update(['is_active' => false]);
        } elseif ($state === 'noncanonical active') {
            $connection->table('users')->where('id', $creator)->update(['is_active' => 2]);
        } else {
            $connection->table('users')->where('id', $creator)->update(['role' => 'future-customer']);
        }
        $before = $this->snapshot($connection);
        $writes = [];

        $this->recordWrites($connection, $writes, fn () => $this->assertReason('creator_unavailable',
            fn () => $this->provision($connection, $creator)));

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function unavailableCreators(): array
    {
        return array_map(static fn (string $state): array => [$state],
            ['missing', 'inactive', 'noncanonical active', 'unknown role']);
    }

    public function test_account_revocation_is_rechecked_even_for_an_exact_existing_request(): void
    {
        $connection = $this->fixture();
        $this->provision($connection, 211);
        $connection->table('users')->where('id', 211)->update(['is_active' => false]);
        $before = $this->snapshot($connection);
        $writes = [];

        $this->recordWrites($connection, $writes, fn () => $this->assertReason('creator_unavailable',
            fn () => $this->provision($connection, 211)));

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
    }

    #[DataProvider('damagedRequests')]
    public function test_existing_request_cannot_resurrect_or_adopt_damaged_authority(string $state): void
    {
        $connection = $this->fixture();
        $created = $this->provision($connection, 211);
        $members = $connection->table('workspace_users')->where('workspace_id', $created->workspaceId());
        if ($state === 'missing membership') {
            $members->delete();
        } elseif ($state === 'inactive membership') {
            $members->update(['is_active' => false]);
        } elseif ($state === 'manager membership') {
            $members->update(['role' => 'manager']);
        } elseif ($state === 'noncanonical membership') {
            $members->update(['is_active' => 2]);
        } elseif ($state === 'missing workspace') {
            $connection->getSchemaBuilder()->disableForeignKeyConstraints();
            $connection->table('workspaces')->where('id', $created->workspaceId())->delete();
            $connection->getSchemaBuilder()->enableForeignKeyConstraints();
        } else {
            $connection->table('workspace_provisionings')->update(['name_hash' => str_repeat('f', 64)]);
        }
        $before = $this->snapshot($connection);
        $writes = [];

        $this->recordWrites($connection, $writes, fn () => $this->assertReason('request_conflict',
            fn () => $this->provision($connection, 211)));

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function damagedRequests(): array
    {
        return array_map(static fn (string $state): array => [$state], [
            'missing membership', 'inactive membership', 'manager membership', 'noncanonical membership',
            'missing workspace', 'corrupt name hash',
        ]);
    }

    #[DataProvider('insertStages')]
    public function test_failure_after_each_completed_insert_rolls_back_all_rows_before_retry(string $stage): void
    {
        $connection = $this->fixture();
        $before = $this->snapshot($connection);
        $triggered = false;
        Log::spy();

        $this->withQueryListener($connection, function (QueryExecuted $event) use ($stage, &$triggered): void {
            if ($this->insertedTable($event->sql) === $stage) {
                $triggered = true;
                $this->assertSame(1, $event->connection->transactionLevel());
                $this->assertTrue($event->connection->getPdo()->inTransaction());
                throw new RuntimeException('private-provisioning-query-name@example.invalid');
            }
        }, fn () => $this->assertReason('database_unavailable', fn () => $this->provision($connection, 211)));

        $this->assertTrue($triggered);
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $retry = $this->provision($connection, 211);
        $this->assertTrue($retry->wasCreated());
        $this->assertOnlyNewSetup($before, $this->snapshot($connection), $retry->workspaceId());
        $this->assertNoLogs();
    }

    public static function insertStages(): array
    {
        return array_map(static fn (string $table): array => [$table], self::INSERTED_TABLES);
    }

    #[DataProvider('insertStages')]
    public function test_simulated_raw_mysql_allocator_overflow_at_each_insert_rolls_back_every_new_row(string $table): void
    {
        // The actual MySqlProcessor runs on an owned SQLite fixture. Only its
        // raw allocator response is simulated after validating real SQLite
        // prerequisites; this does not prove MySQL storage or locking.
        $base = $this->fixture();
        app(WorkspaceProvisioningSchema::class)->assertReady($base);
        $before = $this->snapshot($base);
        $connection = new class($base->getPdo(), ':memory:', '', ['driver' => 'mysql', 'name' => 'provisioning_raw_id_simulation']) extends MySqlConnection
        {
            public string $overflowTable = '';

            public int $overflowReads = 0;

            private bool $lastInsertIsTarget = false;

            public function insert($query, $bindings = [], $sequence = null)
            {
                $this->lastInsertIsTarget = preg_match('/^\s*insert\s+into\s+["\x60]?'.preg_quote($this->overflowTable, '/').'["\x60]?\s/i', $query) === 1;

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
        $schema = new class extends WorkspaceProvisioningSchema
        {
            public function assertReady(Connection $connection): void {}
        };
        $service = new WorkspaceProvisioner($schema);
        Log::spy();

        $this->assertReason('invalid_prerequisites', fn () => $service->provision($connection, 211, self::NAME, self::KEY));

        // One read is consumed by the processor's lossy integer cast; the
        // service must inspect the raw value again and refuse before trusting it.
        $this->assertSame(2, $connection->overflowReads);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($base->getPdo()->inTransaction());
        $this->assertSame($before, $this->snapshot($base));
        $retry = $this->provision($base, 211);
        $this->assertTrue($retry->wasCreated());
        $this->assertOnlyNewSetup($before, $this->snapshot($base), $retry->workspaceId());
        $this->assertNoLogs();
    }

    public function test_collation_equivalent_corrupt_request_hash_is_refused_without_new_setup(): void
    {
        $connection = $this->fixture();
        // Rebuild only this empty synthetic ledger to exercise SQL equality
        // that differs from exact bytes, as a case-insensitive MySQL collation can.
        $table = $connection->selectFromWriteConnection("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'workspace_provisionings'")[0]->sql;
        $indexes = $connection->selectFromWriteConnection("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'workspace_provisionings' AND sql IS NOT NULL");
        $table = preg_replace('/("request_hash"[^,]*)(,)/', '$1 COLLATE NOCASE$2', $table, 1, $changed);
        $this->assertSame(1, $changed);
        $connection->getSchemaBuilder()->drop('workspace_provisionings');
        $connection->statement($table);
        foreach ($indexes as $index) {
            $connection->statement($index->sql);
        }
        $this->provision($connection, 211);
        $hash = hash('sha256', "harusi.workspace.request.v1\0".self::KEY);
        $connection->table('workspace_provisionings')->update(['request_hash' => strtoupper($hash)]);
        $this->assertSame(1, $connection->table('workspace_provisionings')->where('request_hash', $hash)->count());
        $before = $this->snapshot($connection);
        $writes = [];

        $this->recordWrites($connection, $writes, fn () => $this->assertReason('request_conflict', fn () => $this->provision($connection, 211)));

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public function test_primary_only_operation_ignores_an_unavailable_default_and_read_connection(): void
    {
        $connection = $this->fixture();
        $default = DB::getDefaultConnection();
        $defaultBefore = $this->snapshot(DB::connection());
        $read = $connection->getReadPdo();
        $attempts = 0;
        $connection->setReadPdo(static function () use (&$attempts): never {
            $attempts++;
            throw new RuntimeException('private-read-connection');
        });
        DB::setDefaultConnection('unconfigured_provisioning_default');
        try {
            $created = $this->provision($connection, 211);
            $retry = $this->provision($connection, 211);
            $this->assertSame('unconfigured_provisioning_default', DB::getDefaultConnection());
        } finally {
            $connection->setReadPdo($read);
            DB::setDefaultConnection($default);
        }

        $this->assertTrue($created->wasCreated());
        $this->assertFalse($retry->wasCreated());
        $this->assertSame($created->workspaceId(), $retry->workspaceId());
        $this->assertSame(0, $attempts);
        $this->assertSame($defaultBefore, $this->snapshot(DB::connection()));
    }

    public function test_ambient_transaction_is_refused_without_querying_or_changing_the_caller(): void
    {
        $connection = $this->fixture();
        $connection->beginTransaction();
        $before = $this->snapshot($connection);
        $queries = 0;
        try {
            $this->withQueryListener($connection, static function () use (&$queries): void {
                $queries++;
            }, fn () => $this->assertReason('invalid_prerequisites', fn () => $this->provision($connection, 211)));
            $this->assertSame(0, $queries);
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertTrue($connection->getPdo()->inTransaction());
            $this->assertSame($before, $this->snapshot($connection));
        } finally {
            $connection->rollBack();
        }
    }

    #[DataProvider('invalidPrerequisites')]
    public function test_invalid_schema_or_foreign_key_state_refuses_without_writes(string $state): void
    {
        $connection = $this->fixture();
        $schema = $connection->getSchemaBuilder();
        if ($state === 'missing ledger') {
            $schema->drop('workspace_provisionings');
        } elseif ($state === 'missing hash column') {
            $schema->table('workspace_provisionings', fn ($table) => $table->dropColumn('name_hash'));
        } elseif ($state === 'missing request unique') {
            foreach ($schema->getIndexes('workspace_provisionings') as $index) {
                if ($index['unique'] && $index['columns'] === ['creator_user_id', 'request_hash']) {
                    $connection->statement('DROP INDEX "'.str_replace('"', '""', $index['name']).'"');
                }
            }
        } else {
            $schema->disableForeignKeyConstraints();
        }
        $before = $this->snapshot($connection);
        $writes = [];

        $this->recordWrites($connection, $writes, fn () => $this->assertReason('invalid_prerequisites',
            fn () => $this->provision($connection, 211)));

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame(0, $connection->transactionLevel());
    }

    public static function invalidPrerequisites(): array
    {
        return array_map(static fn (string $state): array => [$state], [
            'missing ledger', 'missing hash column', 'missing request unique', 'foreign keys disabled',
        ]);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_inputs_are_refused_before_connection_or_schema_work(mixed $creator, mixed $name, mixed $key): void
    {
        $connectors = $queries = $transactions = 0;
        $connection = new Connection(static function () use (&$connectors): never {
            $connectors++;
            throw new RuntimeException('private-never-connect');
        }, 'private-unavailable', '', ['driver' => 'sqlite', 'name' => 'private-never-connect']);
        $connection->beforeExecuting(static function () use (&$queries): void {
            $queries++;
        });
        $connection->beforeStartingTransaction(static function () use (&$transactions): void {
            $transactions++;
        });

        $this->assertReason('invalid_input', fn () => app(WorkspaceProvisioner::class)->provision($connection, $creator, $name, $key));

        $this->assertSame([0, 0, 0], [$connectors, $queries, $transactions]);
    }

    public static function invalidInputs(): array
    {
        return [
            'numeric string creator' => ['211', self::NAME, self::KEY],
            'float creator' => [211.0, self::NAME, self::KEY],
            'boolean creator' => [true, self::NAME, self::KEY],
            'zero creator' => [0, self::NAME, self::KEY],
            'null creator' => [null, self::NAME, self::KEY],
            'array name' => [211, ['name' => self::NAME], self::KEY],
            'invalid UTF8 name' => [211, "\xc3\x28", self::KEY],
            'Unicode whitespace only name' => [211, " \u{2003}\u{00a0} ", self::KEY],
            'leading C0 name' => [211, "\n".self::NAME, self::KEY],
            'C1 name' => [211, self::NAME."\u{0085}", self::KEY],
            'format control name' => [211, self::NAME."\u{200d}", self::KEY],
            'overlong Unicode name' => [211, str_repeat('界', 256), self::KEY],
            'uppercase key' => [211, self::NAME, strtoupper(self::KEY)],
            'version1 key' => [211, self::NAME, str_replace('-41d3-', '-11d3-', self::KEY)],
            'null key' => [211, self::NAME, null],
        ];
    }

    private function provision(Connection $connection, int $creator, string $name = self::NAME, string $key = self::KEY): WorkspaceProvisioningResult
    {
        return app(WorkspaceProvisioner::class)->provision($connection, $creator, $name, $key);
    }

    private function assertReason(string $reason, callable $operation): void
    {
        $caught = null;
        try {
            $operation();
        } catch (WorkspaceProvisioningException $exception) {
            $caught = $exception;
        }
        $this->assertInstanceOf(WorkspaceProvisioningException::class, $caught);
        $this->assertSame($reason, $caught->reason());
        $this->assertSame('Workspace could not be created.', $caught->getMessage());
        $this->assertNull($caught->getPrevious());
        foreach ([self::NAME, self::KEY, 'example.invalid', 'private-', '211', 'insert into'] as $private) {
            $this->assertStringNotContainsString($private, $caught->getMessage());
        }
    }

    private function recordWrites(Connection $connection, array &$writes, callable $operation): mixed
    {
        return $this->withQueryListener($connection, function (QueryExecuted $event) use (&$writes): void {
            if (preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $event->sql)) {
                $writes[] = $this->insertedTable($event->sql) ?? 'unexpected DML or DDL';
            }
        }, $operation);
    }

    private function insertedTable(string $sql): ?string
    {
        foreach (self::INSERTED_TABLES as $table) {
            if (preg_match('/\A\s*insert\s+into\s+["\x60]?'.preg_quote($table, '/').'["\x60]?\s*\(/i', $sql)) {
                return $table;
            }
        }

        return null;
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

    private function assertOnlyNewSetup(array $before, array $after, int $workspaceId): void
    {
        $this->assertSame($before['schema'], $after['schema']);
        foreach (self::INSERTED_TABLES as $table) {
            $key = $table === 'workspaces' ? 'id' : 'workspace_id';
            $new = array_values(array_filter($after['rows'][$table], static fn (array $row): bool => (int) $row[$key] === $workspaceId));
            $this->assertCount(1, $new);
            $after['rows'][$table] = array_values(array_filter($after['rows'][$table], static fn (array $row): bool => (int) $row[$key] !== $workspaceId));
        }
        foreach (['before', 'after'] as $which) {
            $snapshot = $which === 'before' ? $before : $after;
            $snapshot['rows']['sqlite_sequence'] = array_values(array_filter($snapshot['rows']['sqlite_sequence'],
                static fn (array $row): bool => ! in_array($row['name'], self::INSERTED_TABLES, true)));
            if ($which === 'before') {
                $before = $snapshot;
            } else {
                $after = $snapshot;
            }
        }
        $this->assertSame($before, $after);
    }

    private function assertNoLogs(): void
    {
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    private function fixture(): Connection
    {
        $name = 'workspace_provision_'.count($this->ownedConnections);
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
                foreach ($source->table($object->name)->useWritePdo()->get() as $row) {
                    $connection->table($object->name)->insert((array) $row);
                }
            }
        }
        $connection->getSchemaBuilder()->enableForeignKeyConstraints();
        $this->assertSame(0, $connection->transactionLevel());
        $timestamp = '2026-09-01 10:15:00';
        $password = Hash::make('synthetic-existing-password');
        $connection->table('users')->insert([
            ['id' => 211, 'name' => 'Synthetic existing scanner', 'email' => 'synthetic-scanner@example.invalid', 'password' => $password, 'remember_token' => 'synthetic-remember', 'role' => 'scanner', 'event_id' => null, 'gate_name' => 'Existing scanner gate', 'is_active' => true, 'is_platform_admin' => false, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['id' => 212, 'name' => 'Synthetic existing admin', 'email' => 'synthetic-admin@example.invalid', 'password' => $password, 'remember_token' => null, 'role' => 'admin', 'event_id' => null, 'gate_name' => null, 'is_active' => true, 'is_platform_admin' => false, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['id' => 213, 'name' => 'Synthetic explicit platform account', 'email' => 'synthetic-platform@example.invalid', 'password' => $password, 'remember_token' => null, 'role' => 'admin', 'event_id' => null, 'gate_name' => null, 'is_active' => true, 'is_platform_admin' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ]);
        $connection->table('workspaces')->insert(['id' => 51, 'name' => self::NAME, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        $connection->table('workspace_users')->insert(['id' => 61, 'workspace_id' => 51, 'user_id' => 212, 'role' => 'owner', 'is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        $connection->table('events')->insert(['id' => 201, 'event_name' => 'Synthetic existing unowned event', 'workspace_id' => null, 'status' => 'active']);
        $connection->table('guests')->insert(['id' => 301, 'event_id' => 201, 'name' => 'Synthetic conserved guest', 'phone_number' => '+255712345679', 'manual_code' => '0042', 'pass_type' => 'special', 'allowed_entries' => 6, 'used_entries' => 2, 'status' => 'partially_used']);
        $connection->table('qr_codes')->insert(['id' => 401, 'guest_id' => 301, 'qr_token' => str_repeat('a', 64), 'qr_image_path' => 'private/synthetic-legacy-qr.png']);
        $connection->table('admissions')->insert(['id' => 501, 'event_id' => 201, 'guest_id' => 301, 'quantity' => 2, 'admitted_by' => 'Synthetic existing scanner', 'admitted_at' => $timestamp]);
        $connection->table('checkins')->insert(['id' => 601, 'event_id' => 201, 'guest_id' => 301, 'qr_code_id' => 401, 'user_id' => 211, 'scan_result' => 'admitted', 'entries_added' => 2, 'used_entries_after_scan' => 2, 'remaining_entries_after_scan' => 4]);
        $connection->table('scan_logs')->insert(['id' => 701, 'event_id' => 201, 'guest_id' => 301, 'action' => 'admit', 'result' => 'admitted', 'quantity' => 2, 'message' => 'Synthetic conserved history', 'scanned_at' => $timestamp]);

        return $connection;
    }

    private function snapshot(Connection $connection): array
    {
        $rows = [];
        foreach ($connection->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $table) {
            $contents = array_map(static fn (object $row): array => (array) $row,
                $connection->table($table->name)->useWritePdo()->get()->all());
            usort($contents, static fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));
            $rows[$table->name] = $contents;
        }

        return [
            'schema' => array_map(static fn (object $row): array => (array) $row,
                $connection->selectFromWriteConnection('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')),
            'rows' => $rows,
        ];
    }
}
