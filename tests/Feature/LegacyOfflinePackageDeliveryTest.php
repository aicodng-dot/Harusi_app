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
use App\Services\Tenancy\LegacyOfflinePackageDelivery;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class LegacyOfflinePackageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const UNAVAILABLE = 'Unable to download the offline scanner package. Try the existing package again.';

    #[DataProvider('lateBoundaryChanges')]
    public function test_delivery_rechecks_late_authority_parent_and_package_policy_before_reading_bytes(string $mode, bool $platform, string $change, int $status): void
    {
        $f = $this->fixture($platform);
        $reader = $this->reader();
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
                'actor_active_two' => DB::table('users')->where('id', 211)->update(['is_active' => 2]),
                'actor_deleted' => $this->deleteActor(),
                'session_foreign_parent' => DB::table('offline_scanner_sessions')->where('id', 401)->update(['event_id' => 303]),
                'package_owned_parent' => DB::table('offline_scanner_packages')->where('id', 501)->update(['offline_scanner_session_id' => 402]),
                'package_other_same_event_session' => DB::table('offline_scanner_packages')->where('id', 501)->update(['offline_scanner_session_id' => 404]),
                'revoked' => DB::table('offline_scanner_packages')->where('id', 501)->update(['revoked_at' => now()]),
                'secure_deleted' => DB::table('offline_scanner_packages')->where('id', 501)->update(['secure_deleted_at' => now()]),
                'package_deleted' => DB::table('offline_scanner_packages')->where('id', 501)->delete(),
            };
            $expected = $this->snapshot();
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301, 'guest_import' => ['marker' => 'preserved']]);
        $this->requestDownload($mode)->assertStatus($status)->assertSessionHas('selected_event_id', 301)->assertSessionHas('guest_import.marker', 'preserved');
        $this->assertTrue($changed);
        $this->assertSame(0, $reader->calls);
        $this->assertSame($expected, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function lateBoundaryChanges(): array
    {
        $cases = [];
        foreach (['current', 'historical'] as $mode) {
            foreach ([false, true] as $platform) {
                foreach (['owned_event' => 404, 'actor_role' => 403, 'actor_active_two' => 403,
                    'actor_deleted' => 403, 'session_foreign_parent' => 404, 'package_owned_parent' => 404,
                    'package_other_same_event_session' => 404, 'revoked' => 403, 'secure_deleted' => 403,
                    'package_deleted' => 404] as $change => $status) {
                    $cases[$mode.' '.($platform ? 'platform' : 'staff').' '.$change] = [$mode, $platform, $change, $status];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('actors')]
    public function test_current_export_pointer_is_checked_again_after_the_request_preflight(bool $platform): void
    {
        $f = $this->fixture($platform);
        $reader = $this->reader();
        $changed = false;
        $expected = null;
        DB::connection()->beforeStartingTransaction(function () use (&$changed, &$expected): void {
            if ($changed) {
                return;
            }
            $changed = true;
            DB::table('offline_scanner_sessions')->where('id', 401)->update(['package_export_id' => 503]);
            $expected = $this->snapshot();
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301]);
        $this->requestDownload('current')->assertNotFound();
        $this->assertTrue($changed);
        $this->assertSame(0, $reader->calls);
        $this->assertSame($expected, $this->snapshot());
    }

    #[DataProvider('caseSensitiveActors')]
    public function test_fresh_actor_role_is_byte_exact_even_when_the_request_actor_was_admin(string $mode, bool $platform): void
    {
        $f = $this->fixture($platform);
        $reader = $this->reader();
        $changed = false;
        $expected = null;
        DB::connection()->beforeStartingTransaction(function () use (&$changed, &$expected): void {
            if ($changed) {
                return;
            }
            $changed = true;
            DB::table('users')->where('id', 211)->update(['role' => 'ADMIN']);
            $expected = $this->snapshot();
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301]);
        $this->requestDownload($mode)->assertForbidden();
        $this->assertTrue($changed);
        $this->assertSame(0, $reader->calls);
        $this->assertSame($expected, $this->snapshot());
    }

    public static function caseSensitiveActors(): array
    {
        return ['historical staff' => ['historical', false], 'current platform' => ['current', true]];
    }

    #[DataProvider('pathsAndActors')]
    public function test_success_keeps_exact_bytes_headers_and_only_changes_delivery_metadata_and_audit(string $mode, bool $platform): void
    {
        $f = $this->fixture($platform);
        $before = $this->snapshot();
        $queries = [];
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301]);
        $response = $this->queries($queries, fn () => $this->requestDownload($mode));
        $this->assertDownloadResponse($response, $f['contents'], $f['package']);
        $this->assertSuccessDelta($before, 501);
        $heads = array_values(array_filter($queries, static fn ($query): bool => $query['level'] >= 2
            && preg_match('/\bfrom\s+"(?:events|users|offline_scanner_sessions|offline_scanner_packages)"\s+where\s+/i', $query['sql'])));
        $this->assertGreaterThanOrEqual(4, count($heads));
        foreach ([['events', 301], ['users', 211], ['offline_scanner_sessions', 401], ['offline_scanner_packages', 501]] as $index => [$table, $id]) {
            $this->assertStringContainsString('from "'.$table.'"', $heads[$index]['sql']);
            $this->assertSame($id, $heads[$index]['id']);
        }
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    #[DataProvider('historicalStates')]
    public function test_historical_download_keeps_old_archived_expired_and_terminal_session_support(string $state, bool $platform): void
    {
        $f = $this->fixture($platform);
        DB::table('offline_scanner_sessions')->where('id', 401)->update(['package_export_id' => 503]);
        match ($state) {
            'archived' => DB::table('offline_scanner_packages')->where('id', 501)->update(['archived_at' => now()]),
            'expired' => DB::table('offline_scanner_sessions')->where('id', 401)->update(['package_expires_at' => now()->subDay()]),
            'closed' => DB::table('offline_scanner_sessions')->where('id', 401)->update(['status' => OfflineScannerSession::STATUS_CLOSED]),
            'cancelled' => DB::table('offline_scanner_sessions')->where('id', 401)->update(['status' => OfflineScannerSession::STATUS_CANCELLED]),
        };
        $before = $this->snapshot();
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301]);
        $response = $this->requestDownload('historical');
        $this->assertDownloadResponse($response, $f['contents'], $f['package']);
        $this->assertSuccessDelta($before, 501);
    }

    public static function historicalStates(): array
    {
        return ['archived staff' => ['archived', false], 'expired platform' => ['expired', true],
            'closed staff' => ['closed', false], 'cancelled platform' => ['cancelled', true]];
    }

    public function test_response_is_already_buffered_before_the_first_delivery_write(): void
    {
        $f = $this->fixture();
        $deleted = false;
        $beforeWrites = null;
        DB::connection()->beforeExecuting(function (string $sql) use ($f, &$deleted, &$beforeWrites): void {
            if (! $deleted && preg_match('/\A\s*update\s+"offline_scanner_packages"/i', $sql)) {
                $deleted = true;
                Storage::disk(config('offline_scanner.disk'))->delete($f['package']->file_path);
                $beforeWrites = $this->snapshot();
            }
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301]);
        $response = $this->requestDownload('historical');
        $this->assertTrue($deleted);
        $this->assertDownloadResponse($response, $f['contents'], $f['package']);
        Storage::disk(config('offline_scanner.disk'))->assertMissing($f['package']->file_path);
        $this->assertSuccessDelta($beforeWrites, 501);
    }

    #[DataProvider('sqlFaults')]
    public function test_precommit_sql_failure_conserves_metadata_audits_and_files(string $mode, string $stage): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $failed = false;
        $queries = [];
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($stage, &$failed): void {
            $matches = $stage === 'audit' ? preg_match('/\A\s*insert into "offline_scanner_session_audits"/i', $sql) : preg_match('/\A\s*update "offline_scanner_packages"/i', $sql);
            if ($matches) {
                $failed = true;
                throw new QueryException('sqlite', $sql, $bindings, new RuntimeException('private-delivery-sql-marker'));
            }
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301]);
        $response = $this->queries($queries, fn () => $this->requestDownload($mode));
        $this->assertTrue($failed);
        // On old code the completed metadata UPDATE survives the audit failure.
        $this->assertSame($before, $this->snapshot(), 'A failed delivery retained partial download metadata or audit effects.');
        $response->assertStatus(503)->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSee(self::UNAVAILABLE)->assertDontSee('private-delivery-sql-marker');
        $this->assertSame(self::UNAVAILABLE, $response->getContent());
        $writes = array_values(array_filter($queries, static fn ($q): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $q['sql'])));
        $this->assertCount($stage === 'audit' ? 1 : 0, $writes);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function sqlFaults(): array
    {
        return ['current audit' => ['current', 'audit'], 'historical audit' => ['historical', 'audit'],
            'current metadata' => ['current', 'metadata'], 'historical metadata' => ['historical', 'metadata']];
    }

    #[DataProvider('modes')]
    public function test_typed_deadlock_before_metadata_has_generic_feedback_without_completed_writes(string $mode): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $attempts = 0;
        $queries = [];
        DB::connection()->beforeExecuting(function (string $sql) use (&$attempts): void {
            if (preg_match('/\A\s*update "offline_scanner_packages"/i', $sql)) {
                $attempts++;
                throw new DeadlockException('Deadlock found when trying to get lock; try restarting transaction', 40001);
            }
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301]);
        $this->queries($queries, fn () => $this->requestDownload($mode))->assertStatus(503)->assertSee(self::UNAVAILABLE);
        $this->assertGreaterThanOrEqual(1, $attempts);
        $this->assertSame([], array_values(array_filter($queries, static fn ($q): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $q['sql']))));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    #[DataProvider('readerFaults')]
    public function test_file_reader_failure_precedes_delivery_writes_and_keeps_generic_feedback(string $fault, int $status): void
    {
        $f = $this->fixture();
        $reader = $this->reader();
        if ($fault === 'missing') {
            Storage::disk(config('offline_scanner.disk'))->delete($f['package']->file_path);
        } elseif ($fault === 'unsafe_path') {
            DB::table('offline_scanner_packages')->where('id', 501)->update(['file_path' => '../outside-fixture-file']);
        } else {
            $reader->fault = $fault;
        }
        $before = $this->snapshot();
        $queries = [];
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301]);
        $response = $this->queries($queries, fn () => $this->requestDownload('historical'));
        $response->assertStatus($status)->assertDontSee('private-delivery-reader-marker')->assertDontSee('../outside-fixture-file');
        if ($status === 503) {
            $response->assertSee(self::UNAVAILABLE);
        }
        $this->assertSame(1, $reader->calls);
        $this->assertSame([2], $reader->levels);
        $this->assertSame([], array_values(array_filter($queries, static fn ($q): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $q['sql']))));
        $this->assertSame($before, $this->snapshot());
    }

    public static function readerFaults(): array
    {
        return ['missing file' => ['missing', 503], 'reader exception' => ['runtime', 503],
            'unsafe historical path' => ['unsafe_path', 503], 'reader HTTP refusal preserved' => ['http', 404]];
    }

    #[DataProvider('badIdentities')]
    public function test_ambiguous_raw_identity_refuses_before_any_row_or_file_read(string $target, string $shape): void
    {
        $f = $this->fixture();
        $reader = $this->reader();
        $selected = 301;
        $id = match ($target) {
            'package' => 501, 'parent', 'current_session' => 401, 'actor' => 211, default => 301
        };
        $bad = match ($shape) {
            'zero' => 0, 'padded' => '0'.$id, default => '9223372036854775808'
        };
        if ($target === 'selected') {
            $selected = $bad;
        } else {
            $model = match ($target) {
                'package', 'parent' => $f['package'], 'actor' => $f['actor'], default => $f['session']
            };
            $key = match ($target) {
                'parent' => 'offline_scanner_session_id', 'current_event' => 'event_id', default => 'id'
            };
            $model->setRawAttributes(array_replace($model->getAttributes(), [$key => $bad]), true);
        }
        $before = $this->snapshot();
        $queries = [];
        $refused = false;
        try {
            $this->queries($queries, fn () => app(LegacyOfflinePackageDelivery::class)->download($f['package'], $f['actor'], $selected, $f['session']));
        } catch (HttpExceptionInterface $error) {
            $refused = true;
            $this->assertSame($target === 'actor' ? 403 : 404, $error->getStatusCode());
        }
        $this->assertTrue($refused);
        $this->assertSame([], $queries);
        $this->assertSame(0, $reader->calls);
        $this->assertSame($before, $this->snapshot());
    }

    public static function badIdentities(): array
    {
        $cases = [];
        foreach (['selected', 'package', 'parent', 'actor', 'current_session', 'current_event'] as $target) {
            foreach (['padded', 'overflow'] as $shape) {
                $cases[$target.' '.$shape] = [$target, $shape];
            }
        }
        foreach (['selected', 'package', 'parent', 'actor'] as $target) {
            $cases[$target.' zero'] = [$target, 'zero'];
        }

        return $cases;
    }

    #[DataProvider('actorAliases')]
    public function test_original_or_current_unsafe_actor_cannot_alias_an_actual_safe_account(string $variant): void
    {
        $f = $this->fixture();
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
            $this->queries($queries, fn () => app(LegacyOfflinePackageDelivery::class)->download($f['package'], $f['actor'], 301));
        } catch (HttpExceptionInterface $error) {
            $refused = true;
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertTrue($refused);
        $this->assertSame([], $queries);
        $this->assertSame($before, $this->snapshot());
    }

    public static function actorAliases(): array
    {
        return ['unsafe original' => ['original'], 'unsafe current' => ['current']];
    }

    #[DataProvider('postGenerationFaults')]
    public function test_delivery_failure_preserves_real_committed_generation_and_retry_uses_existing_package(string $fault): void
    {
        $f = $this->fixture(false, true);
        $reader = $this->reader();
        $armed = false;
        $captured = null;
        $dispatcher = DB::connection()->getEventDispatcher();
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed): void {
            if (str_starts_with($query->sql, 'insert into "offline_scanner_session_audits"') && in_array(OfflineScannerSessionAudit::ACTION_SESSION_ACTIVATED, $query->bindings, true)) {
                $armed = true;
            }
        });
        DB::connection()->beforeStartingTransaction(function () use (&$armed, &$captured, $fault, $reader): void {
            if (! $armed || $captured !== null || DB::connection()->transactionLevel() !== 1) {
                return;
            }
            if ($fault === 'owned_event') {
                DB::table('events')->where('id', 301)->update(['workspace_id' => 101]);
            } else {
                $reader->failNext = true;
            }
            $captured = $this->snapshot();
        });
        $this->actingAs($f['actor'])->withSession(['selected_event_id' => 301]);
        $this->post(route('admin.offline-scanner-sessions.store', 301), $this->generationInput())->assertStatus($fault === 'owned_event' ? 404 : 503);
        $this->assertNotNull($captured);
        $this->assertSame($captured, $this->snapshot());
        $session = OfflineScannerSession::query()->where('event_id', 301)->sole();
        $package = OfflineScannerPackage::query()->where('offline_scanner_session_id', $session->id)->sole();
        $this->assertSame('active', $session->status);
        $this->assertNull($package->downloaded_at);
        $this->assertSame(4, $session->audits()->count());
        $this->assertSame(1, DB::table('offline_scanner_package_guest_snapshots')->where('offline_scanner_session_id', $session->id)->count());
        $contents = Storage::disk(config('offline_scanner.disk'))->get($package->file_path);
        $this->assertSame($package->file_hash, hash('sha256', $contents));
        $uuid = $package->package_uuid;
        if ($fault === 'owned_event') {
            DB::table('events')->where('id', 301)->update(['workspace_id' => null]);
        }
        $beforeRetry = $this->snapshot();
        $response = $this->get(route('admin.offline-scanner-packages.download', $package));
        $this->assertDownloadResponse($response, $contents, $package);
        $this->assertSuccessDelta($beforeRetry, $package->id);
        $this->assertSame(1, OfflineScannerSession::query()->where('event_id', 301)->count());
        $this->assertSame(1, OfflineScannerPackage::query()->where('offline_scanner_session_id', $session->id)->count());
        $this->assertSame($uuid, $package->fresh()->package_uuid);
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function postGenerationFaults(): array
    {
        return ['authority changed after commit' => ['owned_event'], 'file read failed after commit' => ['reader']];
    }

    public static function actors(): array
    {
        return ['staff' => [false], 'platform member' => [true]];
    }

    public static function modes(): array
    {
        return ['current export' => ['current'], 'historical URL' => ['historical']];
    }

    public static function pathsAndActors(): array
    {
        return ['current staff' => ['current', false], 'current platform' => ['current', true], 'historical staff' => ['historical', false], 'historical platform' => ['historical', true]];
    }

    private function fixture(bool $platform = false, bool $forGeneration = false): array
    {
        Storage::fake(config('offline_scanner.disk'));
        config(['services.offline_scanner_package.signature_algorithm' => 'hmac-sha256',
            'services.offline_scanner_package.allow_hmac_fallback' => true, 'services.offline_scanner_package.hmac_key' => 'synthetic-delivery-key',
            'app.key' => 'base64:'.base64_encode(hash('sha256', 'synthetic-delivery-encryption-key', true))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic private package workspace']);
        foreach ([301 => null, 302 => 101, 303 => null] as $id => $workspace) {
            Event::factory()->create(['id' => $id, 'workspace_id' => $workspace, 'status' => 'active', 'event_name' => 'Synthetic package event '.$id]);
        }
        $actor = User::factory()->create(['id' => 211, 'role' => 'admin', 'is_active' => true, 'is_platform_admin' => $platform]);
        User::factory()->create(['id' => 215, 'role' => 'admin', 'is_active' => true]);
        foreach ([212, 213] as $id) {
            User::factory()->create(['id' => $id, 'role' => 'scanner', 'event_id' => 301, 'is_active' => true, 'is_platform_admin' => false]);
        }
        if ($platform) {
            DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 211, 'role' => 'owner', 'is_active' => true]);
        }
        foreach ([901 => 301, 902 => 302] as $id => $eventId) {
            Guest::query()->forceCreate(['id' => $id, 'event_id' => $eventId, 'name' => 'Synthetic private package guest '.$id,
                'phone_number' => $id === 901 ? '+255712345678' : '+255712345679', 'manual_code' => str_pad((string) $id, 4, '0', STR_PAD_LEFT), 'pass_type' => Guest::PASS_DOUBLE,
                'allowed_entries' => 2, 'used_entries' => 1, 'status' => 'partially_used']);
            QrCode::query()->create(['guest_id' => $id, 'qr_token' => Guest::makeSecureToken(), 'is_active' => true, 'generated_at' => now()]);
        }
        foreach ([402 => [302, 'closed'], 403 => [303, 'cancelled'], 404 => [301, 'cancelled']] as $id => [$event, $status]) {
            // Generation requires no existing Session for Event301.
            if ($forGeneration && $id === 404) {
                continue;
            }
            OfflineScannerSession::factory()->create(['id' => $id, 'event_id' => $event, 'created_by_user_id' => 215,
                'status' => $status, 'results_imported_at' => $status === 'closed' ? now()->subDay() : null]);
        }
        $session = null;
        $package = null;
        $contents = "invented-package-bytes\0\xff\n";
        if (! $forGeneration) {
            $session = OfflineScannerSession::factory()->create(['id' => 401, 'event_id' => 301, 'created_by_user_id' => 215,
                'master_scanner_user_id' => 212, 'status' => 'active', 'package_expires_at' => now()->addDay()]);
        }
        foreach ($forGeneration ? [502 => 402] : [501 => 401, 502 => 402, 503 => 401] as $id => $parent) {
            $p = OfflineScannerPackage::factory()->create(['id' => $id, 'offline_scanner_session_id' => $parent, 'generated_by_user_id' => 215,
                'file_name' => 'synthetic-'.$id.'.wqrpkg', 'file_path' => 'offline-packages/synthetic/'.$id.'.wqrpkg',
                'downloaded_at' => now()->subDay(), 'downloaded_by_user_id' => 215,
                'file_hash' => str_repeat('f', 64), 'package_fingerprint' => str_repeat('b', 64), 'encrypted_file_size' => strlen($contents)]);
            Storage::disk(config('offline_scanner.disk'))->put($p->file_path, $id === 502 ? 'invented-private-other-file' : $contents);
            if ($id === 501) {
                $package = $p;
            }
        }
        if (! $forGeneration) {
            DB::table('offline_scanner_sessions')->where('id', 401)->update(['package_export_id' => 501]);
            $session = $session->fresh();
        }
        DB::table('offline_scanner_sessions')->where('id', 402)->update(['package_export_id' => 502]);
        $import = OfflineScannerResultImport::factory()->create(['id' => 602, 'offline_scanner_session_id' => 402,
            'event_id' => 302, 'uploaded_by_user_id' => 215, 'status' => 'completed', 'imported_logs' => 1]);
        Storage::disk(config('offline_scanner.disk'))->put($import->encrypted_file_path, 'invented-private-result-file');
        OfflineScannerImportConflict::factory()->create(['id' => 702, 'offline_scanner_result_import_id' => 602,
            'offline_scanner_imported_log_id' => null, 'event_id' => 302, 'guest_id' => 902,
            'resolution_status' => 'resolved', 'resolved_by_user_id' => 215]);
        OfflineScannerSessionAudit::query()->forceCreate(['id' => 902, 'offline_scanner_session_id' => 402, 'event_id' => 302,
            'actor_user_id' => 215, 'action' => 'session_closed', 'metadata' => ['conserve' => true], 'occurred_at' => now()->subDay()]);

        return compact('actor', 'session', 'package', 'contents');
    }

    private function reader(): DeliveryFixtureReader
    {
        $reader = new DeliveryFixtureReader(app(OfflineScannerSessionAuditService::class));
        $this->app->instance(OfflineScannerFileStorage::class, $reader);

        return $reader;
    }

    private function deleteActor(): void
    {
        DB::table('workspace_users')->where('user_id', 211)->delete();
        DB::table('users')->where('id', 211)->delete();
    }

    private function requestDownload(string $mode): TestResponse
    {
        return $mode === 'current' ? $this->post(route('admin.offline-scanner-sessions.generate-package', 401), ['confirm_package_download' => '1'])
            : $this->get(route('admin.offline-scanner-packages.download', 501));
    }

    private function generationInput(): array
    {
        return ['master_scanner_user_id' => 212, 'master_gate_name' => 'Synthetic master gate', 'client_scanner_user_ids' => [213],
            'client_gate_names' => [213 => 'Synthetic client gate'], 'package_expires_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'package_password' => 'SyntheticPackage!123456', 'package_password_confirmation' => 'SyntheticPackage!123456', 'confirm_generation' => '1'];
    }

    private function assertDownloadResponse(TestResponse $response, string $contents, OfflineScannerPackage $package): void
    {
        $response->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('Content-Length', (string) strlen($contents))
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Offline-Package-Fingerprint', $package->package_fingerprint);
        $this->assertSame($contents, $response->getContent());
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString($package->file_name, $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    private function assertSuccessDelta(array $before, int $packageId): void
    {
        $after = $this->snapshot();
        $old = null;
        $current = null;
        foreach ($before['offline_scanner_packages'] as $row) {
            if ((int) $row['id'] === $packageId) {
                $old = $row;
            }
        }
        foreach ($after['offline_scanner_packages'] as $row) {
            if ((int) $row['id'] === $packageId) {
                $current = $row;
            }
        }
        $this->assertNotNull($old);
        $this->assertNotNull($current);
        $this->assertSame(211, (int) $current['downloaded_by_user_id']);
        $this->assertNotNull($current['downloaded_at']);
        foreach (['downloaded_at', 'downloaded_by_user_id', 'updated_at'] as $field) {
            $old[$field] = $current[$field];
        }
        $this->assertSame($old, $current);
        foreach ($before['offline_scanner_packages'] as &$row) {
            if ((int) $row['id'] === $packageId) {
                $row = $current;
            }
        } unset($row);
        $oldIds = array_column($before['offline_scanner_session_audits'], 'id');
        $extra = array_values(array_filter($after['offline_scanner_session_audits'], static fn ($row): bool => ! in_array($row['id'], $oldIds, true)));
        $retained = array_values(array_filter($after['offline_scanner_session_audits'], static fn ($row): bool => in_array($row['id'], $oldIds, true)));
        $this->assertSame($before['offline_scanner_session_audits'], $retained);
        $this->assertCount(1, $extra);
        $audit = $extra[0];
        $this->assertSame((int) $current['offline_scanner_session_id'], (int) $audit['offline_scanner_session_id']);
        $this->assertSame(301, (int) $audit['event_id']);
        $this->assertSame(211, (int) $audit['actor_user_id']);
        $this->assertSame('package_downloaded', $audit['action']);
        $this->assertSame(['package_uuid' => $current['package_uuid'], 'package_fingerprint' => $current['package_fingerprint'], 'file_hash' => $current['file_hash'],
            'file_size' => (int) $current['encrypted_file_size'], 'storage_disk' => $current['storage_disk'], 'file_path' => $current['file_path']], json_decode($audit['metadata'], true, 32, JSON_THROW_ON_ERROR));
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

class DeliveryFixtureReader extends OfflineScannerFileStorage
{
    public int $calls = 0;

    public array $levels = [];

    public string $fault = '';

    public bool $failNext = false;

    public function packageDownloadResponse(OfflineScannerPackage $package): Response
    {
        $this->calls++;
        $this->levels[] = DB::connection()->transactionLevel();
        if ($this->fault === 'http') {
            abort(404);
        }
        if ($this->fault === 'runtime' || $this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('private-delivery-reader-marker');
        }

        return parent::packageDownloadResponse($package);
    }
}
