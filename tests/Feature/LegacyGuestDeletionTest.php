<?php

namespace Tests\Feature;

use App\Exceptions\LegacyGuestDeletionFailed;
use App\Exceptions\OfflineEventLockedException;
use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\QrCode;
use App\Models\ScanLog;
use App\Models\User;
use App\Services\Tenancy\LegacyGuestDeletion;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Concerns\FakesGuestQrDisk;
use Tests\TestCase;

/** Whole-family deletion boundary; fake files/SQLite do not prove durable storage or MySQL locks. */
class LegacyGuestDeletionTest extends TestCase
{
    use FakesGuestQrDisk;

    private FilesystemAdapter $disk;

    private FilesystemAdapter $public;

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new LogicException('Guest deletion fixtures require isolated in-memory SQLite.');
        }
        $pdo = $db->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new LogicException('Guest deletion fixtures require actual SQLite PDO.');
        }
        $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool => $row['name'] === 'main'));
        if (count($main) !== 1 || $main[0]['file'] !== '' || $db->transactionLevel() !== 0 || $pdo->inTransaction()
            || (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new LogicException('Guest deletion fixtures require memory main, enabled FKs and actual autocommit0.');
        }
        $temporaryRoot = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'codex-harusi-guest-deletion-'.bin2hex(random_bytes(8));
        (new Filesystem)->makeDirectory($temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views', 0777, true);
        $this->app->useStoragePath($temporaryRoot);
        config(['view.compiled' => $temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            'logging.channels.single.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log',
            'logging.channels.daily.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log',
            'mail.default' => 'array', 'cache.default' => 'array', 'session.driver' => 'array',
            'queue.default' => 'sync', 'services.whatsapp.provider' => 'null']);
        $this->beforeApplicationDestroyed(static function () use ($db, $temporaryRoot): void {
            $db->disconnect();
            $resolved = realpath($temporaryRoot);
            $prefix = rtrim((string) realpath(sys_get_temp_dir()), '/\\').DIRECTORY_SEPARATOR.'codex-harusi-guest-deletion-';
            if ($resolved !== false && str_starts_with(strtolower($resolved), strtolower($prefix))) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        });
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
        $this->freezeTime();
        $this->disk = $this->fakeGuestQrDisk();
        $fakeRoot = realpath($this->disk->path(''));
        if ($fakeRoot === false || ! str_starts_with(strtolower($fakeRoot), strtolower($temporaryRoot.DIRECTORY_SEPARATOR))) {
            throw new LogicException('The private QR fake must stay inside guarded TEMP storage.');
        }
        // Any public-disk effect stays in guarded TEMP storage and is part of every snapshot.
        $this->public = Storage::fake('public');
        $publicRoot = realpath($this->public->path(''));
        if ($publicRoot === false || ! str_starts_with(strtolower($publicRoot), strtolower($temporaryRoot.DIRECTORY_SEPARATOR))) {
            throw new LogicException('The public fake must stay inside guarded TEMP storage.');
        }
    }

    #[DataProvider('lateAuthorityChanges')]
    public function test_http_late_authority_or_any_target_loss_refuses_before_adapter_resolution(string $action, string $change, int $status): void
    {
        $this->fixture();
        $probe = $this->installDiskProbe();
        $expected = null;
        $this->beforeFirstTransaction(function () use ($change, &$expected): void {
            $this->assertAutocommit();
            match ($change) {
                'inactive' => DB::table('users')->where('id', 211)->update(['is_active' => false]),
                'role' => DB::table('users')->where('id', 211)->update(['role' => User::ROLE_CUSTOMER]),
                'owned' => DB::table('events')->where('id', 301)->update(['workspace_id' => 101]),
                'moved' => DB::table('guests')->where('id', 902)->update(['event_id' => 302]),
                'missing' => DB::table('guests')->where('id', 902)->delete(),
            };
            $expected = $this->snapshot();
        });

        $response = $this->requestDeletion($action, $action === 'single' ? 902 : 901);

        $response->assertStatus($status)->assertSessionMissing('success');
        $this->assertNotNull($expected);
        $this->assertSame([0, []], [$probe->resolutions, $probe->calls], 'The entire current set is accepted before even resolving a file adapter.');
        $this->assertSame($expected, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function lateAuthorityChanges(): array
    {
        $cases = [];
        foreach (['single', 'bulk'] as $action) {
            foreach (['inactive' => 403, 'role' => 403, 'owned' => 404, 'moved' => 404, 'missing' => 404] as $change => $status) {
                $cases[$action.' '.$change] = [$action, $change, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('positiveContexts')]
    public function test_http_current_primary_whole_set_precedes_bytes_and_preserves_exact_cascade_scalars(string $action, string $variant): void
    {
        $this->fixture(true);
        if ($variant === 'platform') {
            DB::table('users')->where('id', 211)->update(['is_platform_admin' => true]);
            DB::table('events')->where('id', 301)->update(['status' => Event::STATUS_ARCHIVED]);
        } elseif ($variant === 'member') {
            DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 211, 'role' => 'owner', 'is_active' => false]);
            DB::table('events')->where('id', 301)->update(['status' => Event::STATUS_CANCELLED]);
        }
        $before = $this->snapshot();
        $db = DB::connection();
        $ambient = $variant === 'nested' ? 1 : 0;
        if ($ambient) {
            $db->beginTransaction();
        }
        $starts = [];
        $db->beforeStartingTransaction(static function () use ($db, &$starts): void {
            $starts[] = [$db->transactionLevel(), $db->getPdo()->inTransaction()];
        });
        $grammar = $this->inspectReads();
        $probe = $this->installDiskProbe(function (string $path, int $call) use ($before, $grammar): bool {
            if ($call === 1) {
                $grammar->observing = false;
                $this->assertSame($before, $this->snapshot(), 'Every target/QR/path/reference check precedes the first byte or deletion DML.');
            }

            return $this->disk->delete($path);
        });
        $ids = $action === 'single' ? [901] : [901, 902];
        try {
            $response = $this->requestDeletion($action);
            $grammar->observing = false;
            $this->assertSuccess($response, $action);
            $this->assertSame([[$ambient, (bool) $ambient]], $starts, 'The service composes one local attempt without a metadata wrapper.');
            $expectedTables = ['events', 'users', 'events', 'offline_scanner_sessions', 'guests', 'qr_codes'];
            $this->assertSame(array_merge($expectedTables, array_fill(0, count($ids), 'qr_codes')), array_column($grammar->reads, 'table'));
            foreach ($grammar->reads as $read) {
                $this->assertSame([true, true, $ambient + 1, true], [$read['write'], $read['lock'], $read['level'], $read['pdo_transaction']]);
            }
            $guestRead = $grammar->reads[4];
            $this->assertMatchesRegularExpression('/"(?:guests"\.")?event_id"\s*=\s*\?/', $guestRead['sql']);
            $bindings = $guestRead['bindings'];
            sort($bindings, SORT_NUMERIC);
            $this->assertSame(array_merge([301], $ids), $bindings);
            $this->assertAscendingIdOrder($guestRead['orders']);
            $this->assertAscendingIdOrder($grammar->reads[5]['orders']);
            foreach (array_slice($grammar->reads, 6) as $referenceRead) {
                $this->assertSame(['id'], array_map(static fn (string $column): string => last(explode('.', $column)), $referenceRead['columns']));
                $this->assertStringContainsString('qr_image_path', $referenceRead['sql']);
                $this->assertStringNotContainsString('guest_id', $referenceRead['sql']);
                $this->assertStringNotContainsString('event_id', $referenceRead['sql']);
                $this->assertSame(1, $referenceRead['limit']);
            }
            $this->assertSame(1, $probe->resolutions);
            $this->assertSame(array_map(fn (int $id): string => $this->path($id), $ids), $probe->deletedPaths());
            $this->assertDeletedState($before, $ids, $probe->deletedPaths());
            $this->assertSame($ambient, $db->transactionLevel());
            $this->assertSame((bool) $ambient, $db->getPdo()->inTransaction());
        } finally {
            $grammar->observing = false;
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
        }
        if ($ambient) {
            foreach ($ids as $id) {
                unset($before['_files'][$this->path($id)]);
            }
            $this->assertSame($before, $this->snapshot(), 'Caller rollback restores SQL/FKs while the completed bytes remain absent.');
        }
        $this->assertAutocommit();
    }

    public static function positiveContexts(): array
    {
        return ['single ordinary' => ['single', 'ordinary'], 'bulk platform archived' => ['bulk', 'platform'],
            'single member cancelled Event' => ['single', 'member'], 'bulk caller transaction' => ['bulk', 'nested']];
    }

    #[DataProvider('actions')]
    public function test_http_request_and_selected_context_cannot_replace_captured_targets(string $action): void
    {
        $this->fixture();
        $before = $this->snapshot();
        $this->beforeFirstTransaction(static function (): void {
            session()->put('selected_event_id', 302);
            request()->merge(['guest_ids' => [903], 'event_id' => 302]);
        });
        $probe = $this->installDiskProbe();

        $this->assertSuccess($this->requestDeletion($action), $action);

        $ids = $action === 'single' ? [901] : [901, 902];
        $this->assertDeletedState($before, $ids, array_map(fn (int $id): string => $this->path($id), $ids));
        $this->assertSame(302, session('selected_event_id'));
        $this->assertSame(count($ids), count($probe->deletedPaths()));
        $this->assertAutocommit();
    }

    #[DataProvider('currentAssociations')]
    public function test_http_current_qr_association_and_raw_path_replace_cached_preflight_state(string $action, string $change): void
    {
        $this->fixture();
        $newPath = 'qr-codes/deletion/current-901.png';
        $expected = null;
        $this->beforeFirstTransaction(function () use ($newPath, $change, &$expected): void {
            if ($change === 'association') {
                DB::table('qr_codes')->where('id', 501)->update(['guest_id' => 905]);
                QrCode::query()->forceCreate(['id' => 505, 'guest_id' => 901, 'qr_token' => 'synthetic-current-deletion-token',
                    'qr_image_path' => $newPath, 'is_active' => true, 'generated_at' => now()]);
            } else {
                DB::table('qr_codes')->where('id', 501)->update(['qr_image_path' => $newPath]);
            }
            $this->disk->put($newPath, 'Synthetic current path bytes.');
            $expected = $this->snapshot();
        });
        $probe = $this->installDiskProbe();

        $this->assertSuccess($this->requestDeletion($action), $action);

        $this->assertNotNull($expected);
        $paths = $action === 'single' ? [$newPath] : [$newPath, $this->path(902)];
        $this->assertSame($paths, $probe->deletedPaths());
        $this->assertDeletedState($expected, $action === 'single' ? [901] : [901, 902], $paths);
        $this->assertTrue($this->disk->exists($this->path(901)), 'Old orphan/now-neighbor bytes are untouched.');
        $this->assertAutocommit();
    }

    public static function currentAssociations(): array
    {
        return ['bulk current association' => ['bulk', 'association'], 'single current path' => ['single', 'path']];
    }

    #[DataProvider('sharedReferences')]
    public function test_http_shared_reference_anywhere_vetoes_the_whole_batch_before_adapter_resolution(int $otherQr): void
    {
        $this->fixture();
        if ($otherQr === 505) {
            QrCode::query()->forceCreate(['id' => 505, 'guest_id' => 905, 'qr_token' => 'synthetic-unselected-shared-qr',
                'qr_image_path' => $this->path(901), 'is_active' => true]);
        } else {
            DB::table('qr_codes')->where('id', $otherQr)->update(['qr_image_path' => $this->path(901)]);
        }
        $before = $this->snapshot();
        $probe = $this->installDiskProbe();
        $grammar = $this->inspectReads();

        $this->assertFailure($this->requestDeletion('bulk'));

        $grammar->observing = false;
        $this->assertSame([0, []], [$probe->resolutions, $probe->calls]);
        $this->assertSame($before, $this->snapshot());
        $referenceReads = array_values(array_filter($grammar->reads, static fn (array $read): bool => $read['table'] === 'qr_codes' && $read['columns'] !== ['*']));
        $this->assertNotEmpty($referenceReads);
        foreach ($referenceReads as $read) {
            $this->assertSame(['id'], array_map(static fn (string $column): string => last(explode('.', $column)), $read['columns']));
            $this->assertTrue($read['write']);
            $this->assertTrue($read['lock']);
            $this->assertStringNotContainsString('guest_id', $read['sql']);
            $this->assertStringNotContainsString('event_id', $read['sql']);
        }
        $this->assertAutocommit();
    }

    public static function sharedReferences(): array
    {
        return ['another selected target' => [502], 'unselected same-Event Guest' => [505], 'foreign owned Event' => [504]];
    }

    public function test_http_later_bytes_use_frozen_scalar_path_despite_callback_mutating_a_loaded_qr_model(): void
    {
        $this->fixture();
        $before = $this->snapshot();
        $secondQr = null;
        QrCode::retrieved(static function (QrCode $qr) use (&$secondQr): void {
            if ((int) $qr->getRawOriginal('id') === 502) {
                $secondQr = $qr;
            }
        });
        Guest::deleting(function (Guest $guest) use (&$secondQr): void {
            if ((int) $guest->id === 901 && $secondQr instanceof QrCode) {
                $secondQr->setAttribute('qr_image_path', $this->path(904));
            }
        });
        $probe = $this->installDiskProbe();

        $this->assertSuccess($this->requestDeletion('bulk'), 'bulk');

        $this->assertInstanceOf(QrCode::class, $secondQr);
        $this->assertSame([$this->path(901), $this->path(902)], $probe->deletedPaths());
        $this->assertDeletedState($before, [901, 902], [$this->path(901), $this->path(902)]);
        $this->assertAutocommit();
    }

    #[DataProvider('missingVariants')]
    public function test_http_ordinary_missing_and_falsey_path_branches_preserve_success_and_orphan_bytes(string $variant): void
    {
        $this->fixture();
        match ($variant) {
            'missing_qr' => DB::table('qr_codes')->where('id', 501)->delete(),
            'null' => DB::table('qr_codes')->where('id', 501)->update(['qr_image_path' => null]),
            'empty' => DB::table('qr_codes')->where('id', 501)->update(['qr_image_path' => '']),
            'zero' => DB::table('qr_codes')->where('id', 501)->update(['qr_image_path' => '0']),
            'missing_file' => $this->disk->delete($this->path(901)),
            'bulk_mixed' => DB::table('qr_codes')->where('id', 501)->delete(),
        };
        $before = $this->snapshot();
        $probe = $this->installDiskProbe();
        $action = $variant === 'bulk_mixed' ? 'bulk' : 'single';

        $this->assertSuccess($this->requestDeletion($action), $action);

        $paths = $variant === 'missing_file' ? [$this->path(901)] : ($action === 'bulk' ? [$this->path(902)] : []);
        $this->assertSame($paths, $probe->deletedPaths());
        $this->assertSame($paths === [] ? 0 : 1, $probe->resolutions, 'No-byte branches need no filesystem adapter.');
        $this->assertDeletedState($before, $action === 'single' ? [901] : [901, 902], $paths);
        $this->assertAutocommit();
    }

    public static function missingVariants(): array
    {
        return array_combine(['missing_qr', 'null', 'empty', 'zero', 'missing_file', 'bulk_mixed'], array_map(static fn (string $variant): array => [$variant], ['missing_qr', 'null', 'empty', 'zero', 'missing_file', 'bulk_mixed']));
    }

    #[DataProvider('storageFailures')]
    public function test_http_false_or_throwing_storage_stops_sql_and_later_targets_with_named_partial_bytes(string $action, string $fault, int $faultAt): void
    {
        $this->fixture();
        $before = $this->snapshot();
        $removed = [];
        $probe = $this->installDiskProbe(function (string $path, int $call) use ($fault, $faultAt, &$removed): bool {
            if ($call !== $faultAt || $fault === 'throw_after') {
                $this->disk->delete($path);
                $removed[] = $path;
            }
            if ($call === $faultAt) {
                if ($fault === 'false') {
                    return false;
                }
                throw new RuntimeException('p4043_PRIVATE_DIAGNOSTIC storage adapter path secret');
            }

            return true;
        });

        $this->assertFailure($this->requestDeletion($action));

        $this->assertCount($faultAt, $probe->deletedPaths());
        foreach ($removed as $path) {
            unset($before['_files'][$path]);
        }
        $this->assertSame($before, $this->snapshot(), 'Known SQL/FKs roll back; only explicitly observed byte effects remain.');
        $this->assertNoPrivateDiagnostics();
        $this->assertAutocommit();
    }

    public static function storageFailures(): array
    {
        return ['single false before unlink' => ['single', 'false', 1], 'bulk second false' => ['bulk', 'false', 2],
            'single throw after unlink' => ['single', 'throw_after', 1], 'bulk second throw before unlink' => ['bulk', 'throw_before', 2]];
    }

    #[DataProvider('modelCancellations')]
    public function test_http_model_false_or_null_delete_cannot_report_success_after_bytes(string $action, string $fault): void
    {
        $this->fixture();
        $before = $this->snapshot();
        if ($fault === 'false') {
            Guest::deleting(static fn (Guest $guest): ?bool => (int) $guest->id === 902 ? false : null);
        } else {
            Guest::retrieved(static function (Guest $guest): void {
                if (DB::connection()->transactionLevel() > 0 && (int) $guest->id === 901) {
                    $guest->exists = false;
                }
            });
        }
        $probe = $this->installDiskProbe();

        $this->assertFailure($this->requestDeletion($action));

        $expectedPaths = $action === 'bulk' ? [$this->path(901), $this->path(902)] : [$this->path(901)];
        $this->assertSame($expectedPaths, $probe->deletedPaths());
        foreach ($expectedPaths as $path) {
            unset($before['_files'][$path]);
        }
        $this->assertSame($before, $this->snapshot(), 'Cancellation is a failure; preceding SQL rolls back but preceding bytes do not.');
        $this->assertAutocommit();
    }

    public static function modelCancellations(): array
    {
        return ['bulk second deleting false' => ['bulk', 'false'], 'single no-longer-existing model null' => ['single', 'null']];
    }

    #[DataProvider('actions')]
    public function test_http_real_sql_refusal_rolls_back_all_rows_after_observed_byte_removal(string $action): void
    {
        $this->fixture(true);
        $before = $this->snapshot();
        $id = $action === 'single' ? 901 : 902;
        $this->installSqlFault($id, false);
        $partial = null;
        Guest::deleted(function (Guest $guest) use (&$partial): void {
            if ((int) $guest->id === 901) {
                $partial = $this->snapshot();
            }
        });
        $probe = $this->installDiskProbe();

        $this->assertFailure($this->requestDeletion($action));

        if ($action === 'bulk') {
            $this->assertNotNull($partial);
            $this->assertCount(count($before['guests']) - 1, $partial['guests']);
        }
        $paths = $action === 'single' ? [$this->path(901)] : [$this->path(901), $this->path(902)];
        $this->assertSame($paths, $probe->deletedPaths());
        foreach ($paths as $path) {
            unset($before['_files'][$path]);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertNoPrivateDiagnostics();
        $this->assertAutocommit();
    }

    #[DataProvider('concurrencyMessages')]
    public function test_http_concurrency_shaped_sql_or_model_exception_has_one_attempt_and_no_repeat_unlink(string $source): void
    {
        $this->fixture();
        $before = $this->snapshot();
        if ($source === 'sql') {
            $this->installSqlFault(901, true);
        } else {
            Guest::deleting(static function (Guest $guest): void {
                if ((int) $guest->id === 901) {
                    throw new RuntimeException('database is locked p4043_PRIVATE_DIAGNOSTIC model');
                }
            });
        }
        $starts = 0;
        DB::connection()->beforeStartingTransaction(static function () use (&$starts): void {
            $starts++;
        });
        $probe = $this->installDiskProbe();

        $this->assertFailure($this->requestDeletion('single'));

        $this->assertSame(1, $starts);
        $this->assertSame([$this->path(901)], $probe->deletedPaths());
        unset($before['_files'][$this->path(901)]);
        $this->assertSame($before, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function concurrencyMessages(): array
    {
        return ['actual SQLite trigger with lock message' => ['sql'], 'model exception with lock message' => ['model']];
    }

    public function test_http_nested_later_storage_lock_message_rolls_back_service_sql_and_preserves_caller_scope(): void
    {
        $this->fixture(true);
        $before = $this->snapshot();
        $db = DB::connection();
        $db->beginTransaction();
        DB::table('events')->where('id', 302)->update(['venue_name' => 'Synthetic caller-owned change']);
        $callerState = $this->snapshot();
        $starts = 0;
        $db->beforeStartingTransaction(static function () use (&$starts): void {
            $starts++;
        });
        $probe = $this->installDiskProbe(function (string $path, int $call): bool {
            if ($call === 2) {
                throw new RuntimeException('database is locked p4043_PRIVATE_DIAGNOSTIC nested adapter');
            }

            return $this->disk->delete($path);
        });
        try {
            $this->assertFailure($this->requestDeletion('bulk'));
            $this->assertSame(1, $starts);
            $this->assertSame(1, $db->transactionLevel());
            $this->assertTrue($db->getPdo()->inTransaction());
            $this->assertSame([$this->path(901), $this->path(902)], $probe->deletedPaths());
            unset($callerState['_files'][$this->path(901)]);
            $this->assertSame($callerState, $this->snapshot(), 'The service savepoint restores its first Guest deletion while retaining caller work.');
        } finally {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
        }
        unset($before['_files'][$this->path(901)]);
        $this->assertSame($before, $this->snapshot());
        $this->assertAutocommit();
    }

    #[DataProvider('offlineContexts')]
    public function test_http_late_offline_session_retains_browser_and_json_feedback(string $action, bool $json): void
    {
        $this->fixture();
        $expected = null;
        $this->beforeFirstTransaction(function () use (&$expected): void {
            OfflineScannerSession::factory()->active()->create(['id' => 411, 'event_id' => 301, 'created_by_user_id' => 211]);
            $expected = $this->snapshot();
        });
        $probe = $this->installDiskProbe();

        $response = $this->requestDeletion($action, 901, $json);

        if ($json) {
            $response->assertStatus(423)->assertExactJson(['message' => OfflineEventLockedException::MESSAGE]);
        } else {
            $response->assertRedirect(route('admin.guests.index'))->assertSessionHas('error', OfflineEventLockedException::MESSAGE);
        }
        $response->assertSessionMissing('success');
        $this->assertNotNull($expected);
        $this->assertSame([0, []], [$probe->resolutions, $probe->calls]);
        $this->assertSame($expected, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function offlineContexts(): array
    {
        return ['single browser' => ['single', false], 'bulk JSON' => ['bulk', true]];
    }

    #[DataProvider('failureLanguages')]
    public function test_http_failure_uses_account_language_or_sw_fallback_without_changing_global_locale(mixed $preference, string $expectedLanguage): void
    {
        $this->fixture();
        User::retrieved(static function (User $user) use ($preference): void {
            if ((int) $user->id === 211) {
                $user->setAttribute('preferred_language', $preference);
            }
        });
        $global = $expectedLanguage === 'sw' ? 'en' : 'sw';
        app()->setLocale($global);
        $before = $this->snapshot();
        $probe = $this->installDiskProbe(static fn (): bool => false);

        $this->assertFailure($this->requestDeletion('single'), $expectedLanguage);

        $this->assertSame($global, app()->getLocale());
        $this->assertSame([$this->path(901)], $probe->deletedPaths());
        $this->assertSame($before, $this->snapshot());
        $this->assertNoPrivateDiagnostics();
        $this->assertAutocommit();
    }

    public static function failureLanguages(): array
    {
        return ['SW preference' => ['sw', 'sw'], 'EN preference' => ['en', 'en'],
            'unsupported preference' => ['unsupported', 'sw'], 'null legacy preference' => [null, 'sw']];
    }

    #[DataProvider('invalidContexts')]
    public function test_direct_invalid_frozen_context_refuses_before_any_sql_transaction_or_adapter(string $variant, int $status): void
    {
        $f = $this->fixture();
        $guest = clone $f['guest'];
        $actor = clone $f['actor'];
        $selected = 301;
        $ids = [901, 902];
        match ($variant) {
            'bulk_padded' => $ids = [901, '0902'],
            'bulk_float' => $ids = [901, 902.0],
            'bulk_duplicate' => $ids = [901, '901'],
            'bulk_empty' => $ids = [],
            'bulk_oversized' => $ids = range(1, 51),
            'bulk_overflow' => $ids = [(string) PHP_INT_MAX.'0'],
            'selected_mismatch' => $selected = 302,
            'guest_original_overflow' => $guest->setRawAttributes(array_replace($guest->getAttributes(), ['id' => (string) PHP_INT_MAX.'0']), true),
            'guest_current_id' => $guest->setAttribute('id', 902),
            'guest_current_event' => $guest->setAttribute('event_id', 302),
            'guest_original_padded_event' => $guest->setRawAttributes(array_replace($guest->getAttributes(), ['event_id' => '0301']), true),
            'actor_current_id' => $actor->setAttribute('id', 215),
            'actor_original_float' => $actor->setRawAttributes(array_replace($actor->getAttributes(), ['id' => 211.0]), true),
            'selected_array' => $selected = [301],
        };
        $before = $this->snapshot();
        $probe = $this->installDiskProbe();
        $armed = true;
        $queries = [];
        DB::connection()->beforeExecuting(static function (string $sql) use (&$armed, &$queries): void {
            if ($armed) {
                $queries[] = $sql;
                throw new LogicException('Malformed deletion context reached SQL.');
            }
        });
        $starts = 0;
        DB::connection()->beforeStartingTransaction(static function () use (&$starts): void {
            $starts++;
        });
        $refused = false;
        try {
            $service = app(LegacyGuestDeletion::class);
            if (str_starts_with($variant, 'bulk_')) {
                $service->deleteMany($ids, $actor, $selected);
            } else {
                $service->delete($guest, $actor, $selected);
            }
        } catch (HttpExceptionInterface $exception) {
            $refused = true;
            $this->assertSame($status, $exception->getStatusCode());
        } finally {
            $armed = false;
        }

        $this->assertTrue($refused);
        $this->assertSame([], $queries);
        $this->assertSame(0, $starts);
        $this->assertSame([0, []], [$probe->resolutions, $probe->calls]);
        $this->assertSame($before, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function invalidContexts(): array
    {
        $variants = ['bulk_padded', 'bulk_float', 'bulk_duplicate', 'bulk_empty', 'bulk_oversized', 'bulk_overflow',
            'selected_mismatch', 'guest_original_overflow', 'guest_current_id', 'guest_current_event',
            'guest_original_padded_event', 'actor_current_id', 'actor_original_float', 'selected_array'];
        $cases = [];
        foreach ($variants as $variant) {
            $cases[$variant] = [$variant, str_starts_with($variant, 'actor_') ? 403 : 404];
        }

        return $cases;
    }

    #[DataProvider('malformedCurrentQr')]
    public function test_direct_current_qr_raw_identity_parent_or_path_refuses_before_adapter_resolution(string $field, mixed $value, bool $operational): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $probe = $this->installDiskProbe();
        QrCode::retrieved(static function (QrCode $qr) use ($field, $value): void {
            if ((int) $qr->getRawOriginal('id') === 502) {
                $qr->setRawAttributes(array_replace($qr->getAttributes(), [$field => $value]), true);
            }
        });
        $refused = false;
        try {
            app(LegacyGuestDeletion::class)->deleteMany([902, '901'], $f['actor'], 301);
        } catch (HttpExceptionInterface|LegacyGuestDeletionFailed $exception) {
            $refused = true;
            if ($operational) {
                $this->assertInstanceOf(LegacyGuestDeletionFailed::class, $exception);
                $this->assertSame('Guest deletion failed.', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            } else {
                $this->assertInstanceOf(HttpExceptionInterface::class, $exception);
                $this->assertSame(404, $exception->getStatusCode());
            }
        }

        $this->assertTrue($refused);
        $this->assertSame([0, []], [$probe->resolutions, $probe->calls]);
        $this->assertSame($before, $this->snapshot(), 'Malformed hydration fixtures neither change the installed schema nor authorize earlier target effects.');
        $this->assertAutocommit();
    }

    public static function malformedCurrentQr(): array
    {
        return ['overflowing QR key' => ['id', (string) PHP_INT_MAX.'0', false],
            'padded QR parent' => ['guest_id', '0902', false], 'foreign QR parent' => ['guest_id', 903, false],
            'unsupported raw path type' => ['qr_image_path', ['not a schema string'], true]];
    }

    public function test_direct_reversed_adjacent_maximum_integer_ids_have_exact_bulk_numeric_order(): void
    {
        $this->fixture();
        Event::factory()->create(['id' => PHP_INT_MAX, 'workspace_id' => null]);
        $actor = User::factory()->create(['id' => PHP_INT_MAX, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $ids = [PHP_INT_MAX - 1, PHP_INT_MAX];
        foreach ($ids as $index => $id) {
            Guest::query()->forceCreate(['id' => $id, 'event_id' => PHP_INT_MAX,
                'name' => 'Synthetic maximum Guest '.$index, 'phone_number' => '+255712345678', 'manual_code' => '999'.(8 + $index),
                'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1, 'used_entries' => 0, 'status' => Guest::STATUS_ACTIVE]);
            QrCode::query()->forceCreate(['id' => $id, 'guest_id' => $id,
                'qr_token' => 'synthetic-maximum-deletion-token-'.$index, 'qr_image_path' => $this->path($id), 'is_active' => true]);
            $this->disk->put($this->path($id), 'Synthetic maximum bytes '.$index);
        }
        $before = $this->snapshot();
        $probe = $this->installDiskProbe();

        $count = app(LegacyGuestDeletion::class)->deleteMany([PHP_INT_MAX, PHP_INT_MAX - 1], $actor, PHP_INT_MAX);

        $paths = array_map(fn (int $id): string => $this->path($id), $ids);
        $this->assertSame(2, $count);
        $this->assertSame($paths, $probe->deletedPaths(), 'Canonical PHP-range IDs must not collapse through floating-point sorting.');
        $this->assertDeletedState($before, $ids, $paths);
        $this->assertAutocommit();
    }

    public static function actions(): array
    {
        return ['single' => ['single'], 'bulk' => ['bulk']];
    }

    private function fixture(bool $richHistory = false): array
    {
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic deletion neighbor workspace']);
        foreach ([301 => null, 302 => null, 303 => 101] as $id => $workspace) {
            Event::factory()->create(['id' => $id, 'workspace_id' => $workspace]);
        }
        $actor = User::factory()->create(['id' => 211, 'role' => User::ROLE_ADMIN, 'is_active' => true, 'preferred_language' => 'sw']);
        User::factory()->create(['id' => 215, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $guest = null;
        foreach ([901 => 301, 902 => 301, 903 => 302, 904 => 303, 905 => 301] as $id => $event) {
            $row = Guest::query()->forceCreate(['id' => $id, 'event_id' => $event, 'name' => 'Synthetic deletion Guest '.$id,
                'phone_number' => '+255712345678', 'manual_code' => '0'.$id, 'pass_type' => Guest::PASS_DOUBLE,
                'allowed_entries' => 2, 'used_entries' => $id === 902 ? 2 : 1,
                'status' => $id === 901 ? Guest::STATUS_CANCELLED : Guest::STATUS_PARTIALLY_USED]);
            if ($id === 901) {
                $guest = $row;
            }
            if ($id !== 905) {
                QrCode::query()->forceCreate(['id' => $id - 400, 'guest_id' => $id, 'qr_token' => 'synthetic-deletion-token-'.$id,
                    'qr_image_path' => $this->path($id), 'is_active' => true, 'generated_at' => now()->subDay()]);
                $this->disk->put($this->path($id), 'Synthetic retained QR bytes '.$id);
            }
            Admission::query()->create(['id' => $id, 'event_id' => $event, 'guest_id' => $id, 'quantity' => 1,
                'admitted_by' => 'Synthetic scanner', 'admitted_at' => now()->subHour()]);
            Checkin::query()->create(['id' => $id, 'event_id' => $event, 'guest_id' => $id,
                'qr_code_id' => $id === 905 ? null : $id - 400, 'user_id' => 211,
                'entries_added' => 1, 'used_entries_after_scan' => 1, 'remaining_entries_after_scan' => 1,
                'scan_result' => Checkin::RESULT_ADMITTED, 'checked_in_at' => now()->subHour()]);
            ScanLog::query()->create(['id' => $id, 'event_id' => $event, 'guest_id' => $id,
                'action' => 'admit', 'result' => 'admitted', 'quantity' => 1, 'message' => 'Synthetic retained scan',
                'metadata' => ['fixture' => 'preserved'], 'scanned_at' => now()->subHour()]);
            NotificationLog::query()->create(['id' => $id, 'event_id' => $event, 'guest_id' => $id,
                'channel' => 'whatsapp', 'message_type' => 'general_update', 'recipient_phone' => '+255712345678',
                'message' => 'Synthetic notification history only', 'status' => 'skipped', 'provider' => 'null', 'context' => ['fixture' => 'preserved']]);
        }
        if ($richHistory) {
            $this->addOfflineHistory();
        }
        $this->disk->put('retained/unrelated.txt', 'Synthetic retained unrelated bytes.');
        $this->actingAs($actor)->withSession(['selected_event_id' => 301, 'guest_import' => ['marker' => 'preserved']]);

        return compact('actor', 'guest');
    }

    private function addOfflineHistory(): void
    {
        OfflineScannerSession::factory()->create(['id' => 410, 'event_id' => 301, 'created_by_user_id' => 211, 'status' => OfflineScannerSession::STATUS_CLOSED]);
        OfflineScannerPackage::factory()->create(['id' => 420, 'offline_scanner_session_id' => 410, 'generated_by_user_id' => 211]);
        OfflineScannerResultImport::factory()->create(['id' => 430, 'offline_scanner_session_id' => 410, 'event_id' => 301,
            'uploaded_by_user_id' => 211, 'status' => OfflineScannerResultImport::STATUS_COMPLETED]);
        foreach ([901, 902] as $id) {
            DB::table('offline_scanner_imported_logs')->insert(['id' => $id, 'offline_scanner_result_import_id' => 430,
                'offline_scanner_session_id' => 410, 'log_uuid' => 'synthetic-deletion-log-'.$id,
                'external_guest_id' => (string) $id, 'result_code' => 'admitted', 'import_status' => 'imported',
                'raw_payload_hash' => hash('sha256', 'synthetic-'.$id)]);
            DB::table('offline_scanner_package_guest_snapshots')->insert(['id' => $id, 'offline_scanner_session_id' => 410,
                'offline_scanner_package_id' => 420, 'guest_id' => $id, 'external_guest_id' => (string) $id,
                'record_version' => 'synthetic-version-'.$id, 'allowed_entries' => 2, 'used_entries' => 1, 'remaining_entries' => 1]);
            DB::table('offline_scanner_final_guest_states')->insert(['id' => $id, 'offline_scanner_result_import_id' => 430,
                'offline_scanner_session_id' => 410, 'event_id' => 301, 'external_guest_id' => (string) $id, 'guest_id' => $id,
                'final_used' => 1, 'final_remaining' => 1, 'final_record_version' => 'synthetic-final-'.$id, 'last_master_sequence_number' => 1]);
        }
        DB::table('offline_scanner_import_conflicts')->insert(['id' => 550, 'offline_scanner_result_import_id' => 430,
            'offline_scanner_imported_log_id' => 901, 'event_id' => 301, 'guest_id' => 901,
            'replacement_guest_id' => 902, 'conflict_type' => 'synthetic-retained', 'server_state' => json_encode(['retained' => true])]);
        DB::table('offline_scanner_import_conflicts')->insert(['id' => 551, 'offline_scanner_result_import_id' => 430,
            'event_id' => 302, 'guest_id' => 903, 'replacement_guest_id' => 901, 'conflict_type' => 'synthetic-neighbor']);
        DB::table('admissions')->where('id', 901)->update(['offline_scanner_imported_log_id' => 901]);
        DB::table('checkins')->where('id', 901)->update(['offline_scanner_imported_log_id' => 901, 'offline_log_uuid' => 'synthetic-deletion-log-901']);
    }

    private function requestDeletion(string $action, int $guestId = 901, bool $json = false): TestResponse
    {
        $this->from(route('admin.guests.index'));
        $url = $action === 'bulk' ? route('admin.guests.bulk-destroy') : route('admin.guests.destroy', $guestId);
        $data = $action === 'bulk' ? ['guest_ids' => [902, '901']] : [];

        return $json ? $this->deleteJson($url, $data) : $this->delete($url, $data);
    }

    private function assertSuccess(TestResponse $response, string $action): void
    {
        $response->assertRedirect(route('admin.guests.index'))->assertSessionHasNoErrors()->assertSessionMissing('error')
            ->assertSessionHas('success', $action === 'bulk' ? '2 guest passes deleted.' : 'Guest pass deleted.');
    }

    private function assertFailure(TestResponse $response, string $language = 'sw'): void
    {
        $message = $language === 'en'
            ? 'Guest deletion could not be completed. Refresh the guest list before taking another action.'
            : 'Haikuwezekana kukamilisha ufutaji. Pakia upya orodha ya wageni kabla ya kuchukua hatua nyingine.';
        $response->assertRedirect(route('admin.guests.index'))->assertSessionMissing('success')->assertSessionHas('error', $message);
    }

    private function beforeFirstTransaction(Closure $hook): void
    {
        $armed = true;
        DB::connection()->beforeStartingTransaction(static function () use (&$armed, $hook): void {
            if ($armed) {
                $armed = false;
                $hook();
            }
        });
    }

    private function installDiskProbe(?Closure $delete = null): GuestDeletionInspectingDisk
    {
        $probe = new GuestDeletionInspectingDisk($this->disk, $delete);
        Storage::shouldReceive('disk')->with('guest_qr')->andReturnUsing(static function () use ($probe): GuestDeletionInspectingDisk {
            $probe->resolutions++;

            return $probe;
        });

        return $probe;
    }

    private function installSqlFault(int $id, bool $concurrency): void
    {
        if (! in_array($id, [901, 902], true)) {
            throw new LogicException('Only invented fixture targets may receive a TEMP trigger.');
        }
        $message = $concurrency ? 'database is locked' : 'p4043_PRIVATE_DIAGNOSTIC fixture SQL refusal';
        DB::connection()->unprepared('CREATE TEMP TRIGGER "p4043_delete_fault" BEFORE DELETE ON "guests" WHEN OLD."id" = '
            .$id.' BEGIN SELECT RAISE(ABORT, \''.$message.'\'); END');
    }

    private function assertDeletedState(array $expected, array $guestIds, array $deletedPaths): void
    {
        $qrIds = array_column(array_filter($expected['qr_codes'], static fn (array $row): bool => in_array($row['guest_id'], $guestIds, true)), 'id');
        foreach (['guests' => 'id', 'qr_codes' => 'guest_id', 'admissions' => 'guest_id'] as $table => $column) {
            $expected[$table] = array_values(array_filter($expected[$table], static fn (array $row): bool => ! in_array($row[$column], $guestIds, true)));
        }
        foreach (['checkins', 'scan_logs', 'notification_logs', 'offline_scanner_import_conflicts', 'offline_scanner_package_guest_snapshots', 'offline_scanner_final_guest_states'] as $table) {
            foreach ($expected[$table] as &$row) {
                foreach (['guest_id', 'replacement_guest_id'] as $column) {
                    if (array_key_exists($column, $row) && in_array($row[$column], $guestIds, true)) {
                        $row[$column] = null;
                    }
                }
                if ($table === 'checkins' && in_array($row['qr_code_id'], $qrIds, true)) {
                    $row['qr_code_id'] = null;
                }
            }
            unset($row);
        }
        foreach ($deletedPaths as $path) {
            unset($expected['_files'][$path]);
        }
        $this->assertSame($expected, $this->snapshot(), 'Only declared Guest/QR/Admission cascades, FK nulling and captured bytes change; retained scalar provenance, parents and neighbors remain exact.');
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $contents = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($contents, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $rows[$table->name] = $contents;
        }
        $files = $this->disk->allFiles();
        sort($files);
        $rows['_files'] = [];
        foreach ($files as $file) {
            $rows['_files'][$file] = hash('sha256', $this->disk->get($file));
        }
        $publicFiles = $this->public->allFiles();
        sort($publicFiles);
        $rows['_public_files'] = [];
        foreach ($publicFiles as $file) {
            $rows['_public_files'][$file] = hash('sha256', $this->public->get($file));
        }

        return $rows;
    }

    private function assertNoPrivateDiagnostics(): void
    {
        $this->assertStringNotContainsString('p4043_PRIVATE_DIAGNOSTIC', json_encode(session()->all()));
        $logs = storage_path('logs');
        if (is_dir($logs)) {
            foreach ((new Filesystem)->allFiles($logs) as $file) {
                $this->assertStringNotContainsString('p4043_PRIVATE_DIAGNOSTIC', file_get_contents($file->getPathname()));
            }
        }
    }

    private function assertAutocommit(): void
    {
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function path(int $id): string
    {
        return 'qr-codes/deletion/'.$id.'.png';
    }

    private function assertAscendingIdOrder(array $orders): void
    {
        $this->assertNotEmpty($orders);
        $this->assertSame('asc', $orders[0]['direction']);
        $this->assertMatchesRegularExpression('/(?:\A|\.)id\z/', $orders[0]['column']);
    }

    private function inspectReads(): GuestDeletionInspectingGrammar
    {
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $grammar = new GuestDeletionInspectingGrammar($db);
        $db->setQueryGrammar($grammar);
        $this->beforeApplicationDestroyed(static fn () => $db->setQueryGrammar($original));

        return $grammar;
    }
}

/**
 * Trusted local observation wrapper on the guarded TEMP private QR disk: built from
 * that disk's own driver/adapter/config, so the production GuestQrStorage policy
 * validates the same root. Adapter resolution is captured separately from byte
 * calls; inherited root/config inspection is not recorded.
 */
final class GuestDeletionInspectingDisk extends LocalFilesystemAdapter
{
    public int $resolutions = 0;

    public array $calls = [];

    public function __construct(private FilesystemAdapter $delegate, private ?Closure $delete = null)
    {
        parent::__construct($delegate->getDriver(), $delegate->getAdapter(), $delegate->getConfig());
    }

    public function exists($path): bool
    {
        $this->calls[] = ['exists', $path];

        return $this->delegate->exists($path);
    }

    public function get($path): ?string
    {
        $this->calls[] = ['get', $path];

        return $this->delegate->get($path);
    }

    public function delete($path): bool
    {
        $this->calls[] = ['delete', $path];

        return $this->delete !== null ? ($this->delete)($path, count($this->deletedPaths())) : $this->delegate->delete($path);
    }

    public function deletedPaths(): array
    {
        return array_column(array_filter($this->calls, static fn (array $call): bool => $call[0] === 'delete'), 1);
    }
}

/** SQLite omits FOR UPDATE; these observations establish builder intent only. */
final class GuestDeletionInspectingGrammar extends SQLiteGrammar
{
    public bool $observing = true;

    public array $reads = [];

    public function compileSelect(Builder $query)
    {
        $sql = parent::compileSelect($query);
        if ($this->observing && DB::connection()->transactionLevel() > 0
            && (($query->lock !== null && in_array($query->from, ['events', 'users', 'offline_scanner_sessions', 'guests'], true)) || $query->from === 'qr_codes')) {
            $this->reads[] = ['table' => $query->from, 'write' => $query->useWritePdo, 'lock' => $query->lock,
                'level' => DB::connection()->transactionLevel(), 'pdo_transaction' => DB::connection()->getPdo()->inTransaction(),
                'sql' => $sql, 'bindings' => $query->getBindings(), 'columns' => $query->columns ?? ['*'],
                'orders' => $query->orders ?? [], 'limit' => $query->limit];
        }

        return $sql;
    }
}
