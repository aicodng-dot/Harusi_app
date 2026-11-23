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
use App\Services\OfflineScannerFileStorage;
use App\Services\OfflineScannerSessionAuditService;
use App\Services\Tenancy\LegacyOfflineFileArchives;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class LegacyOfflineFileArchivesTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Synthetic archive reason.';

    #[DataProvider('lateBoundaryChanges')]
    public function test_archives_recheck_current_authority_parent_and_policy_without_additional_effects(string $kind, bool $platform, string $change, int $status): void
    {
        $f = $this->fixture($platform, $kind);
        $changed = false;
        $expected = null;
        DB::connection()->beforeStartingTransaction(function () use ($kind, $change, &$changed, &$expected): void {
            if ($changed) {
                return;
            }
            $changed = true;
            $table = $this->table($kind);
            $id = $this->resourceId($kind);
            match ($change) {
                'owned_event' => DB::table('events')->where('id', 301)->update(['workspace_id' => 101]),
                'actor_role' => DB::table('users')->where('id', 211)->update(['role' => 'customer']),
                'actor_uppercase_role' => DB::table('users')->where('id', 211)->update(['role' => 'ADMIN']),
                'actor_disabled' => DB::table('users')->where('id', 211)->update(['is_active' => false]),
                'actor_active_two' => DB::table('users')->where('id', 211)->update(['is_active' => 2]),
                'actor_deleted' => $this->deleteActor(),
                'session_owned_parent' => DB::table('offline_scanner_sessions')->where('id', 401)->update(['event_id' => 302]),
                'session_foreign_parent' => DB::table('offline_scanner_sessions')->where('id', 401)->update(['event_id' => 303]),
                'resource_owned_parent' => DB::table($table)->where('id', $id)->update(['offline_scanner_session_id' => 402]),
                'resource_same_event_parent' => DB::table($table)->where('id', $id)->update(['offline_scanner_session_id' => 404]),
                'resource_deleted' => DB::table($table)->where('id', $id)->delete(),
                'archived' => DB::table($table)->where('id', $id)->update(['archived_at' => now()->subMinute(), 'archived_by_user_id' => 215]),
                'secure_deleted' => DB::table($table)->where('id', $id)->update(['secure_deleted_at' => now()->subMinute(), 'secure_deleted_by_user_id' => 215]),
            };
            $expected = $this->snapshot();
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301, 'guest_import' => ['marker' => 'preserved']]);
        $this->requestArchive($kind)->assertStatus($status)
            ->assertSessionHas('selected_event_id', 301)->assertSessionHas('guest_import.marker', 'preserved')->assertSessionMissing('success');
        $this->assertTrue($changed);
        $this->assertSame([], $f['files']->archiveCalls);
        $this->assertSame(0, $f['files']->byteCalls);
        $this->assertSame($expected, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function lateBoundaryChanges(): array
    {
        $cases = [];
        foreach (['package', 'results'] as $kind) {
            foreach (['owned_event' => 404, 'actor_role' => 403, 'actor_uppercase_role' => 403,
                'actor_active_two' => 403, 'actor_deleted' => 403, 'session_owned_parent' => 404,
                'session_foreign_parent' => 404, 'resource_owned_parent' => 404, 'resource_same_event_parent' => 404,
                'resource_deleted' => 404, 'archived' => 403, 'secure_deleted' => 403] as $change => $status) {
                $cases[$kind.' staff '.$change] = [$kind, false, $change, $status];
            }
            foreach (['owned_event' => 404, 'actor_disabled' => 403] as $change => $status) {
                $cases[$kind.' platform '.$change] = [$kind, true, $change, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('lateResultEventParents')]
    public function test_results_archive_rechecks_its_own_event_link_after_the_request_preflight(?int $eventId): void
    {
        $f = $this->fixture(false, 'results');
        $changed = false;
        $expected = null;
        DB::connection()->beforeStartingTransaction(function () use ($eventId, &$changed, &$expected): void {
            if ($changed) {
                return;
            }
            $changed = true;
            DB::table('offline_scanner_result_imports')->where('id', 601)->update(['event_id' => $eventId]);
            $expected = $this->snapshot();
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301]);
        $this->requestArchive('results')->assertNotFound()->assertSessionMissing('success');
        $this->assertTrue($changed);
        $this->assertSame([], $f['files']->archiveCalls);
        $this->assertSame($expected, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function lateResultEventParents(): array
    {
        return ['missing Event' => [null], 'owned Event' => [302], 'other unowned Event' => [303]];
    }

    #[DataProvider('kindsAndActors')]
    public function test_success_delegates_with_current_locked_parent_and_preserves_exact_metadata_audit_and_bytes(string $kind, bool $platform): void
    {
        $f = $this->fixture($platform, $kind);
        $before = $this->snapshot();
        $queries = [];
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301, 'guest_import' => ['marker' => 'preserved']]);
        $response = $this->queries($queries, fn () => $this->requestArchive($kind));
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHas('success', $this->successMessage($kind))->assertSessionHasNoErrors()
            ->assertSessionHas('selected_event_id', 301)->assertSessionHas('guest_import.marker', 'preserved');
        $this->assertSuccessDelta($before, $kind, self::REASON);
        $this->assertSame([['kind' => $kind, 'resource' => $this->resourceId($kind), 'actor' => 211,
            'session' => 401, 'event' => 301, 'level' => 2]], $f['files']->archiveCalls);
        $this->assertSame(0, $f['files']->byteCalls);
        $heads = array_values(array_filter($queries, static fn ($q): bool => $q['level'] >= 2
            && preg_match('/\bfrom\s+"(?:events|users|offline_scanner_sessions|offline_scanner_packages|offline_scanner_result_imports)"\s+where\s+/i', $q['sql'])));
        $this->assertGreaterThanOrEqual(4, count($heads));
        foreach ([['events', 301], ['users', 211], ['offline_scanner_sessions', 401], [$this->table($kind), $this->resourceId($kind)]] as $index => [$table, $id]) {
            $this->assertStringContainsString('from "'.$table.'"', $heads[$index]['sql']);
            $this->assertSame($id, $heads[$index]['id']);
        }
        $sessionReads = array_values(array_filter($queries, static fn ($q): bool => $q['level'] >= 2
            && str_contains($q['sql'], 'from "offline_scanner_sessions"')));
        $this->assertCount(1, $sessionReads, 'The archive helper must use its supplied locked Session instead of rereading an ordinary relation.');
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function kindsAndActors(): array
    {
        return ['package staff' => ['package', false], 'package platform member' => ['package', true],
            'results staff' => ['results', false], 'results platform member' => ['results', true]];
    }

    #[DataProvider('compatibleStates')]
    public function test_archive_keeps_existing_eligibility_for_history_terminal_sessions_and_unavailable_bytes(string $kind, string $state): void
    {
        $f = $this->fixture(false, $kind);
        match ($state) {
            'revoked' => DB::table('offline_scanner_packages')->where('id', 501)->update(['revoked_at' => now()->subHour()]),
            'historical' => DB::table('offline_scanner_sessions')->where('id', 401)->update(['package_export_id' => 503]),
            'closed' => DB::table('offline_scanner_sessions')->where('id', 401)->update(['status' => OfflineScannerSession::STATUS_CLOSED, 'session_closed_at' => now()->subHour()]),
            'cancelled' => DB::table('offline_scanner_sessions')->where('id', 401)->update(['status' => OfflineScannerSession::STATUS_CANCELLED, 'session_closed_at' => now()->subHour()]),
            'failed' => DB::table('offline_scanner_result_imports')->where('id', 601)->update(['status' => OfflineScannerResultImport::STATUS_FAILED]),
            'missing_bytes' => Storage::disk(config('offline_scanner.disk'))->delete($kind === 'package' ? $f['resource']->file_path : $f['resource']->encrypted_file_path),
            'null_results_path' => DB::table('offline_scanner_result_imports')->where('id', 601)->update(['encrypted_file_path' => null, 'encrypted_file_size' => null, 'storage_disk' => '']),
        };
        $before = $this->snapshot();
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301]);
        $this->requestArchive($kind)->assertRedirect()->assertSessionHas('success', $this->successMessage($kind))->assertSessionHasNoErrors();
        $this->assertSuccessDelta($before, $kind, self::REASON);
        $this->assertCount(1, $f['files']->archiveCalls);
        $this->assertSame(0, $f['files']->byteCalls);
    }

    public static function compatibleStates(): array
    {
        return ['revoked package' => ['package', 'revoked'], 'historical package' => ['package', 'historical'],
            'closed package Session' => ['package', 'closed'], 'missing package bytes' => ['package', 'missing_bytes'],
            'failed results' => ['results', 'failed'], 'cancelled results Session' => ['results', 'cancelled'],
            'missing results bytes' => ['results', 'missing_bytes'], 'nullable results metadata' => ['results', 'null_results_path']];
    }

    #[DataProvider('sqlFaults')]
    public function test_precommit_sql_failure_rolls_back_archive_metadata_and_audit_with_generic_form_feedback(string $kind, string $stage): void
    {
        $f = $this->fixture(false, $kind);
        $before = $this->snapshot();
        $failed = false;
        $queries = [];
        $table = $this->table($kind);
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($stage, $table, &$failed): void {
            $matches = $stage === 'audit' ? preg_match('/\A\s*insert into "offline_scanner_session_audits"/i', $sql)
                : preg_match('/\A\s*update "'.preg_quote($table, '/').'"/i', $sql);
            if ($matches) {
                $failed = true;
                throw new QueryException(DB::connection()->getName(), $sql, $bindings, new RuntimeException('private-archive-sql-marker'));
            }
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301, 'guest_import' => ['marker' => 'preserved']]);
        $response = $this->queries($queries, fn () => $this->requestArchive($kind));
        $this->assertTrue($failed);
        // Old controllers retain the completed archive UPDATE when audit INSERT fails.
        $this->assertSame($before, $this->snapshot(), 'Failed archive retained partial metadata, audits, or file changes.');
        $response->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHasErrors([$this->errorKey($kind) => $this->failureMessage($kind)])
            ->assertSessionMissing('success')->assertSessionHasInput('archive_reason', self::REASON)
            ->assertSessionMissing('_old_input.confirm_archive')->assertSessionMissing('_old_input.private_form_marker')
            ->assertSessionHas('selected_event_id', 301)->assertSessionHas('guest_import.marker', 'preserved');
        $this->assertStringNotContainsString('private-archive-sql-marker', json_encode(session('errors')->getBag('default')->all()));
        $completed = array_values(array_filter($queries, static fn ($q): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $q['sql'])));
        $this->assertCount($stage === 'audit' ? 1 : 0, $completed);
        $this->assertSame(0, $f['files']->byteCalls);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function sqlFaults(): array
    {
        return ['package metadata' => ['package', 'metadata'], 'package audit' => ['package', 'audit'],
            'results metadata' => ['results', 'metadata'], 'results audit' => ['results', 'audit']];
    }

    #[DataProvider('kinds')]
    public function test_typed_deadlock_before_metadata_is_generic_and_has_no_completed_writes(string $kind): void
    {
        $f = $this->fixture(false, $kind);
        $before = $this->snapshot();
        $attempts = 0;
        $table = $this->table($kind);
        DB::connection()->beforeExecuting(function (string $sql) use ($table, &$attempts): void {
            if (preg_match('/\A\s*update "'.preg_quote($table, '/').'"/i', $sql)) {
                $attempts++;
                throw new DeadlockException('Deadlock found when trying to get lock; try restarting transaction', 40001);
            }
        });
        $queries = [];
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301]);
        $this->queries($queries, fn () => $this->requestArchive($kind))
            ->assertRedirect(route('admin.offline-scanner-sessions.show', 401))
            ->assertSessionHasErrors([$this->errorKey($kind) => $this->failureMessage($kind)])->assertSessionMissing('success');
        $this->assertGreaterThanOrEqual(1, $attempts);
        $this->assertSame([], array_values(array_filter($queries, static fn ($q): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $q['sql']))));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    #[DataProvider('badIdentities')]
    public function test_direct_archive_refuses_ambiguous_raw_identity_before_any_row_query(string $kind, string $target, string $shape): void
    {
        $f = $this->fixture(false, $kind);
        $selected = 301;
        $id = match ($target) {
            'resource' => $this->resourceId($kind), 'parent' => 401, 'actor' => 211, default => 301,
        };
        $bad = match ($shape) {
            'null' => null, 'zero' => 0, 'negative' => -1, 'float' => 301.0, 'boolean' => true,
            'padded' => '0'.$id, 'overflow' => '9223372036854775808', 'scientific' => '301e0', 'signed' => '+301',
        };
        if ($target === 'selected') {
            $selected = $bad;
        } else {
            $model = $target === 'actor' ? $f['actor'] : $f['resource'];
            $key = match ($target) {
                'parent' => 'offline_scanner_session_id', 'resource_event' => 'event_id', default => 'id',
            };
            $model->setRawAttributes(array_replace($model->getAttributes(), [$key => $bad]), true);
        }
        $before = $this->snapshot();
        $queries = [];
        $refused = false;
        try {
            $this->queries($queries, fn () => app(LegacyOfflineFileArchives::class)->archive($f['resource'], $f['actor'], $selected, self::REASON));
        } catch (HttpExceptionInterface $error) {
            $refused = true;
            $this->assertSame($target === 'actor' ? 403 : 404, $error->getStatusCode());
        }
        $this->assertTrue($refused);
        $this->assertSame([], $queries);
        $this->assertSame([], $f['files']->archiveCalls);
        $this->assertSame($before, $this->snapshot());
    }

    public static function badIdentities(): array
    {
        $cases = [];
        foreach (['null', 'zero', 'negative', 'float', 'boolean', 'padded', 'overflow', 'scientific', 'signed'] as $shape) {
            $cases['selected '.$shape] = ['package', 'selected', $shape];
        }
        foreach (['package', 'results'] as $kind) {
            foreach (['resource', 'parent'] as $target) {
                foreach (['padded', 'overflow'] as $shape) {
                    $cases[$kind.' '.$target.' '.$shape] = [$kind, $target, $shape];
                }
            }
        }
        foreach (['padded', 'overflow'] as $shape) {
            $cases['actor '.$shape] = ['package', 'actor', $shape];
            $cases['results Event '.$shape] = ['results', 'resource_event', $shape];
        }

        return $cases;
    }

    #[DataProvider('unsafeActorAliases')]
    public function test_original_or_current_actor_overflow_cannot_alias_an_actual_safe_account(string $kind, string $variant): void
    {
        $f = $this->fixture(false, $kind);
        User::factory()->create(['id' => PHP_INT_MAX, 'role' => 'admin', 'is_active' => true]);
        if ($variant === 'original') {
            $f['actor']->setRawAttributes(array_replace($f['actor']->getAttributes(), ['id' => '9223372036854775808']), true);
            $f['actor']->setAttribute('id', PHP_INT_MAX);
        } else {
            $f['actor']->setAttribute('id', '9223372036854775808');
        }
        $this->assertSame(PHP_INT_MAX, $f['actor']->getKey());
        $before = $this->snapshot();
        $queries = [];
        $refused = false;
        try {
            $this->queries($queries, fn () => app(LegacyOfflineFileArchives::class)->archive($f['resource'], $f['actor'], 301, self::REASON));
        } catch (HttpExceptionInterface $error) {
            $refused = true;
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertTrue($refused);
        $this->assertSame([], $queries);
        $this->assertSame($before, $this->snapshot());
    }

    public static function unsafeActorAliases(): array
    {
        return ['package unsafe original' => ['package', 'original'], 'package unsafe current' => ['package', 'current'],
            'results unsafe original' => ['results', 'original'], 'results unsafe current' => ['results', 'current']];
    }

    #[DataProvider('kinds')]
    public function test_current_safe_actor_id_cannot_retarget_an_original_different_admin(string $kind): void
    {
        $f = $this->fixture(false, $kind);
        $f['actor']->setAttribute('id', 215);
        $before = $this->snapshot();
        $queries = [];
        $refused = false;
        try {
            $this->queries($queries, fn () => app(LegacyOfflineFileArchives::class)->archive($f['resource'], $f['actor'], 301, self::REASON));
        } catch (HttpExceptionInterface $error) {
            $refused = true;
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertTrue($refused);
        $this->assertSame([], $queries);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('kinds')]
    public function test_canonical_string_identities_are_accepted_and_service_preserves_exact_reason(string $kind): void
    {
        $f = $this->fixture(false, $kind);
        $attributes = ['id' => (string) $this->resourceId($kind), 'offline_scanner_session_id' => '401'];
        if ($kind === 'results') {
            $attributes['event_id'] = '301';
        }
        $f['resource']->setRawAttributes(array_replace($f['resource']->getAttributes(), $attributes), true);
        $f['actor']->setRawAttributes(array_replace($f['actor']->getAttributes(), ['id' => '211']), true);
        $before = $this->snapshot();
        $reason = '  Direct archive reason preserved exactly.  ';
        $result = app(LegacyOfflineFileArchives::class)->archive($f['resource'], $f['actor'], '301', $reason);
        $this->assertSame($kind === 'package' ? OfflineScannerPackage::class : OfflineScannerResultImport::class, $result::class);
        $this->assertSame($this->resourceId($kind), $result->getKey());
        $this->assertNotNull($result->archived_at);
        $this->assertSame(211, (int) $result->archived_by_user_id);
        $this->assertSuccessDelta($before, $kind, $reason);
        $this->assertSame(0, $f['files']->byteCalls);
    }

    #[DataProvider('invalidForms')]
    public function test_archive_reason_and_confirmation_validation_finish_before_the_transaction(string $kind, string $variant, array $errors): void
    {
        $f = $this->fixture(false, $kind);
        $input = match ($variant) {
            'missing' => [],
            'unconfirmed' => ['archive_reason' => self::REASON, 'confirm_archive' => '0'],
            'array_reason' => ['archive_reason' => ['private-invalid-reason-marker'], 'confirm_archive' => '1'],
            'long_reason' => ['archive_reason' => str_repeat('r', 2001), 'confirm_archive' => '1'],
            'whitespace' => ['archive_reason' => '   ', 'confirm_archive' => '1'],
        };
        $before = $this->snapshot();
        $started = false;
        DB::connection()->beforeStartingTransaction(function () use (&$started): void {
            $started = true;
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301, 'guest_import' => ['marker' => 'preserved']]);
        $this->requestArchive($kind, $input)->assertSessionHasErrors($errors)->assertSessionMissing('success')
            ->assertSessionHas('selected_event_id', 301)->assertSessionHas('guest_import.marker', 'preserved');
        $this->assertFalse($started);
        $this->assertSame([], $f['files']->archiveCalls);
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidForms(): array
    {
        $cases = [];
        foreach (['package', 'results'] as $kind) {
            foreach (['missing' => ['archive_reason', 'confirm_archive'], 'unconfirmed' => ['confirm_archive'],
                'array_reason' => ['archive_reason'], 'long_reason' => ['archive_reason'], 'whitespace' => ['archive_reason']] as $variant => $errors) {
                $cases[$kind.' '.$variant] = [$kind, $variant, $errors];
            }
        }

        return $cases;
    }

    public static function kinds(): array
    {
        return ['package' => ['package'], 'results' => ['results']];
    }

    private function fixture(bool $platform, string $kind): array
    {
        Storage::fake(config('offline_scanner.disk'));
        config(['offline_scanner.retention.retain_completed_days' => 19]);
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic private archive workspace']);
        foreach ([301 => null, 302 => 101, 303 => null] as $id => $workspace) {
            Event::factory()->create(['id' => $id, 'workspace_id' => $workspace, 'event_name' => 'Synthetic archive Event '.$id]);
        }
        $actor = User::factory()->create(['id' => 211, 'role' => 'admin', 'is_active' => true, 'is_platform_admin' => $platform]);
        User::factory()->create(['id' => 215, 'role' => 'admin', 'is_active' => true]);
        User::factory()->create(['id' => 212, 'role' => 'scanner', 'event_id' => 301, 'is_active' => true]);
        if ($platform) {
            DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 211, 'role' => 'owner', 'is_active' => true]);
        }
        foreach ([901 => 301, 902 => 302] as $id => $eventId) {
            Guest::query()->forceCreate(['id' => $id, 'event_id' => $eventId, 'name' => 'Synthetic private archive Guest '.$id,
                'phone_number' => $id === 901 ? '+255712345678' : '+255712345679', 'manual_code' => str_pad((string) $id, 4, '0', STR_PAD_LEFT),
                'pass_type' => Guest::PASS_DOUBLE, 'allowed_entries' => 2, 'used_entries' => 1, 'status' => 'partially_used']);
            QrCode::query()->create(['guest_id' => $id, 'qr_token' => Guest::makeSecureToken(), 'is_active' => true, 'generated_at' => now()]);
        }
        foreach ([401 => [301, 'active'], 402 => [302, 'closed'], 403 => [303, 'cancelled'], 404 => [301, 'cancelled']] as $id => [$eventId, $status]) {
            OfflineScannerSession::factory()->create(['id' => $id, 'event_id' => $eventId, 'created_by_user_id' => 215,
                'master_scanner_user_id' => $id === 401 ? 212 : null, 'status' => $status,
                'session_started_at' => now()->subHours(2), 'session_closed_at' => $status === 'active' ? null : now()->subHour(),
                'results_imported_at' => $status === 'closed' ? now()->subHour() : null]);
        }
        foreach ([501 => 401, 502 => 402, 503 => 401] as $id => $parent) {
            $package = OfflineScannerPackage::factory()->create(['id' => $id, 'offline_scanner_session_id' => $parent, 'generated_by_user_id' => 215,
                'file_name' => 'synthetic-'.$id.'.wqrpkg', 'file_path' => 'offline-packages/synthetic/'.$id.'.wqrpkg',
                'file_hash' => str_repeat('a', 64), 'encrypted_file_size' => 41,
                'downloaded_at' => now()->subHour(), 'downloaded_by_user_id' => 215]);
            Storage::disk(config('offline_scanner.disk'))->put($package->file_path, 'invented-private-package-bytes-'.$id);
        }
        DB::table('offline_scanner_sessions')->where('id', 401)->update(['package_export_id' => 501]);
        DB::table('offline_scanner_sessions')->where('id', 402)->update(['package_export_id' => 502]);
        foreach ([601 => [401, 301, 'preview_ready'], 602 => [402, 302, 'completed']] as $id => [$session, $eventId, $status]) {
            $import = OfflineScannerResultImport::factory()->create(['id' => $id, 'offline_scanner_session_id' => $session,
                'event_id' => $eventId, 'uploaded_by_user_id' => 215, 'status' => $status,
                'encrypted_file_path' => 'offline-results/synthetic/'.$id.'.wqrresults', 'file_hash' => str_repeat('b', 64),
                'encrypted_file_size' => 51, 'imported_logs' => $id === 602 ? 1 : 0]);
            Storage::disk(config('offline_scanner.disk'))->put($import->encrypted_file_path, 'invented-private-results-bytes-'.$id);
        }
        OfflineScannerImportConflict::factory()->create(['id' => 702, 'offline_scanner_result_import_id' => 602,
            'offline_scanner_imported_log_id' => null, 'event_id' => 302, 'guest_id' => 902,
            'resolution_status' => 'resolved', 'resolved_by_user_id' => 215, 'resolved_at' => now()->subHour()]);
        OfflineScannerSessionAudit::query()->forceCreate(['id' => 902, 'offline_scanner_session_id' => 402, 'event_id' => 302,
            'actor_user_id' => 215, 'action' => OfflineScannerSessionAudit::ACTION_SESSION_CLOSED,
            'reason' => 'Synthetic retained history', 'metadata' => ['conserve' => 'exact'], 'occurred_at' => now()->subHour()]);
        $resource = $kind === 'package' ? OfflineScannerPackage::query()->findOrFail(501) : OfflineScannerResultImport::query()->findOrFail(601);
        $files = new ArchiveFixtureStorage(app(OfflineScannerSessionAuditService::class));
        $this->app->instance(OfflineScannerFileStorage::class, $files);

        return compact('actor', 'resource', 'files');
    }

    private function requestArchive(string $kind, ?array $input = null): TestResponse
    {
        return $this->from(route('admin.offline-scanner-sessions.show', 401))->post(
            route($kind === 'package' ? 'admin.offline-scanner-packages.archive' : 'admin.offline-scanner-results.archive', $this->resourceId($kind)),
            $input ?? ['archive_reason' => '  '.self::REASON.'  ', 'confirm_archive' => '1', 'private_form_marker' => 'never-reflash-this-marker'],
        );
    }

    private function deleteActor(): void
    {
        DB::table('workspace_users')->where('user_id', 211)->delete();
        DB::table('users')->where('id', 211)->delete();
    }

    private function table(string $kind): string
    {
        return $kind === 'package' ? 'offline_scanner_packages' : 'offline_scanner_result_imports';
    }

    private function resourceId(string $kind): int
    {
        return $kind === 'package' ? 501 : 601;
    }

    private function errorKey(string $kind): string
    {
        return $kind === 'package' ? 'offline_package' : 'offline_results';
    }

    private function failureMessage(string $kind): string
    {
        return $kind === 'package' ? 'Unable to archive the offline scanner package.' : 'Unable to archive the offline scanner results file.';
    }

    private function successMessage(string $kind): string
    {
        return $kind === 'package' ? 'Offline scanner package file archived according to the retention policy.'
            : 'Offline scanner results file archived according to the retention policy.';
    }

    private function assertSuccessDelta(array $before, string $kind, string $reason): void
    {
        $after = $this->snapshot();
        $table = $this->table($kind);
        $id = $this->resourceId($kind);
        $old = array_values(array_filter($before[$table], static fn ($row): bool => (int) $row['id'] === $id))[0];
        $current = array_values(array_filter($after[$table], static fn ($row): bool => (int) $row['id'] === $id))[0];
        $this->assertNotNull($current['archived_at']);
        $this->assertSame(211, (int) $current['archived_by_user_id']);
        foreach (['archived_at', 'archived_by_user_id', 'updated_at'] as $field) {
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
        $this->assertSame(OfflineScannerSessionAudit::ACTION_FILE_ARCHIVED, $audit['action']);
        $this->assertSame($reason, $audit['reason']);
        $this->assertNotNull($audit['occurred_at']);
        $metadata = $kind === 'package' ? ['file_type' => 'package', 'package_uuid' => $current['package_uuid']]
            : ['file_type' => 'results', 'import_uuid' => $current['import_uuid'], 'results_export_id' => $current['results_export_id']];
        $metadata += ['file_hash' => $current['file_hash'], 'file_size' => $current['encrypted_file_size'] === null ? null : (int) $current['encrypted_file_size'],
            'storage_disk' => $current['storage_disk'], 'file_path' => $current[$kind === 'package' ? 'file_path' : 'encrypted_file_path'], 'retention_days' => 19];
        $this->assertSame($metadata, json_decode($audit['metadata'], true, 32, JSON_THROW_ON_ERROR));
        $before['offline_scanner_session_audits'] = $after['offline_scanner_session_audits'];
        $this->assertSame($before, $after);
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $contents = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($contents, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $rows[$table->name] = $contents;
        }
        $files = Storage::disk(config('offline_scanner.disk'))->allFiles();
        sort($files);
        $rows['_files'] = [];
        foreach ($files as $file) {
            $rows['_files'][$file] = hash('sha256', Storage::disk(config('offline_scanner.disk'))->get($file));
        }

        return $rows;
    }

    private function queries(array &$queries, callable $callback): mixed
    {
        $db = DB::connection();
        $old = $db->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = ['sql' => $event->sql, 'id' => $event->bindings[0] ?? null, 'level' => $event->connection->transactionLevel()];
        });
        $db->setEventDispatcher($dispatcher);
        try {
            return $callback();
        } finally {
            $db->setEventDispatcher($old);
        }
    }
}

