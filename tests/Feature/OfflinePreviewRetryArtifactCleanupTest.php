<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\OfflineScannerSessionUser;
use App\Models\QrCode;
use App\Models\User;
use App\Services\CanonicalJson;
use App\Services\OfflineScannerFileStorage;
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use App\Services\OfflineScannerSessionAuditService;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Root callback retry regression; broader commit/rollback recovery is separate.
 * SQL aborts and explicit PDO commits are deterministic controls, not contention
 * or lost-acknowledgment simulations. All files belong to fake private disks.
 * Private fixture helpers are copied from the independent P4-032 probe; this
 * class intentionally inherits only Tests\TestCase and never RefreshDatabase.
 */
class OfflinePreviewRetryArtifactCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new LogicException('Artifact recovery regressions require isolated in-memory SQLite.');
        }
        $pdo = $db->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new LogicException('Artifact recovery regressions require the actual SQLite PDO driver.');
        }
        $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), static fn (array $database): bool => $database['name'] === 'main'));
        if (count($main) !== 1 || $main[0]['file'] !== '') {
            throw new LogicException('Artifact recovery regressions refuse a file-backed main database.');
        }
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new LogicException('Artifact recovery regressions require level zero and PDO autocommit.');
        }
        $this->beforeApplicationDestroyed(static fn () => $db->disconnect());
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
        $this->freezeTime();
        Storage::fake($this->confirmationOfflineScannerDisk());
    }

    public function test_first_callback_sql_abort_then_retry_success_removes_only_prior_artifact_before_put(): void
    {
        $f = $this->retryFixture();
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $s = $this->armSqlAbort($f, 1);
        $response = $this->postRetryUpload($f, $contents);

        $this->assertAttemptEvidence($f, $s, 2, 1, $contents);
        $paths = array_column($f['files']->putCalls, 'path');
        $this->assertSame([$paths[0]], array_column($f['files']->deleteCalls, 'path'));
        $this->assertSame(2, $f['files']->deleteCalls[0]['attempt']);
        $this->assertSame(['put', 'exists', 'delete', 'put'], array_column($f['files']->timeline, 'op'));
        $this->assertFalse(Storage::disk($f['disk'])->exists($paths[0]));
        $this->assertFalse(DB::table('offline_scanner_result_imports')->where('encrypted_file_path', $paths[0])->exists());
        $import = OfflineScannerResultImport::query()->where('offline_scanner_session_id', $f['session']->id)->sole();
        $this->assertSame($paths[1], $import->encrypted_file_path);
        $response->assertRedirect(route('admin.offline-scanner-results.show', $import))->assertSessionHasNoErrors();
        $this->assertPreviewEffects($before, $f, $import, $contents, []);
        $this->assertRequestContextRetained($response, $f);
        $this->assertAutocommit();
    }

    public function test_three_callback_aborts_remove_all_known_prior_and_existing_final_artifacts(): void
    {
        $f = $this->retryFixture();
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $s = $this->armSqlAbort($f, 3);
        $response = $this->postRetryUpload($f, $contents);

        $this->assertAttemptEvidence($f, $s, 3, 3, $contents);
        $paths = array_column($f['files']->putCalls, 'path');
        $this->assertSame($paths, array_column($f['files']->existsCalls, 'path'));
        $this->assertSame($paths, array_column($f['files']->deleteCalls, 'path'));
        $this->assertSame([2, 3, 3], array_column($f['files']->deleteCalls, 'attempt'));
        $this->assertSame([1, 1, 0], array_column($f['files']->deleteCalls, 'level'));
        $this->assertSame([true, true, false], array_column($f['files']->deleteCalls, 'pdo_transaction'));
        $this->assertSame(['put', 'exists', 'delete', 'put', 'exists', 'delete', 'put', 'exists', 'delete'], array_column($f['files']->timeline, 'op'));
        $this->assertUploadedOnly($before, $f);
        $this->assertSafeGenericFeedback($response, $f, $paths);
        $this->assertAutocommit();
    }

    public function test_ordinary_nonconcurrency_refusal_preserves_existing_single_attempt_cleanup(): void
    {
        $f = $this->retryFixture();
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $s = $this->armSqlAbort($f, 3, null, false);
        $response = $this->postRetryUpload($f, $contents);

        $this->assertAttemptEvidence($f, $s, 1, 1, $contents);
        $path = $f['files']->putCalls[0]['path'];
        $this->assertSame([$path], array_column($f['files']->existsCalls, 'path'));
        $this->assertSame([$path], array_column($f['files']->deleteCalls, 'path'));
        $this->assertSame(0, $f['files']->deleteCalls[0]['level']);
        $this->assertFalse($f['files']->deleteCalls[0]['pdo_transaction']);
        $this->assertUploadedOnly($before, $f);
        $this->assertSafeGenericFeedback($response, $f, [$path]);
        $this->assertAutocommit();
    }

    public function test_prior_artifact_already_missing_is_tolerated_without_delete(): void
    {
        $f = $this->retryFixture();
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $s = $this->armSqlAbort($f, 1, function () use ($f): void {
            $path = $f['files']->putCalls[0]['path'];
            $this->assertTrue(Storage::disk($f['disk'])->delete($path));
        });
        $response = $this->postRetryUpload($f, $contents);

        $this->assertAttemptEvidence($f, $s, 2, 1, $contents);
        $this->assertSame([$f['files']->putCalls[0]['path']], array_column($f['files']->existsCalls, 'path'));
        $this->assertSame([], $f['files']->deleteCalls);
        $this->assertSame(['put', 'exists', 'put'], array_column($f['files']->timeline, 'op'));
        $import = OfflineScannerResultImport::query()->where('offline_scanner_session_id', $f['session']->id)->sole();
        $response->assertRedirect(route('admin.offline-scanner-results.show', $import))->assertSessionHasNoErrors();
        $this->assertPreviewEffects($before, $f, $import, $contents, []);
        $this->assertAutocommit();
    }

    #[DataProvider('failedDeletionModes')]
    public function test_unsuccessful_prior_deletion_stops_before_new_put_and_retains_safe_feedback(string $mode): void
    {
        $f = $this->retryFixture();
        $f['files']->deleteFailure = $mode;
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $s = $this->armSqlAbort($f, 1);
        $response = $this->postRetryUpload($f, $contents);

        $this->assertSame(2, $s->attempts);
        $this->assertCount(1, $f['files']->putCalls, 'Failed prior cleanup cannot silently create a new preview file.');
        $path = $f['files']->putCalls[0]['path'];
        $this->assertSame([$path], array_column($f['files']->existsCalls, 'path'));
        $this->assertSame([$path], array_column($f['files']->deleteCalls, 'path'));
        $this->assertSame(2, $f['files']->deleteCalls[0]['attempt']);
        $this->assertSame(1, $f['files']->deleteCalls[0]['level']);
        $this->assertTrue($f['files']->deleteCalls[0]['pdo_transaction']);
        $this->assertSame(['put', 'exists', 'delete'], array_column($f['files']->timeline, 'op'));
        $this->assertUploadedOnly($before, $f, [$path => hash('sha256', $contents)]);
        $this->assertSame($contents, Storage::disk($f['disk'])->get($path));
        $this->assertSafeGenericFeedback($response, $f, [$path]);
        $this->assertAutocommit();
    }

    public static function failedDeletionModes(): array
    {
        return ['false deletion' => ['false'], 'throwing deletion' => ['throw'],
            'transient concurrency-shaped storage refusal' => ['throw_concurrency']];
    }

    #[DataProvider('lostAuthorityModes')]
    public function test_authority_loss_before_retry_refuses_before_any_more_protected_file_access(string $mode, int $status): void
    {
        $f = $this->retryFixture();
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $s = $this->armSqlAbort($f, 1, function () use ($f, $mode): void {
            if ($mode === 'event_owned') {
                DB::table('events')->where('id', $f['event']->id)->update(['workspace_id' => 211]);
            } else {
                DB::table('users')->where('id', $f['admin']->id)->update(['is_active' => false]);
            }
        });
        $response = $this->postRetryUpload($f, $contents);

        $response->assertStatus($status);
        $this->assertSame(2, $s->attempts);
        $this->assertCount(1, $f['files']->putCalls);
        $this->assertSame([], $f['files']->existsCalls);
        $this->assertSame([], $f['files']->deleteCalls);
        $path = $f['files']->putCalls[0]['path'];
        $this->assertSame($contents, Storage::disk($f['disk'])->get($path));
        $this->assertSame($s->starts[1]['snapshot'], $this->confirmationSnapshot(), 'Current authority refusal adds no preview rows, audit or file effects.');
        $this->assertStringNotContainsString($path, $response->getContent());
        $this->assertAutocommit();
    }

    public static function lostAuthorityModes(): array
    {
        return ['Event acquired by workspace' => ['event_owned', 404], 'administrator becomes inactive' => ['actor_inactive', 403]];
    }

    public function test_retry_cleanup_uses_captured_disk_and_keeps_same_path_decoy_on_new_disk(): void
    {
        $f = $this->retryFixture();
        $newDisk = 'artifact_recovery_new_private';
        Storage::fake($newDisk);
        $oldFiles = $this->fileHashes($f['disk']);
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $s = $this->armSqlAbort($f, 1, function () use ($f, $newDisk): void {
            $path = $f['files']->putCalls[0]['path'];
            config(['offline_scanner.disk' => $newDisk]);
            Storage::disk($newDisk)->put($path, 'retained-new-disk-decoy');
        });
        $response = $this->postRetryUpload($f, $contents);

        $this->assertSame(2, $s->attempts);
        $this->assertCount(2, $f['files']->putCalls);
        $firstPath = $f['files']->putCalls[0]['path'];
        $this->assertSame([$f['disk'], $newDisk], array_column($f['files']->putCalls, 'disk'));
        $this->assertSame([$f['disk']], array_column($f['files']->existsCalls, 'disk'));
        $this->assertSame([$f['disk']], array_column($f['files']->deleteCalls, 'disk'));
        $this->assertSame($oldFiles, $this->fileHashes($f['disk']), 'Old private-disk neighbors remain exact and the known rolled-back artifact is gone.');
        $this->assertSame('retained-new-disk-decoy', Storage::disk($newDisk)->get($firstPath));
        $import = OfflineScannerResultImport::query()->where('offline_scanner_session_id', $f['session']->id)->sole();
        $this->assertSame($newDisk, $import->storage_disk);
        $response->assertRedirect(route('admin.offline-scanner-results.show', $import))->assertSessionHasNoErrors();
        $before['_files'] = [$firstPath => hash('sha256', 'retained-new-disk-decoy')];
        $this->assertPreviewEffects($before, $f, $import, $contents, []);
        $this->assertAutocommit();
    }

    #[DataProvider('committedReferenceModes')]
    public function test_any_current_path_reference_vetoes_cleanup_without_hydrating_foreign_import(string $mode): void
    {
        $f = $this->retryFixture();
        if ($mode === 'null_disk_foreign_event') {
            // Future/malformed legacy-schema fixture only. Installed storage_disk is NOT NULL.
            $this->assertAutocommit();
            $this->assertSame('sqlite', DB::connection()->getDriverName());
            $this->assertSame(':memory:', DB::connection()->getDatabaseName());
            Schema::table('offline_scanner_result_imports', static function (Blueprint $table): void {
                $table->string('storage_disk', 80)->nullable()->change();
            });
        }
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $grammar = $this->inspectReferenceQueries($f['files']);
        $hydrated = [];
        OfflineScannerResultImport::retrieved(function (OfflineScannerResultImport $model) use ($f, &$hydrated): void {
            if ($f['files']->attempt >= 2) {
                $hydrated[] = $model->getKey();
            }
        });
        $s = $this->armSqlAbort($f, 1, function () use ($f, $mode): void {
            $path = $f['files']->putCalls[0]['path'];
            $neighbor = DB::table('offline_scanner_result_imports')->where('encrypted_file_path', 'retry-neighbor/retained-results.wqrresults')->first();
            $reference = OfflineScannerResultImport::factory()->create([
                'offline_scanner_session_id' => $mode === 'same_disk_same_event' ? $f['session']->id : $neighbor->offline_scanner_session_id,
                'event_id' => $mode === 'same_disk_same_event' ? $f['event']->id : $neighbor->event_id,
                'uploaded_by_user_id' => $f['admin']->id,
                'encrypted_file_path' => $path,
                'storage_disk' => $mode === 'different_disk_foreign_event' ? 'unrelated-private-metadata' : $f['disk'],
            ]);
            if ($mode === 'null_disk_foreign_event') {
                DB::table('offline_scanner_result_imports')->where('id', $reference->id)->update(['storage_disk' => null]);
            }
        });
        $response = $this->postRetryUpload($f, $contents);

        $this->assertSame(2, $s->attempts);
        $this->assertCount(1, $f['files']->putCalls);
        $path = $f['files']->putCalls[0]['path'];
        $this->assertSame([], $f['files']->existsCalls, 'A positive path reference vetoes before file existence or deletion, even if disk metadata differs or is NULL.');
        $this->assertSame([], $f['files']->deleteCalls);
        $this->assertSame([], $hydrated, 'The reference safeguard must not hydrate a private Import.');
        $this->assertReferenceLookupIntent($grammar, $path);
        $this->assertSame($contents, Storage::disk($f['disk'])->get($path));
        $this->assertSame($s->starts[1]['snapshot'], $this->confirmationSnapshot());
        $this->assertSafeGenericFeedback($response, $f, [$path]);
        $this->assertAutocommit();
    }

    public static function committedReferenceModes(): array
    {
        return [
            'same path and same disk, same Event' => ['same_disk_same_event'],
            'same path, foreign Event and different disk' => ['different_disk_foreign_event'],
            'same path, foreign Event and NULL disk legacy fixture' => ['null_disk_foreign_event'],
        ];
    }

    #[DataProvider('rawCommitModes')]
    public function test_explicit_raw_commit_before_callback_concurrency_throw_preserves_committed_import_and_file(bool $beginAgain): void
    {
        $f = $this->retryFixture();
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $grammar = $this->inspectReferenceQueries($f['files']);
        $s = $this->installScenario($f);
        $committed = null;
        $pdoState = null;
        DB::connection()->beforeExecuting(function (string $sql) use ($f, $beginAgain, &$committed, &$pdoState): void {
            if ($f['files']->attempt !== 1 || preg_match('/\Ainsert into "offline_scanner_result_devices"/i', $sql) !== 1) {
                return;
            }
            $db = DB::connection();
            $pdo = $db->getPdo();
            $this->assertSame(1, $db->transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            $this->assertTrue($pdo->commit());
            $committed = $this->confirmationSnapshot();
            if ($beginAgain) {
                $this->assertTrue($pdo->beginTransaction());
            }
            $pdoState = ['pdo' => spl_object_id($pdo), 'level' => $db->transactionLevel(), 'open' => $pdo->inTransaction()];
            throw new RuntimeException('database is locked; synthetic-explicit-raw-commit-marker');
        });
        $response = $this->postRetryUpload($f, $contents);

        $this->assertNotNull($committed);
        $this->assertSame(['pdo' => spl_object_id(DB::connection()->getPdo()), 'level' => 1, 'open' => $beginAgain], $pdoState);
        $this->assertSame(2, $s->attempts);
        $this->assertCount(1, $f['files']->putCalls);
        $path = $f['files']->putCalls[0]['path'];
        $this->assertSame([], $f['files']->existsCalls);
        $this->assertSame([], $f['files']->deleteCalls);
        $this->assertSame($committed, $this->confirmationSnapshot(), 'Explicit PDO commit is observable, not an uncertain commit simulation; its Import and bytes must survive retry refusal.');
        $this->assertSame($contents, Storage::disk($f['disk'])->get($path));
        $this->assertSame(1, DB::table('offline_scanner_result_imports')->where('encrypted_file_path', $path)->count());
        if ($beginAgain) {
            $this->assertReferenceLookupIntent($grammar, $path);
            $this->assertSafeGenericFeedback($response, $f, [$path]);
        } else {
            $response->assertRedirect(route('admin.offline-scanner-sessions.show', $f['session']))->assertSessionHasErrors('offline_results');
            $this->assertFeedbackContainsNoPrivateDetails([$path]);
        }
        $this->assertAutocommit();
    }

    public static function rawCommitModes(): array
    {
        return ['raw commit with PDO closed' => [false], 'raw commit then raw begin on the same PDO' => [true]];
    }

    #[DataProvider('successfulCallbackCommitErrorModes')]
    public function test_successful_callback_commit_class_error_leaves_earlier_artifact_outside_retry_cleanup(bool $physicallyCommit): void
    {
        $f = $this->retryFixture();
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $s = $this->installScenario($f);
        $commitState = null;
        $s->dispatcher->listen(TransactionCommitting::class, function () use ($f, $physicallyCommit, &$commitState): void {
            if ($f['files']->attempt !== 1) {
                return;
            }
            $db = DB::connection();
            $this->assertSame(1, $db->transactionLevel());
            $this->assertTrue($db->getPdo()->inTransaction());
            $this->assertSame(2, DB::table('offline_scanner_result_devices')->where('offline_scanner_session_id', $f['session']->id)->count());
            $this->assertSame(3, DB::table('offline_scanner_imported_logs')->where('offline_scanner_session_id', $f['session']->id)->count());
            $this->assertSame(1, DB::table('offline_scanner_session_audits')->where('offline_scanner_session_id', $f['session']->id)->where('action', OfflineScannerSessionAudit::ACTION_RESULTS_VERIFIED)->count());
            if ($physicallyCommit) {
                $this->assertTrue($db->getPdo()->commit());
            }
            $commitState = $this->confirmationSnapshot();
            throw new RuntimeException('database is locked; synthetic-successful-callback-commit-marker');
        });
        $response = $this->postRetryUpload($f, $contents);

        $this->assertNotNull($commitState, 'The observer runs after the complete callback, inside Laravel commit handling.');
        $this->assertSame(2, $s->attempts);
        $this->assertSame([], $f['files']->existsCalls, 'A commit-class exception never marks the earlier successful callback artifact for retry cleanup.');
        $this->assertSame([], $f['files']->deleteCalls);
        $firstPath = $f['files']->putCalls[0]['path'];
        $this->assertSame($contents, Storage::disk($f['disk'])->get($firstPath));
        if ($physicallyCommit) {
            $this->assertCount(1, $f['files']->putCalls);
            $this->assertSame($commitState, $this->confirmationSnapshot());
            $response->assertRedirect(route('admin.offline-scanner-sessions.show', $f['session']))->assertSessionHasErrors('offline_results');
            $this->assertFeedbackContainsNoPrivateDetails([$firstPath]);
        } else {
            $this->assertCount(2, $f['files']->putCalls);
            $import = OfflineScannerResultImport::query()->where('offline_scanner_session_id', $f['session']->id)->sole();
            $this->assertSame($f['files']->putCalls[1]['path'], $import->encrypted_file_path);
            $response->assertRedirect(route('admin.offline-scanner-results.show', $import))->assertSessionHasNoErrors();
            $this->assertPreviewEffects($before, $f, $import, $contents, [$firstPath]);
        }
        $this->assertAutocommit();
    }

    public static function successfulCallbackCommitErrorModes(): array
    {
        return ['commit-phase throw before physical commit' => [false], 'explicit physical commit then commit-phase throw' => [true]];
    }

    private function installScenario(array $f, ?Closure $beforeRetry = null): object
    {
        $db = DB::connection();
        $s = (object) ['attempts' => 0, 'starts' => [], 'rollbacks' => [], 'sqlFailures' => [], 'triggerArmed' => false, 'dispatcher' => new Dispatcher($this->app)];
        $originalDispatcher = $db->getEventDispatcher();
        $this->beforeApplicationDestroyed(static fn () => $db->setEventDispatcher($originalDispatcher));
        $s->dispatcher->listen(TransactionRolledBack::class, function () use ($f, $db, $s): void {
            $s->rollbacks[] = ['attempt' => $f['files']->attempt, 'level' => $db->transactionLevel(), 'pdo_transaction' => $db->getPdo()->inTransaction(),
                'exists' => count($f['files']->existsCalls), 'deletes' => count($f['files']->deleteCalls), 'snapshot' => $this->confirmationSnapshot()];
        });
        $db->setEventDispatcher($s->dispatcher);
        $db->beforeStartingTransaction(function () use ($f, $db, $s, $beforeRetry): void {
            if (! DB::table('offline_scanner_session_audits')->where('offline_scanner_session_id', $f['session']->id)->where('action', OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED)->exists()) {
                return;
            }
            $s->attempts++;
            $f['files']->attempt = $s->attempts;
            if ($s->attempts > 1 && $beforeRetry) {
                $beforeRetry();
            }
            $s->starts[] = ['attempt' => $s->attempts, 'level' => $db->transactionLevel(), 'pdo_transaction' => $db->getPdo()->inTransaction(), 'snapshot' => $this->confirmationSnapshot()];
        });

        return $s;
    }

    private function armSqlAbort(array $f, int $failedAttempts, ?Closure $beforeRetry = null, bool $concurrency = true): object
    {
        $s = null;
        $retry = function () use ($failedAttempts, $beforeRetry, &$s): void {
            if ($s->attempts > $failedAttempts && $s->triggerArmed) {
                DB::connection()->unprepared('DROP TRIGGER "synthetic_artifact_recovery_abort"');
                $s->triggerArmed = false;
            }
            if ($beforeRetry) {
                $beforeRetry();
            }
        };
        $s = $this->installScenario($f, $retry);
        $s->triggerArmed = true;
        $message = DB::connection()->getPdo()->quote($concurrency ? 'database is locked; private-artifact-recovery-marker' : 'synthetic ordinary refusal; private-artifact-recovery-marker');
        DB::connection()->unprepared('CREATE TEMP TRIGGER "synthetic_artifact_recovery_abort" BEFORE INSERT ON "offline_scanner_result_devices" WHEN NEW."offline_scanner_session_id" = '.(int) $f['session']->id.' BEGIN SELECT RAISE(ABORT, '.$message.'); END');
        DB::connection()->beforeExecuting(function (string $sql) use ($f, $s): void {
            if (! $s->triggerArmed || preg_match('/\Ainsert into "offline_scanner_result_devices"/i', $sql) !== 1) {
                return;
            }
            $import = DB::table('offline_scanner_result_imports')->where('offline_scanner_session_id', $f['session']->id)->sole();
            $s->sqlFailures[] = ['attempt' => $f['files']->attempt, 'level' => DB::connection()->transactionLevel(), 'pdo_transaction' => DB::connection()->getPdo()->inTransaction(),
                'path' => $import->encrypted_file_path, 'uuid' => $import->import_uuid, 'devices' => DB::table('offline_scanner_result_devices')->where('offline_scanner_session_id', $f['session']->id)->count()];
        });

        return $s;
    }

    private function assertAttemptEvidence(array $f, object $s, int $attempts, int $failures, string $contents): void
    {
        $this->assertSame($attempts, $s->attempts);
        $this->assertCount($attempts, $s->starts);
        $this->assertCount($attempts, $f['files']->putCalls);
        $this->assertCount($failures, $s->sqlFailures);
        $this->assertCount($failures, $s->rollbacks);
        $paths = array_column($f['files']->putCalls, 'path');
        $this->assertCount($attempts, array_unique($paths));
        foreach ($s->starts as $i => $start) {
            $this->assertSame($i + 1, $start['attempt']);
            $this->assertSame(0, $start['level']);
            $this->assertFalse($start['pdo_transaction']);
        }
        foreach ($f['files']->putCalls as $i => $put) {
            $this->assertSame($i + 1, $put['attempt']);
            $this->assertSame(1, $put['level']);
            $this->assertTrue($put['pdo_transaction']);
            $this->assertSame($f['disk'], $put['disk']);
            $this->assertSame(hash('sha256', $contents), $put['hash']);
            $uuid = pathinfo($put['path'], PATHINFO_FILENAME);
            $this->assertTrue(Str::isUuid($uuid));
            $this->assertSame('offline-results/'.$f['session']->session_uuid.'/'.$uuid.'.wqrresults', $put['path']);
        }
        foreach ($s->sqlFailures as $i => $failure) {
            $this->assertSame($i + 1, $failure['attempt']);
            $this->assertSame(1, $failure['level']);
            $this->assertTrue($failure['pdo_transaction']);
            $this->assertSame($paths[$i], $failure['path']);
            $this->assertSame(pathinfo($paths[$i], PATHINFO_FILENAME), $failure['uuid']);
            $this->assertSame(0, $failure['devices']);
            $this->assertSame(0, $s->rollbacks[$i]['level']);
            $this->assertFalse($s->rollbacks[$i]['pdo_transaction']);
            $this->assertSame($i, $s->rollbacks[$i]['deletes'], 'Rollback notification adds no file deletion; only a later authorized callback may clean prior artifacts.');
        }
    }

    private function assertUploadedOnly(array $before, array $f, array $remaining = []): void
    {
        $after = $this->confirmationSnapshot();
        $this->assertAuditSnapshot($before, $after, $f, [OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED]);
        $before['offline_scanner_session_audits'] = $after['offline_scanner_session_audits'];
        $before['_files'] = array_merge($before['_files'], $remaining);
        ksort($before['_files']);
        $this->assertSame($before, $after, 'Only preliminary upload audit and explicitly retained operation-local artifacts survive; every neighbor stays exact.');
    }

    private function assertSafeGenericFeedback($response, array $f, array $paths): void
    {
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', $f['session']))
            ->assertSessionHasErrors(['offline_results' => 'Unable to verify the Android scanner results file.'])->assertSessionMissing('success');
        $this->assertFeedbackContainsNoPrivateDetails($paths);
        $this->assertRequestContextRetained($response, $f);
    }

    private function assertFeedbackContainsNoPrivateDetails(array $paths): void
    {
        $feedback = json_encode(session('errors')->getBag('default')->all(), JSON_THROW_ON_ERROR);
        foreach (array_merge($paths, ['database is locked', 'synthetic ordinary refusal', 'private-artifact-recovery-marker', 'synthetic-explicit-raw-commit-marker', 'synthetic-successful-callback-commit-marker', 'fixture-delete-private-marker']) as $private) {
            $this->assertStringNotContainsString($private, $feedback);
        }
    }

    private function assertRequestContextRetained($response, array $f): void
    {
        $response->assertSessionHas('selected_event_id', $f['event']->id)->assertSessionHas('guest_import.marker', 'retained');
    }

    private function assertAutocommit(): void
    {
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function fileHashes(string $disk): array
    {
        $result = [];
        foreach (Storage::disk($disk)->allFiles() as $path) {
            $result[$path] = hash('sha256', Storage::disk($disk)->get($path));
        }
        ksort($result);

        return $result;
    }

    private function inspectReferenceQueries(ArtifactRecoveryFixtureFiles $files): ArtifactRecoveryInspectingSqliteGrammar
    {
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $grammar = new ArtifactRecoveryInspectingSqliteGrammar($db);
        $grammar->files = $files;
        $db->setQueryGrammar($grammar);
        $this->beforeApplicationDestroyed(static fn () => $db->setQueryGrammar($original));

        return $grammar;
    }

    private function assertReferenceLookupIntent(ArtifactRecoveryInspectingSqliteGrammar $grammar, string $path): void
    {
        $queries = array_values(array_filter($grammar->referenceLookups, static fn (array $query): bool => $query['attempt'] === 2 && $query['level'] === 1 && in_array($path, $query['bindings'], true)));
        $this->assertNotEmpty($queries, 'Retry cleanup requires a current primary locking ID-only lookup for any exact-path Import reference.');
        foreach ($queries as $query) {
            $this->assertSame(true, $query['lock']);
            $this->assertSame(true, $query['write']);
            $this->assertSame(1, $query['level']);
            $this->assertTrue($query['pdo_transaction']);
            $this->assertCount(1, $query['columns']);
            $this->assertContains($query['columns'][0], ['id', 'offline_scanner_result_imports.id']);
            $this->assertStringNotContainsString('event_id', $query['where_json']);
            $this->assertStringNotContainsString('storage_disk', $query['where_json'], 'Any matching path vetoes; NULL/different disk metadata cannot grant deletion.');
        }
    }

    private function retryFixture(): array
    {
        $f = $this->confirmationFixture();
        $f['disk'] = $this->confirmationOfflineScannerDisk();
        DB::table('workspaces')->insert(['id' => 211, 'name' => 'Synthetic retry neighbor workspace']);
        $neighbor = Event::factory()->create(['event_name' => 'Synthetic owned retry neighbor', 'workspace_id' => 211]);
        $otherSession = OfflineScannerSession::factory()->create(['event_id' => $neighbor->id, 'created_by_user_id' => $f['admin']->id]);
        OfflineScannerPackage::factory()->create(['offline_scanner_session_id' => $otherSession->id,
            'generated_by_user_id' => $f['admin']->id, 'file_name' => 'retained-retry-package.wqrpkg',
            'storage_disk' => $f['disk'], 'file_path' => 'retry-neighbor/retained-package.wqrpkg']);
        OfflineScannerResultImport::factory()->create(['offline_scanner_session_id' => $otherSession->id,
            'event_id' => $neighbor->id, 'uploaded_by_user_id' => $f['admin']->id,
            'encrypted_file_path' => 'retry-neighbor/retained-results.wqrresults', 'storage_disk' => $f['disk']]);
        OfflineScannerSessionAudit::query()->create(['offline_scanner_session_id' => $otherSession->id,
            'event_id' => $neighbor->id, 'actor_user_id' => $f['admin']->id,
            'action' => OfflineScannerSessionAudit::ACTION_SESSION_CREATED, 'metadata' => ['retain' => 'exact'], 'occurred_at' => now()]);
        Storage::disk($f['disk'])->put('retry-neighbor/retained-package.wqrpkg', 'invented-retained-retry-package');
        Storage::disk($f['disk'])->put('retry-neighbor/retained-results.wqrresults', 'invented-retained-retry-results');
        $f['files'] = new ArtifactRecoveryFixtureFiles(app(OfflineScannerSessionAuditService::class));
        $this->app->instance(OfflineScannerFileStorage::class, $f['files']);

        return $f;
    }

    private function postRetryUpload(array $f, string $contents)
    {
        return $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id, 'guest_import' => ['marker' => 'retained']])
            ->from(route('admin.offline-scanner-sessions.show', $f['session']))
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $f['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent('observed-preview-retry.wqrresults', $contents),
            ]);
    }

    private function assertAuditSnapshot(array $before, array $after, array $f, array $actions): void
    {
        $table = 'offline_scanner_session_audits';
        $oldIds = array_column($before[$table], 'id');
        $retained = array_values(array_filter($after[$table], static fn ($row): bool => in_array($row['id'], $oldIds, true)));
        $added = array_values(array_filter($after[$table], static fn ($row): bool => ! in_array($row['id'], $oldIds, true)));
        $this->assertSame($before[$table], $retained);
        $this->assertCount(count($actions), $added);
        $actual = array_column($added, 'action');
        sort($actual);
        sort($actions);
        $this->assertSame($actions, $actual);
        foreach ($added as $row) {
            $this->assertSame($f['session']->id, $row['offline_scanner_session_id']);
            $this->assertSame($f['event']->id, $row['event_id']);
            $this->assertSame($f['admin']->id, $row['actor_user_id']);
        }
    }

    private function assertPreviewEffects(array $before, array $f, OfflineScannerResultImport $import, string $contents, array $unreferencedPaths): void
    {
        $this->assertSame(OfflineScannerResultImport::STATUS_PREVIEW_READY, $import->status);
        $this->assertSame($f['event']->id, $import->event_id);
        $this->assertSame($f['admin']->id, $import->uploaded_by_user_id);
        $this->assertSame(OfflineScannerSession::STATUS_RESULTS_UPLOADED, $f['session']->fresh()->status);
        $this->assertSame($contents, Storage::disk($this->confirmationOfflineScannerDisk())->get($import->encrypted_file_path));
        $this->assertSame(hash('sha256', $contents), $import->file_hash);
        $after = $this->confirmationSnapshot();
        $previousSessions = array_column($before['offline_scanner_sessions'], null, 'id');
        foreach ($after['offline_scanner_sessions'] as $row) {
            $old = $previousSessions[$row['id']];
            if ($row['id'] === $f['session']->id) {
                $this->assertSame($f['event']->id, $row['active_event_id']);
                foreach (['status', 'updated_at', 'active_event_id'] as $field) {
                    unset($old[$field], $row[$field]);
                }
            }
            $this->assertSame($old, $row);
        }
        $before['offline_scanner_sessions'] = $after['offline_scanner_sessions'];
        foreach (['offline_scanner_result_imports' => 1, 'offline_scanner_result_devices' => 2,
            'offline_scanner_imported_logs' => 3, 'offline_scanner_final_guest_states' => 2] as $table => $count) {
            $oldIds = array_column($before[$table], 'id');
            $retained = array_values(array_filter($after[$table], static fn ($row): bool => in_array($row['id'], $oldIds, true)));
            $added = array_values(array_filter($after[$table], static fn ($row): bool => ! in_array($row['id'], $oldIds, true)));
            $this->assertSame($before[$table], $retained);
            $this->assertCount($count, $added);
            foreach ($added as $row) {
                $this->assertSame($f['session']->id, $row['offline_scanner_session_id']);
                if ($table === 'offline_scanner_result_imports') {
                    $this->assertSame($import->id, $row['id']);
                    $this->assertSame($f['event']->id, $row['event_id']);
                } else {
                    $this->assertSame($import->id, $row['offline_scanner_result_import_id']);
                }
            }
            $before[$table] = $after[$table];
        }
        $this->assertAuditActions($before, $f, [OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED, OfflineScannerSessionAudit::ACTION_RESULTS_VERIFIED]);
        $before['offline_scanner_session_audits'] = $after['offline_scanner_session_audits'];
        $before['_files'][$import->encrypted_file_path] = hash('sha256', $contents);
        foreach ($unreferencedPaths as $path) {
            $before['_files'][$path] = hash('sha256', $contents);
        }
        ksort($before['_files']);
        $this->assertSame($before, $after, 'The committed preview and its referenced file are retained along with the explicitly observed earlier unreferenced artifact; every neighbor is conserved.');
    }

    private function assertAuditActions(array $before, array $f, array $actions): array
    {
        $after = $this->confirmationSnapshot();
        $table = 'offline_scanner_session_audits';
        $oldIds = array_column($before[$table], 'id');
        $retained = array_values(array_filter($after[$table], static fn ($row): bool => in_array($row['id'], $oldIds, true)));
        $added = array_values(array_filter($after[$table], static fn ($row): bool => ! in_array($row['id'], $oldIds, true)));
        $this->assertSame($before[$table], $retained, 'Existing audit history stays intact.');
        $this->assertCount(count($actions), $added);
        $actualActions = array_column($added, 'action');
        sort($actualActions);
        sort($actions);
        $this->assertSame($actions, $actualActions);
        foreach ($added as $row) {
            $this->assertSame($f['session']->id, $row['offline_scanner_session_id']);
            $this->assertSame($f['event']->id, $row['event_id']);
            $this->assertSame($f['admin']->id, $row['actor_user_id']);
        }

        return $after;
    }

    private function confirmationFixture(): array
    {
        $event = Event::factory()->create([
            'event_name' => 'ON Wedding',
            'venue_name' => 'Ocean Hall',
            'event_date' => '2026-08-01',
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $master = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Main Gate',
            'name' => 'Master Scanner',
        ]);
        $client = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Garden Gate',
            'name' => 'Client Scanner 2',
        ]);
        $guestOne = $this->confirmationGuest($event, 'Asha Results Guest', '+255700000201', Guest::PASS_DOUBLE, 2, '1111');
        $guestTwo = $this->confirmationGuest($event, 'Baraka Results Guest', '+255700000202', Guest::PASS_SINGLE, 1, '2222');
        $resultKey = random_bytes(32);

        $session = OfflineScannerSession::factory()->active()->create([
            'event_id' => $event->id,
            'created_by_user_id' => $admin->id,
            'master_scanner_user_id' => $master->id,
            'master_gate_name' => 'Main Gate',
            'package_created_at' => now()->subHours(2),
            'package_expires_at' => now()->addDay(),
            'result_file_encryption_secret_encrypted' => Crypt::encryptString(base64_encode($resultKey)),
        ]);
        $package = OfflineScannerPackage::factory()->create([
            'offline_scanner_session_id' => $session->id,
            'generated_by_user_id' => $admin->id,
        ]);
        $session->update([
            'package_export_id' => $package->id,
            'package_fingerprint' => $package->package_fingerprint,
        ]);

        foreach ([
            [$guestOne, hash('sha256', 'guest-base-'.$guestOne->id.'-1')],
            [$guestTwo, hash('sha256', 'guest-base-'.$guestTwo->id.'-2')],
        ] as [$guest, $recordVersion]) {
            OfflineScannerPackageGuestSnapshot::query()->create([
                'offline_scanner_session_id' => $session->id,
                'offline_scanner_package_id' => $package->id,
                'guest_id' => $guest->id,
                'external_guest_id' => (string) $guest->id,
                'record_version' => $recordVersion,
                'allowed_entries' => $guest->allowed_entries,
                'used_entries' => $guest->used_entries,
                'remaining_entries' => $guest->remainingEntries(),
            ]);
        }

        OfflineScannerSessionUser::factory()->master()->create([
            'offline_scanner_session_id' => $session->id,
            'scanner_user_id' => $master->id,
            'assigned_gate' => 'Main Gate',
        ]);
        OfflineScannerSessionUser::factory()->create([
            'offline_scanner_session_id' => $session->id,
            'scanner_user_id' => $client->id,
            'assigned_gate' => 'Garden Gate',
        ]);

        return [
            'event' => $event,
            'admin' => $admin,
            'master' => $master,
            'client' => $client,
            'guestOne' => $guestOne,
            'guestTwo' => $guestTwo,
            'session' => $session->fresh(['packageExport', 'sessionUsers']),
            'package' => $package,
            'resultKey' => $resultKey,
        ];
    }

    private function confirmationGuest(Event $event, string $name, string $phone, string $passType, int $allowedEntries, string $manualCode): Guest
    {
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => $name,
            'phone_number' => $phone,
            'manual_code' => $manualCode,
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
            'used_entries' => 0,
        ]);

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => Guest::makeSecureToken(),
            'is_active' => true,
            'generated_at' => now(),
        ]);

        return $guest->fresh('qrCode');
    }

    private function confirmationPayload(array $fixture): array
    {
        $resultsExportId = (string) Str::uuid();
        $guestOne = $fixture['guestOne'];
        $guestTwo = $fixture['guestTwo'];

        return [
            'session' => [
                'session_uuid' => $fixture['session']->session_uuid,
                'package_export_id' => $fixture['package']->package_uuid,
                'results_export_id' => $resultsExportId,
                'event_id' => (int) $fixture['event']->id,
                'master_device_id' => 'android-master-1',
                'session_started_at' => now()->subHour()->toJSON(),
                'session_closed_at' => now()->subMinutes(5)->toJSON(),
                'final_master_sequence_number' => 3,
                'exported_at' => now()->toJSON(),
            ],
            'devices' => [
                [
                    'device_id' => 'android-master-1',
                    'device_name' => 'Master Pixel',
                    'device_role' => 'master',
                    'scanner_user_id' => $fixture['master']->id,
                    'scanner_user_name' => $fixture['master']->name,
                    'gate_name' => 'Main Gate',
                    'paired_at' => now()->subHour()->toJSON(),
                    'last_seen_at' => now()->toJSON(),
                    'admissions_count' => 1,
                ],
                [
                    'device_id' => 'android-client-2',
                    'device_name' => 'Client Pixel 2',
                    'device_role' => 'client',
                    'scanner_user_id' => $fixture['client']->id,
                    'scanner_user_name' => $fixture['client']->name,
                    'gate_name' => 'Garden Gate',
                    'paired_at' => now()->subHour()->toJSON(),
                    'last_seen_at' => now()->toJSON(),
                    'admissions_count' => 1,
                ],
            ],
            'admission_logs' => [
                $this->confirmationLog($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 1, 1, 0, 2, 1, 1),
                $this->confirmationLog($guestTwo, $fixture['client'], 'android-client-2', 'Garden Gate', 'manual', 2, 1, 0, 1, 1, 0),
                $this->confirmationLog($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 3, 0, 1, 1, 1, 1, OfflineScannerResultSchema::RESULT_INVALID_QR),
            ],
            'final_guest_states' => [
                [
                    'external_guest_id' => (string) $guestOne->id,
                    'final_used' => 1,
                    'final_remaining' => 1,
                    'final_record_version' => hash('sha256', 'guest-one-final'),
                    'last_master_sequence_number' => 1,
                ],
                [
                    'external_guest_id' => (string) $guestTwo->id,
                    'final_used' => 1,
                    'final_remaining' => 0,
                    'final_record_version' => hash('sha256', 'guest-two-final'),
                    'last_master_sequence_number' => 2,
                ],
            ],
            'metrics' => [
                'total_scans' => 3,
                'total_admitted_people' => 2,
                'total_invalid_scans' => 1,
                'total_manual_admissions' => 1,
                'total_qr_admissions' => 1,
            ],
        ];
    }

    private function confirmationLog(
        Guest $guest,
        User $scanner,
        string $deviceId,
        string $gate,
        string $method,
        int $sequence,
        int $added,
        int $usedBefore,
        int $remainingBefore,
        int $usedAfter,
        int $remainingAfter,
        string $resultCode = OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY,
    ): array {
        return [
            'log_uuid' => (string) Str::uuid(),
            'request_uuid' => (string) Str::uuid(),
            'master_sequence_number' => $sequence,
            'external_guest_id' => (string) $guest->id,
            'scanner_user_id' => $scanner->id,
            'source_device_id' => $deviceId,
            'source_gate_name' => $gate,
            'admission_method' => $method,
            'qr_payload_hash' => $method === 'qr' ? hash('sha256', 'qr-payload-'.$sequence) : null,
            'added_entries' => $added,
            'used_before' => $usedBefore,
            'remaining_before' => $remainingBefore,
            'used_after' => $usedAfter,
            'remaining_after' => $remainingAfter,
            'result_code' => $resultCode,
            'device_timestamp' => now()->subMinutes(20 - $sequence)->toJSON(),
            'master_timestamp' => now()->subMinutes(19 - $sequence)->toJSON(),
            'monotonic_sequence' => $sequence,
            'base_guest_record_version' => hash('sha256', 'guest-base-'.$guest->id.'-'.$sequence),
        ];
    }

    private function confirmationEncryptedResultsFile(array $payload, array $fixture): string
    {
        $json = app(CanonicalJson::class);
        $payloadHash = hash('sha256', $json->encode($payload));
        $iv = random_bytes(12);
        $tag = '';
        $binding = [
            'event_id' => (int) $fixture['event']->id,
            'format' => OfflineScannerResultSchema::FORMAT,
            'master_device_id' => 'android-master-1',
            'payload_hash' => $payloadHash,
            'result_schema_version' => OfflineScannerResultSchema::SCHEMA_VERSION,
            'results_export_id' => $payload['session']['results_export_id'],
            'session_uuid' => $fixture['session']->session_uuid,
            'source_package_export_id' => $fixture['package']->package_uuid,
        ];
        $ciphertext = openssl_encrypt(
            $json->encode($payload),
            'aes-256-gcm',
            $fixture['resultKey'],
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $json->encode($binding),
        );

        $this->assertNotFalse($ciphertext);

        return $json->encode([
            'format' => OfflineScannerResultSchema::FORMAT,
            'result_schema_version' => OfflineScannerResultSchema::SCHEMA_VERSION,
            'source_package_export_id' => $fixture['package']->package_uuid,
            'session_uuid' => $fixture['session']->session_uuid,
            'results_export_id' => $payload['session']['results_export_id'],
            'event_id' => (int) $fixture['event']->id,
            'master_device_id' => 'android-master-1',
            'encryption_algorithm' => OfflineScannerResultSchema::ENCRYPTION_ALGORITHM,
            'initialization_vector' => base64_encode($iv),
            'encrypted_payload' => base64_encode($ciphertext),
            'authentication_tag' => base64_encode($tag),
            'payload_hash' => $payloadHash,
        ]);
    }

    private function confirmationSnapshot(): array
    {
        $rows = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $contents = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($contents, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $rows[$table->name] = $contents;
        }
        $files = Storage::disk($this->confirmationOfflineScannerDisk())->allFiles();
        sort($files);
        $rows['_files'] = [];
        foreach ($files as $file) {
            $rows['_files'][$file] = hash('sha256', Storage::disk($this->confirmationOfflineScannerDisk())->get($file));
        }

        return $rows;
    }

    private function confirmationOfflineScannerDisk(): string
    {
        return (string) config('offline_scanner.disk', 'offline_scanner');
    }
}

