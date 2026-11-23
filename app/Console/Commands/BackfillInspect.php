<?php

namespace App\Console\Commands;

use App\Services\Tenancy\LegacyWorkspaceBackfillInspection;
use App\Services\Tenancy\OwnershipBackfillException;
use App\Services\Tenancy\OwnershipManifest;
use App\Services\Tenancy\OwnershipManifestFile;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use LengthException;
use Throwable;

class BackfillInspect extends Command
{
    protected $signature = 'tenancy:backfill-inspect
        {--connection= : Explicit configured database connection}
        {--manifest= : Private local JSON proposal to inspect without applying}';

    protected $description = 'Inspect exact ownership backfill progress with private read-only aggregate checks';

    public function handle(LegacyWorkspaceBackfillInspection $inspection, DatabaseManager $databases, OwnershipManifestFile $files): int
    {
        $name = $this->option('connection');
        if (! is_string($name) || trim($name) === '') {
            return $this->failure('connection_required');
        }

        $configured = config('database.connections', []);
        if (! is_array($configured) || ! array_key_exists($name, $configured) || ! is_array($configured[$name])) {
            return $this->failure('unknown_connection');
        }

        $path = $this->option('manifest');
        if (! is_string($path) || trim($path) === '') {
            return $this->failure('manifest_required');
        }

        try {
            $json = $files->read($path);
        } catch (LengthException) {
            return $this->failure('manifest_too_large');
        } catch (Throwable) {
            return $this->failure('manifest_unavailable');
        }

        try {
            $manifest = OwnershipManifest::fromJson($json);
        } catch (Throwable) {
            return $this->failure('invalid_manifest');
        }

        try {
            $connection = $databases->connection($name);
            if (! in_array($connection->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
                return $this->failure('unsupported_driver');
            }

            $report = $inspection->inspect($connection, $manifest);
            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (OwnershipBackfillException $exception) {
            $reason = $exception->reason();
            $conflict = in_array($reason, [
                'eligibility_changed', 'coverage_conflict', 'ledger_conflict', 'platform_proposal_unsupported',
            ], true);

            return $this->failure($reason, $conflict);
        } catch (Throwable) {
            // Never report or log private proposal data or raw DB exceptions.
            return $this->failure('database_unavailable');
        }

        $this->line($encoded);

        return self::SUCCESS;
    }

    private function failure(string $reason, bool $conflict = false): int
    {
        $this->line(json_encode([
            'schema_version' => 1,
            'status' => $conflict ? 'conflict' : 'unavailable',
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

        return $conflict ? 1 : 2;
    }
}
