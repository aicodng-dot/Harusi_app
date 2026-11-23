<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionUser;
use App\Models\QrCode;
use App\Models\User;
use App\Services\CanonicalJson;
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use App\Services\Tenancy\LegacyOfflineResultConfirmation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Captured legacy confirmation authority only; engine contention and nested history are separate. */
class LegacyOfflineResultConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake($this->confirmationOfflineScannerDisk());
    }

    #[DataProvider('lateAuthorityChanges')]
    public function test_http_confirmation_refuses_late_authority_or_coherent_parent_change_without_effects(string $change, int $status): void
    {
        $f = $this->authorityFixture();
        $changed = false;
        $expected = null;
        DB::connection()->beforeStartingTransaction(function () use ($f, $change, &$changed, &$expected): void {
            if ($changed) {
                return;
            }
            $changed = true;
            match ($change) {
                'owned_event' => DB::table('events')->where('id', $f['event']->id)->update(['workspace_id' => 211]),
                'disabled_actor' => DB::table('users')->where('id', $f['admin']->id)->update(['is_active' => false]),
                'customer_actor' => DB::table('users')->where('id', $f['admin']->id)->update(['role' => User::ROLE_CUSTOMER]),
                'case_variant_actor' => DB::table('users')->where('id', $f['admin']->id)->update(['role' => 'ADMIN']),
                'noncanonical_active_actor' => DB::table('users')->where('id', $f['admin']->id)->update(['is_active' => 2]),
                default => $this->reparentConfirmation($f, $change === 'owned_parent' ? $f['ownedNeighbor'] : $f['unownedNeighbor']),
            };
            $expected = $this->confirmationSnapshot();
        });

        $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id, 'guest_import' => ['marker' => 'retained']])
            ->post(route('admin.offline-scanner-results.confirm', $f['import']), ['confirm_import' => '1'])
            ->assertStatus($status)->assertSessionHas('selected_event_id', $f['event']->id)->assertSessionHas('guest_import.marker', 'retained');
        $this->assertTrue($changed, 'Only the mutation after the HTTP entry checks is under test.');
        $this->assertSame($expected, $this->confirmationSnapshot(), 'All rows and private files must remain at the post-mutation boundary.');
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function lateAuthorityChanges(): array
    {
        return ['Event became owned' => ['owned_event', 404], 'actor disabled' => ['disabled_actor', 403],
            'actor became customer' => ['customer_actor', 403], 'actor role changed case' => ['case_variant_actor', 403],
            'actor raw active2' => ['noncanonical_active_actor', 403],
            'coherent reparent to owned Event' => ['owned_parent', 404], 'coherent reparent to other legacy Event' => ['unowned_parent', 404]];
    }

    #[DataProvider('allowedActorKinds')]
    public function test_http_confirmation_retains_existing_ordinary_platform_and_member_administrator_behavior(string $kind): void
    {
        $f = $this->authorityFixture($kind);
        $before = $this->confirmationSnapshot();
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id])
            ->post(route('admin.offline-scanner-results.confirm', $f['import']), ['confirm_import' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.offline-scanner-sessions.show', $f['session']))
            ->assertSessionHas('success', 'Verified Android scanner results imported and the offline session was closed.');
        $this->assertCompletedAdmission($f, $before);
    }

    public static function allowedActorKinds(): array
    {
        return ['ordinary legacy administrator' => ['ordinary'], 'platform administrator' => ['platform'],
            'workspace member administrator' => ['member']];
    }

    public function test_http_failure_inside_original_import_rolls_back_actual_admissions_counters_and_audits(): void
    {
        $f = $this->authorityFixture();
        $before = $this->confirmationSnapshot();
        $attempts = 0;
        $partial = null;
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$attempts, &$partial, &$failed): void {
            if ($failed || ! preg_match('/\A\s*insert\s+into\s+"offline_scanner_session_audits"/i', $sql)) {
                return;
            }
            $attempts++;
            if ($attempts === 3) {
                $failed = true;
                $partial = $this->confirmationSnapshot();
                throw new QueryException(DB::connection()->getName(), $sql, $bindings, new \RuntimeException('private-confirmation-audit-failure'));
            }
        });
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id])
            ->from(route('admin.offline-scanner-results.show', $f['import']))
            ->post(route('admin.offline-scanner-results.confirm', $f['import']), ['confirm_import' => '1'])
            ->assertRedirect(route('admin.offline-scanner-results.show', $f['import']))
            ->assertSessionHasErrors(['offline_results' => 'Unable to import the verified Android scanner results.'])->assertSessionMissing('success');
        $this->assertTrue($failed);
        $this->assertSame(3, $attempts);
        $this->assertNotNull($partial);
        $this->assertCount(count($before['admissions']) + 2, $partial['admissions']);
        $this->assertCount(count($before['checkins']) + 2, $partial['checkins']);
        $this->assertCount(count($before['offline_scanner_session_audits']) + 2, $partial['offline_scanner_session_audits']);
        foreach ([$f['guestOne']->id, $f['guestTwo']->id] as $guestId) {
            $this->assertSame(1, array_column($partial['guests'], null, 'id')[$guestId]['used_entries']);
        }
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED, array_column($partial['offline_scanner_result_imports'], null, 'id')[$f['import']->id]['status']);
        $this->assertSame(OfflineScannerSession::STATUS_CLOSED, array_column($partial['offline_scanner_sessions'], null, 'id')[$f['session']->id]['status']);
        $this->assertStringNotContainsString('private-confirmation-audit-failure', json_encode(session('errors')->getBag('default')->all()));
        $this->assertSame($before, $this->confirmationSnapshot(), 'The nested importer and authority prefix must roll back as one operation.');
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public function test_wrapper_completed_retry_retains_noop_after_closed_http_refusal_and_takes_captured_locks_in_order(): void
    {
        $f = $this->authorityFixture();
        $before = $this->confirmationSnapshot();
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id])
            ->post(route('admin.offline-scanner-results.confirm', $f['import']), ['confirm_import' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertCompletedAdmission($f, $before);
        $after = $this->confirmationSnapshot();
        $this->post(route('admin.offline-scanner-results.confirm', $f['import']), ['confirm_import' => '1'])->assertForbidden();
        $this->assertSame($after, $this->confirmationSnapshot());
        $queries = [];
        $result = $this->confirmationQueries($queries, fn () => app(LegacyOfflineResultConfirmation::class)
            ->confirm($f['import'], $f['admin'], $f['event']->id));
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED, $result->status);
        $this->assertSame($after, $this->confirmationSnapshot(), 'A correctly fenced completed internal retry is still a no-op.');
        $heads = array_values(array_filter($queries, static fn ($query): bool => $query['level'] === 2
            && preg_match('/\bfrom\s+"(?:events|users|offline_scanner_sessions|offline_scanner_result_imports)"\s+where\s+"(?:events|users|offline_scanner_sessions|offline_scanner_result_imports)"\."id"\s*=\s*\?/i', $query['sql'])));
        $this->assertGreaterThanOrEqual(4, count($heads));
        foreach (['events' => $f['event']->id, 'users' => $f['admin']->id, 'offline_scanner_sessions' => $f['session']->id,
            'offline_scanner_result_imports' => $f['import']->id] as $table => $id) {
            $head = array_shift($heads);
            $this->assertStringContainsString('from "'.$table.'"', $head['sql']);
            $this->assertSame($id, $head['bindings'][0]);
        }
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    #[DataProvider('invalidRawContexts')]
    public function test_wrapper_invalid_raw_context_refuses_before_any_query_or_transaction(string $kind, int $status): void
    {
        $f = $this->authorityFixture();
        $import = clone $f['import'];
        $actor = clone $f['admin'];
        $selected = $f['event']->id;
        match ($kind) {
            'selected_padded' => $selected = '0'.$selected,
            'selected_overflow' => $selected = '9223372036854775808',
            'selected_newline' => $selected = $selected."\n",
            'selected_float' => $selected = (float) $selected,
            'selected_other' => $selected = $f['unownedNeighbor']->id,
            'import_padded' => $this->rawContext($import, 'id', '0'.$import->id),
            'import_overflow' => $this->rawContext($import, 'id', '9223372036854775808'),
            'session_padded' => $this->rawContext($import, 'offline_scanner_session_id', '0'.$f['session']->id),
            'event_padded' => $this->rawContext($import, 'event_id', '0'.$f['event']->id),
            'actor_overflow' => $this->rawContext($actor, 'id', '9223372036854775808'),
            'actor_newline' => $this->rawContext($actor, 'id', $actor->id."\n"),
            'actor_current_mismatch' => $actor->setAttribute('id', $actor->id + 1),
        };
        $before = $this->confirmationSnapshot();
        $started = false;
        DB::connection()->beforeStartingTransaction(function () use (&$started): void {
            $started = true;
        });
        $queries = [];
        try {
            $this->confirmationQueries($queries, fn () => app(LegacyOfflineResultConfirmation::class)->confirm($import, $actor, $selected));
            $this->fail('Malformed or contradictory captured authority context must be refused.');
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
        return ['padded selected Event' => ['selected_padded', 404], 'overflow selected Event' => ['selected_overflow', 404],
            'newline selected Event' => ['selected_newline', 404], 'float selected Event' => ['selected_float', 404],
            'different canonical selected Event' => ['selected_other', 404], 'padded Import ID' => ['import_padded', 404],
            'overflow Import ID' => ['import_overflow', 404], 'padded captured Session' => ['session_padded', 404],
            'padded captured Event' => ['event_padded', 404], 'overflow actor ID' => ['actor_overflow', 403],
            'newline actor ID' => ['actor_newline', 403], 'actor current/original mismatch' => ['actor_current_mismatch', 403]];
    }

    private function authorityFixture(string $kind = 'ordinary'): array
    {
        $f = $this->confirmationFixture();
        $f['ownedNeighbor'] = $this->confirmationNeighbor(true);
        $f['unownedNeighbor'] = $this->confirmationNeighbor(false);
        if ($kind === 'platform') {
            DB::table('users')->where('id', $f['admin']->id)->update(['is_platform_admin' => true]);
        }
        if ($kind === 'member') {
            DB::table('workspace_users')->insert(['workspace_id' => 211, 'user_id' => $f['admin']->id, 'role' => 'manager', 'is_active' => false]);
        }
        $f['admin']->refresh();
        $f['import'] = $this->confirmationPreviewResults($f, $this->confirmationPayload($f), 'authority-confirmation.wqrresults');

        return $f;
    }

    private function reparentConfirmation(array $f, Event $event): void
    {
        DB::table('offline_scanner_sessions')->where('id', $f['session']->id)->update(['event_id' => $event->id]);
        DB::table('offline_scanner_result_imports')->where('id', $f['import']->id)->update(['event_id' => $event->id]);
    }

    private function assertCompletedAdmission(array $f, array $before): void
    {
        $this->assertSame(1, $f['guestOne']->fresh()->used_entries);
        $this->assertSame(1, $f['guestTwo']->fresh()->used_entries);
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED, $f['import']->fresh()->status);
        $this->assertSame(OfflineScannerSession::STATUS_CLOSED, $f['session']->fresh()->status);
        $this->assertSame(2, DB::table('admissions')->where('event_id', $f['event']->id)->sum('quantity'));
        $this->assertSame(2, DB::table('checkins')->where('event_id', $f['event']->id)->count());
        $this->assertConfirmationEffectsConfined($before, $f['import'], [$f['guestOne']->id, $f['guestTwo']->id], 2, 0, 3);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    private function rawContext($model, string $attribute, mixed $value): void
    {
        $attributes = $model->getAttributes();
        $attributes[$attribute] = $value;
        $model->setRawAttributes($attributes, true);
    }

    private function confirmationQueries(array &$queries, callable $work): mixed
    {
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = ['sql' => $event->sql, 'bindings' => $event->bindings, 'level' => $event->connection->transactionLevel()];
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            return $work();
        } finally {
            $connection->setEventDispatcher($original);
        }
    }

    private function confirmationNeighbor(bool $owned): Event
    {
        $attributes = ['event_name' => 'Synthetic retained confirmation neighbor'];
        if ($owned) {
            DB::table('workspaces')->insert(['id' => 211, 'name' => 'Synthetic retained confirmation workspace']);
            $attributes['workspace_id'] = 211;
        }
        $event = Event::factory()->create($attributes);
        $this->confirmationGuest($event, 'Retained neighbor Guest', '+255700008932', Guest::PASS_DOUBLE, 2, '8932');
        Storage::disk($this->confirmationOfflineScannerDisk())->put('confirmation-neighbor/retain.bin', 'invented-retained-confirmation-bytes');

        return $event;
    }

    private function assertConfirmationEffectsConfined(array $before, OfflineScannerResultImport $import, array $guestIds, int $newAdmissions, int $newConflicts, int $newAudits): void
    {
        $after = $this->confirmationSnapshot();
        $targetLogIds = $import->importedLogs()->pluck('id')->all();
        $rowChanges = [
            'guests' => [$guestIds, ['used_entries', 'status', 'updated_at']],
            'offline_scanner_sessions' => [[$import->offline_scanner_session_id], ['status', 'results_imported_at', 'last_master_sequence', 'session_closed_at', 'updated_at']],
            'offline_scanner_result_imports' => [[$import->id], ['status', 'imported_logs', 'duplicate_logs', 'conflict_logs', 'rejected_logs', 'unknown_guest_logs', 'imported_at', 'updated_at']],
            'offline_scanner_imported_logs' => [$targetLogIds, ['import_status', 'conflict_reason', 'updated_at']],
        ];
        foreach ($rowChanges as $table => [$allowedIds, $allowedFields]) {
            $this->assertCount(count($before[$table]), $after[$table]);
            $previous = array_column($before[$table], null, 'id');
            foreach ($after[$table] as $row) {
                $old = $previous[$row['id']];
                if (in_array($row['id'], $allowedIds, true)) {
                    if ($table === 'offline_scanner_sessions') {
                        $expectedActiveEvent = in_array($row['status'], OfflineScannerSession::blockingStatuses(), true) ? $row['event_id'] : null;
                        $this->assertSame($expectedActiveEvent, $row['active_event_id'], 'The generated active Event column must follow its stored status.');
                        unset($old['active_event_id'], $row['active_event_id']);
                    }
                    foreach ($allowedFields as $field) {
                        unset($old[$field], $row[$field]);
                    }
                }
                $this->assertSame($old, $row, 'Only explicit target fields may change in '.$table.'.');
            }
            $before[$table] = $after[$table];
        }
        foreach (['admissions' => $newAdmissions, 'checkins' => $newAdmissions, 'offline_scanner_import_conflicts' => $newConflicts, 'offline_scanner_session_audits' => $newAudits] as $table => $expectedAdded) {
            $oldIds = array_column($before[$table], 'id');
            $retained = array_values(array_filter($after[$table], static fn ($row): bool => in_array($row['id'], $oldIds, true)));
            $added = array_values(array_filter($after[$table], static fn ($row): bool => ! in_array($row['id'], $oldIds, true)));
            $this->assertSame($before[$table], $retained, 'Existing '.$table.' history must remain intact.');
            $this->assertCount($expectedAdded, $added);
            foreach ($added as $row) {
                if (in_array($table, ['admissions', 'checkins'], true)) {
                    $this->assertContains($row['guest_id'], $guestIds);
                    $this->assertContains($row['offline_scanner_imported_log_id'], $targetLogIds);
                }
                if (array_key_exists('offline_scanner_result_import_id', $row)) {
                    $this->assertSame((string) $import->id, (string) $row['offline_scanner_result_import_id']);
                }
                if (array_key_exists('offline_scanner_session_id', $row)) {
                    $this->assertSame((string) $import->offline_scanner_session_id, (string) $row['offline_scanner_session_id']);
                }
                if (array_key_exists('event_id', $row)) {
                    $this->assertSame((string) $import->event_id, (string) $row['event_id']);
                }
            }
            $before[$table] = $after[$table];
        }
        $this->assertSame($before, $after, 'All other rows and every private fixture-file hash must be conserved.');
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

    private function confirmationPreviewResults(array $fixture, array $payload, string $fileName): OfflineScannerResultImport
    {
        $contents = $this->confirmationEncryptedResultsFile($payload, $fixture);

        $this->actingAs($fixture['admin'])
            ->withSession(['selected_event_id' => $fixture['event']->id])
            ->post(route('admin.offline-scanner-results.preview'), [
                'offline_scanner_session_id' => $fixture['session']->id,
                'results_file' => UploadedFile::fake()->createWithContent($fileName, $contents),
            ])
            ->assertSessionHasNoErrors();

        return OfflineScannerResultImport::query()->latest('id')->firstOrFail();
    }

    private function confirmationOfflineScannerDisk(): string
    {
        return (string) config('offline_scanner.disk', 'offline_scanner');
    }
}
