<?php

namespace App\Console\Commands;

use App\Services\Tenancy\LegacyDataInventory;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

class TenancyPreflight extends Command
{
    protected $signature = 'tenancy:preflight {--connection= : Explicit configured database connection to inspect}';

    protected $description = 'Report a read-only aggregate legacy inventory; never authorize ownership rollout';

    public function handle(LegacyDataInventory $inventory, DatabaseManager $databases): int
    {
        $name = $this->option('connection');

        if (! is_string($name) || trim($name) === '') {
            return $this->unavailable('connection_required');
        }

        $configured = config('database.connections', []);

        if (! is_array($configured) || ! array_key_exists($name, $configured) || ! is_array($configured[$name])) {
            return $this->unavailable('unknown_connection');
        }

        try {
            // Resolving a named connection must never change the application's
            // default connection or invoke any schema/data repair path.
            $connection = $databases->connection($name);

            if (! in_array($connection->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
                return $this->unavailable('unsupported_driver');
            }

            $report = $inventory->inspect($connection);
            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // Query/connection exceptions include DSNs, credentials, paths and
            // bindings. Do not render, report or log their contents here.
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
            'reason' => $reason,
            'rollout_ready' => false,
            'gates' => [
                'ownership_mapping' => 'unverified',
                'backup_restore' => 'unverified',
                'mysql_rehearsal' => 'unverified',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return 2;
    }
}
