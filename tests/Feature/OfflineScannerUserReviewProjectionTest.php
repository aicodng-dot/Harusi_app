<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerResultDevice;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionUser;
use App\Models\QrCode;
use App\Models\User;
use App\Services\CanonicalJson;
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Historical scanner projections only; actor relations and authority/concurrency are separate. */
class OfflineScannerUserReviewProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const PRIVATE_NAME = 'PRIVATE-CURRENT-SCANNER-9821';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake($this->userBaseOfflineScannerDisk());
    }

    #[DataProvider('foreignOwnership')]
    public function test_natural_scanner_rename_and_reassignment_never_reveal_current_foreign_user(bool $owned): void
    {
        $f = $this->userReviewFixture($owned);
        DB::table('users')->whereIn('id', [$f['master']->id, $f['client']->id])->update([
            'event_id' => $f['neighborEvent']->id, 'name' => self::PRIVATE_NAME,
        ]);
        $f['readableIds'] = [];
        $f['choiceIds'] = [];
        $this->withoutPrivateUserHydration($f, function () use ($f): void {
            $this->readUserViews($f);
        });
    }

    public static function foreignOwnership(): array
    {
        return ['other unowned Event' => [false], 'owned tenant Event' => [true]];
    }

    public function test_blank_captured_names_cannot_fall_back_to_private_users_and_canonical_filter_still_works(): void
    {
        $f = $this->userReviewFixture();
        DB::table('users')->whereIn('id', [$f['master']->id, $f['client']->id])->update([
            'event_id' => $f['neighborEvent']->id, 'name' => self::PRIVATE_NAME,
        ]);
        foreach ($f['import']->devices()->get() as $device) {
            $captured = $device->scanner_user_id === $f['master']->id ? null : '';
            $device->update(['scanner_user_name' => $captured]);
            $f['capturedDevices'][$device->device_id]['scanner_user_name'] = $captured;
        }
        $this->withoutPrivateUserHydration($f, function () use ($f): void {
            $before = $this->userReviewSnapshot();
            $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id]);
            foreach (['show', 'logs', 'conflicts'] as $action) {
                $response = $this->get(route('admin.offline-scanner-results.'.$action, $f['import']).'?scanner_user_id='.$f['master']->id)->assertOk();
                $this->assertSame($before, $this->userReviewSnapshot());
                $response->assertDontSee(self::PRIVATE_NAME)->assertSee('Unknown scanner user');
                $this->assertSame((string) $f['master']->id, $response->viewData('filters')['scanner_user_id']);
                $this->assertSame([1, 3], $response->viewData('admissionLogs')->getCollection()->pluck('master_sequence_number')->all());
                $masterOption = $response->viewData('filterOptions')['scannerUsers']->firstWhere('scanner_user_id', $f['master']->id);
                $this->assertNotNull($masterOption, 'Canonical historical identity remains available for review filtering.');
                $this->assertNull($masterOption->scanner_user_name);
                foreach ($response->viewData('resultImport')->devices as $device) {
                    $this->assertSame($f['capturedDevices'][$device->device_id]['scanner_user_name'], $device->scanner_user_name);
                    $this->assertSame($f['capturedDevices'][$device->device_id]['gate_name'], $device->gate_name);
                }
                foreach ($this->responseReferences($response, $action) as [$row, $foreignKey, $relation]) {
                    $this->assertSame($f['references'][$this->referenceKey($row, $foreignKey)], $row->getAttribute($foreignKey));
                    $this->assertTrue($row->relationLoaded($relation));
                    $this->assertNull($row->getRelation($relation));
                    $this->assertFalse($row->isDirty());
                }
            }
        });
    }

    #[DataProvider('protectedScanners')]
    public function test_current_protected_identity_is_not_a_historical_scanner_relation(string $kind): void
    {
        $f = $this->userReviewFixture();
        $changes = ['name' => self::PRIVATE_NAME];
        match ($kind) {
            'admin' => $changes['role'] = User::ROLE_ADMIN,
            'customer' => $changes['role'] = User::ROLE_CUSTOMER,
            'case_variant' => $changes['role'] = 'SCANNER',
            'platform' => $changes['is_platform_admin'] = true,
            default => null,
        };
        DB::table('users')->whereIn('id', [$f['master']->id, $f['client']->id])->update($changes);
        if (in_array($kind, ['active_owner', 'inactive_manager'], true)) {
            foreach ([$f['master']->id, $f['client']->id] as $id) {
                DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => $id,
                    'role' => $kind === 'active_owner' ? 'owner' : 'manager', 'is_active' => $kind === 'active_owner']);
            }
        }
        $f['readableIds'] = [];
        $f['choiceIds'] = [];
        $this->withoutPrivateUserHydration($f, function () use ($f): void {
            $this->readUserViews($f);
        });
    }

    public static function protectedScanners(): array
    {
        return ['admin role' => ['admin'], 'customer role' => ['customer'], 'nonexact role' => ['case_variant'],
            'platform identity' => ['platform'], 'active owner membership' => ['active_owner'], 'inactive manager membership' => ['inactive_manager']];
    }

    #[DataProvider('localActivity')]
    public function test_ordinary_local_scanner_remains_readable_in_history_independent_of_activity(bool $active): void
    {
        $f = $this->userReviewFixture();
        DB::table('users')->where('id', $f['master']->id)->update(['name' => 'Own current Master scanner', 'is_active' => $active]);
        DB::table('users')->where('id', $f['client']->id)->update(['name' => 'Own current Client scanner', 'is_active' => $active]);
        $f['choiceIds'] = $active ? $f['readableIds'] : [];
        $this->readUserViews($f, function (TestResponse $response, string $action, array $references) use ($f): void {
            $this->assertNotEmpty($references);
            foreach ($references as [$row, $foreignKey, $relation]) {
                $id = $f['references'][$this->referenceKey($row, $foreignKey)];
                $this->assertSame($id === $f['master']->id ? 'Own current Master scanner' : 'Own current Client scanner', $row->getRelation($relation)?->name);
            }
        });
    }

    public static function localActivity(): array
    {
        return ['active ordinary scanner' => [true], 'inactive historical scanner' => [false]];
    }

    #[DataProvider('unsafeReferences')]
    public function test_unsafe_raw_scanner_reference_cannot_borrow_a_legitimate_maximum_user(string $kind): void
    {
        $f = $this->userReviewFixture();
        $maximum = User::factory()->create(['id' => PHP_INT_MAX, 'event_id' => $f['event']->id,
            'role' => User::ROLE_SCANNER, 'name' => 'Legitimate own MAX historical scanner']);
        foreach (['offline_scanner_session_users', 'offline_scanner_result_devices', 'offline_scanner_imported_logs'] as $table) {
            DB::table($table)->where('scanner_user_id', $f['client']->id)->update(['scanner_user_id' => $maximum->id]);
        }
        $f = $this->captureReferences($f);
        $f['readableIds'] = [$f['master']->id, $maximum->id];
        $f['choiceIds'] = [$f['master']->id, $f['client']->id, $maximum->id];
        $alias = $kind === 'overflow' ? '9223372036854775808' : (string) PHP_INT_MAX."\n";
        $unsafeKeys = array_keys(array_filter($f['references'], fn ($id) => $id === $f['master']->id));
        foreach ($unsafeKeys as $key) {
            $f['references'][$key] = null;
        }
        $hooks = [];
        $mutated = 0;
        foreach ([OfflineScannerSession::class => 'master_scanner_user_id', OfflineScannerSessionUser::class => 'scanner_user_id',
            OfflineScannerResultDevice::class => 'scanner_user_id', OfflineScannerImportedLog::class => 'scanner_user_id'] as $class => $foreignKey) {
            $hooks[$class] = function (Model $row) use ($unsafeKeys, $foreignKey, $alias, &$mutated): void {
                if (in_array($this->referenceKey($row, $foreignKey), $unsafeKeys, true)) {
                    $attributes = $row->getAttributes();
                    $attributes[$foreignKey] = $alias;
                    $row->setRawAttributes($attributes, true);
                    $mutated++;
                }
            };
        }
        $this->withUserModelHooks($hooks, function () use ($f, $maximum, &$mutated): void {
            $this->readUserViews($f, function (TestResponse $response, string $action, array $references) use ($maximum): void {
                $readable = collect($references)->map(fn ($reference) => $reference[0]->getRelation($reference[2])?->id)->filter()->all();
                if ($action === 'create') {
                    $readable = array_merge($readable, $response->viewData('scannerUsers')->modelKeys());
                }
                $this->assertContains($maximum->id, $readable, 'The canonical MAX companion must remain readable in the same response graph.');
            });
            $this->assertGreaterThanOrEqual(6, $mutated, 'Raw aliases are injected after hydration, never written to the database.');
        });
    }

    public static function unsafeReferences(): array
    {
        return ['overflow' => ['overflow'], 'newline' => ['newline']];
    }

    public function test_preloaded_scanner_relations_are_replaced_with_current_authorized_models(): void
    {
        $f = $this->userReviewFixture();
        $foreign = User::factory()->create(['role' => User::ROLE_SCANNER, 'event_id' => $f['neighborEvent']->id, 'name' => self::PRIVATE_NAME]);
        $hooks = [];
        foreach ([OfflineScannerSession::class => 'masterScannerUser', OfflineScannerSessionUser::class => 'scannerUser',
            OfflineScannerResultDevice::class => 'scannerUser', OfflineScannerImportedLog::class => 'scannerUser'] as $class => $relation) {
            $hooks[$class] = static function (Model $row) use ($foreign, $relation): void {
                $row->setRelation($relation, $foreign);
            };
        }
        $this->withUserModelHooks($hooks, function () use ($f): void {
            $this->readUserViews($f);
        });
    }

    #[DataProvider('mismatchedChildren')]
    public function test_mismatched_child_session_cannot_resolve_a_current_scanner(string $kind): void
    {
        $f = $this->userReviewFixture();
        $other = OfflineScannerSession::factory()->create(['event_id' => $f['event']->id, 'created_by_user_id' => $f['admin']->id,
            'created_at' => now()->subDays(2)]);
        $class = $kind === 'device' ? OfflineScannerResultDevice::class : OfflineScannerImportedLog::class;
        $row = $kind === 'device'
            ? $f['import']->devices()->where('scanner_user_id', $f['master']->id)->sole()
            : $f['log'];
        $row->update(['offline_scanner_session_id' => $other->id]);
        $f['blockedReferences'] = [$this->referenceKey($row, 'scanner_user_id')];
        // A malformed bound log is already a rejected conflict-detail head; the result review still projects its own collection.
        $actions = $kind === 'log' ? ['create', 'session', 'show', 'logs', 'conflicts'] : null;
        $this->readUserViews($f, null, $actions);
        if ($kind === 'log') {
            $before = $this->userReviewSnapshot();
            $this->get(route('admin.offline-scanner-conflicts.show', $f['conflict']))->assertNotFound();
            $this->assertSame($before, $this->userReviewSnapshot());
        }
    }

    public static function mismatchedChildren(): array
    {
        return ['device Session mismatch' => ['device'], 'log Session mismatch' => ['log']];
    }

    private function userReviewFixture(bool $owned = true): array
    {
        $f = $this->userBaseFixture();
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic historical scanner neighbor workspace']);
        $f['neighborEvent'] = Event::factory()->create(['workspace_id' => $owned ? 101 : null, 'event_name' => 'Synthetic scanner neighbor Event']);
        Storage::disk($this->userBaseOfflineScannerDisk())->put('scanner-review-neighbor/retain.bin', 'invented-private-scanner-neighbor-bytes');
        $f['import'] = $this->userBasePreviewResults($f, $this->userBasePayload($f), 'scanner-review.wqrresults');
        $f['log'] = $f['import']->importedLogs()->orderBy('master_sequence_number')->firstOrFail();
        $f['conflict'] = OfflineScannerImportConflict::query()->create([
            'offline_scanner_result_import_id' => $f['import']->id, 'offline_scanner_imported_log_id' => $f['log']->id,
            'event_id' => $f['event']->id, 'guest_id' => $f['guestOne']->id,
            'conflict_type' => OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED, 'conflict_reason' => 'Synthetic retained scanner review',
            'server_state' => ['guest_id' => $f['guestOne']->id, 'event_id' => $f['event']->id, 'allowed_entries' => 2, 'used_entries' => 1, 'remaining_entries' => 1],
            'server_used' => 1, 'server_remaining' => 1,
            'resolution_status' => OfflineScannerImportConflict::RESOLUTION_RESOLVED,
            'resolution_action' => OfflineScannerImportConflict::ACTION_KEEP_SERVER_STATE,
            'resolved_by_user_id' => $f['admin']->id, 'resolved_at' => now(),
        ]);
        $f['capturedDevices'] = $f['import']->devices()->get()->mapWithKeys(fn ($device) => [$device->device_id => [
            'scanner_user_name' => $device->scanner_user_name, 'gate_name' => $device->gate_name,
        ]])->all();
        $f['readableIds'] = [$f['master']->id, $f['client']->id];
        $f['choiceIds'] = $f['readableIds'];
        $f['blockedReferences'] = [];

        return $this->captureReferences($f);
    }

    private function captureReferences(array $f): array
    {
        $references = [];
        $references[$this->referenceKey($f['session'], 'master_scanner_user_id')] = $f['session']->master_scanner_user_id;
        foreach ([$f['session']->sessionUsers()->get(), $f['import']->devices()->get(), $f['import']->importedLogs()->get()] as $rows) {
            foreach ($rows as $row) {
                $references[$this->referenceKey($row, 'scanner_user_id')] = $row->scanner_user_id;
            }
        }
        $f['references'] = $references;

        return $f;
    }

    private function readUserViews(array $f, ?callable $extra = null, ?array $actions = null): void
    {
        $before = $this->userReviewSnapshot();
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id]);
        foreach ($actions ?? ['create', 'session', 'show', 'logs', 'conflicts', 'detail'] as $action) {
            $url = match ($action) {
                'create' => route('admin.offline-scanner-sessions.create', $f['event']),
                'session' => route('admin.offline-scanner-sessions.show', $f['session']),
                'detail' => route('admin.offline-scanner-conflicts.show', $f['conflict']),
                default => route('admin.offline-scanner-results.'.$action, $f['import']),
            };
            $response = $this->get($url)->assertOk();
            $this->assertSame($before, $this->userReviewSnapshot(), 'Historical scanner review GETs must conserve all rows and private bytes.');
            $response->assertDontSee(self::PRIVATE_NAME);
            $references = $this->responseReferences($response, $action);
            $this->assertNotEmpty($references);
            foreach ($references as [$row, $foreignKey, $relation]) {
                $key = $this->referenceKey($row, $foreignKey);
                $this->assertArrayHasKey($key, $f['references']);
                $id = $f['references'][$key];
                $this->assertSame($id, $row->getAttribute($foreignKey), 'Canonical captured scanner IDs remain historical filter/fallback identities.');
                $this->assertTrue($row->relationLoaded($relation), 'The current relation must be explicitly loaded, including a null projection.');
                $expected = $id !== null && in_array($id, $f['readableIds'], true) && ! in_array($key, $f['blockedReferences'], true) ? $id : null;
                $this->assertSame($expected, $row->getRelation($relation)?->id);
                $this->assertFalse($row->isDirty(), 'Read projections must not retain dirty attributes.');
            }
            if ($action === 'create') {
                $choices = $response->viewData('scannerUsers')->modelKeys();
                $expected = $f['choiceIds'];
                sort($choices);
                sort($expected);
                $this->assertSame($expected, $choices, 'New-generation choices retain their active-only rule.');
            }
            if (in_array($action, ['show', 'logs', 'conflicts', 'detail'], true)) {
                foreach ($response->viewData('resultImport')->devices as $device) {
                    $this->assertSame($f['capturedDevices'][$device->device_id]['scanner_user_name'], $device->scanner_user_name);
                    $this->assertSame($f['capturedDevices'][$device->device_id]['gate_name'], $device->gate_name);
                }
            }
            if ($extra !== null) {
                $extra($response, $action, $references);
            }
        }
    }

    private function responseReferences(TestResponse $response, string $action): array
    {
        $references = [];
        $sessions = $action === 'create' ? [$response->viewData('latestSession'), $response->viewData('activeSession')] : [$response->viewData('session')];
        foreach ($sessions as $session) {
            if ($session === null) {
                continue;
            }
            $references[] = [$session, 'master_scanner_user_id', 'masterScannerUser'];
            if ($session->relationLoaded('sessionUsers')) {
                foreach ($session->getRelation('sessionUsers') as $row) {
                    $references[] = [$row, 'scanner_user_id', 'scannerUser'];
                }
            }
        }
        $import = in_array($action, ['show', 'logs', 'conflicts', 'detail'], true) ? $response->viewData('resultImport') : null;
        if ($import) {
            foreach ($import->devices as $row) {
                $references[] = [$row, 'scanner_user_id', 'scannerUser'];
            }
            if ($import->relationLoaded('importedLogs')) {
                foreach ($import->getRelation('importedLogs') as $row) {
                    $references[] = [$row, 'scanner_user_id', 'scannerUser'];
                }
            }
            $conflicts = $action === 'detail' ? [$response->viewData('conflict')] : $import->conflicts;
            foreach ($conflicts as $conflict) {
                if ($conflict->importedLog) {
                    $references[] = [$conflict->importedLog, 'scanner_user_id', 'scannerUser'];
                }
            }
        }
        if (in_array($action, ['show', 'logs', 'conflicts'], true)) {
            foreach ($response->viewData('admissionLogs')->getCollection() as $row) {
                $references[] = [$row, 'scanner_user_id', 'scannerUser'];
            }
        }

        return $references;
    }

    private function referenceKey(Model $row, string $foreignKey): string
    {
        return $row->getTable().':'.$row->getKey().':'.$foreignKey;
    }

    private function withoutPrivateUserHydration(array $f, callable $work): void
    {
        $hydrated = [];
        $this->withUserModelHooks([User::class => function (User $user) use ($f, &$hydrated): void {
            if (in_array($user->getKey(), [$f['master']->id, $f['client']->id], true)) {
                $hydrated[] = $user->getKey();
            }
        }], function () use ($work, &$hydrated): void {
            $work();
            $this->assertSame([], $hydrated, 'Normal foreign/protected Users must be excluded before model hydration.');
        });
    }

    private function withUserModelHooks(array $hooks, callable $work): void
    {
        $dispatcher = User::getEventDispatcher();
        $isolated = clone $dispatcher;
        foreach ($hooks as $model => $hook) {
            $isolated->listen('eloquent.retrieved: '.$model, $hook);
        }
        User::setEventDispatcher($isolated);
        try {
            $work();
        } finally {
            User::setEventDispatcher($dispatcher);
        }
    }

    private function userReviewSnapshot(): array
    {
        $rows = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $contents = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($contents, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $rows[$table->name] = $contents;
        }
        $files = Storage::disk($this->userBaseOfflineScannerDisk())->allFiles();
        sort($files);
        $rows['_files'] = [];
        foreach ($files as $file) {
            $rows['_files'][$file] = hash('sha256', Storage::disk($this->userBaseOfflineScannerDisk())->get($file));
        }

        return $rows;
    }

    private function userBasePreviewResults(array $fixture, array $payload, string $fileName): OfflineScannerResultImport
    {
        $contents = $this->userBaseEncryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent($fileName, $contents),
            ])
            ->assertSessionHasNoErrors();

        return OfflineScannerResultImport::query()->latest('id')->firstOrFail();
    }

    private function userBaseOfflineScannerDisk(): string
    {
        return (string) config('offline_scanner.disk', 'offline_scanner');
    }

    private function userBaseFixture(): array
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
        $guestOne = $this->userBaseGuest($event, 'Asha Results Guest', '+255700000201', Guest::PASS_DOUBLE, 2, '1111');
        $guestTwo = $this->userBaseGuest($event, 'Baraka Results Guest', '+255700000202', Guest::PASS_SINGLE, 1, '2222');
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

    private function userBaseGuest(Event $event, string $name, string $phone, string $passType, int $allowedEntries, string $manualCode): Guest
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

    private function userBasePayload(array $fixture): array
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
                $this->userBaseLog($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 1, 1, 0, 2, 1, 1),
                $this->userBaseLog($guestTwo, $fixture['client'], 'android-client-2', 'Garden Gate', 'manual', 2, 1, 0, 1, 1, 0),
                $this->userBaseLog($guestOne, $fixture['master'], 'android-master-1', 'Main Gate', 'qr', 3, 0, 1, 1, 1, 1, OfflineScannerResultSchema::RESULT_INVALID_QR),
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

    private function userBaseLog(
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

    private function userBaseEncryptedResultsFile(array $payload, array $fixture): string
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
}
