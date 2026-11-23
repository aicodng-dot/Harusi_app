<?php

namespace Tests\Feature;

use App\Services\Tenancy\LegacyOwnershipPreflight;
use App\Services\Tenancy\LegacyWorkspaceBackfill;
use App\Services\Tenancy\LegacyWorkspaceBackfillInspection;
use App\Services\Tenancy\OwnershipBackfillException;
use App\Services\Tenancy\OwnershipManifest;
use App\Services\Tenancy\OwnershipManifestFile;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LengthException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BackfillInspectCommandTest extends TestCase
{
    private array $ownedFiles = [];

    private array $ownedConnections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LegacyWorkspaceBackfill::class)->shouldNotReceive('apply');
        Log::spy();
    }

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

    public function test_successful_aggregate_observation_is_repeatable_private_and_has_no_writer_option(): void
    {
        $json = $this->proposal();
        $file = $this->manifestFile($json);
        $report = $this->successReport();
        $configuration = hash('sha256', serialize(config('database.connections')));
        $default = DB::getDefaultConnection();
        $this->mock(LegacyWorkspaceBackfillInspection::class)->shouldReceive('inspect')->twice()
            ->with(Mockery::on(fn (Connection $connection): bool => $connection->getName() === 'sqlite'), Mockery::on(
                fn (OwnershipManifest $manifest): bool => $manifest->workspaces()[0]['name'] === 'Private proposal marker'
                    && $manifest->workspaces()[0]['owner_user_ids'] === [91400871]
                    && $manifest->workspaces()[0]['event_ids'] === [91400872]
                    && $manifest->platformAdminUserIds() === [],
            ))->andReturn($report);
        $queries = $this->captureQueries();

        [$first, $output] = $this->runInspection(['--connection' => 'sqlite', '--manifest' => $file], 0);
        [$second, $again] = $this->runInspection(['--connection' => 'sqlite', '--manifest' => $file], 0);

        $this->assertSame($report, $first);
        $this->assertSame($first, $second);
        $this->assertSame($output, $again);
        $this->assertSame([], $queries->queries);
        $this->assertSame($json, file_get_contents($file));
        $this->assertSame($default, DB::getDefaultConnection());
        $this->assertSame($configuration, hash('sha256', serialize(config('database.connections'))));
        $command = Artisan::all()['tenancy:backfill-inspect'];
        $this->assertTrue($command->getDefinition()->hasOption('connection'));
        $this->assertTrue($command->getDefinition()->hasOption('manifest'));
        foreach (['apply', 'approve', 'repair', 'force', 'write', 'activate'] as $option) {
            $this->assertFalse($command->getDefinition()->hasOption($option));
        }
        $this->assertPrivate($output, [$file]);
        $this->assertNoLogs();
    }

    #[DataProvider('invalidInvocation')]
    public function test_invalid_invocation_never_reaches_inspector_or_database(array $options, string $reason): void
    {
        $this->mock(LegacyWorkspaceBackfillInspection::class)->shouldNotReceive('inspect');
        $queries = $this->captureQueries();

        [$report, $output] = $this->runInspection($options, 2);

        $this->assertSame('unavailable', $report['status']);
        $this->assertSame($reason, $report['reason']);
        $this->assertSame([], $queries->queries);
        $this->assertPrivate($output);
        $this->assertNoLogs();
    }

    public static function invalidInvocation(): array
    {
        return [
            'missing connection' => [[], 'connection_required'],
            'blank connection' => [['--connection' => '  '], 'connection_required'],
            'unknown connection before manifest' => [['--connection' => 'private-unconfigured', '--manifest' => 'https://private-host.invalid/secret'], 'unknown_connection'],
            'missing manifest' => [['--connection' => 'sqlite'], 'manifest_required'],
            'blank manifest' => [['--connection' => 'sqlite', '--manifest' => '  '], 'manifest_required'],
            'https path' => [['--connection' => 'sqlite', '--manifest' => 'https://private-host.invalid/secret'], 'manifest_unavailable'],
            'file wrapper' => [['--connection' => 'sqlite', '--manifest' => 'file:///private-path/secret.json'], 'manifest_unavailable'],
            'php wrapper' => [['--connection' => 'sqlite', '--manifest' => 'php://memory'], 'manifest_unavailable'],
            'phar wrapper' => [['--connection' => 'sqlite', '--manifest' => 'phar://private-path/secret.json'], 'manifest_unavailable'],
            'UNC path' => [['--connection' => 'sqlite', '--manifest' => '\\\\private-host\\secret\\manifest.json'], 'manifest_unavailable'],
            'slash UNC path' => [['--connection' => 'sqlite', '--manifest' => '//private-host/secret/manifest.json'], 'manifest_unavailable'],
            'NUL path' => [['--connection' => 'sqlite', '--manifest' => "private-path\0secret"], 'manifest_unavailable'],
            'missing file' => [['--connection' => 'sqlite', '--manifest' => 'private-file-does-not-exist-backfill.json'], 'manifest_unavailable'],
        ];
    }

    public function test_connection_validation_precedes_shared_file_reader(): void
    {
        $this->mock(OwnershipManifestFile::class)->shouldNotReceive('read');
        $this->mock(LegacyWorkspaceBackfillInspection::class)->shouldNotReceive('inspect');

        [$report] = $this->runInspection(['--connection' => 'private-unconfigured', '--manifest' => 'private-path.json'], 2);

        $this->assertSame('unknown_connection', $report['reason']);
        $this->assertNoLogs();
    }

    public function test_directory_and_invalid_or_oversize_contents_fail_before_inspection_without_mutation(): void
    {
        $this->mock(LegacyWorkspaceBackfillInspection::class)->shouldNotReceive('inspect');
        $queries = $this->captureQueries();
        [$directory, $directoryOutput] = $this->runInspection(['--connection' => 'sqlite', '--manifest' => sys_get_temp_dir()], 2);
        $this->assertSame('manifest_unavailable', $directory['reason']);
        $this->assertPrivate($directoryOutput, [sys_get_temp_dir()]);
        foreach ([
            ['{"schema_version":1,"schema_version":1,"private-secret":"private-content"}', 'invalid_manifest'],
            ['{"private-secret":"private-content"', 'invalid_manifest'],
            [str_repeat('x', OwnershipManifest::MAX_BYTES + 1), 'manifest_too_large'],
        ] as [$content, $reason]) {
            $file = $this->manifestFile($content);
            [$report, $output] = $this->runInspection(['--connection' => 'sqlite', '--manifest' => $file], 2);
            $this->assertSame($reason, $report['reason']);
            $this->assertSame($content, file_get_contents($file));
            $this->assertPrivate($output, [$file]);
        }
        $this->assertSame([], $queries->queries);
        $this->assertNoLogs();
    }

    public function test_exact_file_byte_limit_is_accepted_without_rewriting_manifest(): void
    {
        $json = $this->proposal();
        $content = $json.str_repeat(' ', OwnershipManifest::MAX_BYTES - strlen($json));
        $file = $this->manifestFile($content);
        $this->mock(LegacyWorkspaceBackfillInspection::class)->shouldReceive('inspect')->once()->andReturn($this->successReport());

        $this->runInspection(['--connection' => 'sqlite', '--manifest' => $file], 0);

        $this->assertSame(OwnershipManifest::MAX_BYTES, filesize($file));
        $this->assertSame($content, file_get_contents($file));
        $this->assertNoLogs();
    }

    #[DataProvider('fixedReasons')]
    public function test_fixed_inspection_conflicts_and_unavailability_have_safe_status_and_exit(string $reason, int $exit, string $status): void
    {
        $file = $this->manifestFile($this->proposal());
        $this->mock(LegacyWorkspaceBackfillInspection::class)->shouldReceive('inspect')->once()
            ->andThrow(OwnershipBackfillException::because($reason));

        [$report, $output] = $this->runInspection(['--connection' => 'sqlite', '--manifest' => $file], $exit);

        $this->assertSame($status, $report['status']);
        $this->assertSame($reason, $report['reason']);
        $this->assertPrivate($output, [$file]);
        $this->assertNoLogs();
    }

    public static function fixedReasons(): array
    {
        return [
            'owner conflict' => ['eligibility_changed', 1, 'conflict'],
            'coverage conflict' => ['coverage_conflict', 1, 'conflict'],
            'ledger conflict' => ['ledger_conflict', 1, 'conflict'],
            'platform intent' => ['platform_proposal_unsupported', 1, 'conflict'],
            'unsafe schema' => ['invalid_prerequisites', 2, 'unavailable'],
            'unavailable database' => ['database_unavailable', 2, 'unavailable'],
        ];
    }

    public function test_raw_inspection_exception_is_sanitized_without_private_output_or_logs(): void
    {
        $file = $this->manifestFile($this->proposal());
        $this->mock(LegacyWorkspaceBackfillInspection::class)->shouldReceive('inspect')->once()->andThrow(
            new RuntimeException('SQLSTATE private-password /private/path private-owner-id private-json'),
        );

        [$report, $output] = $this->runInspection(['--connection' => 'sqlite', '--manifest' => $file], 2);

        $this->assertSame('database_unavailable', $report['reason']);
        $this->assertPrivate($output, [$file]);
        $this->assertNoLogs();
    }

    public function test_explicit_named_connection_does_not_use_or_mutate_unconfigured_default(): void
    {
        $file = $this->manifestFile($this->proposal());
        $name = 'backfill_inspect_named';
        $this->configure($name, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->mock(LegacyWorkspaceBackfillInspection::class)->shouldReceive('inspect')->once()->with(
            Mockery::on(fn (Connection $connection): bool => $connection->getName() === $name), Mockery::type(OwnershipManifest::class),
        )->andReturn($this->successReport());
        $default = DB::getDefaultConnection();
        $queries = $this->captureQueries();
        DB::setDefaultConnection('private-unconfigured-default');
        try {
            $this->runInspection(['--connection' => $name, '--manifest' => $file], 0);
            $this->assertSame('private-unconfigured-default', DB::getDefaultConnection());
            $this->assertSame([], $queries->queries);
        } finally {
            DB::setDefaultConnection($default);
        }
        $this->assertNoLogs();
    }

    public function test_unsupported_driver_is_rejected_without_inspection_network_or_query(): void
    {
        $file = $this->manifestFile($this->proposal());
        $name = 'backfill_inspect_unsupported';
        $this->configure($name, ['driver' => 'pgsql', 'host' => 'private-host.invalid', 'port' => 1, 'database' => 'private-database', 'username' => 'private-user', 'password' => 'private-password']);
        $this->mock(LegacyWorkspaceBackfillInspection::class)->shouldNotReceive('inspect');
        $queries = $this->captureQueries();

        [$report, $output] = $this->runInspection(['--connection' => $name, '--manifest' => $file], 2);

        $this->assertSame('unsupported_driver', $report['reason']);
        $this->assertSame([], $queries->queries);
        $this->assertPrivate($output, [$file]);
        $this->assertNoLogs();
    }

    public function test_both_commands_resolve_the_shared_reader_and_keep_existing_preflight_output(): void
    {
        $path = 'private-shared-reader-contract.json';
        $this->mock(OwnershipManifestFile::class)->shouldReceive('read')->twice()->with($path)->andReturn($this->proposal());
        $this->mock(LegacyWorkspaceBackfillInspection::class)->shouldReceive('inspect')->once()->andReturn($this->successReport());
        $preflight = [
            'schema_version' => 1, 'status' => 'complete', 'mode' => 'read_only', 'findings' => [],
            'rollout_ready' => false, 'gates' => $this->gates(),
        ];
        $this->mock(LegacyOwnershipPreflight::class)->shouldReceive('inspect')->once()->andReturn($preflight);
        $queries = $this->captureQueries();

        $this->runInspection(['--connection' => 'sqlite', '--manifest' => $path], 0);
        $this->assertSame(0, Artisan::call('tenancy:ownership-preflight', ['--connection' => 'sqlite', '--manifest' => $path]));

        $this->assertSame($preflight, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame([], $queries->queries);
        $this->assertNoLogs();
    }

    public function test_shared_reader_preserves_exact_binary_bytes_and_limit_without_parsing_or_writing(): void
    {
        $reader = new OwnershipManifestFile;
        $content = "private-raw-content\0\xff";
        $file = $this->manifestFile($content);
        $this->assertSame($content, $reader->read($file));
        $this->assertSame($content, file_get_contents($file));
        $maximum = str_repeat('x', OwnershipManifest::MAX_BYTES);
        $maximumFile = $this->manifestFile($maximum);
        $this->assertSame($maximum, $reader->read($maximumFile));
        $oversizeFile = $this->manifestFile($maximum.'x');
        $exception = null;
        try {
            $reader->read($oversizeFile);
        } catch (LengthException $caught) {
            $exception = $caught;
        }
        $this->assertNotNull($exception);
        $this->assertNull($exception->getPrevious());
        $this->assertStringNotContainsString($oversizeFile, $exception->getMessage());
        $this->assertSame($maximum.'x', file_get_contents($oversizeFile));
        $this->assertNoLogs();
    }

    private function proposal(): string
    {
        return json_encode([
            'schema_version' => 1,
            'workspaces' => [[
                'reference' => 'private-reference-marker', 'name' => 'Private proposal marker',
                'owner_user_ids' => [91400871], 'event_ids' => [91400872],
            ]],
            'platform_admin_user_ids' => [],
        ], JSON_THROW_ON_ERROR);
    }

    private function successReport(): array
    {
        return [
            'schema_version' => 1, 'status' => 'complete', 'mode' => 'read_only', 'progress' => 'unstarted',
            'proposal' => ['workspaces' => 1, 'owner_memberships' => 1, 'events' => 1],
            'observed' => ['plan_present' => false, 'workspaces_verified' => 0, 'workspaces_remaining' => 1, 'events_assigned' => 0, 'events_remaining' => 1],
            'rollout_ready' => false, 'gates' => $this->gates(),
        ];
    }

    private function gates(): array
    {
        return [
            'ownership_mapping' => 'unverified', 'platform_grants' => 'unverified',
            'backup_restore' => 'unverified', 'mysql_rehearsal' => 'unverified',
        ];
    }

    private function manifestFile(string $content): string
    {
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codex-harusi-inspect-proposal-'.bin2hex(random_bytes(12)).'.json';
        file_put_contents($file, $content);
        $this->ownedFiles[] = $file;

        return $file;
    }

    private function configure(string $name, array $configuration): void
    {
        config(['database.connections.'.$name => $configuration]);
        $this->ownedConnections[] = $name;
    }

    private function captureQueries(): object
    {
        $captured = (object) ['queries' => []];
        DB::listen(static function (QueryExecuted $query) use ($captured): void {
            $captured->queries[] = $query->sql;
        });

        return $captured;
    }

    private function runInspection(array $options, int $expected): array
    {
        $exit = Artisan::call('tenancy:backfill-inspect', $options);
        $output = Artisan::output();
        $this->assertSame($expected, $exit, $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['schema_version']);
        $this->assertSame('read_only', $report['mode']);
        $this->assertFalse($report['rollout_ready']);
        $this->assertSame($this->gates(), $report['gates']);

        return [$report, $output];
    }

    private function assertPrivate(string $output, array $additional = []): void
    {
        foreach (array_merge([
            'private-', '/private/', 'Private proposal', '91400871', '91400872', 'SQLSTATE',
            'owner_user_ids', 'event_ids', 'platform_admin_user_ids', 'manifest_hash', 'example.invalid',
        ], $additional) as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
    }

    private function assertNoLogs(): void
    {
        foreach (['log', 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }
}
