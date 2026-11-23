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
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Feedback checks only; existing autocommit partial effects are observed, not repaired. */
class OfflineScannerDeletionFeedbackTest extends TestCase
{
    private const REASON = 'Synthetic retention deletion reason.';

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new \LogicException('Deletion feedback fixtures require isolated in-memory SQLite.');
        }
        if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new \LogicException('Deletion feedback fixtures require autocommit.');
        }
        $this->beforeApplicationDestroyed(static fn () => $db->disconnect());
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
    }

    #[DataProvider('sqlFaults')]
    public function test_sql_failures_do_not_disclose_delete_details_and_preserve_observed_autocommit_outcome(string $kind, string $stage): void
    {
        $f = $this->fixture($kind);
        $before = $this->snapshot();
        $db = DB::connection();
        $message = $db->getPdo()->quote('private-delete-driver-marker; private-delete-binding-marker; '.$f['path']);
        $trigger = $stage === 'update'
            ? 'CREATE TEMP TRIGGER "synthetic_delete_feedback_update" BEFORE UPDATE OF "secure_deleted_at" ON "'.$this->table($kind).'" WHEN OLD."id" = '.$this->resourceId($kind).' BEGIN SELECT RAISE(ABORT, '.$message.'); END'
            : 'CREATE TEMP TRIGGER "synthetic_delete_feedback_audit" BEFORE INSERT ON "offline_scanner_session_audits" WHEN NEW."offline_scanner_session_id" = 401 AND NEW."action" = '.$db->getPdo()->quote(OfflineScannerSessionAudit::ACTION_FILE_DELETED).' BEGIN SELECT RAISE(ABORT, '.$message.'); END';
        $db->unprepared($trigger);
        $this->assertSame(0, $db->transactionLevel());
        $this->assertFalse($db->getPdo()->inTransaction());
        $attempts = [];
        $db->beforeExecuting(function (string $sql) use ($kind, $stage, $f, $db, &$attempts): void {
            $matches = $stage === 'update'
                ? preg_match('/\Aupdate "'.preg_quote($this->table($kind), '/').'"/i', $sql) === 1
                : preg_match('/\Ainsert into "offline_scanner_session_audits"/i', $sql) === 1;
            if ($matches) {
                $attempts[] = ['file_exists' => Storage::disk($f['disk'])->exists($f['path']),
                    'level' => $db->transactionLevel(), 'pdo_transaction' => $db->getPdo()->inTransaction()];
            }
        });
        $queries = [];
        $response = $this->queries($queries, fn () => $this->requestDeletion($kind));

        // The protected HTTP SQL stage now has its own transaction; request entry/exit remain autocommit0.
        $this->assertSame([['file_exists' => false, 'level' => 1, 'pdo_transaction' => true]], $attempts);
        $this->assertSame([[$f['path'], $f['disk']]], $f['files']->existsCalls);
        $this->assertSame([[$f['path'], $f['disk']]], $f['files']->deleteCalls);
        $this->assertCount($stage === 'update' ? 0 : 1, $this->dml($queries));
        // These are existing partial effects, deliberately verified before feedback:
        // UPDATE refusal loses only bytes; audit refusal also retains committed metadata.
        $this->assertSqlFailureDelta($before, $kind, $stage, $f['path']);
        $this->assertSame(0, $db->transactionLevel());
        $this->assertFalse($db->getPdo()->inTransaction());
        $this->assertPrivateFeedback($response, $kind, $f['path']);
    }

    public static function sqlFaults(): array
    {
        return ['package metadata UPDATE' => ['package', 'update'], 'package audit INSERT' => ['package', 'audit'],
            'results metadata UPDATE' => ['results', 'update'], 'results audit INSERT' => ['results', 'audit']];
    }

    #[DataProvider('storageFaults')]
    public function test_storage_runtime_failures_keep_existing_bytes_and_do_not_disclose_delete_details(string $kind, string $operation): void
    {
        $f = $this->fixture($kind, $operation);
        $before = $this->snapshot();
        $db = DB::connection();
        $this->assertSame(0, $db->transactionLevel());
        $this->assertFalse($db->getPdo()->inTransaction());
        $queries = [];
        $response = $this->queries($queries, fn () => $this->requestDeletion($kind));

        $this->assertSame([[$f['path'], $f['disk']]], $f['files']->existsCalls);
        $this->assertSame($operation === 'delete' ? [[$f['path'], $f['disk']]] : [], $f['files']->deleteCalls);
        $this->assertSame([], $this->dml($queries));
        $this->assertSame($before, $this->snapshot(), 'The injected pre-effect storage refusal must conserve all rows and fake-disk bytes.');
        $this->assertSame(0, $db->transactionLevel());
        $this->assertFalse($db->getPdo()->inTransaction());
        $this->assertPrivateFeedback($response, $kind, $f['path']);
    }

    public static function storageFaults(): array
    {
        return ['package exists' => ['package', 'exists'], 'package delete' => ['package', 'delete'],
            'results exists' => ['results', 'exists'], 'results delete' => ['results', 'delete']];
    }

    private function assertSqlFailureDelta(array $before, string $kind, string $stage, string $path): void
    {
        $after = $this->snapshot();
        unset($before['_files'][$path]);
        if ($stage === 'audit') {
            $table = $this->table($kind);
            $id = $this->resourceId($kind);
            $current = array_values(array_filter($after[$table], static fn ($row): bool => (int) $row['id'] === $id))[0];
            $this->assertNotNull($current['secure_deleted_at']);
            $this->assertSame(211, (int) $current['secure_deleted_by_user_id']);
            $this->assertSame(self::REASON, $current['secure_deletion_reason']);
            foreach ($before[$table] as &$row) {
                if ((int) $row['id'] === $id) {
                    foreach (['secure_deleted_at', 'secure_deleted_by_user_id', 'secure_deletion_reason', 'updated_at'] as $field) {
                        $row[$field] = $current[$field];
                    }
                }
            }
            unset($row);
        }
        $this->assertSame($before, $after, 'Only the observed file loss and, after audit refusal, completed target metadata may differ; no audit or neighbor effects are permitted.');
    }

    private function assertPrivateFeedback(TestResponse $response, string $kind, string $path): void
    {
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHasErrors([$this->errorKey($kind) => 'Unable to delete encrypted offline scanner file.'])
            ->assertSessionMissing('success')->assertSessionHas('selected_event_id', 301)
            ->assertSessionHas('guest_import.marker', 'preserved');
        $errors = session('errors')->getBag('default')->getMessages();
        $this->assertSame([$this->errorKey($kind) => ['Unable to delete encrypted offline scanner file.']], $errors);
        $feedback = json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['private-delete-driver-marker', 'private-delete-binding-marker', 'private-delete-storage-marker',
            $path, self::REASON, 'SQL:', 'offline_scanner_packages', 'offline_scanner_result_imports', 'offline_scanner_session_audits'] as $private) {
            $this->assertStringNotContainsString($private, $feedback);
        }
    }

    private function fixture(string $kind, ?string $storageFault = null): array
    {
        $this->freezeTime();
        $disk = (string) config('offline_scanner.disk');
        Storage::fake($disk);
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
        $files = new SecureDeletionFeedbackFixtureStorage(app(OfflineScannerSessionAuditService::class), $storageFault);
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
        $files = Storage::disk(config('offline_scanner.disk'))->allFiles();
        sort($files);
        $rows['_files'] = [];
        foreach ($files as $file) {
            $rows['_files'][$file] = hash('sha256', Storage::disk(config('offline_scanner.disk'))->get($file));
        }

        return $rows;
    }

    private function queries(array &$queries, callable $callback): mixed
    {
        $db = DB::connection();
        $old = $db->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $db->setEventDispatcher($dispatcher);
        try {
            return $callback();
        } finally {
            $db->setEventDispatcher($old);
        }
    }

    private function dml(array $queries): array
    {
        return array_values(array_filter($queries, static fn ($sql): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $sql)));
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
}

class SecureDeletionFeedbackFixtureStorage extends OfflineScannerFileStorage
{
    public array $deleteCalls = [];

    public array $existsCalls = [];

    public function __construct(OfflineScannerSessionAuditService $audits, private readonly ?string $fault)
    {
        parent::__construct($audits);
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        $this->existsCalls[] = [$path, $disk];
        if ($this->fault === 'exists') {
            throw new \RuntimeException('private-delete-storage-marker; private-delete-binding-marker; '.$path);
        }

        return parent::exists($path, $disk);
    }

    public function delete(string $path, ?string $disk = null): bool
    {
        $this->deleteCalls[] = [$path, $disk];
        if ($this->fault === 'delete') {
            throw new \RuntimeException('private-delete-storage-marker; private-delete-binding-marker; '.$path);
        }

        return parent::delete($path, $disk);
    }
}
