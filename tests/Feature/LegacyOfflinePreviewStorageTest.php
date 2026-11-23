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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Preview authority and known caught cleanup only; controlled hooks are not MySQL race/commit evidence. */
class LegacyOfflinePreviewStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake($this->confirmationOfflineScannerDisk());
    }

    #[DataProvider('latePreviewChanges')]
    public function test_preview_storage_refuses_authority_or_captured_parent_loss_after_authorized_uploaded_audit(string $kind, int $status): void
    {
        $f = $this->previewFixture();
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $changed = false;
        $expected = null;
        $this->beforePreviewTransaction($f, function () use ($f, $kind): void {
            match ($kind) {
                'owned_event' => DB::table('events')->where('id', $f['event']->id)->update(['workspace_id' => 211]),
                'inactive_actor' => DB::table('users')->where('id', $f['admin']->id)->update(['is_active' => false]),
                'customer_actor' => DB::table('users')->where('id', $f['admin']->id)->update(['role' => User::ROLE_CUSTOMER]),
                default => DB::table('offline_scanner_sessions')->where('id', $f['session']->id)
                    ->update(['event_id' => ($kind === 'owned_session' ? $f['ownedNeighbor'] : $f['unownedNeighbor'])->id]),
            };
        }, $changed, $expected);

        $response = $this->postUpload($f, $contents);
        $this->assertTrue($changed, 'The transition occurs after verification, immediately before the preview transaction starts.');
        $this->assertSame($expected, $this->confirmationSnapshot(), 'Only the already authorized preliminary audit and the controlled transition may persist.');
        $response->assertStatus($status)->assertSessionMissing('success')->assertSessionHas('selected_event_id', $f['event']->id)
            ->assertSessionHas('guest_import.marker', 'retained');
        $this->assertAuditActions($before, $f, [OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED]);
        $this->assertSame([], $f['files']->putCalls);
        $this->assertSame([], $f['files']->existsCalls);
        $this->assertSame([], $f['files']->deleteCalls);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function latePreviewChanges(): array
    {
        return ['Event acquired ownership' => ['owned_event', 404], 'administrator inactive' => ['inactive_actor', 403],
            'administrator became customer' => ['customer_actor', 403], 'Session moved owned Event' => ['owned_session', 404],
            'Session moved unowned Event' => ['unowned_session', 404]];
    }

    #[DataProvider('previewActorKinds')]
    public function test_valid_preview_preserves_actor_compatibility_and_takes_current_prefix_inside_its_existing_storage_transaction(string $kind): void
    {
        $f = $this->previewFixture($kind);
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $phase = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$phase): void {
            $phase++;
        });
        $queries = [];
        $response = $this->previewQueries($queries, fn () => $this->postUpload($f, $contents), $phase);
        $import = OfflineScannerResultImport::query()->where('offline_scanner_session_id', $f['session']->id)->sole();
        $response->assertRedirect(route('admin.offline-scanner-results.show', $import))->assertSessionHasNoErrors();
        $this->assertPreviewEffects($before, $f, $import, $contents);
        $this->assertSame(2, $phase, 'The preliminary audit and preview each keep their own existing transaction; no new outer transaction is added.');
        $this->assertSame([['path' => $import->encrypted_file_path, 'disk' => $this->confirmationOfflineScannerDisk(),
            'hash' => hash('sha256', $contents), 'level' => 2]], $f['files']->putCalls);
        $this->assertSame([], $f['files']->existsCalls);
        $this->assertSame([], $f['files']->deleteCalls);
        $heads = array_values(array_filter($queries, static fn ($query): bool => $query['phase'] === 2 && $query['level'] === 2
            && preg_match('/\bfrom\s+"(?:events|users|offline_scanner_sessions)"\s+where\s+(?:"(?:events|users|offline_scanner_sessions)"\.)?"id"\s*=\s*\?/i', $query['sql'])));
        $this->assertGreaterThanOrEqual(3, count($heads));
        foreach (['events' => $f['event']->id, 'users' => $f['admin']->id, 'offline_scanner_sessions' => $f['session']->id] as $table => $id) {
            $head = array_shift($heads);
            $this->assertStringContainsString('from "'.$table.'"', $head['sql']);
            $this->assertSame($id, $head['bindings'][0]);
        }
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function previewActorKinds(): array
    {
        return ['ordinary administrator' => ['ordinary'], 'platform administrator' => ['platform'],
            'workspace-linked administrator' => ['member']];
    }

    #[DataProvider('caughtPreviewFaults')]
    public function test_known_caught_preview_failures_roll_back_rows_and_remove_the_attempt_artifact_without_erasing_uploaded_audit(string $fault): void
    {
        $f = $this->previewFixture('ordinary', $fault === 'put_after' ? $fault : null);
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        if ($fault === 'device_insert') {
            $message = DB::connection()->getPdo()->quote('private-preview-device-insert-marker');
            DB::unprepared('CREATE TEMP TRIGGER "synthetic_preview_device_failure" BEFORE INSERT ON "offline_scanner_result_devices" WHEN NEW."offline_scanner_session_id" = '.(int) $f['session']->id.' BEGIN SELECT RAISE(ABORT, '.$message.'); END');
        }
        $before = $this->confirmationSnapshot();
        $this->postUpload($f, $contents)->assertRedirect(route('admin.offline-scanner-sessions.show', $f['session']))
            ->assertSessionHasErrors(['offline_results' => 'Unable to verify the Android scanner results file.'])->assertSessionMissing('success');
        $this->assertOnlyAuditDelta($before, $f, [OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED]);
        $this->assertCount(1, $f['files']->putCalls);
        $put = $f['files']->putCalls[0];
        $this->assertSame(hash('sha256', $contents), $put['hash']);
        $this->assertSame(2, $put['level']);
        $this->assertSame([['path' => $put['path'], 'disk' => $put['disk'], 'level' => 1]], $f['files']->existsCalls);
        $this->assertSame([['path' => $put['path'], 'disk' => $put['disk'], 'level' => 1]], $f['files']->deleteCalls);
        $this->assertFalse(Storage::disk($put['disk'])->exists($put['path']));
        $feedback = json_encode(session('errors')->getBag('default')->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-preview-', $feedback);
        $this->assertStringNotContainsString($put['path'], $feedback);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function caughtPreviewFaults(): array
    {
        return ['after actual private put' => ['put_after'], 'child SQL INSERT after actual private put' => ['device_insert']];
    }

    public function test_duplicate_inserted_after_external_verification_keeps_existing_in_transaction_validation_and_audit_rollback_semantics(): void
    {
        $f = $this->previewFixture();
        $payload = $this->confirmationPayload($f);
        $contents = $this->confirmationEncryptedResultsFile($payload, $f);
        $before = $this->confirmationSnapshot();
        $changed = false;
        $expected = null;
        $this->beforePreviewTransaction($f, function () use ($f, $payload): void {
            OfflineScannerResultImport::factory()->create(['offline_scanner_session_id' => $f['session']->id,
                'event_id' => $f['event']->id, 'uploaded_by_user_id' => $f['admin']->id,
                'results_export_id' => $payload['session']['results_export_id']]);
        }, $changed, $expected);
        $response = $this->postUpload($f, $contents);
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->confirmationSnapshot(), 'The competing row stays intact; the in-transaction reimport audit rolls back with its validation refusal.');
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', $f['session']))->assertSessionHasErrors('offline_results')->assertSessionMissing('success');
        $this->assertAuditActions($before, $f, [OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED]);
        $this->assertSame([], $f['files']->putCalls);
        $this->assertSame([], $f['files']->deleteCalls);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public function test_cancelled_session_after_validation_keeps_its_existing_business_refusal_and_uploaded_audit(): void
    {
        $f = $this->previewFixture();
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $changed = false;
        $expected = null;
        $this->beforePreviewTransaction($f, function () use ($f): void {
            DB::table('offline_scanner_sessions')->where('id', $f['session']->id)->update(['status' => OfflineScannerSession::STATUS_CANCELLED]);
        }, $changed, $expected);
        $response = $this->postUpload($f, $contents);
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->confirmationSnapshot());
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', $f['session']))->assertSessionHasErrors('offline_results')->assertSessionMissing('success');
        $this->assertAuditActions($before, $f, [OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED]);
        $this->assertSame([], $f['files']->putCalls);
        $this->assertSame([], $f['files']->deleteCalls);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public function test_changed_package_binding_after_validation_is_rechecked_before_preview_artifact_creation(): void
    {
        $f = $this->previewFixture();
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $changed = false;
        $expected = null;
        $this->beforePreviewTransaction($f, function () use ($f): void {
            DB::table('offline_scanner_sessions')->where('id', $f['session']->id)->update(['package_export_id' => $f['otherPackage']->id]);
        }, $changed, $expected);
        $response = $this->postUpload($f, $contents);
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->confirmationSnapshot());
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', $f['session']))->assertSessionHasErrors('offline_results')->assertSessionMissing('success');
        $this->assertAuditActions($before, $f, [OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED]);
        $this->assertSame([], $f['files']->putCalls);
        $this->assertSame([], $f['files']->deleteCalls);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    private function previewFixture(string $kind = 'ordinary', ?string $fault = null): array
    {
        $f = $this->confirmationFixture();
        DB::table('workspaces')->insert(['id' => 211, 'name' => 'Synthetic preview neighbor workspace']);
        $f['ownedNeighbor'] = Event::factory()->create(['event_name' => 'Synthetic owned preview neighbor', 'workspace_id' => 211]);
        $f['unownedNeighbor'] = Event::factory()->create(['event_name' => 'Synthetic unowned preview neighbor', 'workspace_id' => null]);
        $otherSession = OfflineScannerSession::factory()->create(['event_id' => $f['ownedNeighbor']->id, 'created_by_user_id' => $f['admin']->id]);
        $f['otherPackage'] = OfflineScannerPackage::factory()->create(['offline_scanner_session_id' => $otherSession->id,
            'generated_by_user_id' => $f['admin']->id, 'file_name' => 'retained-other-package.wqrpkg',
            'storage_disk' => $this->confirmationOfflineScannerDisk(), 'file_path' => 'preview-neighbor/retained-package.wqrpkg']);
        OfflineScannerSessionAudit::query()->create(['offline_scanner_session_id' => $otherSession->id,
            'event_id' => $f['ownedNeighbor']->id, 'actor_user_id' => $f['admin']->id,
            'action' => OfflineScannerSessionAudit::ACTION_SESSION_CREATED, 'metadata' => ['retain' => 'exact'], 'occurred_at' => now()]);
        Storage::disk($this->confirmationOfflineScannerDisk())->put('preview-neighbor/retained-package.wqrpkg', 'invented-retained-preview-neighbor');
        if ($kind === 'platform') {
            DB::table('users')->where('id', $f['admin']->id)->update(['is_platform_admin' => true]);
        }
        if ($kind === 'member') {
            DB::table('workspace_users')->insert(['workspace_id' => 211, 'user_id' => $f['admin']->id, 'role' => 'manager', 'is_active' => false]);
        }
        $f['admin']->refresh();
        $f['files'] = new PreviewStorageAuthorityFixtureFiles(app(OfflineScannerSessionAuditService::class), $fault === 'put_after');
        $this->app->instance(OfflineScannerFileStorage::class, $f['files']);

        return $f;
    }

    private function beforePreviewTransaction(array $f, callable $change, bool &$changed, ?array &$expected): void
    {
        DB::connection()->beforeStartingTransaction(function () use ($f, $change, &$changed, &$expected): void {
            if ($changed || DB::connection()->transactionLevel() !== 1
                || ! DB::table('offline_scanner_session_audits')->where('offline_scanner_session_id', $f['session']->id)
                    ->where('action', OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED)->exists()) {
                return;
            }
            $changed = true;
            $change();
            $expected = $this->confirmationSnapshot();
        });
    }

    private function postUpload(array $f, string $contents, string $name = 'guarded-preview-storage.wqrresults')
    {
        return $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id, 'guest_import' => ['marker' => 'retained']])
            ->from(route('admin.offline-scanner-sessions.show', $f['session']))
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $f['session']->id, 'results_file' => UploadedFile::fake()->createWithContent($name, $contents),
            ]);
    }

    private function previewQueries(array &$queries, callable $work, int &$phase): mixed
    {
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries, &$phase): void {
            $queries[] = ['sql' => $event->sql, 'bindings' => $event->bindings, 'level' => $event->connection->transactionLevel(), 'phase' => $phase];
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            return $work();
        } finally {
            $connection->setEventDispatcher($original);
        }
    }

    private function assertPreviewEffects(array $before, array $f, OfflineScannerResultImport $import, string $contents): void
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
        ksort($before['_files']);
        $this->assertSame($before, $after, 'Only the authorized preview rows, two audits, Session status and one private artifact may change.');
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

    private function assertOnlyAuditDelta(array $before, array $f, array $actions): void
    {
        $after = $this->assertAuditActions($before, $f, $actions);
        $before['offline_scanner_session_audits'] = $after['offline_scanner_session_audits'];
        $this->assertSame($before, $after, 'Preliminary audit recording alone may not add preview rows, modify neighbors or touch files.');
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

final class PreviewStorageAuthorityFixtureFiles extends OfflineScannerFileStorage
{
    public array $putCalls = [];

    public array $existsCalls = [];

    public array $deleteCalls = [];

    public function __construct(OfflineScannerSessionAuditService $audits, private readonly bool $failAfterPut = false)
    {
        parent::__construct($audits);
    }

    public function put(string $path, string $contents, ?string $disk = null): void
    {
        $this->putCalls[] = ['path' => $path, 'disk' => $disk ?: $this->diskName(),
            'hash' => hash('sha256', $contents), 'level' => DB::connection()->transactionLevel()];
        parent::put($path, $contents, $disk);
        if ($this->failAfterPut) {
            throw new RuntimeException('private-preview-after-put-marker');
        }
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        $this->existsCalls[] = ['path' => $path, 'disk' => $disk ?: $this->diskName(), 'level' => DB::connection()->transactionLevel()];

        return parent::exists($path, $disk);
    }

    public function delete(string $path, ?string $disk = null): bool
    {
        $this->deleteCalls[] = ['path' => $path, 'disk' => $disk ?: $this->diskName(), 'level' => DB::connection()->transactionLevel()];

        return parent::delete($path, $disk);
    }
}
