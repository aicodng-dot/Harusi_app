<?php

namespace Tests\Feature;

use App\Exceptions\LegacyGuestIssuanceFailed;
use App\Exceptions\OfflineEventLockedException;
use App\Models\Admission;
use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerSession;
use App\Models\QrCode;
use App\Models\User;
use App\Services\GuestImportExcelReader;
use App\Services\GuestQrImageService;
use App\Services\Tenancy\LegacyGuestIssuance;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Concerns\FakesGuestQrDisk;
use Tests\TestCase;

/** Whole issuance family; TEMP bytes/SQLite builder intent do not prove MySQL/storage durability. */
class LegacyGuestIssuanceTest extends TestCase
{
    use FakesGuestQrDisk;

    private FilesystemAdapter $disk;

    private FilesystemAdapter $public;

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new LogicException('Guest issuance fixtures require isolated in-memory SQLite.');
        }
        $pdo = $db->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new LogicException('Guest issuance fixtures require actual SQLite PDO.');
        }
        $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool => $row['name'] === 'main'));
        if (count($main) !== 1 || $main[0]['file'] !== '' || $db->transactionLevel() !== 0 || $pdo->inTransaction()
            || (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new LogicException('Guest issuance fixtures require memory main, enabled FKs and actual autocommit0.');
        }
        $temporaryRoot = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'codex-harusi-guest-issuance-'.bin2hex(random_bytes(8));
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
            $prefix = rtrim((string) realpath(sys_get_temp_dir()), '/\\').DIRECTORY_SEPARATOR.'codex-harusi-guest-issuance-';
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

    #[DataProvider('lateAuthorities')]
    public function test_http_current_actor_and_event_authority_are_rechecked_before_mutation(string $action, string $change, int $status): void
    {
        $this->fixture();
        if ($action === 'import') {
            $this->preview([$this->row('New imported Guest')]);
        }
        $before = null;
        $probe = $this->probe();
        $this->beforeFirstTransaction(function () use ($change, &$before): void {
            match ($change) {
                'inactive' => DB::table('users')->where('id', 211)->update(['is_active' => false]),
                'role' => DB::table('users')->where('id', 211)->update(['role' => User::ROLE_CUSTOMER]),
                'owned' => DB::table('events')->where('id', 301)->update(['workspace_id' => 101]),
            };
            $before = $this->snapshot();
        });

        $this->requestAction($action)->assertStatus($status)->assertSessionMissing('success');

        $this->assertNotNull($before);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $probe->effects());
        $this->assertSame(0, $probe->resolutions);
        $this->assertAutocommit();
    }

    public static function lateAuthorities(): array
    {
        $cases = [];
        foreach (['create', 'generate', 'import'] as $action) {
            foreach (['inactive' => 403, 'role' => 403, 'owned' => 404] as $change => $status) {
                $cases[$action.' '.$change] = [$action, $change, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('lateGuestRoutes')]
    public function test_http_generation_and_every_repair_caller_use_current_scoped_guest(string $action, string $change): void
    {
        $this->fixture();
        $before = null;
        $probe = $this->probe();
        $this->beforeFirstTransaction(function () use ($change, &$before): void {
            if ($change === 'moved') {
                DB::table('guests')->where('id', 901)->update(['event_id' => 302]);
            } else {
                DB::table('guests')->where('id', 901)->delete();
            }
            $before = $this->snapshot();
        });

        $this->requestAction($action)->assertNotFound()->assertSessionMissing('success');

        $this->assertNotNull($before, 'The current guarded composition must actually execute.');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $probe->effects());
        $this->assertAutocommit();
    }

    public static function lateGuestRoutes(): array
    {
        $cases = [];
        foreach (['generate', 'image', 'download', 'zip_all', 'zip_selected'] as $action) {
            foreach (['moved', 'missing'] as $change) {
                $cases[$action.' '.$change] = [$action, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('writeActions')]
    public function test_http_later_request_or_session_changes_cannot_retarget_captured_creation(string $action): void
    {
        $this->fixture();
        if ($action === 'import') {
            $this->preview([$this->row('Frozen import')]);
        }
        $before = $this->snapshot();
        $this->beforeFirstTransaction(static function (): void {
            session()->put('selected_event_id', 302);
            request()->merge(['name' => 'Injected foreign payload', 'event_id' => 302]);
        });
        $response = $this->requestAction($action);
        $response->assertRedirect()->assertSessionMissing('error');
        if ($action === 'generate') {
            $this->assertNotSame($before['qr_codes'][0]['qr_token'], QrCode::query()->findOrFail(501)->qr_token);
            $this->assertSame(301, Guest::query()->findOrFail(901)->event_id);
        } else {
            $created = Guest::query()->where('id', '>', 904)->sole();
            $this->assertSame(301, $created->event_id);
            $this->assertNotSame('Injected foreign payload', $created->name);
        }
        $this->assertSame($before['guests'][2], $this->snapshot()['guests'][2]);
        $this->assertAutocommit();
    }

    public static function writeActions(): array
    {
        return ['create' => ['create'], 'generate' => ['generate'], 'import' => ['import']];
    }

    public function test_http_missing_only_candidate_that_acquires_a_qr_is_not_rotated_or_counted(): void
    {
        $this->fixture();
        $before = null;
        $this->beforeFirstTransaction(function () use (&$before): void {
            QrCode::query()->forceCreate(['id' => 502, 'guest_id' => 902, 'qr_token' => str_repeat('n', 64),
                'qr_image_path' => 'qr-codes/arrived.png', 'is_active' => false,
                'generated_at' => now()->subDay(), 'revoked_at' => now()->subHour()]);
            $this->disk->put('qr-codes/arrived.png', $this->imageBytes('0048'));
            $before = $this->snapshot();
        });
        $probe = $this->probe();

        $this->post(route('admin.qr-codes.generate-missing'))->assertRedirect()
            ->assertSessionHas('success', '0 missing QR codes were generated.');

        $this->assertNotNull($before);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $probe->effects());
        $this->assertAutocommit();
    }

    #[DataProvider('offlineRepairRoutes')]
    public function test_http_compatible_images_remain_readable_offline_but_needed_repairs_are_blocked(string $action, bool $repair): void
    {
        $this->fixture();
        $this->offline();
        if ($repair) {
            $this->disk->delete('qr-codes/current.png');
        }
        $before = $this->snapshot();
        $probe = $this->probe();

        $response = $this->requestAction($action);
        if ($repair) {
            $response->assertRedirect()->assertSessionHas('error', OfflineEventLockedException::MESSAGE);
        } else {
            $response->assertOk();
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $probe->effects());
        $this->assertAutocommit();
    }

    public static function offlineRepairRoutes(): array
    {
        $cases = [];
        foreach (['image', 'download', 'zip_all', 'zip_selected'] as $action) {
            foreach ([false, true] as $repair) {
                $cases[$action.($repair ? ' repair' : ' healthy')] = [$action, $repair];
            }
        }

        return $cases;
    }

    #[DataProvider('sharedReferences')]
    public function test_http_shared_current_paths_veto_generation_or_repair_before_bytes(string $action, bool $foreign): void
    {
        $this->fixture();
        if ($foreign) {
            DB::table('qr_codes')->where('id', 503)->update(['qr_image_path' => 'qr-codes/current.png']);
        } else {
            QrCode::query()->forceCreate(['id' => 502, 'guest_id' => 902, 'qr_token' => str_repeat('n', 64),
                'qr_image_path' => 'qr-codes/current.png', 'is_active' => true]);
        }
        $this->disk->put('qr-codes/current.png', 'Synthetic incompatible shared bytes');
        $before = $this->snapshot();
        $probe = $this->probe();

        $this->assertFailure($this->requestAction($action));

        $this->assertSame([], $probe->effects());
        $this->assertSame($before, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function sharedReferences(): array
    {
        return ['generation same Event' => ['generate', false], 'generation foreign Event' => ['generate', true],
            'repair same Event' => ['image', false], 'repair foreign Event' => ['image', true]];
    }

    #[DataProvider('destinationModes')]
    public function test_http_an_existing_unreferenced_different_destination_is_not_overwritten(string $action): void
    {
        $f = $this->fixture();
        $token = $action === 'generate' ? str_repeat('z', 64) : str_repeat('a', 40);
        if ($action === 'generate') {
            Str::createRandomStringsUsing(static fn (): string => $token);
            $this->beforeApplicationDestroyed(static fn () => Str::createRandomStringsNormally());
        } else {
            $this->disk->put('qr-codes/current.png', 'Synthetic image requiring repair');
        }
        $destination = $this->computedPath($f['guest'], $token);
        $this->disk->put($destination, 'Synthetic unreferenced existing destination');
        $before = $this->snapshot();
        $probe = $this->probe();

        $this->assertFailure($this->requestAction($action));

        $this->assertSame([], $probe->effects());
        $this->assertSame($before, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function destinationModes(): array
    {
        return ['explicit rotation' => ['generate'], 'existing repair' => ['image']];
    }

    #[DataProvider('byteFailures')]
    public function test_http_strict_byte_failures_have_one_attempt_and_named_partial_outcomes(string $fault): void
    {
        $this->fixture();
        $before = $this->snapshot();
        $probe = $this->probe(
            function (string $path, string $bytes) use ($fault): mixed {
                if ($fault === 'put_false') {
                    return false;
                }
                $result = $this->disk->put($path, $bytes);
                if ($fault === 'put_partial_throw') {
                    throw new RuntimeException('database is locked p4045_PRIVATE_DIAGNOSTIC put');
                }

                return $result;
            },
            function (string $path) use ($fault): mixed {
                if ($fault === 'delete_false') {
                    return false;
                }
                if ($fault === 'delete_partial_throw') {
                    $this->disk->delete($path);
                    throw new RuntimeException('database is locked p4045_PRIVATE_DIAGNOSTIC unlink');
                }

                return $this->disk->delete($path);
            },
        );
        $starts = 0;
        DB::connection()->beforeStartingTransaction(static function () use (&$starts): void {
            $starts++;
        });

        $this->assertFailure($this->requestAction('generate'));

        $after = $this->snapshot();
        $this->assertSame($this->sqlOnly($before), $this->sqlOnly($after), 'Known SQL rollback does not undo preceding bytes.');
        $this->assertSame(1, $starts);
        $puts = $probe->paths('put');
        $this->assertCount(1, $puts);
        $this->assertSame(in_array($fault, ['delete_false', 'delete_partial_throw'], true) ? 1 : 0, count($probe->paths('delete')));
        $this->assertSame($fault !== 'put_false', $this->disk->exists($puts[0]));
        $this->assertSame($fault !== 'delete_partial_throw', $this->disk->exists('qr-codes/current.png'));
        $this->assertNoPrivateDiagnostics();
        $this->assertAutocommit();
    }

    public static function byteFailures(): array
    {
        return array_combine(['put_false', 'put_partial_throw', 'delete_false', 'delete_partial_throw'],
            array_map(static fn (string $v): array => [$v], ['put_false', 'put_partial_throw', 'delete_false', 'delete_partial_throw']));
    }

    #[DataProvider('cancelledSaves')]
    public function test_http_cancelled_guest_or_qr_saves_cannot_reach_png_or_success(string $action): void
    {
        $this->fixture();
        $before = $this->snapshot();
        $renderer = $this->renderer();
        $probe = $this->probe();
        if ($action === 'create') {
            Guest::creating(static fn (): bool => false);
        } else {
            QrCode::saving(static fn (): bool => false);
        }

        $this->assertFailure($this->requestAction($action));

        $this->assertSame([], $renderer->calls);
        $this->assertSame([], $probe->effects());
        $this->assertSame($before, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function cancelledSaves(): array
    {
        return ['Guest create cancelled' => ['create'], 'QR save cancelled' => ['generate']];
    }

    #[DataProvider('sqlWriteActions')]
    public function test_http_actual_sql_concurrency_failure_is_one_attempt_and_interrupted(string $action): void
    {
        $this->fixture();
        $before = $this->snapshot();
        $probe = $this->probe();
        $attempts = 0;
        DB::connection()->getPdo()->sqliteCreateFunction('issuance_sql_attempt', static function () use (&$attempts): int {
            return ++$attempts;
        }, 0);
        $target = $action === 'create' ? 'BEFORE INSERT ON guests' : 'BEFORE UPDATE ON qr_codes';
        DB::unprepared("CREATE TRIGGER synthetic_issuance_sql_fault {$target} BEGIN SELECT issuance_sql_attempt(); SELECT RAISE(ABORT, 'database is locked p4045_PRIVATE_DIAGNOSTIC sql'); END");

        $this->assertFailure($this->requestAction($action), 'sw', true);

        $this->assertSame(1, $attempts, 'The synthetic SQLite lock message does not justify repeating file-bearing work.');
        $this->assertSame([], $probe->effects());
        $this->assertSame($before, $this->snapshot());
        $this->assertNoPrivateDiagnostics();
        $this->assertAutocommit();
    }

    public static function sqlWriteActions(): array
    {
        return ['create' => ['create'], 'generate' => ['generate']];
    }

    #[DataProvider('languages')]
    public function test_http_fixed_failure_labels_use_supported_account_language_or_sw(mixed $preference, string $expected): void
    {
        $this->fixture();
        User::retrieved(static function (User $user) use ($preference): void {
            if ((int) $user->id === 211) {
                $user->setAttribute('preferred_language', $preference);
            }
        });
        $locale = $expected === 'sw' ? 'en' : 'sw';
        app()->setLocale($locale);
        $this->probe(static fn (): bool => false);

        $this->assertFailure($this->requestAction('generate'), $expected);

        $this->assertSame($locale, app()->getLocale());
        $this->assertNoPrivateDiagnostics();
        $this->assertAutocommit();
    }

    public static function languages(): array
    {
        return ['Swahili' => ['sw', 'sw'], 'English' => ['en', 'en'], 'unsupported' => ['unsupported', 'sw'], 'legacy null' => [null, 'sw']];
    }

    #[DataProvider('invalidBindings')]
    public function test_http_missing_or_mismatched_preview_binding_expires_without_import(string $variant): void
    {
        $this->fixture();
        $this->preview([$this->row('Bound import')]);
        match ($variant) {
            'missing' => session()->forget(['guest_import.event_id', 'guest_import.actor_id']),
            'event' => session()->put('guest_import.event_id', 302),
            'actor' => session()->put('guest_import.actor_id', 212),
            'malformed' => session()->put('guest_import.event_id', '0301'),
        };
        $before = $this->snapshot();
        $probe = $this->probe();

        $this->requestAction('import')->assertRedirect(route('admin.guests.import'))->assertSessionHasErrors('excel_file');

        $this->assertFalse(session()->has('guest_import.token'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $probe->effects());
        $this->assertAutocommit();
    }

    public static function invalidBindings(): array
    {
        return ['unbound old preview' => ['missing'], 'wrong Event' => ['event'], 'wrong actor' => ['actor'], 'noncanonical binding' => ['malformed']];
    }

    #[DataProvider('importFaultModes')]
    public function test_http_only_known_rolled_back_renderer_failure_continues_import(string $mode): void
    {
        $this->fixture();
        $this->preview([$this->row('First imported'), $this->row('Second imported', '+255711111112')]);
        $renderer = $this->renderer(function (Guest $guest, int $call) use ($mode): ?string {
            if (in_array($mode, ['renderer', 'rollback_ack'], true) && $call === 1) {
                throw new RuntimeException('database is locked p4045_PRIVATE_DIAGNOSTIC renderer');
            }

            return null;
        });
        if ($mode === 'sql') {
            DB::unprepared("CREATE TRIGGER synthetic_issuance_import_fault BEFORE INSERT ON guests WHEN NEW.name='First imported' BEGIN SELECT RAISE(ABORT, 'p4045_PRIVATE_DIAGNOSTIC SQL'); END");
        }
        if ($mode === 'rollback_ack') {
            $armed = true;
            Events::listen(TransactionRolledBack::class, static function () use (&$armed): void {
                if ($armed && DB::connection()->transactionLevel() === 0) {
                    $armed = false;
                    throw new RuntimeException('p4045_PRIVATE_DIAGNOSTIC replacement rollback acknowledgement');
                }
            });
        }
        if ($mode === 'committed') {
            $armed = true;
            Events::listen(TransactionCommitted::class, static function () use (&$armed): void {
                if ($armed && DB::connection()->transactionLevel() === 0) {
                    $armed = false;
                    throw new RuntimeException('p4045_PRIVATE_DIAGNOSTIC after committed acknowledgement');
                }
            });
        }

        $response = $this->requestAction('import');

        $response->assertRedirect(route('admin.guests.import'));
        $result = session('guest_import.result');
        $this->assertIsArray($result);
        if ($mode === 'renderer') {
            $response->assertSessionHas('success')->assertSessionMissing('error');
            $this->assertSame([1, 1, 1], [$result['summary']['imported_successfully'], $result['summary']['failed_rows'], $result['summary']['generated_qr_codes']]);
            $this->assertSame('Database error. This row was not imported.', $result['failed'][0]['reason']);
            $this->assertDatabaseMissing('guests', ['name' => 'First imported']);
            $this->assertDatabaseHas('guests', ['name' => 'Second imported', 'event_id' => 301]);
            $this->assertCount(2, $renderer->calls);
        } else {
            $response->assertSessionMissing('success')->assertSessionHas('error', __('guest_issuance.interrupted', [], 'sw'));
            $this->assertTrue($result['interrupted']);
            $this->assertSame([0, 0], [$result['summary']['imported_successfully'], $result['summary']['failed_rows']], 'An uncertain row is not certified failed or successful.');
            $this->assertDatabaseMissing('guests', ['name' => 'Second imported']);
            $this->assertSame($mode === 'committed', Guest::query()->where('name', 'First imported')->exists());
        }
        $this->assertFalse(session()->has('guest_import.token'));
        $this->assertNoPrivateDiagnostics();
        $this->assertAutocommit();
    }

    public static function importFaultModes(): array
    {
        return ['known callback rollback' => ['renderer'], 'actual SQL failure' => ['sql'], 'replacement rollback acknowledgement' => ['rollback_ack'], 'post-commit acknowledgement failure' => ['committed']];
    }

    #[DataProvider('midImportAuthority')]
    public function test_http_mid_import_authority_loss_stops_without_exposing_prior_private_results(string $change, int $status): void
    {
        $this->fixture();
        $this->preview([$this->row('First completed'), $this->row('Never dispatched', '+255711111112')]);
        session()->put('guest_import.result', ['private-old-marker' => 'old private result must be cleared']);
        $starts = 0;
        DB::connection()->beforeStartingTransaction(static function () use (&$starts, $change): void {
            $starts++;
            if ($starts === 2) {
                if ($change === 'actor') {
                    DB::table('users')->where('id', 211)->update(['role' => User::ROLE_CUSTOMER]);
                } else {
                    DB::table('events')->where('id', 301)->update(['workspace_id' => 101]);
                }
            }
        });

        $response = $this->requestAction('import');

        $response->assertStatus($status)->assertSessionMissing('success')->assertSessionMissing('guest_import.result');
        $this->assertDatabaseHas('guests', ['name' => 'First completed', 'event_id' => 301]);
        $this->assertDatabaseMissing('guests', ['name' => 'Never dispatched']);
        $this->assertStringNotContainsString('First completed', $response->getContent());
        $this->assertFalse(session()->has('guest_import.token'));
        $this->assertAutocommit();
    }

    public static function midImportAuthority(): array
    {
        return ['actor loss' => ['actor', 403], 'Event ownership loss' => ['event', 404]];
    }

    public function test_http_bound_preview_consumes_once_and_preserves_existing_duplicate_actions(): void
    {
        $this->fixture();
        $this->preview([$this->row('Issuance Guest 901', '+255712345678'), $this->row('New distinct import')]);
        $this->assertSame([301, 211], [session('guest_import.event_id'), session('guest_import.actor_id')]);
        $token = session('guest_import.token');
        $before = Guest::query()->findOrFail(901)->getRawOriginal();

        $this->post(route('admin.guests.import.process'), ['import_action' => 'confirm', 'import_token' => $token,
            'generate_qr' => false, 'duplicate_actions' => [2 => 'add_anyway']])->assertRedirect(route('admin.guests.import'));

        $result = session('guest_import.result');
        $this->assertSame([2, 1, 1, 0], [$result['summary']['imported_successfully'], $result['summary']['duplicates_added'],
            $result['summary']['imported_new_contacts'], $result['summary']['generated_qr_codes']]);
        $this->assertSame($before, Guest::query()->findOrFail(901)->getRawOriginal());
        $count = Guest::query()->count();
        $this->post(route('admin.guests.import.process'), ['import_action' => 'confirm', 'import_token' => $token])->assertSessionHasErrors('excel_file');
        $this->assertSame($count, Guest::query()->count());
        $this->assertAutocommit();
    }

    #[DataProvider('invalidContexts')]
    public function test_direct_invalid_raw_context_refuses_before_sql_or_adapter(string $variant, int $status): void
    {
        $f = $this->fixture();
        $actor = $f['actor'];
        $guest = $f['guest'];
        $selected = 301;
        match ($variant) {
            'actor_current' => $actor->forceFill(['id' => 212]),
            'actor_original' => $actor->setRawAttributes(array_replace($actor->getAttributes(), ['id' => 211.0]), true),
            'selected_array' => $selected = [301],
            'selected_padded' => $selected = '0301',
            'guest_original' => $guest->setRawAttributes(array_replace($guest->getAttributes(), ['id' => (string) PHP_INT_MAX.'0']), true),
            'guest_current_event' => $guest->forceFill(['event_id' => 302]),
            'guest_current_key' => $guest->forceFill(['id' => 903]),
            'guest_selected' => $selected = 302,
        };
        $before = $this->snapshot();
        $queries = [];
        $armed = true;
        DB::listen(static function ($query) use (&$queries, &$armed): void {
            if ($armed) {
                $queries[] = $query->sql;
            }
        });
        $probe = $this->probe();
        $exception = null;
        try {
            $service = app(LegacyGuestIssuance::class);
            if (str_starts_with($variant, 'guest_')) {
                $service->generate($guest, $actor, $selected);
            } else {
                $service->create($this->payload(), $actor, $selected);
            }
        } catch (HttpExceptionInterface $caught) {
            $exception = $caught;
        } finally {
            $armed = false;
        }
        $this->assertInstanceOf(HttpExceptionInterface::class, $exception);
        $this->assertSame($status, $exception->getStatusCode());
        $this->assertSame([], $queries);
        $this->assertSame(0, $probe->resolutions);
        $this->assertSame($before, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function invalidContexts(): array
    {
        $cases = [];
        foreach (['actor_current', 'actor_original', 'selected_array', 'selected_padded', 'guest_original',
            'guest_current_event', 'guest_current_key', 'guest_selected'] as $variant) {
            $cases[$variant] = [$variant, str_starts_with($variant, 'actor_') ? 403 : 404];
        }

        return $cases;
    }

    #[DataProvider('creationModes')]
    public function test_direct_allowlisted_creation_preserves_pass_and_optional_qr_invariants(string $pass, int $capacity, bool $qr): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $guest = app(LegacyGuestIssuance::class)->create(array_replace($this->payload(), ['pass_type' => $pass,
            'allowed_entries' => $capacity, 'event_id' => 303, 'used_entries' => 10, 'manual_code' => '9999', 'qr_token' => 'injected']), $f['actor'], '301', $qr);

        $this->assertSame([301, $pass, $capacity, 0, Guest::STATUS_ACTIVE], [$guest->event_id, $guest->pass_type,
            $guest->allowed_entries, $guest->used_entries, $guest->status]);
        $this->assertMatchesRegularExpression('/\A[0-9]{4}\z/', $guest->manual_code);
        $this->assertSame($qr, $guest->qrCode !== null);
        if ($qr) {
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{64}\z/', $guest->qrCode->qr_token);
            $this->assertTrue($this->disk->exists($guest->qrCode->qr_image_path));
        }
        $this->assertSame($before['guests'], array_slice($this->snapshot()['guests'], 0, count($before['guests'])));
        $this->assertSame($before['admissions'], $this->snapshot()['admissions']);
        $this->assertAutocommit();
    }

    public static function creationModes(): array
    {
        return ['Single' => [Guest::PASS_SINGLE, 1, true], 'Double' => [Guest::PASS_DOUBLE, 2, true],
            'Special' => [Guest::PASS_SPECIAL, 7, true], 'eleven-person Special' => [Guest::PASS_SPECIAL, 11, true],
            'hundred-person Special' => [Guest::PASS_SPECIAL, 100, true],
            'hundred-person Special without QR' => [Guest::PASS_SPECIAL, 100, false], 'without QR' => [Guest::PASS_DOUBLE, 2, false]];
    }

    #[DataProvider('duplicatePolicies')]
    public function test_direct_import_duplicate_policy_is_current_and_does_not_modify_matched_guest(string $policy, bool $match, string $outcome): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $payload = $match ? $this->payload('Issuance Guest 901', '+255712345678') : $this->payload();

        $result = app(LegacyGuestIssuance::class)->importRow($payload, $policy, $f['actor'], 301, false);

        $this->assertSame($outcome, $result['outcome']);
        $this->assertFalse($result['generated_qr']);
        $this->assertSame($before['guests'][0], Guest::query()->findOrFail(901)->getRawOriginal());
        if ($outcome === 'created') {
            $this->assertInstanceOf(Guest::class, $result['guest']);
            $this->assertNotSame(901, $result['guest']->id);
            $this->assertNull($result['guest']->qrCode);
            $this->assertSame(301, $result['guest']->event_id);
        } else {
            $this->assertNull($result['guest']);
            $this->assertSame($before, $this->snapshot());
        }
        $this->assertAutocommit();
    }

    public static function duplicatePolicies(): array
    {
        return ['new' => ['reject_if_match', false, 'created'], 'current conflict' => ['reject_if_match', true, 'conflict'],
            'skip' => ['skip', true, 'skipped'], 'add anyway' => ['add_anyway', true, 'created']];
    }

    #[DataProvider('primaryContexts')]
    public function test_direct_primary_current_lineage_and_path_vetoes_precede_bytes_in_one_local_attempt(string $variant): void
    {
        $f = $this->fixture();
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
        $grammar = $this->grammar();
        $probe = $this->probe(function (string $path, string $bytes) use ($grammar): bool {
            $this->assertSame(['events', 'users', 'events', 'offline_scanner_sessions', 'guests', 'qr_codes'], array_column(array_slice($grammar->reads, 0, 6), 'table'));
            foreach (array_slice($grammar->reads, 0, 6) as $read) {
                $this->assertTrue($read['write']);
                $this->assertTrue($read['lock']);
                $this->assertTrue($read['pdo_transaction']);
            }
            $this->assertStringContainsString('event_id', $grammar->reads[4]['sql']);
            $this->assertContains(301, $grammar->reads[4]['bindings']);
            $this->assertContains(901, $grammar->reads[4]['bindings']);
            $references = array_filter($grammar->reads, static fn (array $read): bool => str_contains($read['sql'], 'qr_image_path'));
            $this->assertNotEmpty($references);
            foreach ($references as $read) {
                $this->assertSame(['id'], array_map(static fn (string $column): string => last(explode('.', $column)), $read['columns']));
                $this->assertTrue($read['write']);
                $this->assertTrue($read['lock']);
                $this->assertStringNotContainsString('guest_id', $read['sql']);
                $this->assertStringNotContainsString('event_id', $read['sql']);
                $this->assertSame(1, $read['limit']);
            }
            $grammar->observing = false;

            return $this->disk->put($path, $bytes);
        });
        try {
            $result = app(LegacyGuestIssuance::class)->generate($f['guest'], $f['actor'], 301);
            $grammar->observing = false;
            $this->assertTrue($result['generated']);
            $this->assertTrue($result['replaced']);
            $this->assertSame([[$ambient, (bool) $ambient]], $starts);
            $this->assertSame($ambient, $db->transactionLevel());
            $this->assertSame((bool) $ambient, $db->getPdo()->inTransaction());
            $this->assertSame($before['guests'], $this->snapshot()['guests']);
            $this->assertSame($before['admissions'], $this->snapshot()['admissions']);
            $this->assertCount(1, $probe->paths('put'));
            $this->assertSame(['qr-codes/current.png'], $probe->paths('delete'));
        } finally {
            $grammar->observing = false;
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
        }
        if ($ambient) {
            $this->assertSame($this->sqlOnly($before), $this->sqlOnly($this->snapshot()));
            $this->assertFalse($this->disk->exists('qr-codes/current.png'), 'Caller rollback cannot restore completed bytes.');
            $this->assertTrue($this->disk->exists($probe->paths('put')[0]));
        }
        $this->assertAutocommit();
    }

    public static function primaryContexts(): array
    {
        return ['ordinary' => ['ordinary'], 'platform archived Event' => ['platform'], 'membership cancelled Event' => ['member'], 'caller transaction' => ['nested']];
    }

    #[DataProvider('repairTokens')]
    public function test_direct_existing_token_repair_allows_own_path_overwrite_without_changing_credentials(int $length): void
    {
        $f = $this->fixture();
        $token = str_repeat('x', $length);
        $path = $this->computedPath($f['guest'], $token);
        DB::table('qr_codes')->where('id', 501)->update(['qr_token' => $token, 'qr_image_path' => $path,
            'generated_at' => $length === 64 ? null : now()->subDay()]);
        $this->disk->put($path, 'Synthetic own-path incompatible bytes');
        $before = $this->snapshot();
        $probe = $this->probe();

        $qr = app(LegacyGuestIssuance::class)->ensureImage($f['guest'], $f['actor'], 301);

        $this->assertSame($token, $qr->qr_token);
        $this->assertFalse($qr->is_active);
        $this->assertNotNull($qr->revoked_at);
        $this->assertNotNull($qr->generated_at);
        $this->assertSame($path, $qr->qr_image_path);
        $this->assertSame([$path], $probe->paths('put'));
        $this->assertSame([], $probe->paths('delete'));
        $this->assertStringContainsString("Manual admission code\0".'0047', $this->disk->get($path));
        $this->assertSame($before['guests'], $this->snapshot()['guests']);
        $this->assertSame($before['admissions'], $this->snapshot()['admissions']);
        $this->assertAutocommit();
    }

    public static function repairTokens(): array
    {
        return ['40-character existing token' => [40], '64-character missing timestamp' => [64], '128-character existing token' => [128]];
    }

    public function test_direct_authorized_missing_manual_code_is_saved_before_render_and_preserves_usage(): void
    {
        $f = $this->fixture();
        DB::table('guests')->where('id', 901)->update(['manual_code' => null]);
        $before = Guest::query()->findOrFail(901)->getRawOriginal();
        $renderer = $this->renderer(function (Guest $guest): ?string {
            $this->assertMatchesRegularExpression('/\A[0-9]{4}\z/', $guest->manual_code);
            $this->assertSame($guest->manual_code, DB::table('guests')->where('id', 901)->value('manual_code'));

            return null;
        });

        $qr = app(LegacyGuestIssuance::class)->ensureImage($f['guest'], $f['actor'], 301);

        $guest = Guest::query()->findOrFail(901);
        $this->assertMatchesRegularExpression('/\A[0-9]{4}\z/', $guest->manual_code);
        $this->assertSame([$before['allowed_entries'], $before['used_entries'], $before['status']], [$guest->allowed_entries, $guest->used_entries, $guest->status]);
        $this->assertSame(str_repeat('a', 40), $qr->qr_token);
        $this->assertFalse($qr->is_active);
        $this->assertCount(1, $renderer->calls);
        $this->assertAutocommit();
    }

    #[DataProvider('pureRenderModes')]
    public function test_direct_renderer_never_persists_a_missing_manual_code(bool $png): void
    {
        $f = $this->fixture();
        DB::table('guests')->where('id', 901)->update(['manual_code' => null]);
        $guest = Guest::query()->with('qrCode')->findOrFail(901);
        $before = $this->snapshot();
        $exception = null;
        try {
            $renderer = new GuestQrImageService;
            $png ? $renderer->png($guest, 'https://example.invalid/verify/'.str_repeat('a', 40)) : $renderer->manualCodeFor($guest);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }
        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame($before, $this->snapshot());
        $this->assertNull(Guest::query()->findOrFail(901)->manual_code);
        $this->assertAutocommit();
    }

    public static function pureRenderModes(): array
    {
        return ['png' => [true], 'manualCodeFor' => [false]];
    }

    public function test_direct_current_qr_association_replaces_a_stale_cached_relation(): void
    {
        $f = $this->fixture();
        $f['guest']->load('qrCode');
        $this->beforeFirstTransaction(function (): void {
            DB::table('qr_codes')->where('id', 501)->delete();
            QrCode::query()->forceCreate(['id' => 509, 'guest_id' => 901, 'qr_token' => str_repeat('n', 64),
                'qr_image_path' => 'qr-codes/new-current.png', 'is_active' => false, 'revoked_at' => now()]);
            $this->disk->put('qr-codes/new-current.png', 'Synthetic new current QR bytes');
        });
        $probe = $this->probe();

        $result = app(LegacyGuestIssuance::class)->generate($f['guest'], $f['actor'], 301);

        $this->assertSame(509, $result['qr']->id);
        $this->assertSame(['qr-codes/new-current.png'], $probe->paths('delete'));
        $this->assertTrue($this->disk->exists('qr-codes/current.png'), 'Cached orphan bytes supply no authority to retire them.');
        $this->assertFalse($this->disk->exists('qr-codes/new-current.png'));
        $this->assertAutocommit();
    }

    #[DataProvider('freshRepairChanges')]
    public function test_direct_repair_recaptures_current_state_in_its_second_composition(string $change): void
    {
        $f = $this->fixture();
        $guest = $change === 'arrived' ? Guest::query()->findOrFail(902) : $f['guest'];
        $this->disk->delete('qr-codes/current.png');
        $starts = [];
        $before = null;
        DB::connection()->beforeStartingTransaction(function () use (&$starts, &$before, $change): void {
            $starts[] = [DB::connection()->transactionLevel(), DB::connection()->getPdo()->inTransaction()];
            if (count($starts) === 2) {
                if ($change === 'moved') {
                    DB::table('guests')->where('id', 901)->update(['event_id' => 302]);
                } else {
                    QrCode::query()->forceCreate(['id' => 502, 'guest_id' => 902, 'qr_token' => str_repeat('n', 64),
                        'qr_image_path' => 'qr-codes/arrived.png', 'is_active' => false, 'revoked_at' => now()]);
                    $this->disk->put('qr-codes/arrived.png', $this->imageBytes('0048'));
                }
                $before = $this->snapshot();
            }
        });
        $probe = $this->probe();
        $refused = false;
        try {
            $qr = app(LegacyGuestIssuance::class)->ensureImage($guest, $f['actor'], 301);
            if ($change === 'arrived') {
                $this->assertSame(str_repeat('n', 64), $qr->qr_token);
                $this->assertFalse($qr->is_active);
            }
        } catch (HttpExceptionInterface $exception) {
            $refused = true;
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertSame($change === 'moved', $refused);
        $this->assertSame([[0, false], [0, false]], $starts, 'No outer transaction encloses the fresh mutation composition.');
        $this->assertNotNull($before);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $probe->effects());
        $this->assertAutocommit();
    }

    public static function freshRepairChanges(): array
    {
        return ['Guest moved after read' => ['moved'], 'QR appeared after read' => ['arrived']];
    }

    public function test_direct_nested_storage_lock_message_restores_savepoint_without_repeating_put(): void
    {
        $f = $this->fixture();
        $db = DB::connection();
        $db->beginTransaction();
        DB::table('events')->where('id', 301)->update(['venue_name' => 'Synthetic caller change']);
        $before = $this->snapshot();
        $probe = $this->probe(function (string $path, string $bytes): bool {
            $this->disk->put($path, $bytes);
            throw new RuntimeException('database is locked p4045_PRIVATE_DIAGNOSTIC nested adapter');
        });
        $exception = null;
        try {
            app(LegacyGuestIssuance::class)->generate($f['guest'], $f['actor'], 301);
        } catch (LegacyGuestIssuanceFailed $caught) {
            $exception = $caught;
        } finally {
            $this->assertSame(1, $db->transactionLevel());
            $this->assertTrue($db->getPdo()->inTransaction());
            $this->assertSame($this->sqlOnly($before), $this->sqlOnly($this->snapshot()));
            $db->rollBack();
        }
        $this->assertInstanceOf(LegacyGuestIssuanceFailed::class, $exception);
        $this->assertNull($exception->getPrevious());
        $this->assertCount(1, $probe->paths('put'));
        $this->assertSame([], $probe->paths('delete'));
        $this->assertTrue($this->disk->exists($probe->paths('put')[0]));
        $this->assertTrue($this->disk->exists('qr-codes/current.png'));
        $this->assertNoPrivateDiagnostics();
        $this->assertAutocommit();
    }

    #[DataProvider('malformedQrValues')]
    public function test_direct_malformed_current_qr_refuses_before_mutation(string $field, mixed $value, bool $authority): void
    {
        $f = $this->fixture();
        $this->disk->put('qr-codes/current.png', 'Synthetic incompatible bytes');
        $before = $this->snapshot();
        QrCode::retrieved(static function (QrCode $qr) use ($field, $value): void {
            if ((int) $qr->getRawOriginal('id') === 501) {
                $qr->setRawAttributes(array_replace($qr->getAttributes(), [$field => $value]), true);
            }
        });
        $probe = $this->probe();
        $exception = null;
        try {
            app(LegacyGuestIssuance::class)->ensureImage($f['guest'], $f['actor'], 301);
        } catch (HttpExceptionInterface|LegacyGuestIssuanceFailed $caught) {
            $exception = $caught;
        }
        if ($authority) {
            $this->assertInstanceOf(HttpExceptionInterface::class, $exception);
            $this->assertSame(404, $exception->getStatusCode());
        } else {
            $this->assertInstanceOf(LegacyGuestIssuanceFailed::class, $exception);
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame([], $probe->effects());
        $this->assertSame($before, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function malformedQrValues(): array
    {
        return ['overflowing QR PK' => ['id', (string) PHP_INT_MAX.'0', true], 'foreign raw parent' => ['guest_id', 903, true],
            'non-string raw path' => ['qr_image_path', ['not a schema string'], false], 'unsupported token' => ['qr_token', 'invalid', false]];
    }

    #[DataProvider('falseyPaths')]
    public function test_direct_absent_old_path_values_do_not_veto_or_retire_unrelated_bytes(?string $path): void
    {
        $f = $this->fixture();
        DB::table('qr_codes')->where('id', 501)->update(['qr_image_path' => $path]);
        DB::table('qr_codes')->where('id', 503)->update(['qr_image_path' => $path]);
        $probe = $this->probe();

        $result = app(LegacyGuestIssuance::class)->generate($f['guest'], $f['actor'], 301);

        $this->assertTrue($result['generated']);
        $this->assertSame([], $probe->paths('delete'));
        $this->assertCount(1, $probe->paths('put'));
        $this->assertTrue($this->disk->exists('qr-codes/current.png'));
        $this->assertTrue($this->disk->exists('retained/neighbor.txt'));
        $this->assertAutocommit();
    }

    public static function falseyPaths(): array
    {
        return ['null' => [null], 'empty' => [''], 'literal zero' => ['0']];
    }

    public function test_direct_renderer_model_and_session_mutation_cannot_redirect_frozen_byte_paths(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $this->renderer(static function (Guest $guest): ?string {
            $guest->qrCode->forceFill(['qr_image_path' => 'retained/neighbor.txt']);
            $guest->setRelation('event', Event::query()->findOrFail(302));
            session()->put('selected_event_id', 302);

            return null;
        });
        $probe = $this->probe();

        app(LegacyGuestIssuance::class)->generate($f['guest'], $f['actor'], 301);

        $path = DB::table('qr_codes')->where('id', 501)->value('qr_image_path');
        $this->assertSame([$path], $probe->paths('put'));
        $this->assertStringContainsString('/synthetic_issuance_301/', $path);
        $this->assertSame(['qr-codes/current.png'], $probe->paths('delete'));
        $this->assertSame($before['_files']['retained/neighbor.txt'], hash('sha256', $this->disk->get('retained/neighbor.txt')));
        $this->assertSame($before['guests'], $this->snapshot()['guests']);
        $this->assertAutocommit();
    }

    public function test_direct_maximum_canonical_ids_remain_exact_for_existing_generation(): void
    {
        $this->fixture();
        $event = Event::factory()->create(['id' => PHP_INT_MAX, 'workspace_id' => null]);
        $actor = User::factory()->create(['id' => PHP_INT_MAX, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $guest = Guest::query()->forceCreate(['id' => PHP_INT_MAX, 'event_id' => $event->id, 'name' => 'Maximum issuance Guest',
            'phone_number' => '+255712345678', 'manual_code' => '0000', 'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1, 'used_entries' => 0, 'status' => Guest::STATUS_ACTIVE]);
        QrCode::query()->forceCreate(['id' => PHP_INT_MAX, 'guest_id' => PHP_INT_MAX, 'qr_token' => str_repeat('m', 64),
            'qr_image_path' => null, 'is_active' => false]);

        $result = app(LegacyGuestIssuance::class)->generate($guest, $actor, (string) PHP_INT_MAX);

        $this->assertSame(PHP_INT_MAX, $result['qr']->id);
        $this->assertSame(PHP_INT_MAX, $result['qr']->guest_id);
        $this->assertSame('0000', Guest::query()->findOrFail(PHP_INT_MAX)->manual_code);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{64}\z/', $result['qr']->qr_token);
        $this->assertAutocommit();
    }

    public function test_http_actual_renderer_query_failure_interrupts_after_prior_completed_row(): void
    {
        $this->fixture();
        $this->preview([$this->row('Earlier completed'), $this->row('Renderer SQL failed', '+255711111112'),
            $this->row('Never reached', '+255711111113')]);
        $renderer = $this->renderer(static function (Guest $guest, int $call): ?string {
            if ($call === 2) {
                DB::select('SELECT * FROM p4045_PRIVATE_DIAGNOSTIC_missing_table');
            }

            return null;
        });

        $response = $this->requestAction('import');

        $response->assertRedirect(route('admin.guests.import'))->assertSessionMissing('success')
            ->assertSessionHas('error', __('guest_issuance.interrupted', [], 'sw'));
        $result = session('guest_import.result');
        $this->assertTrue($result['interrupted']);
        $this->assertSame([1, 0, 1], [$result['summary']['imported_successfully'], $result['summary']['failed_rows'], $result['summary']['generated_qr_codes']]);
        $this->assertDatabaseHas('guests', ['name' => 'Earlier completed', 'event_id' => 301]);
        $this->assertDatabaseMissing('guests', ['name' => 'Renderer SQL failed']);
        $this->assertDatabaseMissing('guests', ['name' => 'Never reached']);
        $this->assertCount(2, $renderer->calls);
        $this->assertFalse(session()->has('guest_import.token'));
        $this->assertNoPrivateDiagnostics();
        $this->assertAutocommit();
    }

    #[DataProvider('duplicateDecisionTiming')]
    public function test_http_current_duplicate_decisions_share_the_guarded_row_transaction(string $mode): void
    {
        $this->fixture();
        $rows = [$this->row('New duplicate candidate')];
        if ($mode === 'same_file') {
            $rows[] = $this->row('Same file duplicate');
        }
        $this->preview($rows);
        if ($mode === 'late') {
            $this->beforeFirstTransaction(static function (): void {
                Guest::query()->create(['event_id' => 301, 'name' => 'Late separate writer', 'phone_number' => '+255711111111',
                    'manual_code' => '7777', 'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1, 'used_entries' => 0, 'status' => Guest::STATUS_ACTIVE]);
            });
        }
        $probe = $this->probe();
        $this->post(route('admin.guests.import.process'), ['import_action' => 'confirm', 'import_token' => session('guest_import.token'),
            'generate_qr' => false])->assertRedirect(route('admin.guests.import'));
        $result = session('guest_import.result');
        $this->assertSame([$mode === 'same_file' ? 1 : 0, 1, 0], [$result['summary']['imported_successfully'],
            $result['summary']['failed_rows'], $result['summary']['generated_qr_codes']]);
        $this->assertStringContainsString('Duplicate conflict', $result['failed'][0]['reason']);
        $this->assertSame(1, Guest::query()->where('event_id', 301)->where('phone_number', '+255711111111')->count());
        $this->assertSame([], $probe->effects());
        $this->assertAutocommit();
    }

    public static function duplicateDecisionTiming(): array
    {
        return ['duplicate after controller preflight' => ['late'], 'duplicate from preceding imported row' => ['same_file']];
    }

    #[DataProvider('repairedFilenameRoutes')]
    public function test_compat_missing_manual_code_uses_the_persisted_code_in_download_and_zip_filenames(string $action): void
    {
        $this->fixture();
        DB::table('guests')->where('id', 901)->update(['manual_code' => null]);
        $this->app->instance(GuestQrImageService::class, new GuestQrImageService);

        $response = $this->requestAction($action);

        $response->assertOk();
        $guest = Guest::query()->with('qrCode')->findOrFail(901);
        $this->assertMatchesRegularExpression('/\A[0-9]{4}\z/', $guest->manual_code);
        $this->assertSame([2, 1, Guest::STATUS_CANCELLED], [$guest->allowed_entries, $guest->used_entries, $guest->status]);
        $base = 'synthetic_issuance_301_issuance_guest_901_901_qr_'.$guest->manual_code;
        if ($action === 'download') {
            $response->assertHeader('Content-Type', 'image/png');
            $this->assertStringContainsString($base.'.png', (string) $response->headers->get('Content-Disposition'));
            $image = $response->getContent();
        } else {
            $response->assertHeader('Content-Type', 'application/zip');
            $entries = $this->zipEntries($response->getContent());
            $this->assertCount(1, $entries);
            $filename = $base.($action === 'zip_all' ? '_2' : '').'.png';
            $this->assertArrayHasKey($filename, $entries, 'The all-ZIP allowance suffix remains2; selected ZIP uses the ordinary filename.');
            $image = $entries[$filename];
        }
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $image);
        $this->assertStringContainsString("Manual admission code\0".$guest->manual_code, $image);
        $this->assertSame($this->disk->get($guest->qrCode->qr_image_path), $image);
        $this->assertAutocommit();
    }

    public static function repairedFilenameRoutes(): array
    {
        return ['single download' => ['download'], 'all ZIP' => ['zip_all'], 'selected ZIP' => ['zip_selected']];
    }

    /** Read the existing uncompressed ZIP local entries, not filename-like bytes inside PNG payloads. */
    private function zipEntries(string $zip): array
    {
        $entries = [];
        $offset = 0;
        while (substr($zip, $offset, 4) === "PK\x03\x04") {
            $header = unpack('vversion/vflags/vcompression/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname/vextra', substr($zip, $offset + 4, 26));
            $this->assertSame(0, $header['compression']);
            $name = substr($zip, $offset + 30, $header['name']);
            $dataOffset = $offset + 30 + $header['name'] + $header['extra'];
            $entries[$name] = substr($zip, $dataOffset, $header['compressed']);
            $offset = $dataOffset + $header['compressed'];
        }

        return $entries;
    }

    #[DataProvider('resumeManualCodeModes')]
    public function test_resume_nonempty_manual_code_with_trailing_newline_is_refused_without_effects(string $mode): void
    {
        $f = $this->fixture();
        DB::table('guests')->where('id', 901)->update(['manual_code' => "0047\n"]);
        $guest = Guest::query()->with('qrCode')->findOrFail(901);
        $before = $this->snapshot();
        $probe = $this->probe();
        $caught = null;
        try {
            match ($mode) {
                'generate' => app(LegacyGuestIssuance::class)->generate($guest, $f['actor'], 301),
                'repair' => app(LegacyGuestIssuance::class)->ensureImage($guest, $f['actor'], 301),
                'png' => (new GuestQrImageService)->png($guest, 'https://example.invalid/verify/'.str_repeat('a', 40)),
                'manual' => (new GuestQrImageService)->manualCodeFor($guest),
            };
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }
        $this->assertInstanceOf(in_array($mode, ['generate', 'repair'], true) ? LegacyGuestIssuanceFailed::class : RuntimeException::class, $caught);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $probe->effects());
        $this->assertAutocommit();
    }

    public static function resumeManualCodeModes(): array
    {
        return ['explicit' => ['generate'], 'repair' => ['repair'], 'pure PNG' => ['png'], 'pure manual text' => ['manual']];
    }

    #[DataProvider('resumeLegacyStateModes')]
    public function test_resume_missing_manual_code_refuses_hook_changes_to_existing_admission_state(string $mode, array $state): void
    {
        $f = $this->fixture();
        DB::table('guests')->where('id', 901)->update(['manual_code' => null, ...$state]);
        $before = $this->snapshot();
        $probe = $this->probe();
        $renderer = $this->renderer();
        $caught = null;
        try {
            $mode === 'generate'
                ? app(LegacyGuestIssuance::class)->generate($f['guest'], $f['actor'], 301)
                : app(LegacyGuestIssuance::class)->ensureImage($f['guest'], $f['actor'], 301);
        } catch (LegacyGuestIssuanceFailed $exception) {
            $caught = $exception;
        }
        $this->assertInstanceOf(LegacyGuestIssuanceFailed::class, $caught);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $probe->effects());
        $this->assertCount(0, $renderer->calls);
        $this->assertAutocommit();
    }

    public static function resumeLegacyStateModes(): array
    {
        $cases = [];
        foreach (['generate', 'repair'] as $mode) {
            foreach (['capacity' => ['allowed_entries' => 5], 'usage' => ['used_entries' => 3], 'status' => ['status' => Guest::STATUS_ACTIVE]] as $name => $state) {
                $cases[$mode.' '.$name] = [$mode, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('resumeInterruptedPages')]
    public function test_resume_interrupted_import_page_shows_only_observed_rows_without_completion_totals(string $language, int $failureAt): void
    {
        $f = $this->fixture();
        DB::table('users')->where('id', 211)->update(['preferred_language' => $language]);
        $f['actor']->preferred_language = $language;
        $this->preview([$this->row('First observed row'), $this->row('Uncertain second row', '+255711111112'), $this->row('Never dispatched row', '+255711111113')]);
        $commits = 0;
        Events::listen(TransactionCommitted::class, static function () use (&$commits, $failureAt): void {
            if (DB::connection()->transactionLevel() === 0 && ++$commits === $failureAt) {
                throw new RuntimeException('p4045_PRIVATE_DIAGNOSTIC uncertain acknowledgement');
            }
        });

        $response = $this->requestAction('import');
        $response->assertRedirect(route('admin.guests.import'))->assertSessionMissing('success');
        $result = session('guest_import.result');
        $this->assertTrue($result['interrupted']);
        $this->assertCount($failureAt - 1, $result['imported']);
        $this->assertSame($failureAt, Guest::query()->whereIn('name', ['First observed row', 'Uncertain second row'])->count());
        $this->assertDatabaseMissing('guests', ['name' => 'Never dispatched row']);
        $page = $this->followRedirects($response);
        $catalog = $language === 'en' ? 'en' : 'sw';
        $page->assertOk()->assertSee(__('guest_issuance.import_interrupted', [], $catalog))
            ->assertSee(__('guest_issuance.partial_results', [], $catalog))
            ->assertDontSee('Import completed')->assertDontSee('Total rows')
            ->assertDontSee('No new contacts were imported.')->assertDontSee('No failed contacts.')
            ->assertDontSee('Uncertain second row')->assertDontSee('Never dispatched row')
            ->assertDontSee('p4045_PRIVATE_DIAGNOSTIC');
        if ($failureAt === 2) {
            $page->assertSee('First observed row')->assertSee($result['imported'][0]['manual_code']);
        } else {
            $page->assertDontSee('First observed row')->assertDontSee('Successfully imported contacts');
        }
        $this->assertFalse(session()->has('guest_import.token'));
        $this->assertNoPrivateDiagnostics();
        $this->assertAutocommit();
    }

    public static function resumeInterruptedPages(): array
    {
        $cases = [];
        foreach (['sw', 'en', 'zz'] as $language) {
            foreach ([1, 2] as $failureAt) {
                $cases[$language.' interruption '.$failureAt] = [$language, $failureAt];
            }
        }

        return $cases;
    }

    public function test_resume_completed_import_page_keeps_normal_summary_and_empty_sections(): void
    {
        $this->fixture();
        $this->preview([$this->row('Completed visible row')]);
        $response = $this->requestAction('import');
        $response->assertRedirect(route('admin.guests.import'))->assertSessionHas('success')->assertSessionMissing('error');
        $this->followRedirects($response)->assertOk()->assertSee('Import completed')->assertSee('Total rows')
            ->assertSee('Completed visible row')->assertSee('No failed contacts.')
            ->assertDontSee(__('guest_issuance.import_interrupted', [], 'sw'));
        $this->assertAutocommit();
    }

    private function fixture(): array
    {
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic issuance workspace']);
        foreach ([301 => null, 302 => null, 303 => 101] as $id => $workspace) {
            Event::factory()->create(['id' => $id, 'workspace_id' => $workspace, 'event_name' => 'Synthetic issuance '.$id]);
        }
        $actor = User::factory()->create(['id' => 211, 'role' => User::ROLE_ADMIN, 'is_active' => true, 'preferred_language' => 'sw']);
        foreach ([901 => 301, 902 => 301, 903 => 302, 904 => 303] as $id => $event) {
            $guest = Guest::query()->forceCreate(['id' => $id, 'event_id' => $event, 'name' => 'Issuance Guest '.$id,
                'phone_number' => $id === 902 ? '+255712345679' : '+255712345678', 'manual_code' => ['901' => '0047', '902' => '0048', '903' => '0053', '904' => '0054'][$id],
                'pass_type' => Guest::PASS_DOUBLE, 'allowed_entries' => 2, 'used_entries' => $id === 902 ? 0 : 1,
                'status' => $id === 901 ? Guest::STATUS_CANCELLED : Guest::STATUS_ACTIVE]);
            if ($id !== 902) {
                $path = ['901' => 'qr-codes/current.png', '903' => 'qr-codes/foreign.png', '904' => 'qr-codes/owned.png'][$id];
                QrCode::query()->forceCreate(['id' => $id - 400, 'guest_id' => $id,
                    'qr_token' => str_repeat(['901' => 'a', '903' => 'b', '904' => 'c'][$id], ['901' => 40, '903' => 64, '904' => 128][$id]),
                    'qr_image_path' => $path, 'is_active' => $id !== 901, 'generated_at' => now()->subDay(),
                    'revoked_at' => $id === 901 ? now()->subHour() : null]);
                $this->disk->put($path, $this->imageBytes($guest->manual_code));
            }
            Admission::query()->create(['id' => $id, 'event_id' => $event, 'guest_id' => $id, 'quantity' => 1,
                'admitted_by' => 'Synthetic retained scanner', 'admitted_at' => now()->subHour()]);
        }
        $this->disk->put('retained/neighbor.txt', 'Synthetic unrelated retained bytes');
        $this->renderer();
        $this->actingAs($actor)->withSession(['selected_event_id' => 301]);

        return ['actor' => $actor, 'guest' => Guest::query()->findOrFail(901)];
    }

    private function payload(string $name = 'New created Guest', string $phone = '+255711111111'): array
    {
        return ['name' => $name, 'phone_number' => $phone, 'pass_type' => Guest::PASS_DOUBLE, 'allowed_entries' => 2];
    }

    private function row(string $name, string $phone = '+255711111111'): array
    {
        return $this->payload($name, $phone);
    }

    private function preview(array $rows): void
    {
        $parsed = [];
        foreach ($rows as $index => $row) {
            $parsed[] = ['row_number' => $index + 2, 'data' => $row];
        }
        $reader = new class($parsed) extends GuestImportExcelReader
        {
            public function __construct(private array $rows) {}

            public function read(string $path): array
            {
                return [$this->rows, []];
            }
        };
        $this->app->instance(GuestImportExcelReader::class, $reader);
        $this->post(route('admin.guests.import.process'), ['excel_file' => UploadedFile::fake()->create('synthetic.xlsx', 1,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'), 'generate_qr' => true])->assertOk();
        $this->assertIsString(session('guest_import.token'));
    }

    private function requestAction(string $action): TestResponse
    {
        $this->from(route('admin.guests.index'));

        return match ($action) {
            'create' => $this->post(route('admin.guests.store'), $this->payload()),
            'generate' => $this->post(route('admin.guests.qr.generate', 901)),
            'import' => $this->post(route('admin.guests.import.process'), ['import_action' => 'confirm',
                'import_token' => session('guest_import.token'), 'generate_qr' => true]),
            'image' => $this->get(route('admin.guests.qr', 901)),
            'download' => $this->get(route('admin.guests.qr.download', 901)),
            'zip_all' => $this->get(route('admin.qr-codes.download-all')),
            'zip_selected' => $this->post(route('admin.qr-codes.download-selected'), ['guest_ids' => [901]]),
        };
    }

    private function assertFailure(TestResponse $response, string $language = 'sw', bool $interrupted = false): void
    {
        $key = $interrupted ? 'guest_issuance.interrupted' : 'guest_issuance.failure';
        $message = __($key, [], $language);
        $response->assertRedirect()->assertSessionMissing('success')->assertSessionHas('error', $message);
        $this->assertNotSame($key, $message, 'The new feedback must resolve through the requested Laravel catalog.');
    }

    private function renderer(?Closure $hook = null): GuestIssuanceTestRenderer
    {
        $renderer = new GuestIssuanceTestRenderer($hook);
        $this->app->instance(GuestQrImageService::class, $renderer);

        return $renderer;
    }

    private function probe(?Closure $put = null, ?Closure $delete = null): GuestIssuanceInspectingDisk
    {
        $probe = new GuestIssuanceInspectingDisk($this->disk, $put, $delete);
        Storage::shouldReceive('disk')->with('guest_qr')->andReturnUsing(static function () use ($probe): GuestIssuanceInspectingDisk {
            $probe->resolutions++;

            return $probe;
        });

        return $probe;
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

    private function offline(): void
    {
        OfflineScannerSession::factory()->create(['event_id' => 301, 'created_by_user_id' => 211, 'status' => OfflineScannerSession::STATUS_ACTIVE]);
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $contents = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row): array => (array) $row)->all();
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

    private function sqlOnly(array $snapshot): array
    {
        unset($snapshot['_files']);

        return $snapshot;
    }

    private function assertAutocommit(): void
    {
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function assertNoPrivateDiagnostics(): void
    {
        $this->assertStringNotContainsString('p4045_PRIVATE_DIAGNOSTIC', json_encode(session()->all()));
        $logs = storage_path('logs');
        if (is_dir($logs)) {
            foreach ((new Filesystem)->allFiles($logs) as $file) {
                $this->assertStringNotContainsString('p4045_PRIVATE_DIAGNOSTIC', file_get_contents($file->getPathname()));
            }
        }
    }

    private function computedPath(Guest $guest, string $token): string
    {
        return 'qr-codes/'.Event::query()->findOrFail($guest->event_id)->safeSlug().'/guest-'.$guest->id.'-'.$token.'.png';
    }

    private function imageBytes(string $manual): string
    {
        return "Synthetic PNG bytes\0Manual admission code\0".$manual;
    }

    private function grammar(): GuestIssuanceInspectingGrammar
    {
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $grammar = new GuestIssuanceInspectingGrammar($db);
        $db->setQueryGrammar($grammar);
        $this->beforeApplicationDestroyed(static fn () => $db->setQueryGrammar($original));

        return $grammar;
    }
}

final class GuestIssuanceTestRenderer extends GuestQrImageService
{
    public array $calls = [];

    public function __construct(private ?Closure $hook = null) {}

    public function png(Guest $guest, string $payload): string
    {
        $this->calls[] = ['id' => $guest->id, 'event_id' => $guest->event_id, 'manual_code' => $guest->manual_code, 'payload' => $payload];
        $override = $this->hook !== null ? ($this->hook)($guest, count($this->calls), $payload) : null;

        return $override ?? "Synthetic PNG bytes\0Manual admission code\0".(string) $guest->manual_code;
    }
}

/**
 * Trusted local observation wrapper on the guarded TEMP private QR disk: built from
 * that disk's own driver/adapter/config, so the production GuestQrStorage policy
 * validates the same root. Byte effects and adapter resolution are separate
 * observations; inherited root/config inspection is not recorded.
 */
final class GuestIssuanceInspectingDisk extends LocalFilesystemAdapter
{
    public int $resolutions = 0;

    public array $calls = [];

    public function __construct(private FilesystemAdapter $delegate, private ?Closure $put = null, private ?Closure $delete = null)
    {
        parent::__construct($delegate->getDriver(), $delegate->getAdapter(), $delegate->getConfig());
    }

    public function put($path, $contents, $options = []): mixed
    {
        $this->calls[] = ['put', $path];

        return $this->put !== null ? ($this->put)($path, $contents) : $this->delegate->put($path, $contents, $options);
    }

    public function delete($path): mixed
    {
        $this->calls[] = ['delete', $path];

        return $this->delete !== null ? ($this->delete)($path) : $this->delegate->delete($path);
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

    public function paths(string $method): array
    {
        return array_column(array_filter($this->calls, static fn (array $call): bool => $call[0] === $method), 1);
    }

    public function effects(): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => in_array($call[0], ['put', 'delete'], true)));
    }
}

/** SQLite discards FOR UPDATE; the grammar reports builder intent, never actual lock-wait proof. */
final class GuestIssuanceInspectingGrammar extends SQLiteGrammar
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
