<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerPackageGuestSnapshot;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionUser;
use App\Models\QrCode;
use App\Models\User;
use App\Services\CanonicalJson;
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use App\Services\OfflineScannerResults\OfflineScannerResultsImportService;
use App\Services\Tenancy\LegacyOfflineConflictResolution;
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

/** Captured legacy head authority only; bound logs, Guest/sibling effects and engine schedules are separate. */
class LegacyOfflineConflictResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const NOTES = 'Synthetic retained authority resolution.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake($this->confirmationOfflineScannerDisk());
    }

    #[DataProvider('lateHeadChanges')]
    public function test_http_resolution_refuses_late_authority_and_captured_parent_changes_without_effects(string $change, int $status): void
    {
        $f = $this->resolutionFixture();
        if ($change === 'conflict_import') {
            $f['otherImport'] = OfflineScannerResultImport::factory()->create([
                'offline_scanner_session_id' => $f['session']->id, 'event_id' => $f['event']->id,
            ]);
        }
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
                'active_two_actor' => DB::table('users')->where('id', $f['admin']->id)->update(['is_active' => 2]),
                'conflict_import' => DB::table('offline_scanner_import_conflicts')->where('id', $f['conflict']->id)
                    ->update(['offline_scanner_result_import_id' => $f['otherImport']->id]),
                'session_parent' => DB::table('offline_scanner_sessions')->where('id', $f['session']->id)
                    ->update(['event_id' => $f['unownedNeighbor']->id]),
                default => $this->reparentResolution($f, $change === 'owned_parents' ? $f['ownedNeighbor'] : $f['unownedNeighbor']),
            };
            $expected = $this->confirmationSnapshot();
        });

        $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id, 'guest_import' => ['marker' => 'retained']])
            ->post(route('admin.offline-scanner-conflicts.resolve', $f['conflict']), $this->resolutionInput())
            ->assertStatus($status)->assertSessionHas('selected_event_id', $f['event']->id)->assertSessionHas('guest_import.marker', 'retained');
        $this->assertTrue($changed, 'The mutation occurs after request-entry authorization.');
        $this->assertSame($expected, $this->confirmationSnapshot(), 'Late refusals conserve the post-transition rows and private bytes.');
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function lateHeadChanges(): array
    {
        return ['Event became owned' => ['owned_event', 404], 'administrator disabled' => ['disabled_actor', 403],
            'administrator became customer' => ['customer_actor', 403], 'raw activity2' => ['active_two_actor', 403],
            'coherent owned parents' => ['owned_parents', 404], 'coherent foreign legacy parents' => ['unowned_parents', 404],
            'Conflict Import retarget' => ['conflict_import', 404], 'Session parent changed' => ['session_parent', 404]];
    }

    #[DataProvider('allowedAdministrators')]
    public function test_http_resolution_retains_ordinary_platform_and_member_administrator_keep_or_reject_behavior(string $kind, string $action): void
    {
        $f = $this->resolutionFixture($kind);
        $before = $this->confirmationSnapshot();
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id])
            ->post(route('admin.offline-scanner-conflicts.resolve', $f['conflict']), $this->resolutionInput($action))
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.offline-scanner-results.show', $f['import']))
            ->assertSessionHas('success', 'Offline result conflict updated.');
        $this->assertResolvedWithoutAdmissions($f, $before, $action);
    }

    public static function allowedAdministrators(): array
    {
        return ['ordinary keep' => ['ordinary', OfflineScannerImportConflict::ACTION_KEEP_SERVER_STATE],
            'platform reject' => ['platform', OfflineScannerImportConflict::ACTION_REJECT_IMPORTED_LOG],
            'inactive workspace membership keep' => ['member', OfflineScannerImportConflict::ACTION_KEEP_SERVER_STATE]];
    }

    public function test_http_resolution_audit_failure_rolls_back_actual_conflict_log_and_sibling_writes(): void
    {
        $f = $this->resolutionFixture();
        $before = $this->confirmationSnapshot();
        $failed = false;
        $partial = null;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$failed, &$partial): void {
            if ($failed || ! preg_match('/\A\s*insert\s+into\s+"offline_scanner_session_audits"/i', $sql)) {
                return;
            }
            $failed = true;
            $partial = $this->confirmationSnapshot();
            throw new QueryException(DB::connection()->getName(), $sql, $bindings, new \RuntimeException('private-resolution-audit-failure'));
        });
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id])
            ->from(route('admin.offline-scanner-conflicts.show', $f['conflict']))
            ->post(route('admin.offline-scanner-conflicts.resolve', $f['conflict']), $this->resolutionInput())
            ->assertRedirect(route('admin.offline-scanner-conflicts.show', $f['conflict']))
            ->assertSessionHasErrors(['conflict_resolution' => 'Unable to resolve the offline result conflict.'])
            ->assertSessionMissing('success');
        $this->assertTrue($failed);
        $this->assertNotNull($partial);
        $partialConflicts = array_column($partial['offline_scanner_import_conflicts'], null, 'id');
        foreach ($f['conflictIds'] as $id) {
            $this->assertSame(OfflineScannerImportConflict::RESOLUTION_DISMISSED, $partialConflicts[$id]['resolution_status']);
        }
        $this->assertSame(OfflineScannerImportedLog::STATUS_REJECTED,
            array_column($partial['offline_scanner_imported_logs'], null, 'id')[$f['logId']]['import_status']);
        $this->assertSame($before['admissions'], $partial['admissions']);
        $this->assertSame($before['checkins'], $partial['checkins']);
        $this->assertCount(count($before['offline_scanner_session_audits']), $partial['offline_scanner_session_audits']);
        $this->assertStringNotContainsString('private-resolution-audit-failure', json_encode(session('errors')->getBag('default')->all()));
        $this->assertSame($before, $this->confirmationSnapshot(), 'Both nested resolver and outer authority prefix must roll back.');
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public function test_wrapper_captures_only_import_identity_then_locks_the_frozen_head_order_and_preserves_closed_http_refusal(): void
    {
        $f = $this->resolutionFixture();
        $before = $this->confirmationSnapshot();
        $queries = [];
        $result = $this->resolutionQueries($queries, fn () => app(LegacyOfflineConflictResolution::class)
            ->resolve($f['conflict'], $f['admin'], $f['event']->id, $this->resolutionInput()));
        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_DISMISSED, $result->resolution_status);
        $this->assertIdentityCapture($queries[0], $f['import']->id);
        $heads = array_values(array_filter($queries, static fn ($query): bool => $query['level'] === 2
            && preg_match('/\bfrom\s+"(?:events|users|offline_scanner_sessions|offline_scanner_result_imports|offline_scanner_import_conflicts)"\s+where\s+"(?:events|users|offline_scanner_sessions|offline_scanner_result_imports|offline_scanner_import_conflicts)"\."id"\s*=\s*\?/i', $query['sql'])));
        $this->assertGreaterThanOrEqual(5, count($heads));
        foreach (['events' => $f['event']->id, 'users' => $f['admin']->id, 'offline_scanner_sessions' => $f['session']->id,
            'offline_scanner_result_imports' => $f['import']->id, 'offline_scanner_import_conflicts' => $f['conflict']->id] as $table => $id) {
            $head = array_shift($heads);
            $this->assertStringContainsString('from "'.$table.'"', $head['sql']);
            $this->assertSame($id, $head['bindings'][0]);
        }
        $this->assertResolvedWithoutAdmissions($f, $before, OfflineScannerImportConflict::ACTION_KEEP_SERVER_STATE);
        $after = $this->confirmationSnapshot();
        $this->actingAs($f['admin'])->withSession(['selected_event_id' => $f['event']->id])
            ->post(route('admin.offline-scanner-conflicts.resolve', $f['conflict']), $this->resolutionInput())->assertForbidden();
        $this->assertSame($after, $this->confirmationSnapshot());
    }

    #[DataProvider('invalidRawHeads')]
    public function test_wrapper_invalid_raw_head_refuses_before_identity_query_or_transaction(string $kind, int $status): void
    {
        $f = $this->resolutionFixture();
        $conflict = clone $f['conflict'];
        $actor = clone $f['admin'];
        $selected = $f['event']->id;
        match ($kind) {
            'selected_padded' => $selected = '0'.$selected,
            'selected_other' => $selected = $f['unownedNeighbor']->id,
            'conflict_overflow' => $this->rawResolutionContext($conflict, 'id', '9223372036854775808'),
            'conflict_current' => $conflict->setAttribute('id', $conflict->id + 1),
            'import_padded' => $this->rawResolutionContext($conflict, 'offline_scanner_result_import_id', '0'.$f['import']->id),
            'event_newline' => $this->rawResolutionContext($conflict, 'event_id', $f['event']->id."\n"),
            'actor_overflow' => $this->rawResolutionContext($actor, 'id', '9223372036854775808'),
            'actor_current' => $actor->setAttribute('id', $actor->id + 1),
        };
        $before = $this->confirmationSnapshot();
        $started = false;
        DB::connection()->beforeStartingTransaction(function () use (&$started): void {
            $started = true;
        });
        $queries = [];
        try {
            $this->resolutionQueries($queries, fn () => app(LegacyOfflineConflictResolution::class)
                ->resolve($conflict, $actor, $selected, $this->resolutionInput()));
            $this->fail('Malformed captured authority must be refused before any identity query.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
        $this->assertFalse($started);
        $this->assertSame([], $queries);
        $this->assertSame($before, $this->confirmationSnapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function invalidRawHeads(): array
    {
        return ['padded selection' => ['selected_padded', 404], 'different selected Event' => ['selected_other', 404],
            'overflow Conflict ID' => ['conflict_overflow', 404], 'current Conflict retarget' => ['conflict_current', 404],
            'padded Import reference' => ['import_padded', 404], 'newline Event reference' => ['event_newline', 404],
            'overflow actor' => ['actor_overflow', 403], 'current actor retarget' => ['actor_current', 403]];
    }

    #[DataProvider('invalidImportCaptures')]
    public function test_wrapper_unavailable_or_mismatched_import_capture_reads_identity_once_without_starting_transaction(string $kind): void
    {
        $f = $this->resolutionFixture();
        $captureId = $f['import']->id;
        if ($kind === 'missing') {
            // Restrictive Import foreign keys remain enabled. This controlled
            // captured model names an absent canonical Import without corrupting rows.
            $captureId += 1000;
            $f['conflict'] = clone $f['conflict'];
            $this->rawResolutionContext($f['conflict'], 'offline_scanner_result_import_id', $captureId);
        } else {
            DB::table('offline_scanner_result_imports')->where('id', $f['import']->id)->update(['event_id' => $f['unownedNeighbor']->id]);
        }
        $before = $this->confirmationSnapshot();
        $started = false;
        DB::connection()->beforeStartingTransaction(function () use (&$started): void {
            $started = true;
        });
        $queries = [];
        try {
            $this->resolutionQueries($queries, fn () => app(LegacyOfflineConflictResolution::class)
                ->resolve($f['conflict'], $f['admin'], $f['event']->id, $this->resolutionInput()));
            $this->fail('Unavailable or contradictory Import identity must not choose a Session transaction root.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertFalse($started);
        $this->assertCount(1, $queries);
        $this->assertIdentityCapture($queries[0], $captureId);
        $this->assertSame($before, $this->confirmationSnapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function invalidImportCaptures(): array
    {
        return ['missing captured Import' => ['missing'], 'Import Event disagrees' => ['event']];
    }

    private function resolutionFixture(string $kind = 'ordinary'): array
    {
        $f = $this->confirmationFixture();
        DB::table('workspaces')->insert(['id' => 211, 'name' => 'Synthetic retained resolution workspace']);
        $f['ownedNeighbor'] = $this->resolutionNeighbor(true);
        $f['unownedNeighbor'] = $this->resolutionNeighbor(false);
        if ($kind === 'platform') {
            DB::table('users')->where('id', $f['admin']->id)->update(['is_platform_admin' => true]);
        }
        if ($kind === 'member') {
            DB::table('workspace_users')->insert(['workspace_id' => 211, 'user_id' => $f['admin']->id, 'role' => 'manager', 'is_active' => false]);
        }
        $f['admin']->refresh();
        $f['import'] = $this->confirmationPreviewResults($f, $this->confirmationPayload($f), 'authority-resolution.wqrresults');
        $f['guestOne']->update(['used_entries' => 1]);
        app(OfflineScannerResultsImportService::class)->importVerified($f['import'], $f['admin']);
        $f['conflict'] = $f['import']->conflicts()->where('conflict_type', OfflineScannerImportConflict::TYPE_SERVER_USED_CHANGED)
            ->where('resolution_status', OfflineScannerImportConflict::RESOLUTION_OPEN)->firstOrFail();
        $f['logId'] = $f['conflict']->offline_scanner_imported_log_id;
        $f['conflictIds'] = $f['import']->conflicts()->where('offline_scanner_imported_log_id', $f['logId'])->pluck('id')->all();

        return $f;
    }

    private function resolutionNeighbor(bool $owned): Event
    {
        $event = Event::factory()->create(['event_name' => 'Synthetic retained resolution neighbor', 'workspace_id' => $owned ? 211 : null]);
        $this->confirmationGuest($event, 'Retained resolution neighbor', '+255700008932', Guest::PASS_DOUBLE, 2, '8932');
        Storage::disk($this->confirmationOfflineScannerDisk())->put('resolution-neighbor/'.$event->id.'.bin', 'invented-retained-resolution-bytes');

        return $event;
    }

    private function reparentResolution(array $f, Event $event): void
    {
        DB::table('offline_scanner_sessions')->where('id', $f['session']->id)->update(['event_id' => $event->id]);
        DB::table('offline_scanner_result_imports')->where('id', $f['import']->id)->update(['event_id' => $event->id]);
        DB::table('offline_scanner_import_conflicts')->where('offline_scanner_result_import_id', $f['import']->id)->update(['event_id' => $event->id]);
    }

    private function resolutionInput(string $action = OfflineScannerImportConflict::ACTION_KEEP_SERVER_STATE): array
    {
        return ['resolution_action' => $action, 'resolution_notes' => self::NOTES, 'confirm_resolution' => '1'];
    }

    private function assertIdentityCapture(array $query, int $importId): void
    {
        $this->assertSame(1, $query['level']);
        $this->assertStringStartsWith('select "id", "offline_scanner_session_id", "event_id" from "offline_scanner_result_imports"', $query['sql']);
        $this->assertSame([$importId], $query['bindings']);
    }

    private function resolutionQueries(array &$queries, callable $work): mixed
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

    private function rawResolutionContext($model, string $attribute, mixed $value): void
    {
        $attributes = $model->getAttributes();
        $attributes[$attribute] = $value;
        $model->setRawAttributes($attributes, true);
    }

    private function assertResolvedWithoutAdmissions(array $f, array $before, string $action): void
    {
        $this->assertSame(1, $f['guestOne']->fresh()->used_entries);
        $this->assertSame(1, $f['guestTwo']->fresh()->used_entries);
        $this->assertSame(OfflineScannerResultImport::STATUS_COMPLETED, $f['import']->fresh()->status);
        $this->assertSame(OfflineScannerSession::STATUS_CLOSED, $f['session']->fresh()->status);
        $this->assertSame(OfflineScannerImportedLog::STATUS_REJECTED, OfflineScannerImportedLog::query()->findOrFail($f['logId'])->import_status);
        $this->assertSame(0, $f['import']->conflicts()->where('resolution_status', OfflineScannerImportConflict::RESOLUTION_OPEN)->count());
        $this->assertResolutionEffectsConfined($f, $before, $action);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    private function assertResolutionEffectsConfined(array $f, array $before, string $action): void
    {
        $after = $this->confirmationSnapshot();
        $rowChanges = [
            'offline_scanner_sessions' => [[$f['session']->id], ['status', 'results_imported_at', 'last_master_sequence', 'session_closed_at', 'updated_at']],
            'offline_scanner_result_imports' => [[$f['import']->id], ['status', 'imported_logs', 'duplicate_logs', 'conflict_logs', 'rejected_logs', 'unknown_guest_logs', 'imported_at', 'updated_at']],
            'offline_scanner_imported_logs' => [[$f['logId']], ['import_status', 'conflict_reason', 'updated_at']],
            'offline_scanner_import_conflicts' => [$f['conflictIds'], ['resolution_status', 'resolution_action', 'resolved_by_user_id', 'resolution_notes', 'resolved_at', 'updated_at']],
        ];
        foreach ($rowChanges as $table => [$ids, $fields]) {
            $this->assertCount(count($before[$table]), $after[$table]);
            $previous = array_column($before[$table], null, 'id');
            foreach ($after[$table] as $row) {
                $old = $previous[$row['id']];
                if (in_array($row['id'], $ids, true)) {
                    if ($table === 'offline_scanner_sessions') {
                        $expectedActive = in_array($row['status'], OfflineScannerSession::blockingStatuses(), true) ? $row['event_id'] : null;
                        $this->assertSame($expectedActive, $row['active_event_id']);
                        unset($old['active_event_id'], $row['active_event_id']);
                    }
                    if ($table === 'offline_scanner_import_conflicts') {
                        $this->assertSame(OfflineScannerImportConflict::RESOLUTION_DISMISSED, $row['resolution_status']);
                        $this->assertSame($action, $row['resolution_action']);
                        $this->assertSame($f['admin']->id, $row['resolved_by_user_id']);
                        $this->assertSame(self::NOTES, $row['resolution_notes']);
                    }
                    foreach ($fields as $field) {
                        unset($old[$field], $row[$field]);
                    }
                }
                $this->assertSame($old, $row, 'Only named target resolution fields may change in '.$table.'.');
            }
            $before[$table] = $after[$table];
        }
        $table = 'offline_scanner_session_audits';
        $oldIds = array_column($before[$table], 'id');
        $retained = array_values(array_filter($after[$table], static fn ($row): bool => in_array($row['id'], $oldIds, true)));
        $added = array_values(array_filter($after[$table], static fn ($row): bool => ! in_array($row['id'], $oldIds, true)));
        $this->assertSame($before[$table], $retained);
        $this->assertCount(4, $added);
        foreach ($added as $row) {
            $this->assertSame($f['session']->id, $row['offline_scanner_session_id']);
            $this->assertSame($f['event']->id, $row['event_id']);
            $this->assertSame($f['admin']->id, $row['actor_user_id']);
        }
        $before[$table] = $after[$table];
        $this->assertSame($before, $after, 'Guests, admissions, neighboring history and all private files must remain intact.');
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
