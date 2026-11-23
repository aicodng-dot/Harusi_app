<?php

namespace Tests\Feature;

use App\Services\Tenancy\LegacyOwnershipPreflight;
use App\Services\Tenancy\OwnershipManifest;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OwnershipPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    private array $ownedFiles = [];

    private array $ownedConnections = [];

    protected function tearDown(): void
    {
        foreach ($this->ownedConnections as $name) {
            DB::purge($name);
        }
        foreach ($this->ownedFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_valid_proposal_is_private_repeatable_read_only_and_never_approves_rollout(): void
    {
        $json = $this->validProposal();
        $file = $this->manifestFile($json);
        $before = $this->snapshot(DB::connection('sqlite'));
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Log::spy();

        [$first, $output] = $this->runPreflight(['--connection' => 'sqlite', '--manifest' => $file], 0);
        [$second, $again] = $this->runPreflight(['--connection' => 'sqlite', '--manifest' => $file], 0);

        $this->assertSame($first, $second);
        $this->assertSame($output, $again);
        $this->assertSame('complete', $first['status']);
        $this->assertSame(['workspaces' => 1, 'owner_memberships' => 1, 'events' => 2, 'platform_admins' => 1], $first['proposal']);
        $this->assertSame(['unowned_events' => 2, 'mapped_unowned_events' => 2], $first['coverage']);
        $this->assertSame([], $first['findings']);
        foreach ([
            $file, '91400871', '91400872', 'Private ownership proposal marker',
            'private-reference-marker', 'private-ownership@example.invalid',
            'private-password-marker', 'owner_user_ids', 'event_ids', 'platform_admin_user_ids',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $output);
        }
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace|create|alter|drop|truncate|attach|detach|vacuum)\b/i', $query);
        }
        $this->assertSame($before, $this->snapshot(DB::connection('sqlite')));
        $this->assertSame($json, file_get_contents($file));
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('workspace_users', 0);
        $this->assertSame(0, DB::table('users')->where('is_platform_admin', true)->count());
        $this->assertNoLogs();
    }

    public function test_complete_proposal_with_unavailable_owner_returns_findings_without_writing(): void
    {
        $json = $this->validProposal();
        DB::table('users')->where('id', 91400871)->update(['is_active' => false]);
        $before = $this->snapshot(DB::connection('sqlite'));

        [$report] = $this->runPreflight(['--connection' => 'sqlite', '--manifest' => $this->manifestFile($json)], 1);

        $this->assertSame('complete', $report['status']);
        $this->assertSame(['severity' => 'blocker', 'count' => 1], $report['findings']['owners_inactive']);
        $this->assertSame($before, $this->snapshot(DB::connection('sqlite')));
    }

    #[DataProvider('invalidInvocation')]
    public function test_invalid_invocations_do_not_query_or_disclose_inputs(array $options, string $reason): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Log::spy();

        [$report, $output] = $this->runPreflight($options, 2);

        $this->assertSame('unavailable', $report['status']);
        $this->assertSame($reason, $report['reason']);
        $this->assertSame([], $queries);
        $this->assertStringNotContainsString('private-', $output);
        $this->assertNoLogs();
    }

    public static function invalidInvocation(): array
    {
        return [
            'no connection' => [[], 'connection_required'],
            'blank connection' => [['--connection' => '  '], 'connection_required'],
            'unknown before file access' => [['--connection' => 'private-unconfigured', '--manifest' => 'https://private-host.invalid/secret'], 'unknown_connection'],
            'no manifest' => [['--connection' => 'sqlite'], 'manifest_required'],
            'blank manifest' => [['--connection' => 'sqlite', '--manifest' => '  '], 'manifest_required'],
            'https URL' => [['--connection' => 'sqlite', '--manifest' => 'https://private-host.invalid/secret'], 'manifest_unavailable'],
            'file URL' => [['--connection' => 'sqlite', '--manifest' => 'file:///private-path/secret.json'], 'manifest_unavailable'],
            'PHP wrapper' => [['--connection' => 'sqlite', '--manifest' => 'php://memory'], 'manifest_unavailable'],
            'UNC' => [['--connection' => 'sqlite', '--manifest' => '\\\\private-host\\secret\\plan.json'], 'manifest_unavailable'],
            'null byte' => [['--connection' => 'sqlite', '--manifest' => "private-path\0secret"], 'manifest_unavailable'],
            'missing' => [['--connection' => 'sqlite', '--manifest' => 'private-file-that-does-not-exist.json'], 'manifest_unavailable'],
        ];
    }

    public function test_directory_is_not_a_manifest(): void
    {
        [$report, $output] = $this->runPreflight(['--connection' => 'sqlite', '--manifest' => sys_get_temp_dir()], 2);
        $this->assertSame('manifest_unavailable', $report['reason']);
        $this->assertStringNotContainsString(sys_get_temp_dir(), $output);
    }

    public function test_invalid_and_oversize_manifests_fail_before_database_lookups(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Log::spy();
        $cases = [
            ['{"schema_version":1,"schema_version":1,"private-secret":"private-content"}', 'invalid_manifest'],
            [str_repeat('x', OwnershipManifest::MAX_BYTES + 1), 'manifest_too_large'],
        ];
        foreach ($cases as [$content, $reason]) {
            $file = $this->manifestFile($content);
            [$report, $output] = $this->runPreflight(['--connection' => 'sqlite', '--manifest' => $file], 2);
            $this->assertSame($reason, $report['reason']);
            $this->assertStringNotContainsString('private-', $output);
            $this->assertStringNotContainsString($file, $output);
            $this->assertSame($content, file_get_contents($file));
        }
        $this->assertSame([], $queries);
        $this->assertNoLogs();
    }

    public function test_explicit_empty_connection_is_inspected_without_using_the_default(): void
    {
        $file = $this->manifestFile($this->validProposal());
        $primary = DB::connection('sqlite');
        $before = $this->snapshot($primary);
        $this->configure('ownership_empty', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $other = DB::connection('ownership_empty');
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('private-unconfigured-default');
        try {
            [$report, $output] = $this->runPreflight(['--connection' => 'ownership_empty', '--manifest' => $file], 2);
            $this->assertSame('incomplete', $report['status']);
            $this->assertSame('private-unconfigured-default', DB::getDefaultConnection());
            $this->assertStringNotContainsString('private-', $output);
            $this->assertSame([], $other->getSchemaBuilder()->getTables());
            $this->assertSame($before, $this->snapshot($primary));
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    public function test_unsupported_driver_is_rejected_without_network_or_query(): void
    {
        $file = $this->manifestFile($this->validProposal());
        $this->configure('ownership_unsupported', [
            'driver' => 'pgsql', 'host' => 'private-host.invalid', 'port' => 1,
            'database' => 'private-database', 'username' => 'private-user', 'password' => 'private-password',
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Log::spy();

        [$report, $output] = $this->runPreflight(['--connection' => 'ownership_unsupported', '--manifest' => $file], 2);

        $this->assertSame('unsupported_driver', $report['reason']);
        $this->assertStringNotContainsString('private-', $output);
        $this->assertSame([], $queries);
        $this->assertNoLogs();
    }

    public function test_unavailable_database_does_not_create_a_file_or_disclose_configuration(): void
    {
        $file = $this->manifestFile($this->validProposal());
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'private-ownership-db-'.bin2hex(random_bytes(12)).'.sqlite';
        $this->configure('ownership_unavailable', ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'password' => 'private-password']);
        Log::spy();

        [$report, $output] = $this->runPreflight(['--connection' => 'ownership_unavailable', '--manifest' => $file], 2);

        $this->assertSame('database_unavailable', $report['reason']);
        $this->assertStringNotContainsString('private-', $output);
        $this->assertStringNotContainsString('SQLSTATE', $output);
        $this->assertFileDoesNotExist($path);
        $this->assertNoLogs();
    }

    public function test_query_failure_is_sanitized_without_logging_proposal_or_exception(): void
    {
        $file = $this->manifestFile($this->validProposal());
        $this->mock(LegacyOwnershipPreflight::class)->shouldReceive('inspect')->once()->andThrow(
            new RuntimeException('SQLSTATE private-password /private/path private-owner-id private-json'),
        );
        Log::spy();

        [$report, $output] = $this->runPreflight(['--connection' => 'sqlite', '--manifest' => $file], 2);

        $this->assertSame('database_unavailable', $report['reason']);
        $this->assertStringNotContainsString('private-', $output);
        $this->assertStringNotContainsString('SQLSTATE', $output);
        $this->assertNoLogs();
    }

    private function validProposal(): string
    {
        DB::table('users')->insert([
            'id' => 91400871, 'name' => 'Private account marker', 'email' => 'private-ownership@example.invalid',
            'password' => Hash::make('private-password-marker'), 'role' => 'admin', 'is_active' => true,
        ]);
        DB::table('events')->insert(['id' => 91400872, 'event_name' => 'Private event marker', 'status' => 'archived']);

        return json_encode([
            'schema_version' => 1,
            'workspaces' => [[
                'reference' => 'private-reference-marker', 'name' => 'Private ownership proposal marker',
                'owner_user_ids' => [91400871], 'event_ids' => DB::table('events')->orderBy('id')->pluck('id')->all(),
            ]],
            'platform_admin_user_ids' => [91400871],
        ], JSON_THROW_ON_ERROR);
    }

    private function manifestFile(string $content): string
    {
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codex-harusi-owner-proposal-'.bin2hex(random_bytes(12)).'.json';
        file_put_contents($file, $content);
        $this->ownedFiles[] = $file;

        return $file;
    }

    private function configure(string $name, array $configuration): void
    {
        config(['database.connections.'.$name => $configuration]);
        $this->ownedConnections[] = $name;
    }

    private function runPreflight(array $options, int $expected): array
    {
        $exit = Artisan::call('tenancy:ownership-preflight', $options);
        $output = Artisan::output();
        $this->assertSame($expected, $exit, $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['schema_version']);
        $this->assertSame('read_only', $report['mode']);
        $this->assertFalse($report['rollout_ready']);
        $this->assertSame([
            'ownership_mapping' => 'unverified', 'platform_grants' => 'unverified',
            'backup_restore' => 'unverified', 'mysql_rehearsal' => 'unverified',
        ], $report['gates']);

        return [$report, $output];
    }

    private function assertNoLogs(): void
    {
        foreach (['log', 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    private function snapshot(Connection $connection): array
    {
        $schema = $connection->select('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name');
        $rows = [];
        foreach ($schema as $object) {
            if ($object->type !== 'table') {
                continue;
            }
            $contents = $connection->table($object->name)->get()->map(fn (object $row): array => (array) $row)->all();
            usort($contents, fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            $rows[$object->name] = $contents;
        }

        return ['schema' => array_map(fn (object $row): array => (array) $row, $schema), 'rows' => $rows];
    }
}
