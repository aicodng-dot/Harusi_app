<?php

namespace Tests\Feature;

use App\Exceptions\LegacyQrMediaUnavailable;
use App\Http\Controllers\AdminController;
use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use App\Services\GuestQrStorage;
use App\Services\Tenancy\LegacyQrMediaDelivery;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\PathNormalizer;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Tests\TestCase;
use ZipArchive;

/**
 * P4-047 private ordinary-QR storage and media boundary regressions: fixed
 * private disk policy (configuration/root/handle/exact keys), buffered no-store
 * PNG/ZIP delivery with complete-set and shared-path refusal, no public
 * fallback, coupled issuance/deletion storage and the static-prefix denial.
 * The first 15 cases were observed on the pre-slice-2 source (11 failing gaps,
 * 4 passing controls); the policy/coupling cases below them were added later.
 */
class LegacyQrMediaBoundaryTest extends TestCase
{
    private FilesystemAdapter $public;

    private string $privateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new LogicException('Media boundary fixtures require isolated in-memory SQLite.');
        }
        $pdo = $db->getPdo();
        $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool => $row['name'] === 'main'));
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || count($main) !== 1 || $main[0]['file'] !== ''
            || $db->transactionLevel() !== 0 || $pdo->inTransaction() || (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new LogicException('Media boundary fixtures require memory main, enabled FKs and actual autocommit0.');
        }

        $temporaryRoot = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'harusi-media-boundary-'.bin2hex(random_bytes(8));
        (new Filesystem)->makeDirectory($temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views', 0777, true);
        $this->privateRoot = $temporaryRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'guest-qr-private';
        (new Filesystem)->makeDirectory($this->privateRoot, 0777, true);
        $this->app->useStoragePath($temporaryRoot);
        config(['view.compiled' => $temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            'logging.channels.single.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log',
            'mail.default' => 'array', 'cache.default' => 'array', 'session.driver' => 'array',
            'queue.default' => 'sync', 'services.whatsapp.provider' => 'null',
            // The designed fixed private disk. The original source ignores it.
            'filesystems.disks.guest_qr' => ['driver' => 'local', 'root' => $this->privateRoot,
                'visibility' => 'private', 'throw' => true, 'report' => false]]);
        $this->beforeApplicationDestroyed(static function () use ($db, $temporaryRoot): void {
            $db->disconnect();
            $resolved = realpath($temporaryRoot);
            $prefix = rtrim((string) realpath(sys_get_temp_dir()), '/\\').DIRECTORY_SEPARATOR.'harusi-media-boundary-';
            if ($resolved !== false && str_starts_with(strtolower($resolved), strtolower($prefix))) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        });
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
        $this->freezeTime();
        $this->withoutVite();
        $this->public = Storage::fake('public');
        $fakeRoot = realpath($this->public->path(''));
        if ($fakeRoot === false || ! str_starts_with(strtolower($fakeRoot), strtolower($temporaryRoot.DIRECTORY_SEPARATOR))) {
            throw new LogicException('The public fake must stay inside guarded TEMP storage.');
        }
    }

    public function test_png_preview_is_private_no_store_nosniff_and_inline(): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'preview'));

        $response = $this->get(route('admin.guests.qr', 901));
        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline; filename="', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_delivery_share_locks_its_rows_and_leaves_the_global_path_read_unlocked(): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'locks'));
        $reads = [];
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $grammar = new MediaLockInspectingGrammar($db);
        $grammar->reads = &$reads;
        $db->setQueryGrammar($grammar);
        $this->beforeApplicationDestroyed(static fn () => $db->setQueryGrammar($original));

        (new ReflectionMethod(LegacyQrMediaDelivery::class, 'deliver'))->invoke(app(LegacyQrMediaDelivery::class), [901], 301, 211);
        $db->setQueryGrammar($original);

        $this->assertSame([['events', false, null], ['users', false, null], ['guests', false, 'guests_event_id_index'], ['qr_codes', false, 'qr_codes_guest_id_unique'], ['qr_codes', null, null]], $reads,
            'Shared Event, actor, Guest and QR locks with named indexes; the unindexed path read must never lock every Event\'s QR rows.');
    }

    public function test_png_download_and_zips_are_no_store_and_nosniff(): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'download'));

        foreach ([$this->get(route('admin.guests.qr.download', 901)),
            $this->get(route('admin.qr-codes.download-all')),
            $this->post(route('admin.qr-codes.download-selected'), ['guest_ids' => [901]])] as $response) {
            $response->assertOk();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }
    }

    public function test_selected_zip_disposition_filename_is_quoted(): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'selected'));

        $response = $this->post(route('admin.qr-codes.download-selected'), ['guest_ids' => [901]]);
        $response->assertOk();
        $this->assertMatchesRegularExpression('/^attachment; filename="[^"]+-selected-qr-codes-[0-9-]+\.zip"$/',
            (string) $response->headers->get('Content-Disposition'));
    }

    public function test_new_qr_bytes_are_written_to_the_private_root_not_the_public_disk(): void
    {
        $this->fixture();

        $this->post(route('admin.guests.qr.generate', 903))->assertRedirect();
        $path = (string) QrCode::query()->where('guest_id', 903)->value('qr_image_path');

        $this->assertNotSame('', $path);
        $this->assertFalse($this->public->exists($path), 'Ordinary QR bytes must not be written to the public disk.');
        $this->assertFileExists($this->privateRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
    }

    public function test_a_public_only_copy_is_never_served(): void
    {
        $this->fixture();
        $marker = $this->bytes('0047', 'public-only-copy');
        $this->public->put($this->path(901), $marker);

        $response = $this->get(route('admin.guests.qr.download', 901));
        $this->assertStringNotContainsString('public-only-copy', (string) $response->getContent());
    }

    public function test_missing_private_root_refuses_without_public_fallback(): void
    {
        $this->fixture();
        $marker = $this->bytes('0047', 'fallback-copy');
        $this->public->put($this->path(901), $marker);
        config(['filesystems.disks.guest_qr.root' => $this->privateRoot.DIRECTORY_SEPARATOR.'absent-root']);

        $response = $this->get(route('admin.guests.qr.download', 901));
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('fallback-copy', (string) $response->getContent());
    }

    public function test_a_path_shared_with_another_qr_is_refused_before_delivery(): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'shared-bytes'));
        $this->storeBoth(903, $this->bytes('0048', 'other-guest-bytes'));
        $this->assertSame(1, QrCode::query()->where('guest_id', 903)->update(['qr_image_path' => $this->path(901)]));

        foreach ([$this->get(route('admin.guests.qr.download', 901)),
            $this->post(route('admin.qr-codes.download-selected'), ['guest_ids' => [901]])] as $response) {
            $this->assertNotSame(200, $response->getStatusCode());
            $this->assertStringNotContainsString('shared-bytes', (string) $response->getContent());
        }
    }

    #[DataProvider('aliasKeys')]
    public function test_unsafe_alias_key_is_a_fixed_failure_not_a_normalized_read(string $key): void
    {
        $this->fixture();
        $canonical = 'qr-codes/alias/guest-901.png';
        $this->public->put($canonical, $this->bytes('0047', 'aliased-bytes'));
        (new Filesystem)->ensureDirectoryExists($this->privateRoot.DIRECTORY_SEPARATOR.'qr-codes'.DIRECTORY_SEPARATOR.'alias');
        file_put_contents($this->privateRoot.DIRECTORY_SEPARATOR.'qr-codes'.DIRECTORY_SEPARATOR.'alias'.DIRECTORY_SEPARATOR.'guest-901.png',
            $this->bytes('0047', 'aliased-bytes'));
        QrCode::query()->where('guest_id', 901)->update(['qr_image_path' => $key]);

        $response = $this->get(route('admin.guests.qr.download', 901));
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('aliased-bytes', (string) $response->getContent());
        $this->assertSame($key, QrCode::query()->where('guest_id', 901)->value('qr_image_path'), 'A refused key is not repaired/rewritten.');
    }

    public static function aliasKeys(): array
    {
        return ['backslash separators' => ['qr-codes\\alias\\guest-901.png'],
            'leading slash' => ['/qr-codes/alias/guest-901.png'],
            'dot segment' => ['qr-codes/./alias/guest-901.png']];
    }

    public function test_static_public_qr_prefix_is_denied_before_the_file_bypass(): void
    {
        $htaccess = (string) file_get_contents(base_path('public/.htaccess'));
        $denial = strpos($htaccess, 'storage/qr-codes');
        $bypass = strpos($htaccess, '!-f');

        $this->assertNotFalse($denial, 'A source denial for the old canonical QR static prefix is required.');
        $this->assertNotFalse($bypass);
        $this->assertLessThan($bypass, $denial, 'The denial must precede the existing-file bypass.');
    }

    public function test_png_preview_and_download_serve_exact_stored_bytes_and_names(): void
    {
        $this->fixture();
        $bytes = $this->bytes('0047', 'exact');
        $this->storeBoth(901, $bytes);

        $this->get(route('admin.guests.qr', 901))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($bytes, $this->get(route('admin.guests.qr', 901))->getContent());
        $download = $this->get(route('admin.guests.qr.download', 901));
        $download->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($bytes, $download->getContent());
        $this->assertSame('attachment; filename="synthetic_media_event_301_selected_media_guest_901_qr_0047.png"',
            $download->headers->get('Content-Disposition'));
    }

    public function test_range_requests_receive_the_full_body(): void
    {
        $this->fixture();
        $bytes = $this->bytes('0047', 'range');
        $this->storeBoth(901, $bytes);

        $response = $this->get(route('admin.guests.qr.download', 901), ['Range' => 'bytes=0-7']);
        $response->assertOk();
        $this->assertSame($bytes, $response->getContent());
        $this->assertNull($response->headers->get('Content-Range'));
    }

    public function test_zip_members_names_and_bytes_are_preserved(): void
    {
        $this->fixture();
        $first = $this->bytes('0047', 'zip-first');
        $second = $this->bytes('0048', 'zip-second');
        $this->storeBoth(901, $first);
        $this->storeBoth(903, $second);

        $all = $this->zipEntries($this->get(route('admin.qr-codes.download-all'))->assertOk()->getContent());
        $this->assertSame(['synthetic_media_event_301_selected_media_guest_901_qr_0047_2.png' => $first,
            'synthetic_media_event_301_selected_second_guest_903_qr_0048_1.png' => $second], $all);
        $selected = $this->zipEntries($this->post(route('admin.qr-codes.download-selected'), ['guest_ids' => [903]])->assertOk()->getContent());
        $this->assertSame(['synthetic_media_event_301_selected_second_guest_903_qr_0048.png' => $second], $selected);
    }

    public function test_foreign_guest_media_is_hidden(): void
    {
        $this->fixture();
        $this->storeBoth(902, $this->bytes('0053', 'foreign'));

        $this->get(route('admin.guests.qr', 902))->assertNotFound();
        $this->get(route('admin.guests.qr.download', 902))->assertNotFound();
        $response = $this->post(route('admin.qr-codes.download-selected'), ['guest_ids' => [902]]);
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('foreign', (string) $response->getContent());
    }

    #[DataProvider('unsafeDiskConfigurations')]
    public function test_unsafe_private_disk_configuration_refuses_without_bytes(string $case): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'configuration-guarded'));
        $servedTarget = dirname($this->privateRoot).DIRECTORY_SEPARATOR.'served-target';
        (new Filesystem)->ensureDirectoryExists($servedTarget.DIRECTORY_SEPARATOR.'qr');
        $changes = match ($case) {
            'url' => ['url' => 'https://example.invalid/qr'],
            'serve' => ['serve' => true],
            'prefix' => ['prefix' => 'qr'],
            'not throwing' => ['throw' => false],
            'reporting' => ['report' => true],
            'public visibility' => ['visibility' => 'public'],
            'relative root' => ['root' => 'storage/app/guest-qr-private'],
            'non-local driver' => ['driver' => 'ftp'],
            'inside a linked public target' => ['root' => $servedTarget.DIRECTORY_SEPARATOR.'qr'],
        };
        if ($case === 'inside a linked public target') {
            config(['filesystems.links' => [dirname($this->privateRoot).DIRECTORY_SEPARATOR.'public-link' => $servedTarget]]);
        }
        config(['filesystems.disks.guest_qr' => array_merge(config('filesystems.disks.guest_qr'), $changes)]);
        Storage::forgetDisk('guest_qr');

        $response = $this->get(route('admin.guests.qr.download', 901));
        // The guarded repair probe resolves storage first, so its fixed issuance feedback applies.
        $response->assertRedirect()->assertSessionHas('error', __('guest_issuance.failure', [], 'en'));
        $this->assertStringNotContainsString('configuration-guarded', (string) $response->getContent());
        $this->assertSame([], (new Filesystem)->allFiles($servedTarget), 'Nothing is written through a refused disk.');
    }

    public static function unsafeDiskConfigurations(): array
    {
        return array_combine($cases = ['url', 'serve', 'prefix', 'not throwing', 'reporting', 'public visibility',
            'relative root', 'non-local driver', 'inside a linked public target'], array_map(static fn (string $case): array => [$case], $cases));
    }

    public function test_a_cached_handle_for_a_different_root_is_refused(): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'stale-handle'));
        Storage::disk('guest_qr');
        $other = $this->privateRoot.'-other';
        (new Filesystem)->ensureDirectoryExists($other);
        config(['filesystems.disks.guest_qr.root' => $other]);

        $response = $this->get(route('admin.guests.qr.download', 901));
        $response->assertRedirect()->assertSessionHas('error', __('guest_issuance.failure', [], 'en'));
        $this->assertStringNotContainsString('stale-handle', (string) $response->getContent());
        $this->assertSame([], (new Filesystem)->allFiles($other));
    }

    #[DataProvider('feedbackLanguages')]
    public function test_an_empty_delivery_read_refuses_with_localized_feedback(?string $preference, string $language): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'empty-delivery'));
        DB::table('users')->where('id', 211)->update(['preferred_language' => $preference ?? 'fr']);
        $spy = new MediaDeliveryReadFaultDisk(Storage::disk('guest_qr'));
        // Registered, not mocked: the public disk stays reachable, so a public fallback would be observable.
        Storage::set('guest_qr', $spy);

        $response = $this->from(route('admin.qr-codes.index'))->get(route('admin.guests.qr.download', 901));
        $response->assertRedirect(route('admin.qr-codes.index'))
            ->assertSessionHas('error', __('guest_qr_media.failure', [], $language));
        $this->assertSame(2, $spy->reads, 'The compatible probe read real bytes; the delivery read was refused.');
    }

    public static function feedbackLanguages(): array
    {
        return ['english preference' => ['en', 'en'], 'unsupported preference falls back to kiswahili' => [null, 'sw']];
    }

    public function test_guest_deletion_removes_only_the_private_bytes(): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'delete-target'));

        $this->delete(route('admin.guests.destroy', 901))->assertRedirect();

        $this->assertNull(Guest::query()->find(901));
        $this->assertFileDoesNotExist($this->privateRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->path(901)));
        $this->assertTrue($this->public->exists($this->path(901)), 'An old public copy is not a deletion target.');
    }

    public function test_guest_deletion_refuses_an_unsafe_stored_key_before_any_effect(): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'unsafe-delete'));
        QrCode::query()->where('guest_id', 901)->update(['qr_image_path' => 'qr-codes/../'.$this->path(901)]);

        $this->delete(route('admin.guests.destroy', 901))->assertRedirect()
            ->assertSessionHas('error', __('guest_deletion.failure', [], 'en'));

        $this->assertNotNull(Guest::query()->find(901));
        $this->assertFileExists($this->privateRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->path(901)));
    }

    public function test_explicit_generation_refuses_an_unsafe_old_key_without_writing(): void
    {
        $this->fixture();
        QrCode::query()->where('guest_id', 901)->update(['qr_image_path' => 'qr-codes\\legacy\\guest-901.png']);
        $token = QrCode::query()->where('guest_id', 901)->value('qr_token');

        $this->post(route('admin.guests.qr.generate', 901))->assertRedirect()
            ->assertSessionHas('error', __('guest_issuance.failure', [], 'en'));

        $this->assertSame($token, QrCode::query()->where('guest_id', 901)->value('qr_token'));
        $this->assertSame([], (new Filesystem)->allFiles($this->privateRoot));
    }

    public function test_an_event_without_qr_images_returns_an_empty_private_archive(): void
    {
        $this->fixture();
        QrCode::query()->where('guest_id', 901)->delete();

        $response = $this->get(route('admin.qr-codes.download-all'));
        $response->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame("PK\x05\x06".str_repeat("\0", 18), $response->getContent());
    }

    #[DataProvider('postRepairChanges')]
    public function test_the_locked_delivery_rechecks_state_changed_after_every_repair(string $change, string $endpoint, ?int $status): void
    {
        $this->fixture();
        $bytes = $this->bytes('0047', 'post-repair-guarded');
        $this->storeBoth(901, $bytes);
        $this->storeBoth(903, $this->bytes('0048', 'post-repair-neighbor'));
        $hook = $this->beforeDeliveryTransaction(fn (): int => match ($change) {
            // A foreign Event's QR now names the delivery key: the veto has no Event filter.
            'shared path' => DB::table('qr_codes')->where('guest_id', 902)->update(['qr_image_path' => $this->path(901)]),
            'moved member' => DB::table('guests')->where('id', 901)->update(['event_id' => 302]),
            'deleted qr' => DB::table('qr_codes')->where('guest_id', 901)->delete(),
            'unsafe key' => DB::table('qr_codes')->where('guest_id', 901)->update(['qr_image_path' => str_replace('/', '\\', $this->path(901))]),
            'demoted actor' => DB::table('users')->where('id', 211)->update(['role' => User::ROLE_SCANNER]),
        });

        $this->from(route('admin.qr-codes.index'));
        $response = match ($endpoint) {
            'download' => $this->get(route('admin.guests.qr.download', 901)),
            'selected zip' => $this->post(route('admin.qr-codes.download-selected'), ['guest_ids' => [901, 903]]),
            'all zip' => $this->get(route('admin.qr-codes.download-all')),
        };

        $this->assertSame(1, $hook->fired, 'The change ran once, as the locked delivery transaction began after every repair.');
        $this->assertSame(1, $hook->affected);
        if ($status !== null) {
            $response->assertStatus($status);
        } else {
            $response->assertRedirect(route('admin.qr-codes.index'))->assertSessionHas('error', __('guest_qr_media.failure', [], 'en'));
        }
        $this->assertStringNotContainsString('post-repair', (string) $response->getContent());
        $this->assertSame($bytes, file_get_contents($this->privateRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->path(901))));
        $this->assertCount(2, (new Filesystem)->allFiles($this->privateRoot), 'Delivery writes, repairs or removes nothing.');
    }

    public static function postRepairChanges(): array
    {
        $cases = [];
        foreach (['shared path' => null, 'moved member' => 404, 'deleted qr' => null, 'unsafe key' => null, 'demoted actor' => 403] as $change => $status) {
            foreach (['download', 'selected zip', 'all zip'] as $endpoint) {
                $cases[$change.' / '.$endpoint] = [$change, $endpoint, $status];
            }
        }

        return $cases;
    }

    public function test_delivery_reads_the_private_root_even_when_a_public_copy_differs(): void
    {
        $this->fixture();
        $private = $this->bytes('0047', 'private-original');
        $this->storeBoth(901, $private);
        $this->public->put($this->path(901), $this->bytes('0047', 'public-divergent'));

        $this->assertSame($private, $this->get(route('admin.guests.qr', 901))->assertOk()->getContent());
        $this->assertSame($private, $this->get(route('admin.guests.qr.download', 901))->assertOk()->getContent());
        $this->assertSame(['synthetic_media_event_301_selected_media_guest_901_qr_0047_2.png' => $private],
            $this->zipEntries($this->get(route('admin.qr-codes.download-all'))->assertOk()->getContent()));
        $this->assertSame(['synthetic_media_event_301_selected_media_guest_901_qr_0047.png' => $private],
            $this->zipEntries($this->post(route('admin.qr-codes.download-selected'), ['guest_ids' => [901]])->assertOk()->getContent()));
    }

    #[DataProvider('deliveryReadFaults')]
    public function test_a_null_or_failing_delivery_read_refuses_without_bytes(string $fault, string $endpoint): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'faulted-delivery'));
        $spy = new MediaDeliveryReadFaultDisk(Storage::disk('guest_qr'), $fault);
        // Registered, not mocked: the public disk stays reachable, so a public fallback would be observable.
        Storage::set('guest_qr', $spy);

        $this->from(route('admin.qr-codes.index'));
        $response = $endpoint === 'download'
            ? $this->get(route('admin.guests.qr.download', 901))
            : $this->post(route('admin.qr-codes.download-selected'), ['guest_ids' => [901]]);

        $response->assertRedirect(route('admin.qr-codes.index'))->assertSessionHas('error', __('guest_qr_media.failure', [], 'en'));
        $this->assertSame(2, $spy->reads, 'The compatible probe read real bytes; the delivery read faulted.');
        $this->assertStringNotContainsString('faulted-delivery', (string) $response->getContent());
    }

    public static function deliveryReadFaults(): array
    {
        return ['null read / download' => ['null', 'download'], 'null read / selected zip' => ['null', 'selected zip'],
            'throwing read / download' => ['throw', 'download'], 'throwing read / selected zip' => ['throw', 'selected zip']];
    }

    public function test_the_exact_key_policy_accepts_ordinary_and_historical_keys(): void
    {
        foreach (['qr-codes/synthetic-media-event-301/guest-901-synthetic.png', 'qr-codes/con_event/guest-1-abc.png',
            'legacy/guest.png', 'guest.png', 'qr-codes/event/console.png', 'qr-codes/com10/guest.png',
            'qr-codes/auxiliary/guest.png', 'qr-codes/nul-guest.png', 'qr-codes/guest~a.png', str_repeat('a', 251).'.png'] as $key) {
            $this->assertTrue(GuestQrStorage::isSafeKey($key), 'Accepted: '.$key);
        }
    }

    public function test_the_exact_key_policy_refuses_absolute_alias_and_device_forms(): void
    {
        foreach (['empty' => '', 'over 255 bytes' => str_repeat('a', 252).'.png', 'NUL byte' => "qr-codes/guest\0.png",
            'control byte' => "qr-codes/guest\x1F.png", 'DEL byte' => "qr-codes/guest\x7F.png", 'backslash' => 'qr-codes\\guest.png',
            'drive' => 'C:/qr-codes/guest.png', 'drive-relative' => 'c:guest.png', 'URL' => 'https://example.invalid/guest.png',
            'stream wrapper' => 'file:///tmp/guest.png', 'absolute' => '/qr-codes/guest.png', 'UNC' => '//server/share/guest.png',
            'empty middle segment' => 'qr-codes//guest.png', 'trailing separator' => 'qr-codes/guest.png/',
            'dot segment' => 'qr-codes/./guest.png', 'parent segment' => 'qr-codes/../guest.png', 'leading parent' => '../guest.png',
            'trailing dot' => 'qr-codes/guest.png.', 'trailing space' => 'qr-codes/guest.png ', 'segment trailing space' => 'qr-codes /guest.png',
            'device segment' => 'qr-codes/con/guest.png', 'device with extensions' => 'qr-codes/NUL.tar.gz', 'numbered port' => 'qr-codes/com1.png',
            'zero port' => 'qr-codes/lpt0.png', 'superscript port' => "qr-codes/com\u{00B9}.png", 'superscript printer' => "qr-codes/LPT\u{00B3}.png",
            'single-byte superscript port' => "qr-codes/com\xB2.png", 'console input' => 'qr-codes/conin$',
            'console output' => 'qr-codes/CONOUT$.png', 'device before a spaced extension' => 'qr-codes/nul .png',
            'short directory name' => 'qr-cod~1/guest.png', 'short file name' => 'qr-codes/GUEST-~12.PNG'] as $case => $key) {
            $this->assertFalse(GuestQrStorage::isSafeKey($key), 'Refused: '.$case);
        }
        foreach ([null, 123, ['qr-codes/guest.png']] as $notAString) {
            $this->assertFalse(GuestQrStorage::isSafeKey($notAString));
        }
    }

    public function test_zip32_entry_count_and_name_limits_refuse_before_packing(): void
    {
        $createZip = new ReflectionMethod(AdminController::class, 'createZip');
        $controller = $this->app->make(AdminController::class);

        $files = [];
        for ($index = 0; $index < 0xFFFE; $index++) {
            $files['g'.$index.'.png'] = 'x';
        }
        $zip = $createZip->invoke($controller, $files);
        $this->assertSame("PK\x05\x06", substr($zip, -22, 4));
        $this->assertSame(0xFFFE, unpack('v', substr($zip, -12, 2))[1], 'The largest ZIP32 entry count stays below the ZIP64 sentinel.');
        $files['sentinel.png'] = 'x';
        $this->assertZipRefused($createZip, $controller, $files);

        $named = $createZip->invoke($controller, [str_repeat('n', 0xFFFF) => 'x']);
        $this->assertSame(0xFFFF, unpack('v', substr($named, 26, 2))[1]);
        $this->assertZipRefused($createZip, $controller, [str_repeat('n', 0x10000) => 'x']);
    }

    #[DataProvider('foreignHandles')]
    public function test_a_handle_whose_actual_io_root_differs_is_refused(string $case): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'private-handle-bytes'));
        $this->public->put($this->path(901), $this->bytes('0047', 'public-handle-bytes'));
        $public = $this->public;
        $private = Storage::disk('guest_qr');
        $config = config('filesystems.disks.guest_qr');
        if ($case === 'public operator and adapter') {
            Storage::set('guest_qr', new LocalFilesystemAdapter($public->getDriver(), $public->getAdapter(), $config));
        } elseif ($case === 'public operator with the private adapter') {
            Storage::set('guest_qr', new LocalFilesystemAdapter($public->getDriver(), $private->getAdapter(), $config));
        } elseif ($case === 'stock operator with a rewriting path normalizer') {
            // Every key is redirected from the private root to the public fake through ../ segments.
            $escape = '../../'.ltrim(str_replace('\\', '/', substr((string) realpath($public->path('')),
                strlen((string) realpath(dirname($this->privateRoot, 2))))), '/').'/';
            Storage::set('guest_qr', new LocalFilesystemAdapter(
                new Flysystem($private->getAdapter(), [], new MediaRewritingPathNormalizer($escape)), $private->getAdapter(), $config));
        } else {
            Storage::extend('local', static fn ($app, array $config): LocalFilesystemAdapter => new LocalFilesystemAdapter($public->getDriver(), $public->getAdapter(), $config));
            Storage::forgetDisk('guest_qr');
        }
        $publicBefore = $this->fileHashes($public->path(''));
        $privateBefore = $this->fileHashes($this->privateRoot);

        $response = $this->from(route('admin.qr-codes.index'))->get(route('admin.guests.qr.download', 901));

        $response->assertRedirect(route('admin.qr-codes.index'))->assertSessionHas('error', __('guest_issuance.failure', [], 'en'));
        $this->assertStringNotContainsString('handle-bytes', (string) $response->getContent());
        $this->assertSame($publicBefore, $this->fileHashes($public->path('')));
        $this->assertSame($privateBefore, $this->fileHashes($this->privateRoot));
    }

    public static function foreignHandles(): array
    {
        return array_combine($cases = ['public operator and adapter', 'public operator with the private adapter',
            'stock operator with a rewriting path normalizer', 'custom local creator ignoring the root'],
            array_map(static fn (string $case): array => [$case], $cases));
    }

    #[DataProvider('deviceNamedEvents')]
    public function test_an_event_named_like_a_device_still_issues_and_serves_its_qr(string $name, string $segment): void
    {
        $this->fixture();
        DB::table('events')->where('id', 301)->update(['event_name' => $name]);

        $this->post(route('admin.guests.qr.generate', 903))->assertRedirect()->assertSessionHas('success', 'QR code generated.');
        $path = (string) QrCode::query()->where('guest_id', 903)->value('qr_image_path');

        $this->assertStringStartsWith('qr-codes/'.$segment.'/guest-903-', $path);
        $this->assertTrue(GuestQrStorage::isSafeKey($path));
        $this->assertFileExists($this->privateRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
        $this->get(route('admin.guests.qr.download', 903))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public static function deviceNamedEvents(): array
    {
        return ['console device name' => ['Con', 'con_event'], 'printer port name' => ['LPT1', 'lpt1_event']];
    }

    public function test_guest_deletion_with_a_qr_path_fails_closed_without_the_private_root(): void
    {
        $this->fixture();
        $this->storeBoth(901, $this->bytes('0047', 'rootless-delete'));
        $absent = $this->privateRoot.DIRECTORY_SEPARATOR.'absent-root';
        config(['filesystems.disks.guest_qr.root' => $absent]);
        Storage::forgetDisk('guest_qr');

        $this->delete(route('admin.guests.destroy', 901))->assertRedirect()
            ->assertSessionHas('error', __('guest_deletion.failure', [], 'en'));
        $this->assertNotNull(Guest::query()->find(901));
        $this->assertSame($this->path(901), QrCode::query()->where('guest_id', 901)->value('qr_image_path'));

        $this->delete(route('admin.guests.destroy', 903))->assertRedirect();
        $this->assertNull(Guest::query()->find(903), 'A Guest without a QR path needs no storage.');
        $this->assertDirectoryDoesNotExist($absent, 'A refused root is never created by resolving a handle.');
    }

    /** Runs $change once, as the locked delivery transaction begins after every repair composition. */
    private function beforeDeliveryTransaction(Closure $change): stdClass
    {
        $hook = new stdClass;
        $hook->fired = 0;
        $hook->affected = null;
        DB::connection()->beforeStartingTransaction(static function () use ($change, $hook): void {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['class'] ?? null) === LegacyQrMediaDelivery::class && $frame['function'] === 'deliver') {
                    $hook->fired++;
                    $hook->affected = $change();

                    return;
                }
            }
        });

        return $hook;
    }

    private function assertZipRefused(ReflectionMethod $createZip, AdminController $controller, array $files): void
    {
        try {
            $createZip->invoke($controller, $files);
        } catch (LegacyQrMediaUnavailable) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('ZIP32 bounds must refuse before packing.');
    }

    /** @return array<string, string> relative path => sha256 */
    private function fileHashes(string $root): array
    {
        $hashes = [];
        foreach ((new Filesystem)->allFiles($root) as $file) {
            $hashes[str_replace('\\', '/', $file->getRelativePathname())] = hash_file('sha256', $file->getPathname());
        }
        ksort($hashes);

        return $hashes;
    }

    private function fixture(): void
    {
        foreach ([301, 302] as $id) {
            Event::factory()->create(['id' => $id, 'workspace_id' => null,
                'event_name' => 'Synthetic media Event '.$id, 'status' => Event::STATUS_ACTIVE]);
        }
        $actor = User::factory()->create(['id' => 211, 'name' => 'Synthetic media Administrator',
            'email' => 'media-admin@example.invalid', 'role' => User::ROLE_ADMIN,
            'is_active' => true, 'is_platform_admin' => false, 'preferred_language' => 'en']);
        foreach ([901 => [301, 'Selected media Guest', '0047', 2, true], 903 => [301, 'Selected second Guest', '0048', 1, true],
            902 => [302, 'Foreign media Guest', '0053', 1, true]] as $id => [$eventId, $name, $code, $allowed, $withQr]) {
            Guest::query()->forceCreate(['id' => $id, 'event_id' => $eventId, 'name' => $name,
                'phone_number' => '+255711111'.$id, 'manual_code' => $code,
                'pass_type' => $allowed === 2 ? Guest::PASS_DOUBLE : Guest::PASS_SINGLE,
                'allowed_entries' => $allowed, 'used_entries' => 0, 'status' => Guest::STATUS_ACTIVE]);
            if ($id !== 903) {
                QrCode::query()->forceCreate(['guest_id' => $id, 'qr_token' => str_repeat(chr(64 + ($id % 26)), 40).$id.'Synthetic',
                    'qr_image_path' => $this->path($id), 'is_active' => true, 'generated_at' => now(), 'revoked_at' => null]);
            }
        }
        // 903 receives a QR row in tests that need it; the generate case issues its own.
        $this->actingAs($actor)->withSession(['selected_event_id' => 301]);
    }

    private function path(int $guestId): string
    {
        return 'qr-codes/synthetic-media-event-'.($guestId === 902 ? '302' : '301').'/guest-'.$guestId.'-synthetic.png';
    }

    /** Compatible fixture bytes: the existing probe only checks the manual-code metadata substring. */
    private function bytes(string $manualCode, string $marker): string
    {
        return "\x89PNG\r\n\x1a\n".'synthetic '.$marker." Manual admission code\x00".$manualCode;
    }

    /** Same bytes on the old public disk and the designed private root, so controls pass on both. */
    private function storeBoth(int $guestId, string $bytes): void
    {
        if ($guestId === 903 && ! QrCode::query()->where('guest_id', 903)->exists()) {
            QrCode::query()->forceCreate(['guest_id' => 903, 'qr_token' => str_repeat('C', 40).'903Synthetic',
                'qr_image_path' => $this->path(903), 'is_active' => true, 'generated_at' => now(), 'revoked_at' => null]);
        }
        $this->public->put($this->path($guestId), $bytes);
        $target = $this->privateRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->path($guestId));
        (new Filesystem)->ensureDirectoryExists(dirname($target));
        file_put_contents($target, $bytes);
    }

    private function zipEntries(string $zip): array
    {
        $file = $this->app->storagePath('media-boundary-'.bin2hex(random_bytes(4)).'.zip');
        file_put_contents($file, $zip);
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($file) === true);
        $entries = [];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = (string) $archive->getNameIndex($index);
            $entries[$name] = (string) $archive->getFromIndex($index);
        }
        $archive->close();
        ksort($entries);

        return $entries;
    }
}

