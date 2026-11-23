<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class RecoveryScriptSafetyTest extends TestCase
{
    private const DENIED = "Recovery apply is allowed only in local or testing environments.\n";

    private const USAGE = "Usage: php scripts/recover-wedding-data.php --base-csv=<path> --supplemental-csv=<path> --qr-dir=<path> [--apply]\n";

    #[DataProvider('missingInputInvocations')]
    public function test_missing_inputs_keep_read_only_usage_and_apply_environment_refusal(string $environment, bool $apply, bool $denied): void
    {
        $fixture = $this->fixture($environment);
        // realpath('') resolves the current directory in the legacy script.
        // An explicit absent base file exercises its Usage branch safely.
        $process = $this->invoke($fixture, ['--base-csv='.$fixture.'/missing-base.csv', ...($apply ? ['--apply'] : [])]);

        $this->assertNoDatabaseOrHashWork($fixture);
        $this->assertSame($denied ? 2 : 1, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertSame($denied ? self::DENIED : self::USAGE, $process->getErrorOutput());
    }

    public static function missingInputInvocations(): array
    {
        $cases = [];
        foreach (self::environments() as $label => $environment) {
            $cases[$label.' apply'] = [$environment, true, ! in_array($environment, ['local', 'testing'], true)];
            $cases[$label.' read only'] = [$environment, false, false];
        }

        return $cases;
    }

    #[DataProvider('dryRunEnvironments')]
    public function test_valid_invented_dry_run_keeps_validation_output_without_database_or_hash_work(string $environment): void
    {
        $fixture = $this->fixture($environment);
        $inputs = $this->inventedInputs($fixture);
        $before = $this->inputBytes($inputs);
        $process = $this->invoke($fixture, $this->inputArguments($inputs));

        $this->assertNoDatabaseOrHashWork($fixture);
        $this->assertSame(0, $process->getExitCode());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame(
            "Recovery validation passed: 1 guests (1 base + 0 supplemental, 0 duplicates skipped), 1 QR assets, 0 used entries.\n"
            ."Guest ID range: 1-1. First: Synthetic Recovery Guest. Last: Synthetic Recovery Guest.\n"
            ."Dry run only. Re-run with --apply after backing up the current database.\n",
            $process->getOutput(),
        );
        $this->assertSame($before, $this->inputBytes($inputs));
    }

    public static function dryRunEnvironments(): array
    {
        return ['production' => ['production'], 'local' => ['local']];
    }

    public function test_production_apply_refuses_valid_invented_inputs_before_validation_output_or_database_work(): void
    {
        $fixture = $this->fixture('production');
        $inputs = $this->inventedInputs($fixture);
        $before = $this->inputBytes($inputs);
        $process = $this->invoke($fixture, [...$this->inputArguments($inputs), '--apply']);

        $this->assertNoDatabaseOrHashWork($fixture);
        $this->assertSame(2, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertSame(self::DENIED, $process->getErrorOutput());
        $this->assertSame($before, $this->inputBytes($inputs));
    }

    #[DataProvider('invalidInputShapes')]
    public function test_invalid_input_shapes_return_usage_before_csv_or_database_work(string $shape, string $environment, bool $apply): void
    {
        $fixture = $this->fixture($environment);
        $inputs = $shape === 'no arguments' ? null : $this->inventedInputs($fixture);
        $before = $inputs === null ? null : $this->inputBytes($inputs);
        $arguments = match ($shape) {
            'no arguments' => [],
            'empty options' => ['--base-csv=', '--supplemental-csv=', '--qr-dir='],
            'repeated base option' => [...$this->inputArguments($inputs), '--base-csv='.$inputs['base']],
            'base CSV directory' => ['--base-csv='.$inputs['qr_directory'], '--supplemental-csv='.$inputs['supplemental'], '--qr-dir='.$inputs['qr_directory']],
            'supplemental CSV directory' => ['--base-csv='.$inputs['base'], '--supplemental-csv='.$inputs['qr_directory'], '--qr-dir='.$inputs['qr_directory']],
            'QR directory file' => ['--base-csv='.$inputs['base'], '--supplemental-csv='.$inputs['supplemental'], '--qr-dir='.$inputs['qr']],
        };
        $process = $this->invoke($fixture, [...$arguments, ...($apply ? ['--apply'] : [])]);

        $this->assertNoDatabaseOrHashWork($fixture);
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertSame(self::USAGE, $process->getErrorOutput());
        if ($inputs !== null) {
            $this->assertSame($before, $this->inputBytes($inputs));
        }
    }

    public static function invalidInputShapes(): array
    {
        $cases = [];
        foreach (['no arguments', 'empty options', 'repeated base option', 'base CSV directory',
            'supplemental CSV directory', 'QR directory file'] as $shape) {
            $cases[$shape.' production read only'] = [$shape, 'production', false];
            $cases[$shape.' local apply'] = [$shape, 'local', true];
        }

        return $cases;
    }

    private static function environments(): array
    {
        return ['production' => 'production', 'staging' => 'staging', 'development' => 'development',
            'upper local' => 'LOCAL', 'mixed testing' => 'Testing', 'empty' => '', 'trailing testing' => 'testing ',
            'local' => 'local', 'testing' => 'testing'];
    }

    private function fixture(string $environment): string
    {
        $project = dirname(__DIR__, 2);
        $fixture = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codex-harusi-recovery-cli-test-'.bin2hex(random_bytes(12));
        foreach (['scripts', 'vendor', 'bootstrap', 'environment', 'storage/framework/views',
            'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs', 'storage/app'] as $directory) {
            $this->assertTrue(mkdir($fixture.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory), 0700, true));
        }
        $source = $project.'/scripts/recover-wedding-data.php';
        $copy = $fixture.'/scripts/recover-wedding-data.php';
        $this->assertTrue(copy($source, $copy));
        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $copy));
        $this->write($fixture.'/vendor/autoload.php', "<?php\nini_set('display_errors', '0');\nini_set('log_errors', '0');\nrequire ".var_export($project.'/vendor/autoload.php', true).";\n");

        $wrapper = <<<'PHP'
<?php

$fixtureEnvironment = __FIXTURE_ENVIRONMENT__;
// Pin exact configured values, including a blank APP_ENV, before Laravel boots.
// The real project's .env is outside this fixture's environment directory.
foreach (['APP_ENV' => $fixtureEnvironment, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
    'DB_URL' => '', 'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'WHATSAPP_PROVIDER' => 'null', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require __REAL_BOOTSTRAP__;
// Laravel's default absolute-cache prefixes omit Windows drive letters.
if (preg_match('/\A[A-Za-z]:/', dirname(__DIR__))) {
    $app->addAbsoluteCachePathPrefix(substr(dirname(__DIR__), 0, 2));
}
$app->useEnvironmentPath(dirname(__DIR__).'/environment');
$app->loadEnvironmentFrom('.env.fixture-absent');
$work = (object) ['bootstraps' => 0, 'connections' => 0, 'queries' => 0, 'transactions' => 0, 'connectors' => 0, 'hashes' => 0];
register_shutdown_function(static function () use ($work): void {
    file_put_contents(dirname(__DIR__).'/work.json', json_encode($work, JSON_THROW_ON_ERROR), LOCK_EX);
});
$app->make('events')->listen(\Illuminate\Database\Events\ConnectionEstablished::class,
    static function ($event) use ($work): void {
        $work->connections++;
        $event->connection->beforeExecuting(static function () use ($work): void {
            $work->queries++;
        });
        $event->connection->beforeStartingTransaction(static function () use ($work): void {
            $work->transactions++;
        });
    });
// Every driver is intercepted so an unexpected future path cannot leave this
// isolated fixture. SQLite is the explicitly configured connection.
foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
    $app->bind('db.connector.'.$driver, static fn () => new class($work) implements \Illuminate\Database\Connectors\ConnectorInterface
    {
        public function __construct(private object $work) {}

        public function connect(array $config)
        {
            $this->work->connectors++;
            throw new \RuntimeException('Synthetic recovery connector must remain unused.');
        }
    });
}
$app->extend('hash', static fn ($manager, $container) => new class($container, $work) extends \Illuminate\Hashing\HashManager
{
    public function __construct($container, private object $work)
    {
        parent::__construct($container);
    }

    public function make(#[\SensitiveParameter] $value, array $options = [])
    {
        $this->work->hashes++;
        throw new \RuntimeException('Synthetic recovery hashing must remain unused.');
    }
});
$app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\BootProviders::class,
    static function ($application) use ($work, $fixtureEnvironment): void {
        foreach (['getCachedConfigPath' => 'APP_CONFIG_CACHE', 'getCachedServicesPath' => 'APP_SERVICES_CACHE',
            'getCachedPackagesPath' => 'APP_PACKAGES_CACHE', 'getCachedEventsPath' => 'APP_EVENTS_CACHE',
            'getCachedRoutesPath' => 'APP_ROUTES_CACHE'] as $method => $key) {
            if ($application->{$method}() !== getenv($key)) {
                throw new \RuntimeException('Synthetic recovery cache isolation guard failed.');
            }
        }
        if ($application->environment() !== $fixtureEnvironment
            || $application['config']->get('database.default') !== 'sqlite'
            || $application['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Synthetic recovery configuration guard failed.');
        }
        $work->bootstraps++;
    });

return $app;
PHP;
        $this->write($fixture.'/bootstrap/app.php', str_replace(
            ['__FIXTURE_ENVIRONMENT__', '__REAL_BOOTSTRAP__'],
            [var_export($environment, true), var_export($project.'/bootstrap/app.php', true)],
            $wrapper,
        ));

        // These tiny, uniquely named TEMP fixtures remain available for diagnosis.
        // No customer inputs or project files are deleted or traversed.
        return $fixture;
    }

    private function invoke(string $fixture, array $arguments): Process
    {
        $storage = $fixture.'/storage';
        $process = new Process([PHP_BINARY, $fixture.'/scripts/recover-wedding-data.php', ...$arguments], $fixture, [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('f', 32)),
            'APP_CONFIG_CACHE' => $fixture.'/bootstrap/absent-config.php',
            'APP_SERVICES_CACHE' => $fixture.'/bootstrap/services.php',
            'APP_PACKAGES_CACHE' => $fixture.'/bootstrap/packages.php',
            'APP_EVENTS_CACHE' => $fixture.'/bootstrap/absent-events.php',
            'APP_ROUTES_CACHE' => $fixture.'/bootstrap/absent-routes.php',
            'LARAVEL_STORAGE_PATH' => $storage, 'APP_MAINTENANCE_DRIVER' => 'file',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'DB_HOST' => '127.0.0.1', 'DB_USERNAME' => '', 'DB_PASSWORD' => '',
            'MAIL_MAILER' => 'array', 'WHATSAPP_PROVIDER' => 'null', 'LOG_CHANNEL' => 'null',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'BCRYPT_ROUNDS' => '4',
        ]);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    private function assertNoDatabaseOrHashWork(string $fixture): void
    {
        $this->assertFileExists($fixture.'/work.json');
        $work = json_decode(file_get_contents($fixture.'/work.json'), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame(['bootstraps' => 1, 'connections' => 0, 'queries' => 0,
            'transactions' => 0, 'connectors' => 0, 'hashes' => 0], $work);
    }

    private function inventedInputs(string $fixture): array
    {
        $this->assertTrue(mkdir($fixture.'/inputs/qr', 0700, true));
        $inputs = ['base' => $fixture.'/inputs/base.csv', 'supplemental' => $fixture.'/inputs/supplemental.csv',
            'qr_directory' => $fixture.'/inputs/qr', 'qr' => $fixture.'/inputs/qr/guest-1-'.str_repeat('a', 64).'.png'];
        $this->csv($inputs['base'], [
            ['Guest ID', 'Guest name', 'Phone number', 'Pass type', 'Allowed entries', 'Used entries',
                'Guest status', 'QR status', 'Generated at', 'Revoked at', 'Download file name'],
            ['1', 'Synthetic Recovery Guest', '+255712345678', 'single', '1', '0', 'active', 'active', '', '', ''],
        ]);
        $this->csv($inputs['supplemental'], [['name', 'phone_number', 'pass_type', 'allowed_entries']]);
        // The existing parser reads this metadata marker; this is deliberately
        // an invented byte fixture, not a claim to validate or decode a PNG.
        $this->write($inputs['qr'], "Synthetic metadata fixture\nManual admission code\x000042");

        return $inputs;
    }

    private function inputArguments(array $inputs): array
    {
        return ['--base-csv='.$inputs['base'], '--supplemental-csv='.$inputs['supplemental'], '--qr-dir='.$inputs['qr_directory']];
    }

    private function inputBytes(array $inputs): array
    {
        return array_map(static fn (string $path): string => file_get_contents($path),
            [$inputs['base'], $inputs['supplemental'], $inputs['qr']]);
    }

    private function csv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);
        try {
            foreach ($rows as $row) {
                $this->assertNotFalse(fputcsv($handle, $row, ',', '"', ''));
            }
        } finally {
            fclose($handle);
        }
    }

    private function write(string $path, string $contents): void
    {
        $this->assertSame(strlen($contents), file_put_contents($path, $contents, LOCK_EX));
    }
}