final class ArtifactRecoveryFixtureFiles extends OfflineScannerFileStorage
{
    public int $attempt = 0;

    public array $putCalls = [];

    public array $existsCalls = [];

    public array $deleteCalls = [];

    public array $timeline = [];

    public ?string $deleteFailure = null;

    public function put(string $path, string $contents, ?string $disk = null): void
    {
        $resolvedDisk = $disk ?: $this->diskName();
        parent::put($path, $contents, $resolvedDisk);
        $call = $this->context($path, $resolvedDisk);
        $call['hash'] = hash('sha256', Storage::disk($resolvedDisk)->get($path));
        $this->putCalls[] = $call;
        $this->timeline[] = ['op' => 'put'] + $call;
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        $resolvedDisk = $disk ?: $this->diskName();
        $call = $this->context($path, $resolvedDisk);
        $this->existsCalls[] = $call;
        $this->timeline[] = ['op' => 'exists'] + $call;

        return parent::exists($path, $resolvedDisk);
    }

    public function delete(string $path, ?string $disk = null): bool
    {
        $resolvedDisk = $disk ?: $this->diskName();
        $call = $this->context($path, $resolvedDisk);
        $this->deleteCalls[] = $call;
        $this->timeline[] = ['op' => 'delete'] + $call;
        if ($this->deleteFailure === 'false') {
            return false;
        }
        if ($this->deleteFailure === 'throw') {
            throw new RuntimeException('fixture-delete-private-marker '.$path);
        }
        if ($this->deleteFailure === 'throw_concurrency') {
            $this->deleteFailure = null;
            throw new RuntimeException('database is locked; fixture-delete-private-marker '.$path);
        }

        return parent::delete($path, $resolvedDisk);
    }

    private function context(string $path, string $disk): array
    {
        return ['attempt' => $this->attempt, 'path' => $path, 'disk' => $disk,
            'level' => DB::connection()->transactionLevel(), 'pdo_transaction' => DB::connection()->getPdo()->inTransaction()];
    }
}

/** Inspect builder intent: SQLite emits no FOR UPDATE and proves no row locks. */
final class ArtifactRecoveryInspectingSqliteGrammar extends SQLiteGrammar
{
    public array $referenceLookups = [];

    public ArtifactRecoveryFixtureFiles $files;

    public function compileSelect(Builder $query)
    {
        $whereJson = json_encode($query->wheres, JSON_THROW_ON_ERROR);
        if ($query->from === 'offline_scanner_result_imports' && str_contains($whereJson, 'encrypted_file_path')) {
            $this->referenceLookups[] = ['columns' => $query->columns, 'lock' => $query->lock, 'write' => $query->useWritePdo,
                'bindings' => $query->getBindings(), 'where_json' => $whereJson,
                'attempt' => $this->files->attempt, 'level' => DB::connection()->transactionLevel(), 'pdo_transaction' => DB::connection()->getPdo()->inTransaction()];
        }

        return parent::compileSelect($query);
    }
}
