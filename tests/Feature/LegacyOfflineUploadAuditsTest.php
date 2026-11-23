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
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use App\Services\OfflineScannerResults\OfflineScannerResultsValidator;
use App\Services\Tenancy\LegacyOfflineUploadAudits;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Preliminary audit authority only; preview storage, commit recovery and actual engine schedules are separate. */
class LegacyOfflineUploadAuditsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake($this->confirmationOfflineScannerDisk());
    }

    #[DataProvider('lateGateChanges')]
    public function test_http_preliminary_upload_refuses_authority_loss_after_the_final_session_gate_without_audits_or_files(string $change, int $status): void
    {
        $f = $this->uploadFixture();
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $changed = false;
        $expected = null;
        Gate::after(function (User $user, string $ability, ?bool $result, array $arguments) use ($f, $change, &$changed, &$expected): void {
            $session = $arguments[0] ?? null;
            if ($changed || $ability !== 'view' || $result !== true || ! $session instanceof OfflineScannerSession || $session->id !== $f['session']->id) {
                return;
            }
            $changed = true;
            match ($change) {
                'owned_event' => DB::table('events')->where('id', $f['event']->id)->update(['workspace_id' => 211]),
                'inactive_actor' => DB::table('users')->where('id', $f['admin']->id)->update(['is_active' => false]),
                'customer_actor' => DB::table('users')->where('id', $f['admin']->id)->update(['role' => User::ROLE_CUSTOMER]),
                default => DB::table('offline_scanner_sessions')->where('id', $f['session']->id)->update(['event_id' => $f['ownedNeighbor']->id]),
            };
            $expected = $this->confirmationSnapshot();
        });

        $response = $this->postUpload($f, $contents);
        $this->assertTrue($changed, 'The mutation occurs after the successful final Session Gate, before the first audit.');
        $this->assertSame($expected, $this->confirmationSnapshot(), 'No audit, preview row or private artifact may be added after authority loss.');
        $response->assertStatus($status)->assertSessionHas('selected_event_id', $f['event']->id)
            ->assertSessionHas('guest_import.marker', 'retained')->assertSessionMissing('success');
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function lateGateChanges(): array
    {
        return ['Event became owned' => ['owned_event', 404], 'administrator inactive' => ['inactive_actor', 403],
            'administrator became customer' => ['customer_actor', 403], 'Session moved Event' => ['moved_session', 404]];
    }

    #[DataProvider('validationStageChanges')]
    public function test_http_validation_failure_rechecks_authority_without_erasing_the_prior_authorized_uploaded_audit(string $change, int $status): void
    {
        $f = $this->uploadFixture();
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $changed = false;
        $expected = null;
        $validator = Mockery::mock(OfflineScannerResultsValidator::class, [app(CanonicalJson::class)])->makePartial();
        $validator->shouldReceive('validateEnvelope')->once()->andReturnUsing(function (array $envelope, ?OfflineScannerSession $session = null) use ($f, $change, &$changed, &$expected): never {
            $changed = true;
            if ($change === 'event') {
                DB::table('events')->where('id', $f['event']->id)->update(['workspace_id' => 211]);
            } else {
                DB::table('users')->where('id', $f['admin']->id)->update(['is_active' => false]);
            }
            $expected = $this->confirmationSnapshot();
            throw ValidationException::withMessages(['offline_results' => 'Synthetic validation-stage refusal.']);
        });
        $this->app->instance(OfflineScannerResultsValidator::class, $validator);

        $response = $this->postUpload($f, $contents);
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->confirmationSnapshot(), 'Failed verification may not append an unauthorized audit after this transition.');
        $response->assertStatus($status)->assertSessionMissing('success');
        $this->assertAuditActions($before, $f, [OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED]);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function validationStageChanges(): array
    {
        return ['Event lost during validation' => ['event', 404], 'actor lost during validation' => ['actor', 403]];
    }

    public function test_http_duplicate_stage_cannot_append_reimport_or_failure_audits_after_event_authority_is_lost(): void
    {
        $f = $this->uploadFixture();
        $payload = $this->confirmationPayload($f);
        $contents = $this->confirmationEncryptedResultsFile($payload, $f);
        $exportId = $payload['session']['results_export_id'];
        OfflineScannerResultImport::factory()->create(['offline_scanner_session_id' => $f['session']->id,
            'event_id' => $f['event']->id, 'uploaded_by_user_id' => $f['admin']->id, 'results_export_id' => $exportId]);
        $before = $this->confirmationSnapshot();
        $changed = false;
        $expected = null;
        $queries = [];
        $observer = function (QueryExecuted $query) use ($f, $exportId, &$changed, &$expected): void {
            if ($changed || ! str_contains($query->sql, 'from "offline_scanner_result_imports"')
                || ! str_contains($query->sql, '"results_export_id"') || ! str_contains(strtolower($query->sql), 'exists')
                || ! in_array($exportId, $query->bindings, true)) {
                return;
            }
            $changed = true;
            DB::table('events')->where('id', $f['event']->id)->update(['workspace_id' => 211]);
            $expected = $this->confirmationSnapshot();
        };
        $response = $this->uploadQueries($queries, fn () => $this->postUpload($f, $contents), $observer);
        $this->assertTrue($changed, 'The existing export is observed before the external reimport audit is attempted.');
        $this->assertSame($expected, $this->confirmationSnapshot());
        $response->assertNotFound()->assertSessionMissing('success');
        $this->assertAuditActions($before, $f, [OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED]);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public function test_http_authorized_invalid_json_keeps_uploaded_and_failed_audits_without_preview_or_file_effects(): void
    {
        $f = $this->uploadFixture();
        $before = $this->confirmationSnapshot();
        $this->postUpload($f, '{', 'invalid-json.wqrresults')->assertRedirect(route('admin.offline-scanner-sessions.show', $f['session']))
            ->assertSessionHasErrors('offline_results')->assertSessionMissing('success');
        $this->assertOnlyAuditDelta($before, $f, [OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED, OfflineScannerSessionAudit::ACTION_RESULTS_VERIFICATION_FAILED]);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    #[DataProvider('allowedActorKinds')]
    public function test_http_valid_preview_preserves_ordinary_platform_and_workspace_administrator_compatibility(string $kind): void
    {
        $f = $this->uploadFixture($kind);
        $contents = $this->confirmationEncryptedResultsFile($this->confirmationPayload($f), $f);
        $before = $this->confirmationSnapshot();
        $response = $this->postUpload($f, $contents);
        $import = OfflineScannerResultImport::query()->where('offline_scanner_session_id', $f['session']->id)->sole();
        $response->assertRedirect(route('admin.offline-scanner-results.show', $import))->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Android scanner results verified. Review the preview before importing.');
        $this->assertPreviewEffects($before, $f, $import, $contents);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function allowedActorKinds(): array
    {
        return ['ordinary administrator' => ['ordinary'], 'platform administrator' => ['platform'],
            'workspace-linked administrator' => ['member']];
    }

    #[DataProvider('invalidRawContexts')]
    public function test_helper_invalid_raw_context_refuses_before_queries_and_transaction(string $kind, int $status): void
    {
        $f = $this->uploadFixture();
        $session = clone $f['session'];
        $actor = clone $f['admin'];
        $selected = $f['event']->id;
        match ($kind) {
            'selected_padded' => $selected = '0'.$selected,
            'session_overflow' => $this->rawUploadContext($session, 'id', '9223372036854775808'),
            'event_padded' => $this->rawUploadContext($session, 'event_id', '0'.$f['event']->id),
            'actor_current' => $actor->setAttribute('id', $actor->id + 1),
        };
        $before = $this->confirmationSnapshot();
        $started = false;
        DB::connection()->beforeStartingTransaction(function () use (&$started): void {
            $started = true;
        });
        $queries = [];
        try {
            $this->uploadQueries($queries, fn () => app(LegacyOfflineUploadAudits::class)
                ->record($session, $actor, $selected, OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED, ['file_name' => 'raw.wqrresults']));
            $this->fail('Malformed captured upload context must be refused before database work.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
        $this->assertFalse($started);
        $this->assertSame([], $queries);
        $this->assertSame($before, $this->confirmationSnapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function invalidRawContexts(): array
    {
        return ['padded selected Event' => ['selected_padded', 404], 'overflow Session ID' => ['session_overflow', 404],
            'padded Session Event' => ['event_padded', 404], 'current actor retarget' => ['actor_current', 403]];
    }

    public function test_helper_records_only_the_named_audit_after_current_primary_event_actor_and_session_prefix(): void
    {
        $f = $this->uploadFixture();
        $before = $this->confirmationSnapshot();
        $queries = [];
        $metadata = ['file_name' => 'prefix.wqrresults', 'file_hash' => hash('sha256', 'invented-prefix-bytes'), 'file_size' => 21];
        $context = $this->uploadQueries($queries, fn () => app(LegacyOfflineUploadAudits::class)
            ->record($f['session'], $f['admin'], $f['event']->id, OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED, $metadata));
        $this->assertInstanceOf(OfflineScannerSession::class, $context['session']);
        $this->assertInstanceOf(User::class, $context['actor']);
        $this->assertNotSame($f['session'], $context['session']);
        $this->assertNotSame($f['admin'], $context['actor']);
        $this->assertSame($f['session']->id, $context['session']->getRawOriginal('id'));
        $this->assertSame($f['event']->id, $context['session']->getRawOriginal('event_id'));
        $this->assertSame($f['admin']->id, $context['actor']->getRawOriginal('id'));
        $heads = array_values(array_filter($queries, static fn ($query): bool => $query['level'] === 2
            && preg_match('/\bfrom\s+"(?:events|users|offline_scanner_sessions)"\s+where\s+"(?:events|users|offline_scanner_sessions)"\."id"\s*=\s*\?/i', $query['sql'])));
        $this->assertGreaterThanOrEqual(3, count($heads));
        foreach (['events' => $f['event']->id, 'users' => $f['admin']->id, 'offline_scanner_sessions' => $f['session']->id] as $table => $id) {
            $head = array_shift($heads);
            $this->assertStringContainsString('from "'.$table.'"', $head['sql']);
            $this->assertSame($id, $head['bindings'][0]);
        }
        $auditInserts = array_values(array_filter($queries, static fn ($query): bool => preg_match('/\Ainsert into "offline_scanner_session_audits"/i', $query['sql'])));
        $this->assertCount(1, $auditInserts);
        $this->assertSame(2, $auditInserts[0]['level']);
        $this->assertOnlyAuditDelta($before, $f, [OfflineScannerSessionAudit::ACTION_RESULTS_UPLOADED]);
        $audit = OfflineScannerSessionAudit::query()->where('offline_scanner_session_id', $f['session']->id)->sole();
        $this->assertSame($metadata, $audit->metadata);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    private function uploadFixture(string $kind = 'ordinary'): array
    {
        $f = $this->confirmationFixture();
        DB::table('workspaces')->insert(['id' => 211, 'name' => 'Synthetic upload neighbor workspace']);
        $f['ownedNeighbor'] = Event::factory()->create(['event_name' => 'Synthetic upload neighbor', 'workspace_id' => 211]);
        $neighborSession = OfflineScannerSession::factory()->create(['event_id' => $f['ownedNeighbor']->id, 'created_by_user_id' => $f['admin']->id]);
        OfflineScannerSessionAudit::query()->create(['offline_scanner_session_id' => $neighborSession->id,
            'event_id' => $f['ownedNeighbor']->id, 'actor_user_id' => $f['admin']->id,
            'action' => OfflineScannerSessionAudit::ACTION_SESSION_CREATED, 'metadata' => ['retain' => 'exact'], 'occurred_at' => now()]);
        Storage::disk($this->confirmationOfflineScannerDisk())->put('upload-neighbor/retain.bin', 'invented-retained-upload-neighbor');
        if ($kind === 'platform') {
            DB::table('users')->where('id', $f['admin']->id)->update(['is_platform_admin' => true]);
        }
        if ($kind === 'member') {
            DB::table('workspace_users')->insert(['workspace_id' => 211, 'user_id' => $f['admin']->id, 'role' => 'manager', 'is_active' => false]);
        }
        $f['admin']->refresh();

        return $f;
    }

    private function postUpload(array $f, string $contents, string $name = 'guarded-upload.wqrresults')
    {
        return $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id, 'guest_import' => ['marker' => 'retained']])
            ->from(route('admin.offline-scanner-sessions.show', $f['session']))
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $f['session']->id, 'results_file' => UploadedFile::fake()->createWithContent($name, $contents),
            ]);
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

    private function rawUploadContext($model, string $attribute, mixed $value): void
    {
        $attributes = $model->getAttributes();
        $attributes[$attribute] = $value;
        $model->setRawAttributes($attributes, true);
    }

    private function uploadQueries(array &$queries, callable $work, ?callable $observer = null): mixed
    {
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries, $observer): void {
            $queries[] = ['sql' => $event->sql, 'bindings' => $event->bindings, 'level' => $event->connection->transactionLevel()];
            if ($observer !== null) {
                $observer($event);
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            return $work();
        } finally {
            $connection->setEventDispatcher($original);
        }
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
