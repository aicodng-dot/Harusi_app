<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\QrCode;
use App\Models\User;
use App\Services\Tenancy\LegacyOfflineSessionActions;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class LegacyOfflineSessionActionsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('lateBoundaryChanges')]
    public function test_actions_refuse_late_parent_or_actor_change_without_additional_effects(string $action, bool $platform, string $change, int $status): void
    {
        $f = $this->fixture($platform, $action);
        $changed = false;
        $expected = null;
        DB::connection()->beforeStartingTransaction(function () use ($change, &$changed, &$expected): void {
            if ($changed) {
                return;
            }
            $changed = true;
            match ($change) {
                'owned_event' => DB::table('events')->where('id', 301)->update(['workspace_id' => 101]),
                'actor_role' => DB::table('users')->where('id', 211)->update(['role' => 'customer']),
                'actor_uppercase_role' => DB::table('users')->where('id', 211)->update(['role' => 'ADMIN']),
                'actor_padded_role' => DB::table('users')->where('id', 211)->update(['role' => 'admin ']),
                'actor_prefixed_role' => DB::table('users')->where('id', 211)->update(['role' => ' admin']),
                'actor_disabled' => DB::table('users')->where('id', 211)->update(['is_active' => false]),
                'actor_active_two' => DB::table('users')->where('id', 211)->update(['is_active' => 2]),
                'actor_deleted' => $this->deleteActor(),
                'session_owned_parent' => DB::table('offline_scanner_sessions')->where('id', 401)->update(['event_id' => 302]),
                'session_foreign_unowned_parent' => DB::table('offline_scanner_sessions')->where('id', 401)->update(['event_id' => 303]),
                'session_deleted' => DB::table('offline_scanner_sessions')->where('id', 401)->delete(),
            };
            $expected = $this->snapshot();
        });

        $response = $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301, 'guest_import' => ['marker' => 'preserved']])
            ->post(route('admin.offline-scanner-sessions.'.$action, 401), $this->input($action));
        $response->assertStatus($status)->assertSessionHas('selected_event_id', 301)->assertSessionHas('guest_import.marker', 'preserved');
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function lateBoundaryChanges(): array
    {
        $cases = [];
        foreach (['cancel', 'close'] as $action) {
            foreach ([false, true] as $platform) {
                foreach (['owned_event' => 404, 'actor_role' => 403, 'actor_uppercase_role' => 403,
                    'actor_padded_role' => 403, 'actor_prefixed_role' => 403, 'actor_disabled' => 403,
                    'actor_active_two' => 403, 'actor_deleted' => 403, 'session_owned_parent' => 404,
                    'session_foreign_unowned_parent' => 404, 'session_deleted' => 404] as $change => $status) {
                    $cases[$action.' '.($platform ? 'platform' : 'staff').' '.$change] = [$action, $platform, $change, $status];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('latePolicyChanges')]
    public function test_actions_recheck_current_policy_eligibility_after_the_controller_gate(string $action, bool $platform, array $change): void
    {
        $f = $this->fixture($platform, $action);
        $changed = false;
        $expected = null;
        DB::connection()->beforeStartingTransaction(function () use ($change, &$changed, &$expected): void {
            if ($changed) {
                return;
            }
            $changed = true;
            DB::table('offline_scanner_sessions')->where('id', 401)->update($change);
            $expected = $this->snapshot();
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301])
            ->post(route('admin.offline-scanner-sessions.'.$action, 401), $this->input($action))->assertForbidden();
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function latePolicyChanges(): array
    {
        $cases = [];
        foreach ([false, true] as $platform) {
            foreach ([
                'cancel now closed' => ['cancel', ['status' => OfflineScannerSession::STATUS_CLOSED]],
                'cancel now imported' => ['cancel', ['results_imported_at' => '2026-10-23 10:00:00']],
                'close now closed' => ['close', ['status' => OfflineScannerSession::STATUS_CLOSED]],
                'close now cancelled' => ['close', ['status' => OfflineScannerSession::STATUS_CANCELLED]],
                'close no results' => ['close', ['status' => OfflineScannerSession::STATUS_ACTIVE, 'results_imported_at' => null]],
            ] as $label => [$action, $change]) {
                $cases[$label.' '.($platform ? 'platform' : 'staff')] = [$action, $platform, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('importedRows')]
    public function test_cancel_current_imported_rows_keep_existing_business_refusal(bool $platform, string $status, int $imported): void
    {
        $f = $this->fixture($platform, 'cancel');
        $changed = false;
        $expected = null;
        DB::connection()->beforeStartingTransaction(function () use ($status, $imported, &$changed, &$expected): void {
            if ($changed) {
                return;
            }
            $changed = true;
            $this->resultImport(601, 401, 301, $status, $imported);
            $expected = $this->snapshot();
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301])->from(route('admin.offline-scanner-sessions.show', 401))
            ->post(route('admin.offline-scanner-sessions.cancel', 401), $this->input('cancel'))
            ->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHasErrors(['offline_session' => 'This offline scanner session already has imported results and cannot be cancelled.']);
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function importedRows(): array
    {
        $cases = [];
        foreach ([false, true] as $platform) {
            foreach ([['completed', 0], ['completed_with_conflicts', 0], ['preview_ready', 1]] as [$status, $imported]) {
                $cases[($platform ? 'platform ' : 'staff ').$status] = [$platform, $status, $imported];
            }
        }

        return $cases;
    }

    #[DataProvider('actors')]
    public function test_close_sees_open_conflict_added_after_preflight_and_does_not_unlock(bool $platform): void
    {
        $f = $this->fixture($platform, 'close');
        $this->resultImport(601, 401, 301, OfflineScannerResultImport::STATUS_COMPLETED);
        $changed = false;
        $expected = null;
        DB::connection()->beforeStartingTransaction(function () use (&$changed, &$expected): void {
            if ($changed) {
                return;
            }
            $changed = true;
            OfflineScannerImportConflict::factory()->create(['id' => 701, 'offline_scanner_result_import_id' => 601,
                'offline_scanner_imported_log_id' => null, 'event_id' => 301, 'resolution_status' => OfflineScannerImportConflict::RESOLUTION_OPEN]);
            $expected = $this->snapshot();
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301])->from(route('admin.offline-scanner-sessions.show', 401))
            ->post(route('admin.offline-scanner-sessions.close', 401), $this->input('close'))
            ->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHasErrors(['offline_session' => 'Resolve all offline result conflicts before closing this session.']);
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    #[DataProvider('successTimelines')]
    public function test_success_preserves_history_files_audit_timeline_and_parent_lock_order(string $action, bool $platform, string $initialStatus): void
    {
        $f = $this->fixture($platform, $action, $initialStatus);
        $before = $this->snapshot();
        $queries = [];
        $response = $this->queries($queries, fn () => $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301])
            ->post(route('admin.offline-scanner-sessions.'.$action, 401), $this->input($action)));
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHas('success', $action === 'cancel'
                ? 'Offline scanner session cancelled and event lock released.'
                : 'Offline scanner session closed and normal Laravel admissions resumed.');
        $this->assertSuccessDelta($before, $action, $initialStatus);
        $heads = array_values(array_filter($queries, static fn ($query): bool => $query['level'] >= 2
            && preg_match('/\bfrom\s+"(?:events|users|offline_scanner_sessions)"\s+where\s+"(?:events|users|offline_scanner_sessions)"\."id"\s*=\s*\?/i', $query['sql'])));
        $this->assertGreaterThanOrEqual(3, count($heads));
        $this->assertStringContainsString('from "events"', $heads[0]['sql']);
        $this->assertSame(301, $heads[0]['id']);
        $this->assertStringContainsString('from "users"', $heads[1]['sql']);
        $this->assertSame(211, $heads[1]['id']);
        $this->assertStringContainsString('from "offline_scanner_sessions"', $heads[2]['sql']);
        $this->assertSame(401, $heads[2]['id']);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function successTimelines(): array
    {
        $cases = [];
        foreach ([false, true] as $platform) {
            foreach ([['cancel', OfflineScannerSession::STATUS_ACTIVE],
                ['close', OfflineScannerSession::STATUS_RESULTS_IMPORTED],
                ['close', OfflineScannerSession::STATUS_ACTIVE]] as [$action, $status]) {
                $cases[$action.' '.$status.' '.($platform ? 'platform' : 'staff')] = [$action, $platform, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('actionsAndActors')]
    public function test_audit_sql_failure_rolls_back_session_and_first_audit_with_generic_feedback(string $action, bool $platform): void
    {
        $f = $this->fixture($platform, $action, OfflineScannerSession::STATUS_ACTIVE);
        $before = $this->snapshot();
        $auditAttempts = 0;
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$auditAttempts, &$failed): void {
            if ($failed || ! preg_match('/\A\s*insert\s+into\s+"offline_scanner_session_audits"/i', $sql)) {
                return;
            }
            $auditAttempts++;
            if ($auditAttempts === 2) {
                $failed = true;
                throw new QueryException(DB::connection()->getName(), $sql, $bindings, new \RuntimeException('private-sql-audit-failure-marker'));
            }
        });
        $queries = [];
        $response = $this->queries($queries, fn () => $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301])
            ->from(route('admin.offline-scanner-sessions.show', 401))
            ->post(route('admin.offline-scanner-sessions.'.$action, 401), $this->input($action)));
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHasErrors(['offline_session' => 'Unable to '.$action.' the offline scanner session.'])
            ->assertSessionMissing('success');
        $this->assertTrue($failed);
        $this->assertSame(2, $auditAttempts);
        $completed = array_values(array_filter($queries, static fn ($query): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $query['sql'])));
        $this->assertCount(2, $completed);
        $this->assertStringStartsWith('update "offline_scanner_sessions"', $completed[0]['sql']);
        $this->assertStringStartsWith('insert into "offline_scanner_session_audits"', $completed[1]['sql']);
        $this->assertStringNotContainsString('private-sql-audit-failure-marker', json_encode(session('errors')->getBag('default')->all()));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    #[DataProvider('actionsAndActors')]
    public function test_required_confirmation_and_reason_validation_preserve_every_row_and_file(string $action, bool $platform): void
    {
        $f = $this->fixture($platform, $action);
        $before = $this->snapshot();
        $started = false;
        DB::connection()->beforeStartingTransaction(function () use (&$started): void {
            $started = true;
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301])
            ->post(route('admin.offline-scanner-sessions.'.$action, 401), [])
            ->assertSessionHasErrors($action === 'cancel' ? ['confirm_unused', 'cancellation_reason'] : ['confirm_close']);
        $this->assertFalse($started);
        $this->assertSame($before, $this->snapshot());
    }

    public static function actors(): array
    {
        return ['staff' => [false], 'platform flag and workspace membership' => [true]];
    }

    public static function actionsAndActors(): array
    {
        return ['staff cancel' => ['cancel', false], 'platform cancel' => ['cancel', true],
            'staff close' => ['close', false], 'platform close' => ['close', true]];
    }

    private function input(string $action): array
    {
        return $action === 'cancel'
            ? ['confirm_unused' => '1', 'cancellation_reason' => '  Synthetic session was never used.  ']
            : ['confirm_close' => '1'];
    }

    private function assertSuccessDelta(array $before, string $action, string $previousStatus): void
    {
        $after = $this->snapshot();
        $oldSession = array_values(array_filter($before['offline_scanner_sessions'], static fn ($row): bool => $row['id'] === 401))[0];
        $newSession = array_values(array_filter($after['offline_scanner_sessions'], static fn ($row): bool => $row['id'] === 401))[0];
        $this->assertSame($action === 'cancel' ? OfflineScannerSession::STATUS_CANCELLED : OfflineScannerSession::STATUS_CLOSED, $newSession['status']);
        $this->assertNotNull($newSession['session_closed_at']);
        foreach ($oldSession as $key => $value) {
            if (! in_array($key, ['status', 'session_closed_at', 'updated_at', 'active_event_id'], true)) {
                $this->assertSame($value, $newSession[$key], 'Unexpected change to session column '.$key);
            }
        }
        $this->assertNull($newSession['active_event_id']);
        $audits = OfflineScannerSessionAudit::query()->where('offline_scanner_session_id', 401)->orderBy('id')->get();
        $expectedActions = $action === 'cancel'
            ? [OfflineScannerSessionAudit::ACTION_SESSION_CANCELLED, OfflineScannerSessionAudit::ACTION_EVENT_UNLOCKED]
            : ($previousStatus === OfflineScannerSession::STATUS_ACTIVE
                ? [OfflineScannerSessionAudit::ACTION_EVENT_UNLOCKED, OfflineScannerSessionAudit::ACTION_SESSION_CLOSED]
                : [OfflineScannerSessionAudit::ACTION_SESSION_CLOSED]);
        $this->assertSame($expectedActions, $audits->pluck('action')->all());
        foreach ($audits as $audit) {
            $this->assertSame(301, $audit->event_id);
            $this->assertSame(211, $audit->actor_user_id);
            $this->assertNotNull($audit->occurred_at);
            if ($action === 'cancel') {
                $this->assertSame('Synthetic session was never used.', $audit->reason);
                $this->assertSame($audit->action === OfflineScannerSessionAudit::ACTION_SESSION_CANCELLED
                    ? ['confirmed_unused' => true] : ['unlock_reason' => 'session_cancelled'], $audit->metadata);
            } else {
                $this->assertNull($audit->reason);
                $this->assertSame(['previous_status' => $previousStatus, 'status' => OfflineScannerSession::STATUS_CLOSED], $audit->metadata);
            }
        }
        foreach ($before['offline_scanner_session_audits'] as $oldAudit) {
            $this->assertContains($oldAudit, $after['offline_scanner_session_audits']);
        }
        $this->assertCount(count($before['offline_scanner_session_audits']) + count($expectedActions), $after['offline_scanner_session_audits']);
        foreach ($before['offline_scanner_sessions'] as &$row) {
            if ($row['id'] === 401) {
                $row = $newSession;
            }
        }
        unset($row);
        $before['offline_scanner_session_audits'] = $after['offline_scanner_session_audits'];
        $this->assertSame($before, $after);
    }

    private function queries(array &$queries, callable $callback): mixed
    {
        $db = DB::connection();
        $original = $db->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = ['sql' => $event->sql, 'id' => $event->bindings[0] ?? null, 'level' => $event->connection->transactionLevel()];
        });
        $db->setEventDispatcher($dispatcher);
        try {
            return $callback();
        } finally {
            $db->setEventDispatcher($original);
        }
    }

    #[DataProvider('ambiguousRawIds')]
    public function test_direct_actions_reject_ambiguous_raw_ids_before_any_row_query(string $action, string $target, string $shape): void
    {
        $f = $this->fixture(false, $action);
        $id = match ($target) {
            'actor' => 211, 'session' => 401, default => 301
        };
        $bad = match ($shape) {
            'zero' => 0, 'padded' => '0'.$id, 'overflow' => '9223372036854775808'
        };
        $selected = 301;
        if ($target === 'selected') {
            $selected = $bad;
        } elseif ($target === 'actor') {
            $f['actor']->setRawAttributes(array_replace($f['actor']->getAttributes(), ['id' => $bad]), true);
        } else {
            $key = $target === 'session' ? 'id' : 'event_id';
            $f['session']->setRawAttributes(array_replace($f['session']->getAttributes(), [$key => $bad]), true);
        }
        $before = $this->snapshot();
        $queries = [];
        $refused = false;
        try {
            $this->queries($queries, fn () => $this->direct($action, $f['session'], $f['actor'], $selected));
        } catch (HttpExceptionInterface $exception) {
            $refused = true;
            $this->assertSame($target === 'actor' ? 403 : 404, $exception->getStatusCode());
        }
        $this->assertTrue($refused);
        $this->assertSame([], $queries);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function ambiguousRawIds(): array
    {
        $cases = [];
        foreach (['cancel', 'close'] as $action) {
            foreach (['selected', 'session', 'session_event', 'actor'] as $target) {
                foreach (['zero', 'padded', 'overflow'] as $shape) {
                    $cases[$action.' '.$target.' '.$shape] = [$action, $target, $shape];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('unsafeActorAliases')]
    public function test_unsafe_original_or_current_actor_identity_cannot_alias_a_real_safe_account(string $action, string $variant): void
    {
        $f = $this->fixture(false, $action);
        User::factory()->create(['id' => PHP_INT_MAX, 'role' => 'admin', 'is_active' => true]);
        if ($variant === 'unsafe_original') {
            $f['actor']->setRawAttributes(array_replace($f['actor']->getAttributes(), ['id' => '9223372036854775808']), true);
            $f['actor']->setAttribute('id', PHP_INT_MAX);
            $this->assertSame('9223372036854775808', $f['actor']->getRawOriginal('id'));
        } else {
            $f['actor']->setAttribute('id', '9223372036854775808');
            $this->assertSame(211, $f['actor']->getRawOriginal('id'));
        }
        $this->assertSame(PHP_INT_MAX, $f['actor']->getKey());
        $before = $this->snapshot();
        $queries = [];
        $refused = false;
        try {
            $this->queries($queries, fn () => $this->direct($action, $f['session'], $f['actor'], 301));
        } catch (HttpExceptionInterface $exception) {
            $refused = true;
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertTrue($refused);
        $this->assertSame([], $queries);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function unsafeActorAliases(): array
    {
        return ['cancel unsafe original' => ['cancel', 'unsafe_original'], 'close unsafe original' => ['close', 'unsafe_original'],
            'cancel unsafe current' => ['cancel', 'unsafe_current'], 'close unsafe current' => ['close', 'unsafe_current']];
    }

    #[DataProvider('corruptImportParents')]
    public function test_direct_actions_refuse_immediate_import_with_missing_or_foreign_event_parent(string $action, ?int $event): void
    {
        $f = $this->fixture(false, $action);
        $this->resultImport(601, 401, 301, OfflineScannerResultImport::STATUS_PREVIEW_READY);
        DB::table('offline_scanner_result_imports')->where('id', 601)->update(['event_id' => $event]);
        $before = $this->snapshot();
        $queries = [];
        $refused = false;
        try {
            $this->queries($queries, fn () => $this->direct($action, $f['session'], $f['actor'], 301));
        } catch (HttpExceptionInterface $exception) {
            $refused = true;
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertTrue($refused);
        $this->assertNotEmpty(array_filter($queries, static fn ($query): bool => str_contains($query['sql'], 'from "offline_scanner_result_imports"')));
        $this->assertSame([], array_values(array_filter($queries, static fn ($query): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $query['sql']))));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function corruptImportParents(): array
    {
        return ['cancel missing event' => ['cancel', null], 'cancel foreign event' => ['cancel', 302],
            'close missing event' => ['close', null], 'close foreign event' => ['close', 302]];
    }

    #[DataProvider('deadlockActions')]
    public function test_deadlock_fault_before_session_update_has_generic_feedback_and_preserves_fixture(string $action): void
    {
        $f = $this->fixture(false, $action, OfflineScannerSession::STATUS_ACTIVE);
        $before = $this->snapshot();
        $attempts = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$attempts): void {
            if (preg_match('/\A\s*update\s+"offline_scanner_sessions"/i', $sql)) {
                $attempts++;
                throw new DeadlockException('Deadlock found when trying to get lock; try restarting transaction', 40001);
            }
        });
        $queries = [];
        $response = $this->queries($queries, fn () => $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301])
            ->from(route('admin.offline-scanner-sessions.show', 401))
            ->post(route('admin.offline-scanner-sessions.'.$action, 401), $this->input($action)));
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHasErrors(['offline_session' => 'Unable to '.$action.' the offline scanner session.'])
            ->assertSessionMissing('success');
        $this->assertGreaterThanOrEqual(1, $attempts);
        $this->assertSame([], array_values(array_filter($queries, static fn ($query): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $query['sql']))));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function deadlockActions(): array
    {
        return ['cancel' => ['cancel'], 'close' => ['close']];
    }

    private function direct(string $action, OfflineScannerSession $session, User $actor, mixed $selected): OfflineScannerSession
    {
        $actions = app(LegacyOfflineSessionActions::class);

        return $action === 'cancel'
            ? $actions->cancel($session, $actor, $selected, true, 'Synthetic direct cancellation.')
            : $actions->close($session, $actor, $selected);
    }

    private function deleteActor(): void
    {
        DB::table('workspace_users')->where('user_id', 211)->delete();
        DB::table('users')->where('id', 211)->delete();
    }

    private function fixture(bool $platform = false, string $action = 'cancel', ?string $status = null): array
    {
        Storage::fake(config('offline_scanner.disk'));
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic private ownership']);
        foreach ([301 => null, 302 => 101, 303 => null] as $id => $workspace) {
            Event::factory()->create(['id' => $id, 'workspace_id' => $workspace, 'event_name' => 'Synthetic session event '.$id]);
        }
        $actor = User::factory()->create(['id' => 211, 'role' => 'admin', 'is_active' => true, 'is_platform_admin' => $platform]);
        User::factory()->create(['id' => 215, 'role' => 'admin', 'is_active' => true]);
        User::factory()->create(['id' => 212, 'role' => 'scanner', 'event_id' => 301]);
        User::factory()->create(['id' => 214, 'role' => 'customer', 'is_active' => true]);
        DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 214, 'role' => 'owner', 'is_active' => false]);
        if ($platform) {
            DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 211, 'role' => 'owner', 'is_active' => true]);
        }
        $status ??= $action === 'close' ? OfflineScannerSession::STATUS_RESULTS_IMPORTED : OfflineScannerSession::STATUS_ACTIVE;
        $session = OfflineScannerSession::factory()->create(['id' => 401, 'event_id' => 301,
            'created_by_user_id' => 215, 'master_scanner_user_id' => 212,
            'status' => $status, 'session_started_at' => now()->subHours(2),
            'results_imported_at' => $action === 'close' ? now()->subHour() : null,
            'session_closed_at' => null]);
        OfflineScannerSession::factory()->create(['id' => 402, 'event_id' => 302, 'created_by_user_id' => 215,
            'status' => OfflineScannerSession::STATUS_CLOSED, 'results_imported_at' => now()->subDay(), 'session_closed_at' => now()->subDay()]);
        OfflineScannerSession::factory()->create(['id' => 403, 'event_id' => 303, 'created_by_user_id' => 215,
            'status' => OfflineScannerSession::STATUS_CANCELLED, 'session_closed_at' => now()->subDay()]);
        $guest = Guest::query()->forceCreate(['id' => 501, 'event_id' => 302, 'name' => 'Synthetic private guest',
            'phone_number' => '+255712345678', 'manual_code' => '0051', 'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2, 'used_entries' => 1]);
        QrCode::query()->create(['guest_id' => 501, 'qr_token' => Guest::makeSecureToken(), 'is_active' => true, 'generated_at' => now()]);
        $package = OfflineScannerPackage::factory()->create(['id' => 502, 'offline_scanner_session_id' => 402, 'generated_by_user_id' => 215]);
        DB::table('offline_scanner_sessions')->where('id', 402)->update(['package_export_id' => 502]);
        Storage::disk(config('offline_scanner.disk'))->put($package->file_path, 'invented-private-package-marker');
        $import = $this->resultImport(602, 402, 302, OfflineScannerResultImport::STATUS_COMPLETED, 1);
        Storage::disk(config('offline_scanner.disk'))->put($import->encrypted_file_path, 'invented-private-results-marker');
        OfflineScannerImportConflict::factory()->create(['id' => 702, 'offline_scanner_result_import_id' => 602,
            'offline_scanner_imported_log_id' => null, 'event_id' => 302, 'guest_id' => 501,
            'resolution_status' => OfflineScannerImportConflict::RESOLUTION_RESOLVED, 'resolved_by_user_id' => 215, 'resolved_at' => now()->subDay()]);
        OfflineScannerSessionAudit::query()->forceCreate(['id' => 902, 'offline_scanner_session_id' => 402, 'event_id' => 302,
            'actor_user_id' => 215, 'action' => OfflineScannerSessionAudit::ACTION_SESSION_CLOSED,
            'reason' => 'Synthetic old audit marker', 'metadata' => ['preserve' => 'exact'], 'occurred_at' => now()->subDay()]);

        return compact('actor', 'session', 'guest');
    }

    private function resultImport(int $id, int $session, int $event, string $status, int $imported = 0): OfflineScannerResultImport
    {
        return OfflineScannerResultImport::factory()->create(['id' => $id, 'offline_scanner_session_id' => $session,
            'event_id' => $event, 'uploaded_by_user_id' => 215, 'status' => $status, 'imported_logs' => $imported]);
    }

    private function snapshot(): array
    {
        $db = DB::connection();
        $result = [];
        foreach ($db->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $rows = $db->table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($rows, static fn ($left, $right) => strcmp(json_encode($left), json_encode($right)));
            $result[$table->name] = $rows;
        }
        $result['_fake_files'] = [];
        foreach (Storage::disk(config('offline_scanner.disk'))->allFiles() as $file) {
            $result['_fake_files'][$file] = hash('sha256', Storage::disk(config('offline_scanner.disk'))->get($file));
        }
        ksort($result['_fake_files']);

        return $result;
    }
}