/** Synthetic custom normalizer that prefixes every key, redirecting it outside the private root. */
/** SQLite discards locks; the grammar reports builder intent, never actual lock-wait proof. */
final class MediaLockInspectingGrammar extends SQLiteGrammar
{
    public array $reads = [];

    public function compileSelect(Builder $query)
    {
        $this->reads[] = [$query->from, $query->lock, $query->indexHint?->index];

        return parent::compileSelect($query);
    }
}

final class MediaRewritingPathNormalizer implements PathNormalizer
{
    public function __construct(private string $prefix) {}

    public function normalizePath(string $path): string
    {
        return $this->prefix.$path;
    }
}

/** Trusted local observation wrapper on the guarded TEMP private root: the first read is real, later reads fault. */
final class MediaDeliveryReadFaultDisk extends LocalFilesystemAdapter
{
    public int $reads = 0;

    public function __construct(private LocalFilesystemAdapter $delegate, private string $fault = 'empty')
    {
        parent::__construct($delegate->getDriver(), $delegate->getAdapter(), $delegate->getConfig());
    }

    public function get($path): ?string
    {
        if ($this->reads++ === 0) {
            return $this->delegate->get($path);
        }

        return match ($this->fault) {
            'empty' => '',
            'null' => null,
            'throw' => throw new RuntimeException('Synthetic delivery read failure'),
        };
    }
}
