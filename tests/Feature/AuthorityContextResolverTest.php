<?php

namespace Tests\Feature;

use App\Services\Tenancy\AuthorityContext;
use App\Services\Tenancy\AuthorityContextException;
use App\Services\Tenancy\AuthorityContextResolver;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AuthorityContextResolverTest extends TestCase
{
    private array $ownedConnections = [];

    protected function tearDown(): void
    {
        foreach ($this->ownedConnections as $name) {
            DB::purge($name);
        }
        parent::tearDown();
    }

    #[DataProvider('authorityBoundaries')]
    public function test_legacy_platform_and_requested_membership_authority_stay_independent(int $user, ?int $workspace, array $facts): void
    {
        $connection = $this->fixture();
        $this->assertFacts($facts, $this->observe($connection, fn () => $this->resolver()->resolve($connection, $user, $workspace)));
    }

    public static function authorityBoundaries(): array
    {
        return [
            'legacy admin alone is not platform or owner' => [211, 501, [true, false, null]],
            'explicit manager context' => [211, 502, [true, false, 'manager']],
            'scanner owner is not platform' => [212, 501, [true, false, 'owner']],
            'platform account can independently be manager' => [213, 501, [true, true, 'manager']],
            'platform is not another workspace membership' => [213, 502, [true, true, null]],
            'explicit eligible scanner flag is independent' => [214, 501, [true, true, null]],
            'missing account denies all facts' => [999, 501, [false, false, null]],
            'omitted workspace selects no context' => [212, null, [true, false, null]],
        ];
    }

    public function test_omitted_workspace_does_not_query_or_choose_any_membership_or_default_workspace(): void
    {
        $connection = $this->fixture();
        $connection->table('workspace_users')->insert(['id' => 604, 'workspace_id' => 502,
            'user_id' => 212, 'role' => 'manager', 'is_active' => 1]);
        $queries = [];
        $context = $this->observe($connection, fn () => $this->resolver()->resolve($connection, 212), $queries);
        $this->assertFacts([true, false, null], $context);
        foreach ($queries as $query) {
            // Schema metadata may mention these names; selected entity rows must not.
            $this->assertDoesNotMatchRegularExpression('/\bfrom\s+["`]?workspace(?:_users|s)["`]?\b/i', $query['sql']);
        }
    }

    #[DataProvider('accountDenials')]
    public function test_ineligible_or_malformed_current_accounts_deny_platform_and_membership(array $change): void
    {
        $connection = $this->fixture();
        $connection->table('users')->where('id', 213)->update($change);
        $this->assertFacts([false, false, null], $this->observe($connection,
            fn () => $this->resolver()->resolve($connection, 213, 501)));
    }

    public static function accountDenials(): array
    {
        return [
            'inactive' => [['is_active' => 0]],
            'null active' => [['is_active' => null]],
            'truthy integer' => [['is_active' => 2]],
            'word false' => [['is_active' => 'false']],
            'word true' => [['is_active' => 'true']],
            'noncanonical numeric string' => [['is_active' => '01']],
            'case changed legacy role' => [['role' => 'Admin']],
            'membership role in account field' => [['role' => 'owner']],
            'unknown role' => [['role' => 'future-role']],
            'null role' => [['role' => null]],
        ];
    }

    #[DataProvider('noncanonicalFlags')]
    public function test_platform_flag_is_not_coerced_from_truthy_storage(mixed $flag): void
    {
        $connection = $this->fixture();
        $connection->table('users')->where('id', 213)->update(['is_platform_admin' => $flag]);
        $this->assertFacts([true, false, 'manager'], $this->observe($connection,
            fn () => $this->resolver()->resolve($connection, 213, 501)));
    }

    public static function noncanonicalFlags(): array
    {
        return ['false integer' => [0], 'null' => [null], 'truthy integer' => [2],
            'word false' => ['false'], 'word true' => ['true'], 'padded integer' => ['01'], 'empty' => ['']];
    }

    public function test_canonical_sql_text_booleans_are_read_without_eloquent_casting(): void
    {
        $connection = $this->fixture();
        $connection->table('users')->where('id', 213)->update(['is_active' => '1', 'is_platform_admin' => '1']);
        $connection->table('workspace_users')->where('id', 603)->update(['is_active' => '1']);
        $this->assertFacts([true, true, 'manager'], $this->observe($connection,
            fn () => $this->resolver()->resolve($connection, 213, 501)));
    }

    #[DataProvider('membershipDenials')]
    public function test_malformed_or_inactive_memberships_do_not_acquire_authority(array $change): void
    {
        $connection = $this->fixture();
        $connection->table('workspace_users')->where('id', 601)->update($change);
        $this->assertFacts([true, false, null], $this->observe($connection,
            fn () => $this->resolver()->resolve($connection, 212, 501)));
    }

    public static function membershipDenials(): array
    {
        return ['inactive' => [['is_active' => 0]], 'null active' => [['is_active' => null]],
            'truthy integer' => [['is_active' => 2]], 'word true' => [['is_active' => 'true']],
            'word false' => [['is_active' => 'false']], 'case changed owner' => [['role' => 'Owner']],
            'unsupported role' => [['role' => 'admin']], 'null role' => [['role' => null]],
            'wrong account' => [['user_id' => 211]], 'wrong workspace' => [['workspace_id' => 502]]];
    }

    #[DataProvider('missingOrAmbiguousMemberships')]
    public function test_missing_parent_member_or_duplicate_memberships_fail_closed(string $state): void
    {
        $connection = $this->fixture();
        if ($state === 'missing member') {
            $connection->table('workspace_users')->where('id', 601)->delete();
        } elseif ($state === 'missing parent') {
            $connection->table('workspaces')->where('id', 501)->delete();
        } else {
            $connection->table('workspace_users')->insert(['id' => 604, 'workspace_id' => 501,
                'user_id' => 212, 'role' => 'owner', 'is_active' => $state === 'inactive duplicate' ? 0 : 1]);
        }
        $this->assertFacts([true, false, null], $this->observe($connection,
            fn () => $this->resolver()->resolve($connection, 212, 501)));
    }

    public static function missingOrAmbiguousMemberships(): array
    {
        return ['member deleted' => ['missing member'], 'workspace deleted' => ['missing parent'],
            'two active memberships' => ['active duplicate'], 'duplicate with inactive row' => ['inactive duplicate']];
    }

    public function test_one_resolver_observes_revocation_and_parent_changes_on_every_fresh_invocation(): void
    {
        $connection = $this->fixture();
        $resolver = $this->resolver();
        $resolve = fn () => $this->observe($connection, fn () => $resolver->resolve($connection, 213, 501));
        $this->assertFacts([true, true, 'manager'], $resolve());
        $connection->table('users')->where('id', 213)->update(['is_platform_admin' => 0]);
        $this->assertFacts([true, false, 'manager'], $resolve());
        $connection->table('workspace_users')->where('id', 603)->update(['role' => 'owner']);
        $this->assertFacts([true, false, 'owner'], $resolve());
        $connection->table('workspace_users')->where('id', 603)->update(['is_active' => 0]);
        $this->assertFacts([true, false, null], $resolve());
        $connection->table('workspace_users')->where('id', 603)->update(['is_active' => 1]);
        $connection->table('workspaces')->where('id', 501)->delete();
        $this->assertFacts([true, false, null], $resolve());
        $connection->table('workspaces')->insert(['id' => 501, 'name' => 'Fresh parent']);
        $this->assertFacts([true, false, 'owner'], $resolve());
        $connection->table('users')->where('id', 213)->update(['is_active' => 0, 'is_platform_admin' => 1]);
        $this->assertFacts([false, false, null], $resolve());
        $connection->table('users')->where('id', 213)->update(['is_active' => 1, 'role' => 'unknown']);
        $this->assertFacts([false, false, null], $resolve());
        $connection->table('users')->where('id', 213)->delete();
        $this->assertFacts([false, false, null], $resolve());
    }

    #[DataProvider('invalidIdentifiers')]
    public function test_invalid_identifiers_are_rejected_before_metadata_or_row_queries(mixed $id): void
    {
        $connection = $this->fixture();
        $queries = [];
        $this->assertReason('invalid_identifiers', function () use ($connection, $id, &$queries): void {
            $this->observe($connection, fn () => $this->resolver()->resolve($connection, $id, 501), $queries);
        });
        $this->assertSame([], $queries);
        if ($id !== null) {
            $this->assertReason('invalid_identifiers', function () use ($connection, $id, &$queries): void {
                $this->observe($connection, fn () => $this->resolver()->resolve($connection, 212, $id), $queries);
            });
            $this->assertSame([], $queries);
        }
    }

    public static function invalidIdentifiers(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'numeric string' => ['212'], 'float' => [212.0],
            'true' => [true], 'false' => [false], 'null user' => [null], 'overflow float' => [PHP_INT_MAX + 1],
            'overflow decimal string' => [(string) PHP_INT_MAX.'0'], 'array' => [[]]];
    }

    public function test_positive_integer_boundary_is_selected_exactly_without_float_coercion(): void
    {
        $connection = $this->fixture();
        $connection->table('users')->insert(['id' => PHP_INT_MAX, 'role' => 'scanner', 'is_active' => 1, 'is_platform_admin' => 0]);
        $connection->table('workspaces')->insert(['id' => PHP_INT_MAX, 'name' => 'Boundary']);
        $connection->table('workspace_users')->insert(['id' => PHP_INT_MAX, 'workspace_id' => PHP_INT_MAX,
            'user_id' => PHP_INT_MAX, 'role' => 'owner', 'is_active' => 1]);
        $this->assertFacts([true, false, 'owner'], $this->observe($connection,
            fn () => $this->resolver()->resolve($connection, PHP_INT_MAX, PHP_INT_MAX)));
        $this->assertFacts([false, false, null], $this->observe($connection,
            fn () => $this->resolver()->resolve($connection, PHP_INT_MAX - 1, PHP_INT_MAX)));
    }

    #[DataProvider('unsafeSchemas')]
    public function test_read_prerequisites_refuse_missing_or_unsafe_schema_without_repair(string $table, ?string $replacement): void
    {
        $connection = $this->fixture();
        $connection->getSchemaBuilder()->drop($table);
        if ($replacement !== null) {
            $connection->statement($replacement);
        }
        $this->assertReason('invalid_prerequisites', fn () => $this->observe($connection,
            fn () => $this->resolver()->resolve($connection, 212, 501)));
    }

    public static function unsafeSchemas(): array
    {
        return [
            'missing accounts' => ['users', null],
            'missing workspaces' => ['workspaces', null],
            'missing memberships' => ['workspace_users', null],
            'missing platform flag' => ['users', 'create table users (id integer primary key, role text, is_active)'],
            'missing membership role' => ['workspace_users', 'create table workspace_users (id integer primary key, workspace_id integer, user_id integer, is_active)'],
            'text account primary key' => ['users', 'create table users (id text primary key, role text, is_active, is_platform_admin)'],
            'composite workspace primary key' => ['workspaces', 'create table workspaces (id integer, name text, primary key(id, name))'],
            'membership without primary key' => ['workspace_users', 'create table workspace_users (id integer, workspace_id integer, user_id integer, role text, is_active)'],
            'floating workspace primary key' => ['workspaces', 'create table workspaces (id real primary key, name text)'],
            'view in place of workspace table' => ['workspaces', 'create view workspaces as select 501 as id'],
        ];
    }

    public function test_unsupported_driver_fails_before_database_queries(): void
    {
        $fixture = $this->fixture();
        $connection = new class($fixture->getPdo(), ':memory:', '', ['driver' => 'pgsql', 'name' => 'authority_unsupported']) extends SQLiteConnection
        {
            public function getDriverName()
            {
                return 'pgsql';
            }
        };
        $queries = [];
        $this->assertReason('invalid_prerequisites', function () use ($connection, &$queries): void {
            $this->observe($connection, fn () => $this->resolver()->resolve($connection, 212, 501), $queries);
        });
        $this->assertSame([], $queries);
    }

    public function test_named_prefixed_connection_needs_only_the_three_read_tables(): void
    {
        $connection = $this->fixture('authority_');
        $this->assertCount(3, $connection->getSchemaBuilder()->getTableListing());
        $this->assertFacts([true, false, 'owner'], $this->observe($connection,
            fn () => $this->resolver()->resolve($connection, 212, 501)));
    }

    public function test_all_selected_reads_use_primary_even_when_replica_has_old_authority(): void
    {
        $connection = $this->fixture();
        $replica = $this->fixture();
        $connection->table('users')->where('id', 213)->update(['is_platform_admin' => 0]);
        $connection->table('workspace_users')->where('id', 603)->update(['is_active' => 0]);
        $connection->setReadPdo($replica->getPdo());
        try {
            $this->assertFacts([true, false, null], $this->observe($connection,
                fn () => $this->resolver()->resolve($connection, 213, 501)));
            // Missing schema on read PDO also makes metadata routing observable.
            $emptyReplica = new PDO('sqlite::memory:');
            $connection->setReadPdo($emptyReplica);
            $this->assertFacts([true, false, 'owner'], $this->observe($connection,
                fn () => $this->resolver()->resolve($connection, 212, 501)));
        } finally {
            $connection->setReadPdo(null);
        }
    }

    public function test_unavailable_read_replica_is_never_opened_for_rows_or_schema_metadata(): void
    {
        $connection = $this->fixture();
        $attempts = 0;
        $connection->setReadPdo(function () use (&$attempts): PDO {
            $attempts++;
            throw new RuntimeException('Private read replica must remain unopened.');
        });
        try {
            $this->assertFacts([true, false, 'owner'], $this->observe($connection,
                fn () => $this->resolver()->resolve($connection, 212, 501)));
            $this->assertSame(0, $attempts);
        } finally {
            $connection->setReadPdo(null);
        }
    }

    public function test_selected_entity_queries_are_bound_and_limited_to_duplicate_detection(): void
    {
        $connection = $this->fixture();
        for ($i = 0; $i < 20; $i++) {
            $connection->table('workspace_users')->insert(['id' => 1000 + $i, 'workspace_id' => 501,
                'user_id' => 212, 'role' => 'owner', 'is_active' => 1]);
        }
        $queries = [];
        $this->assertFacts([true, false, null], $this->observe($connection,
            fn () => $this->resolver()->resolve($connection, 212, 501), $queries));
        $selected = 0;
        foreach ($queries as $query) {
            if (preg_match('/\bfrom\s+["`]?(users|workspaces|workspace_users)["`]?\b/i', $query['sql']) !== 1) {
                continue;
            }
            $selected++;
            $this->assertMatchesRegularExpression('/\blimit\s+[12]\b/i', $query['sql']);
            $this->assertStringContainsString('?', $query['sql']);
            $this->assertNotEmpty($query['bindings']);
            $this->assertDoesNotMatchRegularExpression('/\b(?:211|212|501)\b/', $query['sql']);
        }
        $this->assertSame(3, $selected);
    }

    public function test_caller_transaction_is_preserved_with_connection_scoped_visibility(): void
    {
        $connection = $this->fixture();
        $connection->beginTransaction();
        try {
            $connection->table('users')->where('id', 213)->update(['is_platform_admin' => 0]);
            $this->assertFacts([true, false, 'manager'], $this->observe($connection,
                fn () => $this->resolver()->resolve($connection, 213, 501)));
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertReason('invalid_identifiers', fn () => $this->observe($connection,
                fn () => $this->resolver()->resolve($connection, '213', 501)));
            $this->assertSame(1, $connection->transactionLevel());
        } finally {
            $connection->rollBack();
        }
        $this->assertFacts([true, true, 'manager'], $this->observe($connection,
            fn () => $this->resolver()->resolve($connection, 213, 501)));
    }

    public function test_private_sql_failure_is_sanitized_without_logging_or_transaction_changes(): void
    {
        $connection = $this->fixture();
        $connection->beginTransaction();
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (preg_match('/\bfrom\s+["`]?users["`]?\b/i', $query->sql) && str_contains($query->sql, 'is_platform_admin')) {
                throw new RuntimeException('Private SQL password@example.invalid marker');
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $this->assertReason('database_unavailable', fn () => $this->observe($connection,
                fn () => $this->resolver()->resolve($connection, 213, 501)));
            $this->assertSame(1, $connection->transactionLevel());
        } finally {
            $connection->setEventDispatcher($original);
            $connection->rollBack();
        }
    }

    private function resolver(): AuthorityContextResolver
    {
        return app(AuthorityContextResolver::class);
    }

    private function fixture(string $prefix = ''): Connection
    {
        $name = 'authority_fixture_'.count($this->ownedConnections);
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:',
            'prefix' => $prefix, 'foreign_key_constraints' => true]]);
        $this->ownedConnections[] = $name;
        $connection = DB::connection($name);
        // Untyped flags preserve malformed storage rather than coercing it in SQL.
        $connection->statement('create table '.$prefix.'users (id integer primary key, role text, is_active, is_platform_admin, private_marker text)');
        $connection->statement('create table '.$prefix.'workspaces (id integer primary key, name text, private_marker text)');
        $connection->statement('create table '.$prefix.'workspace_users (id integer primary key, workspace_id integer, user_id integer, role text, is_active, private_marker text)');
        $connection->table('users')->insert([
            ['id' => 211, 'role' => 'admin', 'is_active' => 1, 'is_platform_admin' => 0, 'private_marker' => 'Private admin@example.invalid'],
            ['id' => 212, 'role' => 'scanner', 'is_active' => 1, 'is_platform_admin' => 0, 'private_marker' => 'Private owner@example.invalid'],
            ['id' => 213, 'role' => 'admin', 'is_active' => 1, 'is_platform_admin' => 1, 'private_marker' => 'Private platform@example.invalid'],
            ['id' => 214, 'role' => 'scanner', 'is_active' => 1, 'is_platform_admin' => 1, 'private_marker' => 'Private scanner@example.invalid'],
        ]);
        $connection->table('workspaces')->insert([
            ['id' => 501, 'name' => 'Same private name', 'private_marker' => 'Private workspace one'],
            ['id' => 502, 'name' => 'Same private name', 'private_marker' => 'Private workspace two'],
        ]);
        $connection->table('workspace_users')->insert([
            ['id' => 601, 'workspace_id' => 501, 'user_id' => 212, 'role' => 'owner', 'is_active' => 1],
            ['id' => 602, 'workspace_id' => 502, 'user_id' => 211, 'role' => 'manager', 'is_active' => 1],
            ['id' => 603, 'workspace_id' => 501, 'user_id' => 213, 'role' => 'manager', 'is_active' => 1],
        ]);

        return $connection;
    }

    private function observe(Connection $connection, callable $callback, ?array &$queries = null): mixed
    {
        $before = $this->snapshot($connection);
        $level = $connection->transactionLevel();
        $default = DB::getDefaultConnection();
        $original = $connection->getEventDispatcher();
        $queries = $transactions = [];
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries, $original): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            $original?->dispatch($query);
        });
        foreach ([TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class] as $event) {
            $dispatcher->listen($event, function ($event) use (&$transactions): void {
                $transactions[] = get_class($event);
            });
        }
        $connection->setEventDispatcher($dispatcher);
        DB::setDefaultConnection('authority_unconfigured_default');
        Log::spy();
        try {
            return $callback();
        } finally {
            $this->assertSame('authority_unconfigured_default', DB::getDefaultConnection());
            $this->assertSame($level, $connection->transactionLevel());
            $this->assertSame([], $transactions);
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace|create|alter|drop|truncate|attach|detach|vacuum|begin|commit|rollback|lock)\b/i', $query['sql']);
            }
            DB::setDefaultConnection($default);
            if ($original !== null) {
                $connection->setEventDispatcher($original);
            } else {
                $connection->unsetEventDispatcher();
            }
            $this->assertSame($before, $this->snapshot($connection));
            foreach (['log', 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $method) {
                Log::shouldNotHaveReceived($method);
            }
        }
    }

    private function snapshot(Connection $connection): array
    {
        // Always use primary so the test itself does not consume stale replica rows.
        $schema = $connection->select("select type, name, tbl_name, sql from sqlite_master where name not like 'sqlite_%' order by type, name", [], false);
        $rows = [];
        foreach ($schema as $entry) {
            if ($entry->type === 'table') {
                $rows[$entry->name] = $connection->select('select * from "'.str_replace('"', '""', $entry->name).'" order by id', [], false);
            }
        }

        return ['schema' => json_encode($schema, JSON_THROW_ON_ERROR), 'rows' => json_encode($rows, JSON_THROW_ON_ERROR)];
    }

    private function assertFacts(array $expected, AuthorityContext $context): void
    {
        $this->assertSame($expected, [$context->isAccountEligible(), $context->hasPlatformCapability(), $context->workspaceRole()]);
        $this->assertSame('{}', json_encode($context, JSON_THROW_ON_ERROR));
        $this->assertSame([], get_object_vars($context));
    }

    private function assertReason(string $reason, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the fixed safe authority-resolution failure.');
        } catch (AuthorityContextException $exception) {
            $this->assertSame($reason, $exception->reason());
            $this->assertSame('Authority context could not be resolved.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            foreach (['Private', 'password', 'example.invalid', 'select ', '211', '212', '501'] as $private) {
                $this->assertStringNotContainsString($private, $exception->getMessage());
            }
        }
    }
}
