<?php

namespace App\Services\Tenancy;

use App\Models\Guest;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use RuntimeException;

class LegacyDataInventory
{
    /** Columns needed for this bounded inventory, not an exhaustive schema specification. */
    public const TABLES = [
        'migrations' => ['id', 'migration', 'batch'],
        'workspaces' => ['id', 'name'],
        'workspace_users' => ['id', 'workspace_id', 'user_id', 'role', 'is_active'],
        'workspace_provisionings' => ['id', 'creator_user_id', 'workspace_id', 'request_hash', 'name_hash'],
        'legacy_ownership_plans' => ['id', 'schema_version', 'manifest_hash'],
        'legacy_workspace_mappings' => ['id', 'ownership_plan_id', 'manifest_reference', 'workspace_id'],
        'users' => ['id', 'email', 'password', 'remember_token', 'phone_number', 'is_active', 'preferred_language', 'role', 'event_id', 'gate_name', 'is_platform_admin'],
        'events' => ['id', 'status', 'workspace_id'],
        'guests' => ['id', 'event_id', 'manual_code', 'pass_type', 'allowed_entries', 'used_entries', 'status'],
        'qr_codes' => ['id', 'guest_id', 'qr_token'],
        'admissions' => ['id', 'event_id', 'guest_id', 'quantity'],
        'checkins' => ['id', 'event_id', 'guest_id', 'qr_code_id', 'user_id'],
        'scan_logs' => ['id', 'event_id', 'guest_id'],
        'notification_logs' => ['id', 'event_id', 'guest_id'],
        'personal_access_tokens' => ['id', 'tokenable_type', 'tokenable_id', 'token', 'expires_at'],
        'password_reset_tokens' => ['email', 'token', 'created_at'],
        'sessions' => ['id', 'user_id'],
        'cache' => ['key'],
        'cache_locks' => ['key'],
        'jobs' => ['id'],
        'job_batches' => ['id'],
        'failed_jobs' => ['id'],
        'offline_scanner_sessions' => ['id'],
        'offline_scanner_session_users' => ['id'],
        'offline_scanner_packages' => ['id'],
        'offline_scanner_result_imports' => ['id'],
        'offline_scanner_imported_logs' => ['id'],
        'offline_scanner_import_conflicts' => ['id'],
        'offline_scanner_session_audits' => ['id'],
        'offline_scanner_result_devices' => ['id'],
        'offline_scanner_final_guest_states' => ['id'],
        'offline_scanner_package_guest_snapshots' => ['id'],
    ];

    private const UNIQUE_INDEXES = [
        'index_users_email_unique' => ['users', ['email']],
        'index_users_phone_unique' => ['users', ['phone_number']],
        'index_guests_event_manual_code_unique' => ['guests', ['event_id', 'manual_code']],
        'index_qr_codes_guest_unique' => ['qr_codes', ['guest_id']],
        'index_qr_codes_token_unique' => ['qr_codes', ['qr_token']],
        'index_personal_access_tokens_token_unique' => ['personal_access_tokens', ['token']],
    ];

    public function inspect(Connection $connection): array
    {
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException('Unsupported inventory database driver.');
        }

        $version = $connection->getServerVersion();
        $schema = $connection->getSchemaBuilder();
        $tables = [];
        $available = [];
        $findings = [];
        $complete = true;

        foreach (self::TABLES as $table => $required) {
            $present = $schema->hasTable($table);
            $available[$table] = $present ? $schema->getColumnListing($table) : [];
            $missing = array_values(array_diff($required, $available[$table]));
            $tables[$table] = [
                'present' => $present,
                'rows' => $present ? $connection->table($table)->count() : null,
                'missing_columns' => $missing,
            ];
            $complete = $complete && $present && $missing === [];
        }

        $migrations = $this->migrations($connection, $available);
        $this->finding($findings, 'migrations_pending', count($migrations['pending']), 'review');
        $this->finding($findings, 'migrations_unknown', $migrations['unknown_count'] ?? 0, 'review');

        foreach (self::UNIQUE_INDEXES as $key => [$table, $columns]) {
            if ($this->hasColumns($available, [$table => $columns])) {
                $this->finding($findings, $key, $this->hasUniqueIndex($connection, $table, $columns) ? 0 : 1);
            }
        }