class ArchiveFixtureStorage extends OfflineScannerFileStorage
{
    public array $archiveCalls = [];

    public int $byteCalls = 0;

    public function archivePackage(OfflineScannerPackage $package, User $actor, string $reason, ?OfflineScannerSession $currentSession = null): OfflineScannerPackage
    {
        $this->capture('package', $package->getKey(), $actor, $currentSession);

        return parent::archivePackage($package, $actor, $reason, $currentSession);
    }

    public function archiveResultImport(OfflineScannerResultImport $resultImport, User $actor, string $reason, ?OfflineScannerSession $currentSession = null): OfflineScannerResultImport
    {
        $this->capture('results', $resultImport->getKey(), $actor, $currentSession);

        return parent::archiveResultImport($resultImport, $actor, $reason, $currentSession);
    }

    public function put(string $path, string $contents, ?string $disk = null): void
    {
        $this->byteCalls++;
        throw new RuntimeException('Archive must not write file bytes.');
    }

    public function get(string $path, ?string $disk = null): string
    {
        $this->byteCalls++;
        throw new RuntimeException('Archive must not read file bytes.');
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        $this->byteCalls++;
        throw new RuntimeException('Archive must not inspect file bytes.');
    }

    public function delete(string $path, ?string $disk = null): bool
    {
        $this->byteCalls++;
        throw new RuntimeException('Archive must not delete file bytes.');
    }

    private function capture(string $kind, int $resourceId, User $actor, ?OfflineScannerSession $session): void
    {
        $this->archiveCalls[] = ['kind' => $kind, 'resource' => $resourceId, 'actor' => $actor->getKey(),
            'session' => $session?->getKey(), 'event' => $session?->getRawOriginal('event_id'),
            'level' => DB::connection()->transactionLevel()];
    }
}
