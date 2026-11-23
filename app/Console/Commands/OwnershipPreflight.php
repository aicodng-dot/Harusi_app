<?php

namespace App\Console\Commands;

use App\Services\Tenancy\LegacyOwnershipPreflight;
use App\Services\Tenancy\OwnershipManifest;
use App\Services\Tenancy\OwnershipManifestFile;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use LengthException;
use Throwable;

class OwnershipPreflight extends Command
{
    protected $signature = 'tenancy:ownership-preflight
        {--connection= : Explicit configured database connection}
        {--manifest= : Private local JSON proposal to inspect without applying}';

    protected $description = 'Validate an explicit ownership proposal with read-only aggregate checks';

    public function handle(LegacyOwnershipPreflight $preflight, DatabaseManager $databases, OwnershipManifestFile $files): int
    {
        $name = $this->option('connection');
        if (! is_string($name) || trim($name) === '') {
            return $this->unavailable('connection_required');
        }

        $configured = config('database.connections', []);
        if (! is_array($configured) || ! array_key_exists($name, $configured) || ! is_array($configured[$name])) {
            return $this->unavailable('unknown_connection');
        }

        $path = $this->option('manifest');
        if (! is_string($path) || trim($path) === '') {
            return $this->unavailable('manifest_required');
        }

        try {
            $json = $files->read($path);
        } catch (LengthException) {
            return $this->unavailable('manifest_too_large');
        } catch (Throwable) {
            return $this->unavailable('manifest_unavailable');
        }

        try {
            $manifest = OwnershipManifest::fromJson($json);
        } catch (Throwable) {
            return $this->unavailable('invalid_manifest');
        }

        try {
            $connection = $databases->connection($name);
            if (! in_array($connection->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
                return $this->unavailable('unsupported_driver');
            }

            $report = $preflight->inspect($connection, $manifest);
            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // Do not report/log exceptions: connection details and proposal
            // contents must not be exposed through diagnostic output.
            return $this->unavailable('database_unavailable');
        }

        $this->line($encoded);
        if ($report['status'] !== 'complete') {
            return 2;
        }
        foreach ($report['findings'] as $finding) {
            if ($finding['count'] > 0) {
                return 1;
            }
        }

        return self::SUCCESS;
    }

    private function unavailable(string $reason): int
    {
        $this->line(json_encode([
            'schema_version' => 1,
            'status' => 'unavailable',
            'mode' => 'read_only',
            'reason' => $reason,
            'rollout_ready' => false,
            'gates' => [
                'ownership_mapping' => 'unverified',
                'platform_grants' => 'unverified',
                'backup_restore' => 'unverified',
                'mysql_rehearsal' => 'unverified',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return 2;
    }
}