        // ID-based paging must never skip rows in a malformed legacy schema.
        // Require the key's declared uniqueness and observed non-nullness before
        // using it for streaming or parent joins. Missing prerequisites remain
        // visible as an incomplete report, even if other checks found nothing.
        foreach (['guests', 'admissions'] as $table) {
            if (! $this->hasColumns($available, [$table => ['id']])) {
                continue;
            }

            if (! $this->hasUniqueIndex($connection, $table, ['id'])
                || $connection->table($table)->whereNull('id')->exists()) {
                $complete = false;
                $this->finding($findings, $table.'_invalid_stream_key', 1);
                $available[$table] = array_values(array_diff($available[$table], ['id']));
            }
        }

        $this->coreReferences($connection, $available, $findings);
        $this->guestValues($connection, $available, $findings);
        $this->duplicates($connection, $available, $findings);
        $this->reviewCounts($connection, $available, $findings);
        ksort($findings);

        return [
            'schema_version' => 1,
            'status' => $complete ? 'complete' : 'incomplete',
            'database' => ['driver' => $driver, 'version' => $version],
            'consistency' => 'best_effort',
            'tables' => $tables,
            'migrations' => $migrations,
            'findings' => $findings,
            'gates' => [
                'ownership_mapping' => 'unverified',
                'backup_restore' => 'unverified',
                'mysql_rehearsal' => 'unverified',
            ],
            'rollout_ready' => false,
            'limitations' => [
                'Observations are best effort and are not an atomic snapshot or a backup.',
                'Missing schema prerequisites skip dependent checks and make the report incomplete.',
                'Only selected columns, unique indexes and core relationships are checked.',
                'Duplicate findings count duplicate groups; other data findings count affected rows.',
                'Index and streaming-key findings count failed prerequisites, not affected rows.',
                'Offline tables are counted only; provenance, conflict semantics and files are not audited.',
                'Nullable historical links require review and are not automatically corrupt.',
                'Current scanner assignment is not compared with historical records; admission sums are not used to derive counters.',
                'No ownership, backup restoration or real MySQL rehearsal is established by this report.',
            ],
        ];
    }

    private function migrations(Connection $connection, array $available): array
    {
        if (! $this->hasColumns($available, ['migrations' => ['migration']])) {
            return ['applied_count' => null, 'pending' => [], 'unknown_count' => null];
        }

        $files = glob(database_path('migrations/*.php'));
        if ($files === false || $files === []) {
            throw new RuntimeException('Migration inventory is unavailable.');
        }

        $known = array_map(fn (string $file): string => basename($file, '.php'), $files);
        sort($known);
        // Fetch only known names. Unknown stored names may contain sensitive
        // operator input and must never become part of the report.
        $appliedKnown = $connection->table('migrations')->whereIn('migration', $known)
            ->distinct()->pluck('migration')->all();

        return [
            'applied_count' => $connection->table('migrations')->count(),
            'pending' => array_values(array_diff($known, $appliedKnown)),
            'unknown_count' => $connection->table('migrations')->where(function (Builder $query) use ($known): void {
                $query->whereNotIn('migration', $known)->orWhereNull('migration');
            })->count(),
        ];
    }

    private function hasUniqueIndex(Connection $connection, string $table, array $columns): bool
    {
        sort($columns);
        $partialIndexes = [];
        if ($connection->getDriverName() === 'sqlite') {
            $partialIndexes = $connection->query()->fromRaw('pragma_index_list(?)', [$connection->getTablePrefix().$table])
                ->where('partial', 1)->pluck('name')->map(fn (string $name): string => strtolower($name))->all();
        }

        foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {
            $indexedColumns = $index['columns'];
            sort($indexedColumns);
            if (! $index['unique'] || $indexedColumns !== $columns || in_array($index['name'], $partialIndexes, true)) {
                continue;
            }

            // SQLite's normalized index metadata omits expression columns.
            // A unique(email, expression) index does not make email unique.
            if ($connection->getDriverName() === 'sqlite' && ! $index['primary']
                && $connection->query()->fromRaw('pragma_index_xinfo(?)', [$index['name']])
                    ->where('key', 1)->where('cid', '<', 0)->exists()) {
                continue;
            }

            // MySQL's normalized metadata uses GROUP_CONCAT(column_name),
            // which omits NULL functional-index parts. Compare the raw part
            // count as well, so UNIQUE(email, expression) cannot prove email
            // uniqueness. fromRaw avoids applying the app's table prefix to
            // the fixed information_schema table itself.
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $parts = $connection->query()->fromRaw('information_schema.statistics')
                    ->where('table_schema', $connection->getDatabaseName())
                    ->where('table_name', $connection->getTablePrefix().$table)
                    ->whereRaw('LOWER(index_name) = ?', [strtolower($index['name'])])
                    ->selectRaw('COUNT(*) AS parts, COUNT(column_name) AS named_parts')->first();

                if ((int) $parts->parts !== count($columns)
                    || (int) $parts->named_parts !== count($columns)) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    private function coreReferences(Connection $connection, array $available, array &$findings): void
    {
        foreach ([
            ['guests', 'event_id', 'events', true, 'guests_missing_event', 'blocker'],
            ['qr_codes', 'guest_id', 'guests', true, 'qr_codes_missing_guest', 'blocker'],
            ['admissions', 'guest_id', 'guests', true, 'admissions_missing_guest', 'blocker'],
            ['admissions', 'event_id', 'events', true, 'admissions_missing_event', 'blocker'],
            ['checkins', 'event_id', 'events', true, 'checkins_missing_event', 'review'],
            ['checkins', 'guest_id', 'guests', false, 'checkins_missing_guest', 'review'],
            ['checkins', 'qr_code_id', 'qr_codes', false, 'checkins_missing_qr_code', 'review'],
            ['checkins', 'user_id', 'users', false, 'checkins_missing_user', 'review'],
            ['scan_logs', 'event_id', 'events', true, 'scan_logs_missing_event', 'review'],
            ['scan_logs', 'guest_id', 'guests', false, 'scan_logs_missing_guest', 'review'],
            ['notification_logs', 'event_id', 'events', false, 'notification_logs_missing_event', 'review'],
            ['notification_logs', 'guest_id', 'guests', false, 'notification_logs_missing_guest', 'review'],
        ] as [$table, $column, $parent, $required, $key, $severity]) {
            if (! $this->hasColumns($available, [$table => [$column], $parent => ['id']])) {
                continue;
            }

            $query = $connection->table($table.' as child')->leftJoin($parent.' as parent', 'child.'.$column, '=', 'parent.id')
                ->whereNull('parent.id');
            if (! $required) {
                $query->whereNotNull('child.'.$column);
            }
            $this->finding($findings, $key, $query->count(), $severity);
        }

        foreach (['admissions', 'checkins', 'scan_logs', 'notification_logs'] as $table) {
            if ($this->hasColumns($available, [$table => ['guest_id', 'event_id'], 'guests' => ['id', 'event_id']])) {
                $count = $connection->table($table.' as child')->join('guests as parent', 'child.guest_id', '=', 'parent.id')
                    ->whereColumn('child.event_id', '!=', 'parent.event_id')->count();
                $this->finding($findings, $table.'_guest_event_mismatch', $count, $table === 'admissions' ? 'blocker' : 'review');
            }
        }

        if ($this->hasColumns($available, ['checkins' => ['guest_id', 'qr_code_id'], 'qr_codes' => ['id', 'guest_id']])) {
            $count = $connection->table('checkins as child')->join('qr_codes as parent', 'child.qr_code_id', '=', 'parent.id')
                ->whereColumn('child.guest_id', '!=', 'parent.guest_id')->count();
            $this->finding($findings, 'checkins_qr_guest_mismatch', $count, 'review');
        }

        if ($this->hasColumns($available, ['users' => ['role', 'event_id'], 'events' => ['id']])) {
            $count = $connection->table('users as child')->leftJoin('events as parent', 'child.event_id', '=', 'parent.id')
                ->where('child.role', 'scanner')->whereNotNull('child.event_id')->whereNull('parent.id')->count();
            $this->finding($findings, 'scanners_missing_event', $count, 'review');
        }
    }

    private function guestValues(Connection $connection, array $available, array &$findings): void
    {
        $required = ['id', 'manual_code', 'pass_type', 'allowed_entries', 'used_entries', 'status'];
        if ($this->hasColumns($available, ['guests' => $required])) {
            $invalidCode = $invalidPass = $invalidUsage = $invalidStatus = 0;
            $connection->table('guests')->select($required)->chunkById(500, function ($rows) use (&$invalidCode, &$invalidPass, &$invalidUsage, &$invalidStatus): void {
                foreach ($rows as $row) {
                    if (! is_string($row->manual_code) || preg_match('/\A[0-9]{4}\z/', $row->manual_code) !== 1) {
                        $invalidCode++;
                    }
                    $allowed = $this->integer($row->allowed_entries);
                    $used = $this->integer($row->used_entries);
                    $validPass = match ($row->pass_type) {
                        'single' => $allowed === 1,
                        'double' => $allowed === 2,
                        'special' => $allowed !== null && $allowed >= Guest::MIN_SPECIAL_ENTRIES && $allowed <= Guest::MAX_ALLOWED_ENTRIES,
                        default => false,
                    };
                    if (! $validPass) {
                        $invalidPass++;
                    }
                    if ($allowed === null || $used === null || $allowed < 1 || $allowed > Guest::MAX_ALLOWED_ENTRIES || $used < 0 || $used > $allowed) {
                        $invalidUsage++;
                    }
                    if (! in_array($row->status, ['active', 'partially_used', 'fully_used', 'cancelled'], true)) {
                        $invalidStatus++;
                    }
                }
            });
            $this->finding($findings, 'guests_invalid_manual_code', $invalidCode);
            $this->finding($findings, 'guests_invalid_pass_bounds', $invalidPass);
            $this->finding($findings, 'guests_invalid_usage_bounds', $invalidUsage);
            $this->finding($findings, 'guests_invalid_status', $invalidStatus, 'review');
        }

        if ($this->hasColumns($available, ['admissions' => ['id', 'quantity']])) {
            $invalid = 0;
            $connection->table('admissions')->select(['id', 'quantity'])->chunkById(500, function ($rows) use (&$invalid): void {
                foreach ($rows as $row) {
                    $quantity = $this->integer($row->quantity);
                    if ($quantity === null || $quantity < 1 || $quantity > Guest::MAX_ALLOWED_ENTRIES) {
                        $invalid++;
                    }
                }
            });
            $this->finding($findings, 'admissions_invalid_quantity', $invalid);
        }
    }

    private function duplicates(Connection $connection, array $available, array &$findings): void
    {
        foreach ([
            ['guests', ['event_id', 'manual_code'], 'guests_duplicate_manual_code'],
            ['qr_codes', ['guest_id'], 'qr_codes_duplicate_guest'],
            ['qr_codes', ['qr_token'], 'qr_codes_duplicate_token'],
        ] as [$table, $columns, $key]) {
            if (! $this->hasColumns($available, [$table => $columns])) {
                continue;
            }

            $groups = $connection->table($table)->selectRaw('COUNT(*) AS duplicate_rows')->groupBy($columns)->havingRaw('COUNT(*) > 1');
            foreach ($columns as $column) {
                $groups->whereNotNull($column);
            }
            $this->finding($findings, $key, $connection->query()->fromSub($groups, 'duplicate_groups')->count());
        }
    }

    private function reviewCounts(Connection $connection, array $available, array &$findings): void
    {
        foreach ([
            ['notification_logs', 'event_id', 'notification_logs_eventless'],
            ['notification_logs', 'guest_id', 'notification_logs_guestless'],
            ['checkins', 'guest_id', 'checkins_guestless'],
            ['checkins', 'user_id', 'checkins_userless'],
            ['scan_logs', 'guest_id', 'scan_logs_guestless'],
        ] as [$table, $column, $key]) {
            if ($this->hasColumns($available, [$table => [$column]])) {
                $this->finding($findings, $key, $connection->table($table)->whereNull($column)->count(), 'review');
            }
        }

        if ($this->hasColumns($available, ['users' => ['role', 'event_id']])) {
            $this->finding($findings, 'scanners_unassigned', $connection->table('users')->where('role', 'scanner')->whereNull('event_id')->count(), 'review');
        }
    }

    private function hasColumns(array $available, array $requirements): bool
    {
        foreach ($requirements as $table => $columns) {
            if (array_diff($columns, $available[$table] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value) || preg_match('/\A-?[0-9]+\z/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? null : $integer;
    }

    private function finding(array &$findings, string $key, int $count, string $severity = 'blocker'): void
    {
        if ($count > 0) {
            $findings[$key] = ['severity' => $severity, 'count' => $count];
        }
    }
}
