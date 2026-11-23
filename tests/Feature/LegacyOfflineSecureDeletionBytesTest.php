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
use App\Services\Tenancy\LegacyOfflineSecureDeletion;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * Byte-phase authority regression; metadata/audit and recovery stay separate.
 * Run only test_http_ methods against original source: direct service methods
 * intentionally require the future LegacyOfflineSecureDeletion service.
 * Private fixtures/conservation helpers are copied from DeletionFeedback and
 * SecureDeletionResult tests. This class inherits only Tests\TestCase.
 * SQLite builder/transaction observations prove no actual MySQL row locks.
 */
class LegacyOfflineSecureDeletionBytesTest extends TestCase
{
    private const REASON = 'Synthetic retention deletion reason.';

    private const ALTERNATE_DISK = 'secure_delete_alternate_private';

    private string $fixtureDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new LogicException('Secure-delete byte fixtures require isolated in-memory SQLite.');
        }
        $pdo = $db->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new LogicException('Secure-delete byte fixtures require actual SQLite PDO.');
        }
        $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool => $row['name'] === 'main'));
        if (count($main) !== 1 || $main[0]['file'] !== '' || $db->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new LogicException('Secure-delete byte fixtures require an in-memory main database and actual autocommit0.');
        }
        $temporaryRoot = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'codex-harusi-secure-delete-'.bin2hex(random_bytes(8));
        (new Filesystem)->makeDirectory($temporaryRoot, 0777, true);
        (new Filesystem)->makeDirectory($temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views', 0777, true);
        $this->app->useStoragePath($temporaryRoot);
        config(['view.compiled' => $temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            'logging.channels.single.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log',
            'logging.channels.daily.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log']);
        $this->beforeApplicationDestroyed(static function () use ($db, $temporaryRoot): void {
            $db->disconnect();
            $resolved = realpath($temporaryRoot);
            $prefix = rtrim((string) realpath(sys_get_temp_dir()), '/\\').DIRECTORY_SEPARATOR.'codex-harusi-secure-delete-';
            if ($resolved !== false && str_starts_with(strtolower($resolved), strtolower($prefix))) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        });
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
    }

    #[DataProvider('lateChanges')]
    public function test_http_late_changes_refuse_before_private_bytes_and_conserve_postmutation_state(string $kind, string $change, int $status): void
    {
        $f = $this->fixture($kind);
        $expected = null;
        $retentionChanged = false;
        $f['files']->beforeComposition = function () use ($kind, $change, &$expected, &$retentionChanged): void {
            $table = $this->table($kind);
            $id = $this->resourceId($kind);
            if ($change === 'retention_disabled') {
                // Let the composed helper's initial retention check pass, then
                // revoke at the protected byte transaction's begin boundary.
                DB::connection()->beforeStartingTransaction(function () use (&$expected, &$retentionChanged): void {
                    if ($retentionChanged) {
                        return;
                    }
                    $retentionChanged = true;
                    config(['offline_scanner.retention.allow_secure_deletion' => false]);
                    $expected = $this->snapshot();
                });
                $expected = $this->snapshot();

                return;
            }
            match ($change) {
                'owned_event' => DB::table('events')->where('id', 301)->update(['workspace_id' => 101]),
                'actor_inactive' => DB::table('users')->where('id', 211)->update(['is_active' => false]),
                'actor_customer' => DB::table('users')->where('id', 211)->update(['role' => User::ROLE_CUSTOMER]),
                'session_reparent' => DB::table('offline_scanner_sessions')->where('id', 401)->update(['event_id' => 302]),
                'artifact_reparent' => DB::table($table)->where('id', $id)->update(['offline_scanner_session_id' => 402]),
                'path_retarget' => DB::table($table)->where('id', $id)->update([$kind === 'package' ? 'file_path' : 'encrypted_file_path' => $kind === 'package' ? 'offline-packages/synthetic/502.wqrpkg' : 'offline-results/synthetic/602.wqrresults']),
                'disk_retarget' => DB::table($table)->where('id', $id)->update(['storage_disk' => self::ALTERNATE_DISK]),
                'already_deleted' => DB::table($table)->where('id', $id)->update(['secure_deleted_at' => now()->subMinute(), 'secure_deleted_by_user_id' => 215, 'secure_deletion_reason' => 'Synthetic prior marker']),
            };
            $expected = $this->snapshot();
        };
        $response = $this->requestDeletion($kind);

        $this->assertSame(1, $f['files']->compositionCount, 'Mutation is after final HTTP entry/Gate and at the composed helper boundary, not an earlier route-model read.');
        $this->assertSame(1, $f['files']->mutationCount);
        $this->assertNotNull($expected);
        $response->assertStatus($status)->assertSessionMissing('success')
            ->assertSessionHas('selected_event_id', 301)->assertSessionHas('guest_import.marker', 'preserved');
        if ($change === 'retention_disabled') {
            $this->assertTrue($retentionChanged, 'The current retention flag must be rechecked inside the guarded byte phase after the initial composed-helper check.');
            $this->assertGenericFeedback($response, $kind, $f['path']);
        }
        $this->assertSame([], $f['files']->existsCalls);
        $this->assertSame([], $f['files']->deleteCalls);
        $this->assertSame($expected, $this->snapshot(), 'No byte, metadata, audit or neighboring effect may follow the one-shot late mutation.');
        $this->assertAutocommit();
    }

    public static function lateChanges(): array
    {
        $cases = [];
        foreach (['package', 'results'] as $kind) {
            foreach (['owned_event' => 404, 'actor_inactive' => 403, 'actor_customer' => 403, 'session_reparent' => 404,
                'artifact_reparent' => 404, 'path_retarget' => 404, 'disk_retarget' => 404, 'already_deleted' => 403, 'retention_disabled' => 302] as $change => $status) {
                $cases[$kind.' late '.$change] = [$kind, $change, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('allowedAdministrators')]
    public function test_http_allowed_admins_delete_once_under_primary_byte_prefix_and_keep_existing_composition(string $kind, string $variant): void
    {
        $f = $this->fixture($kind);
        if ($variant === 'platform') {
            DB::table('users')->where('id', 211)->update(['is_platform_admin' => true]);
        } elseif ($variant === 'member') {
            DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 211, 'role' => 'owner', 'is_active' => true]);
            // Empty raw disk uses the existing fallback; no nullable-schema change is needed.
            DB::table($this->table($kind))->where('id', $this->resourceId($kind))->update(['storage_disk' => '']);
            Storage::disk(self::ALTERNATE_DISK)->put($f['path'], 'retained-same-path-alternate-disk-decoy');
        }
        $f['files']->beforeComposition = function () use ($variant): void {
            if ($variant === 'member') {
                config(['offline_scanner.disk' => self::ALTERNATE_DISK]);
            }
            DB::table('users')->where('id', 211)->update(['name' => 'Current byte-phase administrator']);
        };
        $ambient = $variant === 'platform' ? 1 : 0;
        $db = DB::connection();
        if ($ambient === 1) {
            $db->beginTransaction();
        }
        $starts = [];
        $db->beforeStartingTransaction(static function () use ($f, $db, &$starts): void {
            if (! $f['files']->providerReturned) {
                $starts[] = $db->transactionLevel();
            }
        });
        $grammar = $this->inspectByteQueries($f['files']);
        $before = null;
        $originalHook = $f['files']->beforeComposition;
        $f['files']->beforeComposition = function () use ($originalHook, &$before): void {
            $originalHook();
            $before = $this->snapshot();
        };
        try {
            $response = $this->requestDeletion($kind);
            $grammar->observing = false;
            $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
                ->assertSessionHas('success', $this->successMessage($kind))->assertSessionHasNoErrors();
            $this->assertSame([$ambient], $starts, 'Exactly one byte transaction is added to the existing caller level.');
            $this->assertSame(1, $f['files']->compositionCount);
            $this->assertSame([true], $f['files']->providerSupplied);
            $this->assertSame(1, $f['files']->providerCalls);
            $this->assertByteCall($f['files']->existsCalls, $f['path'], $f['disk'], $ambient + 1);
            $this->assertByteCall($f['files']->deleteCalls, $f['path'], $f['disk'], $ambient + 1);
            $this->assertPrimaryPrefix($grammar, $kind, $ambient + 1);
            $this->assertSame([], $grammar->defaultSessionsAfterProvider, 'The composed helper must use the provider Session, without a default-read replacement.');
            $this->assertCount(1, $f['files']->contexts);
            $context = $f['files']->contexts[0];
            $this->assertSame(['actor', 'disk', 'path', 'resource', 'session'], $context['keys']);
            $this->assertSame($kind === 'package' ? OfflineScannerPackage::class : OfflineScannerResultImport::class, $context['resource_class']);
            $this->assertSame($this->resourceId($kind), $context['resource']['id']);
            $this->assertSame(401, $context['resource']['offline_scanner_session_id']);
            $this->assertSame(401, $context['session']['id']);
            $this->assertSame(301, $context['session']['event_id']);
            $this->assertSame(211, $context['actor']['id']);
            $this->assertSame('Current byte-phase administrator', $context['actor']['name']);
            $this->assertSame($f['path'], $context['path']);
            $this->assertSame($f['disk'], $context['disk']);
            $this->assertSame($ambient, $context['level']);
            $this->assertNotNull($before);
            $this->assertSuccessDelta($before, $kind, $f['path']);
            if ($variant === 'member') {
                $this->assertSame('retained-same-path-alternate-disk-decoy', Storage::disk(self::ALTERNATE_DISK)->get($f['path']));
            }
            $this->assertSame($ambient, $db->transactionLevel());
            $this->assertSame($ambient === 1, $db->getPdo()->inTransaction());
        } finally {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
        }
    }

    public static function allowedAdministrators(): array
    {
        $cases = [];
        foreach (['package', 'results'] as $kind) {
            foreach (['ordinary', 'platform', 'member'] as $variant) {
                $cases[$kind.' '.$variant] = [$kind, $variant];
            }
        }

        return $cases;
    }

    #[DataProvider('absentTargets')]
    public function test_http_missing_file_or_path_keeps_existing_metadata_audit_contract(string $kind, string $shape): void
    {
        $f = $this->fixture($kind);
        if ($shape === 'missing_file') {
            Storage::disk($f['disk'])->delete($f['path']);
        } else {
            DB::table($this->table($kind))->where('id', $this->resourceId($kind))->update([
                $kind === 'package' ? 'file_path' : 'encrypted_file_path' => $kind === 'package' ? '' : null,
            ]);
        }
        $before = $this->snapshot();
        $response = $this->requestDeletion($kind);

        $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHas('success', $this->successMessage($kind))->assertSessionHasNoErrors();
        $this->assertSame([true], $f['files']->providerSupplied);
        $this->assertSame(1, $f['files']->providerCalls);
        $this->assertSame([], $f['files']->deleteCalls);
        if ($shape === 'missing_file') {
            $this->assertByteCall($f['files']->existsCalls, $f['path'], $f['disk'], 1);
        } else {
            $this->assertSame([], $f['files']->existsCalls);
            $this->assertSame($before['_files'][$f['path']], hash('sha256', Storage::disk($f['disk'])->get($f['path'])), 'No path is guessed from orphan fixture bytes.');
        }
        $this->assertSuccessDelta($before, $kind);
        $this->assertAutocommit();
    }

    public static function absentTargets(): array
    {
        return ['package missing file' => ['package', 'missing_file'], 'results missing file' => ['results', 'missing_file'],
            'package empty path' => ['package', 'no_path'], 'results NULL path' => ['results', 'no_path']];
    }

    #[DataProvider('kinds')]
    public function test_http_concurrency_shaped_storage_failure_does_not_retry_irreversible_byte_phase(string $kind): void
    {
        $f = $this->fixture($kind);
        $f['files']->throwOnceOnDelete = true;
        $before = $this->snapshot();
        $starts = [];
        $db = DB::connection();
        $db->beforeStartingTransaction(static function () use ($db, &$starts): void {
            $starts[] = $db->transactionLevel();
        });
        $response = $this->requestDeletion($kind);

        $this->assertSame([0], $starts, 'Even a concurrency-shaped storage failure must not repeat this irreversible transaction.');
        $this->assertByteCall($f['files']->existsCalls, $f['path'], $f['disk'], 1);
        $this->assertByteCall($f['files']->deleteCalls, $f['path'], $f['disk'], 1);
        $this->assertSame(1, $f['files']->providerCalls);
        $this->assertSame([], $f['files']->contexts, 'Provider failure cannot fall back to the direct helper branch.');
        $this->assertSame($before, $this->snapshot());
        $this->assertGenericFeedback($response, $kind, $f['path']);
        $this->assertAutocommit();
    }

    #[DataProvider('rawServiceRefusals')]
    public function test_direct_future_service_rejects_malformed_captured_identity_before_queries(string $kind, string $shape, int $status): void
    {
        $f = $this->fixture($kind);
        $selected = 301;
        if ($shape === 'resource_original') {
            $f['resource']->setRawAttributes(array_replace($f['resource']->getAttributes(), ['id' => '0501']), true);
            $f['resource']->setAttribute('id', 501);
        } elseif ($shape === 'resource_current') {
            $f['resource']->setAttribute('id', '0601');
        } elseif ($shape === 'actor_current') {
            $f['actor']->setAttribute('id', '0211');
        } else {
            $selected = 302;
        }
        $before = $this->snapshot();
        $queries = [];
        $refused = false;
        try {
            $this->queries($queries, fn () => app(LegacyOfflineSecureDeletion::class)->delete($f['resource'], $f['actor'], $selected, self::REASON));
        } catch (HttpExceptionInterface $error) {
            $refused = true;
            $this->assertSame($status, $error->getStatusCode());
        }
        $this->assertTrue($refused);
        $this->assertSame([], $queries);
        $this->assertSame(0, $f['files']->compositionCount);
        $this->assertSame([], $f['files']->existsCalls);
        $this->assertSame([], $f['files']->deleteCalls);
        $this->assertSame($before, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function rawServiceRefusals(): array
    {
        return ['package unsafe original PK' => ['package', 'resource_original', 404], 'results unsafe current PK' => ['results', 'resource_current', 404],
            'package unsafe actor current PK' => ['package', 'actor_current', 403], 'results selected Event mismatch' => ['results', 'selected_mismatch', 404]];
    }

    #[DataProvider('kinds')]
    public function test_direct_composed_helper_without_provider_preserves_existing_autocommit_sequence(string $kind): void
    {
        $f = $this->fixture($kind);
        $before = $this->snapshot();
        $starts = [];
        DB::connection()->beforeStartingTransaction(static function () use (&$starts): void {
            $starts[] = true;
        });
        $result = $kind === 'package'
            ? $f['files']->secureDeletePackage($f['resource'], $f['actor'], self::REASON)
            : $f['files']->secureDeleteResultImport($f['resource'], $f['actor'], self::REASON);

        $this->assertSame($this->resourceId($kind), $result->getKey());
        $this->assertSame([false], $f['files']->providerSupplied);
        $this->assertSame(0, $f['files']->providerCalls);
        $this->assertSame([], $starts, 'Trusted direct helper composition keeps its prior transaction scope.');
        $this->assertByteCall($f['files']->existsCalls, $f['path'], $f['disk'], 0);
        $this->assertByteCall($f['files']->deleteCalls, $f['path'], $f['disk'], 0);
        $this->assertSuccessDelta($before, $kind, $f['path']);
        $this->assertAutocommit();
    }

    public static function kinds(): array
    {
        return ['package' => ['package'], 'results' => ['results']];
    }

    private function inspectByteQueries(SecureDeletionByteFixtureStorage $files): SecureDeletionByteInspectingGrammar
    {
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $grammar = new SecureDeletionByteInspectingGrammar($db);
        $grammar->files = $files;
        $db->setQueryGrammar($grammar);
        $this->beforeApplicationDestroyed(static fn () => $db->setQueryGrammar($original));

        return $grammar;
    }

    private function assertPrimaryPrefix(SecureDeletionByteInspectingGrammar $grammar, string $kind, int $level): void
    {
        $this->assertSame(['events', 'users', 'offline_scanner_sessions', $this->table($kind)], array_column($grammar->heads, 'table'));
        $this->assertSame([301, 211, 401, $this->resourceId($kind)], array_column($grammar->heads, 'id'));
        foreach ($grammar->heads as $head) {
            $this->assertTrue($head['write']);
            $this->assertSame(true, $head['lock']);
            $this->assertSame($level, $head['level']);
            $this->assertTrue($head['pdo_transaction']);
        }
    }

    private function assertByteCall(array $calls, string $path, string $disk, int $level): void
    {
        $this->assertSame([['path' => $path, 'disk' => $disk, 'level' => $level, 'pdo_transaction' => $level > 0]], $calls);
    }

    private function assertGenericFeedback(TestResponse $response, string $kind, string $path): void
    {
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHasErrors([$this->errorKey($kind) => 'Unable to delete encrypted offline scanner file.'])
            ->assertSessionMissing('success')->assertSessionHas('selected_event_id', 301)->assertSessionHas('guest_import.marker', 'preserved');
        $feedback = json_encode(session('errors')->getBag('default')->getMessages(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([$path, self::REASON, 'database is locked', 'private-byte-phase-storage-marker', 'SQL:'] as $private) {
            $this->assertStringNotContainsString($private, $feedback);
        }
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
        $files = new SecureDeletionByteFixtureStorage(app(OfflineScannerSessionAuditService::class));
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

final class SecureDeletionByteFixtureStorage extends OfflineScannerFileStorage
{
    public int $compositionCount = 0;

    public int $mutationCount = 0;

    public int $providerCalls = 0;

    public bool $providerReturned = false;

    public bool $throwOnceOnDelete = false;

    public ?Closure $beforeComposition = null;

    public array $providerSupplied = [];

    public array $contexts = [];

    public array $existsCalls = [];

    public array $deleteCalls = [];

    // The wider optional fourth argument is compatible with both the existing
    // three-argument helper and the planned optional trusted Closure provider.
    public function secureDeletePackage(OfflineScannerPackage $package, User $actor, string $reason, mixed $byteContextProvider = null, mixed ...$laterProviders): OfflineScannerPackage
    {
        $provider = $this->prepareProvider($byteContextProvider);

        // Existing PHP user-defined methods ignore the extra argument; this
        // keeps original-source HTTP reproduction about behavior, not arity.
        return parent::secureDeletePackage($package, $actor, $reason, $provider, ...$laterProviders);
    }

    public function secureDeleteResultImport(OfflineScannerResultImport $resultImport, User $actor, string $reason, mixed $byteContextProvider = null, mixed ...$laterProviders): OfflineScannerResultImport
    {
        $provider = $this->prepareProvider($byteContextProvider);

        return parent::secureDeleteResultImport($resultImport, $actor, $reason, $provider, ...$laterProviders);
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        $this->existsCalls[] = $this->byteContext($path, $disk);

        return parent::exists($path, $disk);
    }

    public function delete(string $path, ?string $disk = null): bool
    {
        $this->deleteCalls[] = $this->byteContext($path, $disk);
        if ($this->throwOnceOnDelete && count($this->deleteCalls) === 1) {
            throw new RuntimeException('database is locked; private-byte-phase-storage-marker; '.$path);
        }

        return parent::delete($path, $disk);
    }

    private function prepareProvider(mixed $provider): ?Closure
    {
        $this->compositionCount++;
        $this->providerSupplied[] = $provider instanceof Closure;
        $hook = $this->beforeComposition;
        $this->beforeComposition = null;
        if ($hook !== null) {
            $this->mutationCount++;
            $hook();
        }
        if ($provider === null) {
            return null;
        }
        if (! $provider instanceof Closure) {
            throw new LogicException('Protected byte context must be a trusted Closure.');
        }

        return function () use ($provider): array {
            $this->providerCalls++;
            $context = $provider();
            $keys = array_keys($context);
            sort($keys);
            $this->contexts[] = ['keys' => $keys, 'resource_class' => $context['resource']::class,
                'resource' => $context['resource']->getAttributes(), 'session' => $context['session']->getAttributes(),
                'actor' => $context['actor']->getAttributes(), 'path' => $context['path'], 'disk' => $context['disk'],
                'level' => DB::connection()->transactionLevel(), 'pdo_transaction' => DB::connection()->getPdo()->inTransaction()];
            $this->providerReturned = true;

            return $context;
        };
    }

    private function byteContext(string $path, ?string $disk): array
    {
        return ['path' => $path, 'disk' => $disk ?: $this->diskName(), 'level' => DB::connection()->transactionLevel(),
            'pdo_transaction' => DB::connection()->getPdo()->inTransaction()];
    }
}

/** SQLite suppresses FOR UPDATE; inspect intent and order without claiming real locks. */
final class SecureDeletionByteInspectingGrammar extends SQLiteGrammar
{
    public bool $observing = true;

    public SecureDeletionByteFixtureStorage $files;

    public array $heads = [];

    public array $defaultSessionsAfterProvider = [];

    public function compileSelect(Builder $query)
    {
        if ($this->observing) {
            if ($query->from === 'offline_scanner_sessions' && $query->lock === null && $this->files->providerReturned) {
                $this->defaultSessionsAfterProvider[] = $query->getBindings();
            }
            if (! $this->files->providerReturned && in_array($query->from, ['events', 'users', 'offline_scanner_sessions', 'offline_scanner_packages', 'offline_scanner_result_imports'], true) && $query->lock !== null) {
                $this->heads[] = ['table' => $query->from, 'id' => $query->getBindings()[0] ?? null,
                    'write' => $query->useWritePdo, 'lock' => $query->lock, 'level' => DB::connection()->transactionLevel(),
                    'pdo_transaction' => DB::connection()->getPdo()->inTransaction()];
            }
        }

        return parent::compileSelect($query);
    }
}
