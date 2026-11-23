<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\User;
use App\Services\OfflineScannerFileStorage;
use App\Services\OfflineScannerSessionAuditService;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Independent later-write contract; actual MySQL locking and durable recovery are not established. */
class LegacyOfflineSecureDeletionWritesTest extends TestCase
{
    private const REASON = 'Synthetic retention deletion reason.';

    private const ALTERNATE_DISK = 'secure_delete_writes_alternate_private';

    private string $fixtureDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new LogicException('Secure-delete write fixtures require isolated in-memory SQLite.');
        }
        $pdo = $db->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new LogicException('Secure-delete write fixtures require actual SQLite PDO.');
        }
        $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool => $row['name'] === 'main'));
        if (count($main) !== 1 || $main[0]['file'] !== '' || $db->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new LogicException('Secure-delete write fixtures require an in-memory main database and actual autocommit0.');
        }
        $temporaryRoot = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'codex-harusi-secure-delete-writes-'.bin2hex(random_bytes(8));
        (new Filesystem)->makeDirectory($temporaryRoot, 0777, true);
        (new Filesystem)->makeDirectory($temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views', 0777, true);
        $this->app->useStoragePath($temporaryRoot);
        config(['view.compiled' => $temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            'logging.channels.single.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log',
            'logging.channels.daily.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log']);
        $this->beforeApplicationDestroyed(static function () use ($db, $temporaryRoot): void {
            $db->disconnect();
            $resolved = realpath($temporaryRoot);
            $prefix = rtrim((string) realpath(sys_get_temp_dir()), '/\\').DIRECTORY_SEPARATOR.'codex-harusi-secure-delete-writes-';
            if ($resolved !== false && str_starts_with(strtolower($resolved), strtolower($prefix))) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        });
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
    }

    #[DataProvider('betweenStageDrifts')]
    public function test_http_between_stage_drift_cannot_authorize_the_next_write(string $kind, string $boundary, string $drift, int $status): void
    {
        $f = $this->fixture($kind);
        $expected = null;
        $changed = false;
        $hook = function () use ($f, $kind, $drift, &$expected, &$changed): void {
            $this->assertAutocommit();
            $changed = true;
            $this->applyDrift($f, $kind, $drift);
            $expected = $this->snapshot();
        };
        if ($boundary === 'after_bytes') {
            $f['files']->afterBytes = $hook;
        } else {
            $this->afterMetadataCommit($kind, $hook);
        }
        $response = $this->requestDeletion($kind);

        $this->assertTrue($changed, 'The mutation occurs after the named preceding phase, never in an earlier HTTP entry check.');
        $this->assertNotNull($expected);
        $response->assertStatus($status)->assertSessionMissing('success');
        $this->assertSame($expected, $this->snapshot(), 'Refusal conserves the complete post-mutation rows/files; byte removal and any already committed marker remain.');
        $this->assertOneByteRemoval($f);
        $this->assertRequestContext($response);
        $this->assertAutocommit();
    }

    public static function betweenStageDrifts(): array
    {
        $cases = [];
        foreach (['package', 'results'] as $kind) {
            foreach (['after_bytes', 'after_metadata'] as $boundary) {
                foreach (['owned_event' => 404, 'actor_inactive' => 403, 'actor_customer' => 403,
                    'session_parent' => 404, 'artifact_parent' => 404, 'path' => 404, 'disk' => 404,
                    'hash_and_mutable_model' => 404, 'size' => 404, 'uuid' => 404] as $drift => $status) {
                    $cases[$kind.' '.$boundary.' '.$drift] = [$kind, $boundary, $drift, $status];
                }
                if ($kind === 'results') {
                    foreach (['import_event', 'results_export', 'source_package_export'] as $drift) {
                        $cases[$kind.' '.$boundary.' '.$drift] = [$kind, $boundary, $drift, 404];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('markerDrifts')]
    public function test_http_audit_requires_the_actual_marker_persisted_by_this_metadata_stage(string $kind, string $field): void
    {
        $f = $this->fixture($kind);
        $expected = null;
        $changed = false;
        $this->afterMetadataCommit($kind, function () use ($kind, $field, &$expected, &$changed): void {
            $this->assertAutocommit();
            $changed = true;
            $value = match ($field) {
                'secure_deleted_at' => now()->subMinutes(7)->format('Y-m-d H:i:s'),
                'secure_deleted_by_user_id' => 215,
                'secure_deletion_reason' => 'Different persisted marker reason.',
            };
            DB::table($this->table($kind))->where('id', $this->resourceId($kind))->update([$field => $value]);
            $expected = $this->snapshot();
        });
        $response = $this->requestDeletion($kind);

        $this->assertTrue($changed);
        $response->assertForbidden()->assertSessionMissing('success');
        $this->assertSame($expected, $this->snapshot());
        $this->assertOneByteRemoval($f);
        $this->assertRequestContext($response);
        $this->assertAutocommit();
    }

    public static function markerDrifts(): array
    {
        $cases = [];
        foreach (['package', 'results'] as $kind) {
            foreach (['secure_deleted_at', 'secure_deleted_by_user_id', 'secure_deletion_reason'] as $field) {
                $cases[$kind.' '.$field] = [$kind, $field];
            }
        }

        return $cases;
    }

    #[DataProvider('callbackFaults')]
    public function test_http_cancelled_or_changed_callback_persistence_is_not_success(string $kind, string $mode, int $status): void
    {
        $f = $this->fixture($kind);
        if ($mode === 'audit_cancel_matching_history') {
            $this->matchingHistoricalAudit($f, $kind);
        }
        $before = $this->snapshot();
        $triggered = 0;
        $class = $kind === 'package' ? OfflineScannerPackage::class : OfflineScannerResultImport::class;
        $id = $this->resourceId($kind);
        if (str_starts_with($mode, 'metadata_')) {
            $event = match ($mode) {
                'metadata_cancel' => 'saving', default => 'updated',
            };
            $class::$event(function ($model) use ($id, $mode, &$triggered) {
                if ((int) $model->getRawOriginal('id') !== $id || ! $model->isDirty('secure_deleted_at')) {
                    return null;
                }
                $triggered++;
                if ($mode === 'metadata_cancel') {
                    return false;
                }
                if ($mode === 'metadata_wrong_marker') {
                    // Change only the persisted row; the callback model still
                    // carries the expected reason, so a model-only check fails.
                    DB::table($model->getTable())->where('id', $id)->update(['secure_deletion_reason' => 'private-wrong-marker']);
                } elseif ($mode === 'metadata_after_write_throw') {
                    throw new RuntimeException('database is locked; private-after-write-metadata-marker');
                } elseif ($mode === 'metadata_callback_owns_event') {
                    DB::table('events')->where('id', 301)->update(['workspace_id' => 101]);
                }

                return null;
            });
        } else {
            $event = in_array($mode, ['audit_cancel', 'audit_cancel_matching_history'], true) ? 'creating' : 'created';
            OfflineScannerSessionAudit::$event(function (OfflineScannerSessionAudit $audit) use ($mode, &$triggered) {
                if ((int) $audit->offline_scanner_session_id !== 401 || $audit->action !== OfflineScannerSessionAudit::ACTION_FILE_DELETED) {
                    return null;
                }
                $triggered++;
                if (in_array($mode, ['audit_cancel', 'audit_cancel_matching_history'], true)) {
                    return false;
                }
                if ($mode === 'audit_wrong_payload') {
                    $metadata = $audit->metadata;
                    $metadata['file_hash'] = 'private-changed-audit-hash';
                    // Keep the returned model unchanged while altering its
                    // persisted payload, requiring the actual primary read.
                    DB::table('offline_scanner_session_audits')->where('id', $audit->getRawOriginal('id') ?? $audit->id)
                        ->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
                } elseif ($mode === 'audit_after_write_throw') {
                    throw new RuntimeException('database is locked; private-after-write-audit-marker');
                } elseif ($mode === 'audit_callback_disables_actor') {
                    DB::table('users')->where('id', 211)->update(['is_active' => false]);
                }

                return null;
            });
        }
        $response = $this->requestDeletion($kind);

        $this->assertSame(1, $triggered);
        $response->assertStatus($status)->assertSessionMissing('success');
        if ($status === 302) {
            $this->assertGenericFeedback($response, $kind, $f['path']);
        }
        if (str_starts_with($mode, 'metadata_')) {
            $expected = $before;
            unset($expected['_files'][$f['path']]);
            $this->assertSame($expected, $this->snapshot(), 'Metadata cancellation/invalid marker/callback failure rolls back that stage and its own callback writes, leaving only completed byte loss.');
        } else {
            $this->assertMarkerOnlyDelta($before, $f, $kind);
        }
        $this->assertOneByteRemoval($f);
        $this->assertRequestContext($response);
        $this->assertAutocommit();
    }

    public static function callbackFaults(): array
    {
        $cases = [];
        foreach (['package', 'results'] as $kind) {
            foreach (['metadata_cancel' => 302, 'metadata_wrong_marker' => 302, 'metadata_after_write_throw' => 302,
                'metadata_callback_owns_event' => 404, 'audit_cancel' => 302, 'audit_wrong_payload' => 302,
                'audit_after_write_throw' => 302, 'audit_callback_disables_actor' => 403, 'audit_cancel_matching_history' => 302] as $mode => $status) {
                $cases[$kind.' '.$mode] = [$kind, $mode, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('positiveVariants')]
    public function test_http_positive_context_runs_three_separate_stages_with_one_unlink(string $kind, string $variant): void
    {
        $f = $this->fixture($kind);
        if ($variant === 'platform') {
            DB::table('users')->where('id', 211)->update(['is_platform_admin' => true]);
        } elseif ($variant === 'member') {
            DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 211, 'role' => 'owner', 'is_active' => true]);
        } elseif ($variant === 'missing') {
            Storage::disk($f['disk'])->delete($f['path']);
        } elseif ($variant === 'nullable') {
            $this->nullableAssetFixture($kind);
        } elseif ($variant === 'stored_timestamp') {
            // A synthetic DB normalization differs from now()/the update model.
            // Metadata must publish the actual persisted raw timestamp receipt.
            DB::connection()->unprepared('CREATE TEMP TRIGGER "normalize_delete_timestamp" AFTER UPDATE OF "secure_deleted_at" ON "offline_scanner_packages" '
                .'WHEN NEW."id" = 501 AND NEW."secure_deleted_at" IS NOT NULL AND NEW."secure_deleted_at" <> \'2026-10-29 17:10:11.123456\' '
                .'BEGIN UPDATE "offline_scanner_packages" SET "secure_deleted_at" = \'2026-10-29 17:10:11.123456\' WHERE "id" = 501; END');
        } elseif ($variant === 'callback_mutates_model_id') {
            $class = $kind === 'package' ? OfflineScannerPackage::class : OfflineScannerResultImport::class;
            $class::updated(function ($model) use ($kind): void {
                if ((int) $model->getRawOriginal('id') === $this->resourceId($kind) && $model->isDirty('secure_deleted_at')) {
                    $model->setAttribute('id', $kind === 'package' ? 502 : 602);
                }
            });
        }
        $ambient = $variant === 'nested' ? 1 : 0;
        $db = DB::connection();
        if ($ambient) {
            $db->beginTransaction();
        }
        $expectedBefore = null;
        $f['files']->afterBytes = function () use ($variant, &$expectedBefore): void {
            DB::table('users')->where('id', 211)->update(['name' => 'Current write-stage administrator']);
            if ($variant === 'retention_off') {
                config(['offline_scanner.retention.allow_secure_deletion' => false]);
            }
            $expectedBefore = $this->snapshot();
        };
        $starts = [];
        $db->beforeStartingTransaction(static function () use ($f, $db, &$starts): void {
            $starts[] = ['stage' => $f['files']->activeStage, 'level' => $db->transactionLevel(), 'pdo_transaction' => $db->getPdo()->inTransaction()];
        });
        $grammar = $this->inspectStages($f['files']);
        try {
            $response = $this->requestDeletion($kind);
            $grammar->observing = false;
            $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
                ->assertSessionHas('success', $this->successMessage($kind))->assertSessionHasNoErrors();
            $this->assertSame([
                ['stage' => 'byte', 'level' => $ambient, 'pdo_transaction' => (bool) $ambient],
                ['stage' => 'metadata', 'level' => $ambient, 'pdo_transaction' => (bool) $ambient],
                ['stage' => 'audit', 'level' => $ambient, 'pdo_transaction' => (bool) $ambient],
            ], $starts);
            $this->assertSame(['byte' => 1, 'metadata' => 1, 'audit' => 1], $f['files']->providerCalls);
            $this->assertSame(['metadata' => 1, 'audit' => 1], $f['files']->writeCalls);
            $this->assertSame(['metadata' => 1, 'audit' => 1], $f['files']->completedWrites, 'Neither provider return nor fallback may execute a second write.');
            foreach (['byte', 'metadata', 'audit'] as $stage) {
                $heads = array_values(array_filter($grammar->heads, static fn (array $head): bool => $head['stage'] === $stage));
                $this->assertSame(['events', 'users', 'offline_scanner_sessions', $this->table($kind)], array_column(array_slice($heads, 0, 4), 'table'));
                foreach ($heads as $head) {
                    $this->assertTrue($head['write']);
                    $this->assertSame(true, $head['lock']);
                    $this->assertSame($ambient + 1, $head['level']);
                    $this->assertTrue($head['pdo_transaction']);
                }
                $context = $f['files']->returnedContexts[$stage];
                $this->assertSame(['actor', 'disk', 'path', 'resource', 'session'], $context['keys']);
                $this->assertSame($this->resourceId($kind), $context['resource']['id']);
                $this->assertSame(401, $context['resource']['offline_scanner_session_id']);
                $this->assertSame(401, $context['session']['id']);
                $this->assertSame(301, $context['session']['event_id']);
                $this->assertSame(211, $context['actor']['id']);
                if ($stage !== 'byte') {
                    $this->assertSame('Current write-stage administrator', $context['actor']['name']);
                }
                $this->assertSame($f['path'], $context['path']);
                $this->assertSame($f['disk'], $context['disk']);
                $this->assertSame($ambient, $context['level']);
            }
            $this->assertNotNull($expectedBefore);
            $this->assertSuccessDelta($expectedBefore, $kind);
            if ($variant === 'stored_timestamp') {
                $this->assertSame('2026-10-29 17:10:11.123456', DB::table('offline_scanner_packages')->useWritePdo()->where('id', 501)->value('secure_deleted_at'));
            }
            $this->assertCount(1, $f['files']->existsCalls);
            $this->assertCount($variant === 'missing' ? 0 : 1, $f['files']->deleteCalls);
            foreach (array_merge($f['files']->existsCalls, $f['files']->deleteCalls) as $call) {
                $this->assertSame($ambient + 1, $call['level']);
                $this->assertTrue($call['pdo_transaction']);
            }
            $this->assertSame($ambient, $db->transactionLevel());
            $this->assertSame((bool) $ambient, $db->getPdo()->inTransaction());
            $this->assertRequestContext($response);
        } finally {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
        }
    }

    public static function positiveVariants(): array
    {
        $cases = [];
        foreach (['package', 'results'] as $kind) {
            foreach (['ordinary', 'platform', 'member', 'nested', 'retention_off', 'missing', 'nullable', 'callback_mutates_model_id'] as $variant) {
                $cases[$kind.' '.$variant] = [$kind, $variant];
            }
        }
        $cases['package stored_timestamp'] = ['package', 'stored_timestamp'];

        return $cases;
    }

    #[DataProvider('directSqlFaults')]
    public function test_direct_no_provider_sql_failures_keep_level_zero_and_named_legacy_partial_effects(string $kind, string $stage): void
    {
        $f = $this->fixture($kind);
        $before = $this->snapshot();
        $db = DB::connection();
        $message = $db->getPdo()->quote('private-direct-write-sql-marker');
        $trigger = $stage === 'metadata'
            ? 'CREATE TEMP TRIGGER "direct_write_metadata_abort" BEFORE UPDATE OF "secure_deleted_at" ON "'.$this->table($kind).'" WHEN OLD."id" = '.$this->resourceId($kind).' BEGIN SELECT RAISE(ABORT, '.$message.'); END'
            : 'CREATE TEMP TRIGGER "direct_write_audit_abort" BEFORE INSERT ON "offline_scanner_session_audits" WHEN NEW."offline_scanner_session_id" = 401 BEGIN SELECT RAISE(ABORT, '.$message.'); END';
        $db->unprepared($trigger);
        $attempts = [];
        $db->beforeExecuting(function (string $sql) use ($f, $kind, $stage, $db, &$attempts): void {
            $match = $stage === 'metadata' ? preg_match('/\Aupdate "'.preg_quote($this->table($kind), '/').'"/i', $sql) : preg_match('/\Ainsert into "offline_scanner_session_audits"/i', $sql);
            if ($match) {
                $attempts[] = ['level' => $db->transactionLevel(), 'pdo_transaction' => $db->getPdo()->inTransaction(), 'file_exists' => Storage::disk($f['disk'])->exists($f['path'])];
            }
        });
        $caught = false;
        try {
            if ($kind === 'package') {
                $f['files']->secureDeletePackage($f['resource'], $f['actor'], self::REASON);
            } else {
                $f['files']->secureDeleteResultImport($f['resource'], $f['actor'], self::REASON);
            }
        } catch (RuntimeException $error) {
            $caught = true;
            $this->assertStringContainsString('private-direct-write-sql-marker', $error->getMessage());
        }
        $this->assertTrue($caught);
        $this->assertSame([['level' => 0, 'pdo_transaction' => false, 'file_exists' => false]], $attempts);
        if ($stage === 'metadata') {
            unset($before['_files'][$f['path']]);
            $this->assertSame($before, $this->snapshot());
        } else {
            $this->assertMarkerOnlyDelta($before, $f, $kind);
        }
        $this->assertSame(['byte' => 0, 'metadata' => 0, 'audit' => 0], $f['files']->providerCalls);
        $this->assertOneByteRemoval($f, 0);
        $this->assertAutocommit();
    }

    public static function directSqlFaults(): array
    {
        return ['package metadata' => ['package', 'metadata'], 'package audit' => ['package', 'audit'],
            'results metadata' => ['results', 'metadata'], 'results audit' => ['results', 'audit']];
    }

    private function applyDrift(array $f, string $kind, string $drift): void
    {
        $table = $this->table($kind);
        $id = $this->resourceId($kind);
        if ($drift === 'owned_event') {
            DB::table('events')->where('id', 301)->update(['workspace_id' => 101]);
        } elseif ($drift === 'actor_inactive' || $drift === 'actor_customer') {
            DB::table('users')->where('id', 211)->update($drift === 'actor_inactive' ? ['is_active' => false] : ['role' => User::ROLE_CUSTOMER]);
        } elseif ($drift === 'session_parent') {
            DB::table('offline_scanner_sessions')->where('id', 401)->update(['event_id' => 302]);
        } else {
            [$field, $value] = match ($drift) {
                'artifact_parent' => ['offline_scanner_session_id', 402],
                'path' => [$kind === 'package' ? 'file_path' : 'encrypted_file_path', $kind === 'package' ? 'offline-packages/synthetic/502.wqrpkg' : 'offline-results/synthetic/602.wqrresults'],
                'disk' => ['storage_disk', self::ALTERNATE_DISK],
                'hash_and_mutable_model' => ['file_hash', hash('sha256', 'changed-under-mutable-caller')],
                'size' => ['encrypted_file_size', 13],
                'uuid' => [$kind === 'package' ? 'package_uuid' : 'import_uuid', (string) Str::uuid()],
                'import_event' => ['event_id', 302],
                'results_export' => ['results_export_id', (string) Str::uuid()],
                'source_package_export' => ['source_package_export_id', (string) Str::uuid()],
            };
            DB::table($table)->where('id', $id)->update([$field => $value]);
            if ($drift === 'hash_and_mutable_model') {
                $f['files']->lastContext['resource']->setAttribute($field, $value);
            }
        }
    }

    private function afterMetadataCommit(string $kind, Closure $callback): void
    {
        $scheduled = false;
        DB::connection()->getEventDispatcher()->listen(QueryExecuted::class, function (QueryExecuted $event) use ($kind, $callback, &$scheduled): void {
            if ($scheduled || preg_match('/\Aupdate "'.preg_quote($this->table($kind), '/').'"/i', $event->sql) !== 1 || ! str_contains($event->sql, '"secure_deleted_at"')) {
                return;
            }
            $scheduled = true;
            // Immediate on old autocommit; after the new metadata transaction's
            // physical commit, before the next provider, on protected source.
            $event->connection->afterCommit($callback);
        });
    }

    private function nullableAssetFixture(string $kind): void
    {
        $this->assertAutocommit();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        // Synthetic nullable legacy shape only; installed hash/package-size
        // columns are NOT NULL. No repository migration or real schema changes.
        Schema::table($this->table($kind), static function (Blueprint $table) use ($kind): void {
            $table->string('file_hash', 128)->nullable()->change();
            if ($kind === 'package') {
                $table->unsignedBigInteger('encrypted_file_size')->nullable()->change();
            }
        });
        $attributes = ['file_hash' => null, 'encrypted_file_size' => null];
        if ($kind === 'results') {
            $attributes += ['results_export_id' => null, 'source_package_export_id' => null];
        }
        DB::table($this->table($kind))->where('id', $this->resourceId($kind))->update($attributes);
    }

    private function matchingHistoricalAudit(array $f, string $kind): void
    {
        $resource = $f['resource'];
        $metadata = $kind === 'package' ? ['file_type' => 'package', 'package_uuid' => $resource->package_uuid]
            : ['file_type' => 'results', 'import_uuid' => $resource->import_uuid, 'results_export_id' => $resource->results_export_id];
        $metadata += ['file_hash' => $resource->file_hash, 'file_size' => $resource->encrypted_file_size, 'storage_disk' => $f['disk'], 'file_path' => $f['path']];
        OfflineScannerSessionAudit::query()->create(['offline_scanner_session_id' => 401, 'event_id' => 301,
            'actor_user_id' => 211, 'action' => OfflineScannerSessionAudit::ACTION_FILE_DELETED,
            'reason' => self::REASON, 'metadata' => $metadata, 'occurred_at' => now()->subDay()]);
    }

    private function assertMarkerOnlyDelta(array $before, array $f, string $kind): void
    {
        $after = $this->snapshot();
        $table = $this->table($kind);
        foreach ($after[$table] as $row) {
            if ((int) $row['id'] === $this->resourceId($kind)) {
                $current = $row;
            }
        }
        $this->assertNotNull($current['secure_deleted_at']);
        $this->assertSame(211, (int) $current['secure_deleted_by_user_id']);
        $this->assertSame(self::REASON, $current['secure_deletion_reason']);
        foreach ($before[$table] as &$row) {
            if ((int) $row['id'] === $this->resourceId($kind)) {
                foreach (['secure_deleted_at', 'secure_deleted_by_user_id', 'secure_deletion_reason', 'updated_at'] as $field) {
                    $row[$field] = $current[$field];
                }
            }
        }
        unset($row);
        unset($before['_files'][$f['path']]);
        $this->assertSame($before, $after, 'Only completed bytes and the committed exact metadata marker survive; old audits/neighbors remain and no new audit is accepted.');
    }

    private function assertOneByteRemoval(array $f, int $level = 1): void
    {
        $call = ['path' => $f['path'], 'disk' => $f['disk'], 'level' => $level, 'pdo_transaction' => $level > 0];
        $this->assertSame([$call], $f['files']->existsCalls);
        $this->assertSame([$call], $f['files']->deleteCalls);
        $this->assertFalse(Storage::disk($f['disk'])->exists($f['path']));
    }

    private function assertGenericFeedback(TestResponse $response, string $kind, string $path): void
    {
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHasErrors([$this->errorKey($kind) => 'Unable to delete encrypted offline scanner file.']);
        $feedback = json_encode(session('errors')->getBag('default')->getMessages(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([$path, self::REASON, 'private-', 'SQL:', 'offline_scanner_session_audits'] as $private) {
            $this->assertStringNotContainsString($private, $feedback);
        }
    }

    private function assertRequestContext(TestResponse $response): void
    {
        $response->assertSessionHas('selected_event_id', 301)->assertSessionHas('guest_import.marker', 'preserved');
    }

    private function assertAutocommit(): void
    {
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function successMessage(string $kind): string
    {
        return $kind === 'package' ? 'Offline scanner package file was securely deleted and audited.' : 'Offline scanner results file was securely deleted and audited.';
    }

    private function inspectStages(SecureDeletionWritesFixtureStorage $files): SecureDeletionWritesInspectingGrammar
    {
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $grammar = new SecureDeletionWritesInspectingGrammar($db);
        $grammar->files = $files;
        $db->setQueryGrammar($grammar);
        $this->beforeApplicationDestroyed(static fn () => $db->setQueryGrammar($original));

        return $grammar;
    }

    private function fixture(string $kind): array
    {
        $this->freezeTime();
        $disk = (string) config('offline_scanner.disk');
        $this->fixtureDisk = $disk;
        Storage::fake($disk);
        Storage::fake(self::ALTERNATE_DISK);
        config(['offline_scanner.retention.allow_secure_deletion' => true]);
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic deletion neighbor workspace']);
        Event::factory()->create(['id' => 301, 'workspace_id' => null, 'event_name' => 'Synthetic deletion Event']);
        Event::factory()->create(['id' => 302, 'workspace_id' => 101, 'event_name' => 'Synthetic owned neighbor Event']);
        $actor = User::factory()->create(['id' => 211, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        User::factory()->create(['id' => 215, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        Guest::query()->forceCreate(['id' => 901, 'event_id' => 302, 'name' => 'Synthetic retained neighbor Guest',
            'phone_number' => '+255712345678', 'manual_code' => '0901', 'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2, 'used_entries' => 1, 'status' => 'partially_used']);
        foreach ([401 => 301, 402 => 302] as $id => $eventId) {
            OfflineScannerSession::factory()->create(['id' => $id, 'event_id' => $eventId, 'created_by_user_id' => 215,
                'status' => OfflineScannerSession::STATUS_CLOSED, 'session_closed_at' => now()->subHour(),
                'results_imported_at' => now()->subHour(), 'package_expires_at' => now()->subDay()]);
        }
        foreach ([501 => 401, 502 => 402, 503 => 401] as $id => $parent) {
            $bytes = 'invented-private-package-deletion-bytes-'.$id;
            $package = OfflineScannerPackage::factory()->create(['id' => $id, 'offline_scanner_session_id' => $parent,
                'generated_by_user_id' => 215, 'file_name' => 'synthetic-'.$id.'.wqrpkg',
                'file_path' => 'offline-packages/synthetic/'.$id.'.wqrpkg', 'storage_disk' => $disk,
                'file_hash' => hash('sha256', $bytes), 'encrypted_file_size' => strlen($bytes),
                'revoked_at' => now()->subHours(2), 'archived_at' => now()->subHour(), 'archived_by_user_id' => 215]);
            Storage::disk($disk)->put($package->file_path, $bytes);
        }
        // Target package is historical, revoked and archived; none forbids secure deletion.
        DB::table('offline_scanner_sessions')->where('id', 401)->update(['package_export_id' => 503]);
        DB::table('offline_scanner_sessions')->where('id', 402)->update(['package_export_id' => 502]);
        foreach ([601 => [401, 301], 602 => [402, 302]] as $id => [$sessionId, $eventId]) {
            $bytes = 'invented-private-results-deletion-bytes-'.$id;
            $import = OfflineScannerResultImport::factory()->create(['id' => $id, 'offline_scanner_session_id' => $sessionId,
                'event_id' => $eventId, 'uploaded_by_user_id' => 215, 'status' => OfflineScannerResultImport::STATUS_FAILED,
                'encrypted_file_path' => 'offline-results/synthetic/'.$id.'.wqrresults', 'storage_disk' => $disk,
                'file_hash' => hash('sha256', $bytes), 'encrypted_file_size' => strlen($bytes),
                'archived_at' => now()->subHour(), 'archived_by_user_id' => 215]);
            Storage::disk($disk)->put($import->encrypted_file_path, $bytes);
        }
        OfflineScannerSessionAudit::query()->forceCreate(['id' => 902, 'offline_scanner_session_id' => 402,
            'event_id' => 302, 'actor_user_id' => 215, 'action' => OfflineScannerSessionAudit::ACTION_SESSION_CLOSED,
            'reason' => 'Synthetic neighbor retained history', 'metadata' => ['retain' => 'exact'], 'occurred_at' => now()->subHour()]);
        $resource = $kind === 'package' ? OfflineScannerPackage::query()->findOrFail(501) : OfflineScannerResultImport::query()->findOrFail(601);
        $path = $resource->getRawOriginal($kind === 'package' ? 'file_path' : 'encrypted_file_path');
        $files = new SecureDeletionWritesFixtureStorage(app(OfflineScannerSessionAuditService::class));
        $this->app->instance(OfflineScannerFileStorage::class, $files);
        $this->actingAs($actor)->withSession(['selected_event_id' => 301, 'guest_import' => ['marker' => 'preserved']]);

        return compact('actor', 'resource', 'files', 'disk', 'path');
    }

    private function requestDeletion(string $kind): TestResponse
    {
        return $this->from(route('admin.offline-scanner-sessions.show', 401))->delete(
            route($kind === 'package' ? 'admin.offline-scanner-packages.destroy' : 'admin.offline-scanner-results.destroy', $this->resourceId($kind)),
            ['deletion_reason' => '  '.self::REASON.'  ', 'confirm_secure_delete' => '1'],
        );
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $contents = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($contents, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $rows[$table->name] = $contents;
        }
        $files = Storage::disk($this->fixtureDisk)->allFiles();
        sort($files);
        $rows['_files'] = [];
        foreach ($files as $file) {
            $rows['_files'][$file] = hash('sha256', Storage::disk($this->fixtureDisk)->get($file));
        }

        $rows['_alternate_files'] = [];
        $alternate = Storage::disk(self::ALTERNATE_DISK)->allFiles();
        sort($alternate);
        foreach ($alternate as $file) {
            $rows['_alternate_files'][$file] = hash('sha256', Storage::disk(self::ALTERNATE_DISK)->get($file));
        }

        return $rows;
    }

    private function resourceId(string $kind): int
    {
        return $kind === 'package' ? 501 : 601;
    }

    private function table(string $kind): string
    {
        return $kind === 'package' ? 'offline_scanner_packages' : 'offline_scanner_result_imports';
    }

    private function errorKey(string $kind): string
    {
        return $kind === 'package' ? 'offline_package' : 'offline_results';
    }

    private function assertSuccessDelta(array $before, string $kind, ?string $deletedPath = null): void
    {
        $after = $this->snapshot();
        $table = $this->table($kind);
        $id = $this->resourceId($kind);
        $old = array_values(array_filter($before[$table], static fn ($row): bool => (int) $row['id'] === $id))[0];
        $current = array_values(array_filter($after[$table], static fn ($row): bool => (int) $row['id'] === $id))[0];
        $this->assertNotNull($current['secure_deleted_at']);
        $this->assertSame(211, (int) $current['secure_deleted_by_user_id']);
        $this->assertSame(self::REASON, $current['secure_deletion_reason']);
        foreach (['secure_deleted_at', 'secure_deleted_by_user_id', 'secure_deletion_reason', 'updated_at'] as $field) {
            $old[$field] = $current[$field];
        }
        $this->assertSame($old, $current);
        foreach ($before[$table] as &$row) {
            if ((int) $row['id'] === $id) {
                $row = $current;
            }
        }
        unset($row);
        $oldIds = array_column($before['offline_scanner_session_audits'], 'id');
        $extra = array_values(array_filter($after['offline_scanner_session_audits'], static fn ($row): bool => ! in_array($row['id'], $oldIds, true)));
        $retained = array_values(array_filter($after['offline_scanner_session_audits'], static fn ($row): bool => in_array($row['id'], $oldIds, true)));
        $this->assertSame($before['offline_scanner_session_audits'], $retained);
        $this->assertCount(1, $extra);
        $audit = $extra[0];
        $this->assertSame(401, (int) $audit['offline_scanner_session_id']);
        $this->assertSame(301, (int) $audit['event_id']);
        $this->assertSame(211, (int) $audit['actor_user_id']);
        $this->assertSame(OfflineScannerSessionAudit::ACTION_FILE_DELETED, $audit['action']);
        $this->assertSame(self::REASON, $audit['reason']);
        $this->assertNotNull($audit['occurred_at']);
        $metadata = $kind === 'package' ? ['file_type' => 'package', 'package_uuid' => $current['package_uuid']]
            : ['file_type' => 'results', 'import_uuid' => $current['import_uuid'], 'results_export_id' => $current['results_export_id']];
        $metadata += ['file_hash' => $current['file_hash'], 'file_size' => $current['encrypted_file_size'] === null ? null : (int) $current['encrypted_file_size'],
            'storage_disk' => $this->fixtureDisk, 'file_path' => $current[$kind === 'package' ? 'file_path' : 'encrypted_file_path']];
        $this->assertSame($metadata, json_decode($audit['metadata'], true, 32, JSON_THROW_ON_ERROR));
        $before['offline_scanner_session_audits'] = $after['offline_scanner_session_audits'];
        if ($deletedPath !== null) {
            unset($before['_files'][$deletedPath]);
        }
        $this->assertSame($before, $after, 'Only expected deletion metadata, one audit and the selected file may change.');
    }
}

final class SecureDeletionWritesFixtureStorage extends OfflineScannerFileStorage
{
    public ?Closure $afterBytes = null;

    public ?string $activeStage = null;

    public array $lastContext = [];

    public array $providerCalls = ['byte' => 0, 'metadata' => 0, 'audit' => 0];

    public array $writeCalls = ['metadata' => 0, 'audit' => 0];

    public array $completedWrites = ['metadata' => 0, 'audit' => 0];

    public array $returnedContexts = [];

    public array $existsCalls = [];

    public array $deleteCalls = [];

    private bool $observingWrites = false;

    public function secureDeletePackage(OfflineScannerPackage $package, User $actor, string $reason, mixed $byteContextProvider = null, mixed ...$laterProviders): OfflineScannerPackage
    {
        [$byte, $metadata, $audit] = $this->wrapProviders($byteContextProvider, $laterProviders);

        // Old user-defined PHP helpers ignore extra optional arguments. Never
        // invent a later provider merely to make the original-source run fail.
        return parent::secureDeletePackage($package, $actor, $reason, $byte, $metadata, $audit);
    }

    public function secureDeleteResultImport(OfflineScannerResultImport $resultImport, User $actor, string $reason, mixed $byteContextProvider = null, mixed ...$laterProviders): OfflineScannerResultImport
    {
        [$byte, $metadata, $audit] = $this->wrapProviders($byteContextProvider, $laterProviders);

        return parent::secureDeleteResultImport($resultImport, $actor, $reason, $byte, $metadata, $audit);
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        $this->existsCalls[] = $this->byteCall($path, $disk);

        return parent::exists($path, $disk);
    }

    public function delete(string $path, ?string $disk = null): bool
    {
        $this->deleteCalls[] = $this->byteCall($path, $disk);

        return parent::delete($path, $disk);
    }

    private function wrapProviders(mixed $byte, array $later): array
    {
        if (! $this->observingWrites) {
            $this->observingWrites = true;
            DB::connection()->getEventDispatcher()->listen(QueryExecuted::class, function (QueryExecuted $event): void {
                if (preg_match('/\Aupdate "offline_scanner_(packages|result_imports)"/i', $event->sql) === 1 && str_contains($event->sql, '"secure_deleted_at"')) {
                    $this->completedWrites['metadata']++;
                } elseif (preg_match('/\Ainsert into "offline_scanner_session_audits"/i', $event->sql) === 1) {
                    $this->completedWrites['audit']++;
                }
            });
        }
        $metadata = $later[0] ?? null;
        $audit = $later[1] ?? null;
        if ($byte instanceof Closure) {
            $original = $byte;
            $byte = function () use ($original): array {
                $this->activeStage = 'byte';
                $this->providerCalls['byte']++;
                try {
                    $context = $original();
                } finally {
                    $this->activeStage = null;
                }
                $this->recordContext('byte', $context);
                $hook = $this->afterBytes;
                $this->afterBytes = null;
                if ($hook !== null) {
                    $hook();
                }

                return $context;
            };
        }
        foreach (['metadata' => &$metadata, 'audit' => &$audit] as $stage => &$provider) {
            if ($provider instanceof Closure) {
                $original = $provider;
                $provider = function (array $previous, Closure $write) use ($stage, $original): array {
                    $this->activeStage = $stage;
                    $this->providerCalls[$stage]++;
                    $observedWrite = function (array $current) use ($stage, $write): mixed {
                        $this->writeCalls[$stage]++;

                        return $write($current);
                    };
                    try {
                        $context = $original($previous, $observedWrite);
                    } finally {
                        $this->activeStage = null;
                    }
                    $this->recordContext($stage, $context);

                    return $context;
                };
            }
        }
        unset($provider);

        return [$byte, $metadata, $audit];
    }

    private function recordContext(string $stage, array $context): void
    {
        $this->lastContext = $context;
        $keys = array_keys($context);
        sort($keys);
        $this->returnedContexts[$stage] = ['keys' => $keys, 'resource' => $context['resource']->getAttributes(),
            'session' => $context['session']->getAttributes(), 'actor' => $context['actor']->getAttributes(),
            'path' => $context['path'], 'disk' => $context['disk'], 'level' => DB::connection()->transactionLevel()];
    }

    private function byteCall(string $path, ?string $disk): array
    {
        return ['path' => $path, 'disk' => $disk ?: $this->diskName(), 'level' => DB::connection()->transactionLevel(),
            'pdo_transaction' => DB::connection()->getPdo()->inTransaction()];
    }
}

/** Primary/locking builder intent only; SQLite emits no actual FOR UPDATE. */
final class SecureDeletionWritesInspectingGrammar extends SQLiteGrammar
{
    public SecureDeletionWritesFixtureStorage $files;

    public bool $observing = true;

    public array $heads = [];

    public function compileSelect(Builder $query)
    {
        if ($this->observing && $query->lock !== null && in_array($query->from, ['events', 'users', 'offline_scanner_sessions', 'offline_scanner_packages', 'offline_scanner_result_imports'], true)) {
            $this->heads[] = ['stage' => $this->files->activeStage, 'table' => $query->from, 'write' => $query->useWritePdo,
                'lock' => $query->lock, 'level' => DB::connection()->transactionLevel(), 'pdo_transaction' => DB::connection()->getPdo()->inTransaction()];
        }

        return parent::compileSelect($query);
    }
}
