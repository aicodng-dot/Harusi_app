<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use App\Services\OfflineScannerPackageBuilder;
use App\Services\Tenancy\LegacyOfflinePackageGuard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class LegacyOfflinePackageGenerationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('privateScanners')]
    public function test_package_form_never_lists_private_legacy_scanner_identity(string $protection): void
    {
        $f = $this->fixture();
        if ($protection === 'platform') {
            DB::table('users')->where('id', 312)->update(['is_platform_admin' => true]);
        } else {
            DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 312, 'role' => 'owner', 'is_active' => false]);
        }
        $this->actingAs($f['actor'])->get(route('admin.offline-scanner-sessions.create', $f['event']))
            ->assertOk()->assertDontSee('Private Master Identity');
    }

    public static function privateScanners(): array
    {
        return ['platform target' => ['platform'], 'inactive member target' => ['member']];
    }

    #[DataProvider('protectedTargets')]
    public function test_forged_private_scanner_is_rejected_by_request_validation(string $target, string $protection): void
    {
        $f = $this->fixture();
        $id = $target === 'master' ? 312 : 311;
        $this->protect($id, $protection);
        $before = $this->snapshot();
        $this->actingAs($f['actor'])->post(route('admin.offline-scanner-sessions.store', $f['event']), $this->webInput())
            ->assertSessionHasErrors($target === 'master' ? 'master_scanner_user_id' : 'client_scanner_user_ids.0');
        $this->assertSame($before, $this->snapshot());
    }

    public static function protectedTargets(): array
    {
        $cases = [];
        foreach (['master', 'client'] as $target) {
            foreach (['member_active', 'member_inactive', 'platform', 'role_case', 'active_two', 'platform_two'] as $protection) {
                $cases[$target.' '.$protection] = [$target, $protection];
            }
        }

        return $cases;
    }

    #[DataProvider('lateChanges')]
    public function test_generation_rechecks_parent_actor_and_scanner_after_request_validation(string $change, int $status): void
    {
        $f = $this->fixture();
        $changed = false;
        $expected = null;
        DB::connection()->beforeStartingTransaction(function () use ($change, &$changed, &$expected): void {
            if ($changed) {
                return;
            }
            $changed = true;
            match ($change) {
                'owned_event' => DB::table('events')->where('id', 301)->update(['workspace_id' => 101]),
                'actor_disabled' => DB::table('users')->where('id', 211)->update(['is_active' => false]),
                'actor_active_two' => DB::table('users')->where('id', 211)->update(['is_active' => 2]),
                'actor_role' => DB::table('users')->where('id', 211)->update(['role' => 'customer']),
                'actor_deleted' => DB::table('users')->where('id', 211)->delete(),
                'master_role' => DB::table('users')->where('id', 312)->update(['role' => 'customer']),
                'master_disabled' => DB::table('users')->where('id', 312)->update(['is_active' => false]),
                'master_event' => DB::table('users')->where('id', 312)->update(['event_id' => 302]),
                'master_platform' => $this->protect(312, 'platform'),
                'master_member' => $this->protect(312, 'member_inactive'),
                'client_member' => $this->protect(311, 'member_active'),
            };
            $expected = $this->snapshot();
        });
        $response = $this->actingAs($f['actor'])->post(route('admin.offline-scanner-sessions.store', $f['event']), $this->webInput());
        $response->assertStatus($status);
        if ($status === 302) {
            $response->assertSessionHasErrors(['offline_session' => 'Choose a valid scanner user assigned to this event.']);
        }
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function lateChanges(): array
    {
        return ['owned parent' => ['owned_event', 404], 'actor disabled' => ['actor_disabled', 403],
            'actor noncanonical active' => ['actor_active_two', 403], 'actor role' => ['actor_role', 403], 'actor removed' => ['actor_deleted', 403],
            'master role' => ['master_role', 302], 'master disabled' => ['master_disabled', 302], 'master assignment' => ['master_event', 302],
            'master platform' => ['master_platform', 302], 'master inactive membership' => ['master_member', 302],
            'client membership' => ['client_member', 302]];
    }

    #[DataProvider('actors')]
    public function test_valid_generation_keeps_existing_format_and_holds_sorted_accounts_before_memberships(bool $platform): void
    {
        $f = $this->fixture();
        DB::table('users')->where('id', 211)->update(['is_platform_admin' => $platform]);
        if ($platform) {
            DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 211, 'role' => 'owner', 'is_active' => true]);
        }
        $before = $this->snapshot();
        $queries = [];
        $export = $this->queries($queries, fn () => app(OfflineScannerPackageBuilder::class)->generate($f['event'], $f['actor'], $this->builderInput()));
        $heads = array_values(array_filter($queries, static fn ($q): bool => $q['level'] === 2
            && preg_match('/\bfrom\s+"users"\s+where\s+"users"\."id"\s*=\s*\?/i', $q['sql']) === 1));
        $this->assertSame([211, 311, 312], array_column(array_slice($heads, 0, 3), 'id'));
        $membership = array_values(array_filter($queries, static fn ($q): bool => str_contains($q['sql'], 'from "workspace_users"')));
        $this->assertCount(2, $membership);
        $firstMembership = array_search($membership[0], $queries, true);
        $this->assertGreaterThan(array_search($heads[2], $queries, true), $firstMembership);
        $this->assertStringNotContainsString('exists', strtolower($membership[0]['sql']));
        $this->assertSame(1, DB::table('offline_scanner_sessions')->count());
        $this->assertSame(1, DB::table('offline_scanner_packages')->count());
        $this->assertSame(2, DB::table('offline_scanner_session_users')->count());
        $this->assertSame('active', $export->session->status);
        $this->assertSame(hash('sha256', $export->contents), $export->package->file_hash);
        $this->assertSame($export->contents, Storage::disk(config('offline_scanner.disk'))->get($export->package->file_path));
        foreach (['users', 'workspaces', 'workspace_users', 'events', 'guests', 'qr_codes', 'personal_access_tokens', 'password_reset_tokens'] as $table) {
            $this->assertSame($before[$table], $this->snapshot()[$table]);
        }
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function actors(): array
    {
        return ['legacy admin' => [false], 'platform flag and actor membership grant no owned bypass' => [true]];
    }

    public function test_database_failure_after_file_write_rolls_back_and_cleans_only_the_new_file(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failed): void {
            if (preg_match('/\Ainsert into "offline_scanner_packages"/i', $sql)) {
                $failed = true;
                throw new QueryException('sqlite', 'private-package-sql', ['private-name-binding'], new \RuntimeException('private-database-secret'));
            }
        });
        $response = $this->actingAs($f['actor'])->post(route('admin.offline-scanner-sessions.store', $f['event']), $this->webInput());
        $response->assertStatus(302)->assertSessionHasErrors(['offline_session' => 'Unable to generate the encrypted offline scanner package.'])
            ->assertSessionMissing('_old_input.package_password')->assertSessionMissing('_old_input.package_password_confirmation');
        $this->assertTrue($failed);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    #[DataProvider('badIds')]
    public function test_bad_master_or_client_id_refuses_before_any_row_query(mixed $bad, string $target): void
    {
        $f = $this->fixture();
        $input = $this->builderInput();
        if ($target === 'master') {
            $input['master_scanner_user_id'] = $bad;
        } else {
            $input['clients'][0]['scanner_user_id'] = $bad;
        }
        $before = $this->snapshot();
        $queries = [];
        try {
            $this->queries($queries, fn () => app(OfflineScannerPackageBuilder::class)->generate($f['event'], $f['actor'], $input));
            $this->fail('An ambiguous scanner ID was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertContains($exception->getMessage(), ['Choose a valid scanner user assigned to this event.',
                'Client scanner users must be unique and cannot include the Master scanner.']);
        }
        $this->assertSame([], $queries);
        $this->assertSame($before, $this->snapshot());
    }

    public static function badIds(): array
    {
        $cases = [];
        foreach (['master', 'client'] as $target) {
            foreach ([null, 0, -1, true, 312.0, '0312', '312x', '9223372036854775808', []] as $index => $value) {
                $cases[$target.' '.$index] = [$value, $target];
            }
        }

        return $cases;
    }

    public function test_near_php_max_scanner_locks_use_exact_integer_order(): void
    {
        $f = $this->fixture();
        DB::table('users')->where('id', 311)->update(['id' => PHP_INT_MAX - 1]);
        DB::table('users')->where('id', 312)->update(['id' => PHP_INT_MAX]);
        $input = $this->builderInput();
        $input['master_scanner_user_id'] = PHP_INT_MAX;
        $input['clients'][0]['scanner_user_id'] = PHP_INT_MAX - 1;
        $before = $this->snapshot();
        $queries = [];
        $guarded = $this->queries($queries, fn () => DB::transaction(fn () => app(LegacyOfflinePackageGuard::class)->lock($f['event'], $f['actor'], $input)));
        $heads = array_values(array_filter($queries, static fn ($q): bool => str_contains($q['sql'], 'from "users"')));
        $this->assertSame([211, PHP_INT_MAX - 1, PHP_INT_MAX], array_column($heads, 'id'));
        $this->assertSame(PHP_INT_MAX, $guarded['master']->id);
        $this->assertSame(PHP_INT_MAX - 1, $guarded['clients'][0]['user']->id);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('inconsistentActorIdentities')]
    public function test_direct_generation_guard_rejects_inconsistent_original_and_current_actor_identity_before_queries(string $variant): void
    {
        $f = $this->fixture();
        if ($variant === 'unsafe_original') {
            User::factory()->create(['id' => PHP_INT_MAX, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
            $original = '9223372036854775808';
            $current = PHP_INT_MAX;
        } elseif ($variant === 'padded_original') {
            $original = '0211';
            $current = 211;
        } else {
            User::factory()->create(['id' => 251, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
            $original = 211;
            $current = 251;
        }
        $f['actor']->setRawAttributes(array_replace($f['actor']->getAttributes(), ['id' => $original]), true);
        $f['actor']->setAttribute('id', $current);
        $this->assertSame($original, $f['actor']->getRawOriginal('id'));
        $this->assertSame($current, $f['actor']->getAttributes()['id']);
        $before = $this->snapshot();
        $queries = [];
        $refused = false;
        try {
            $this->queries($queries, fn () => DB::transaction(fn () => app(LegacyOfflinePackageGuard::class)
                ->lock($f['event'], $f['actor'], $this->builderInput())));
        } catch (HttpExceptionInterface $exception) {
            $refused = true;
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertTrue($refused, 'Generation accepted an inconsistent original/current actor identity.');
        $this->assertSame([], $queries, 'An inconsistent actor identity reached row queries or DML.');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public static function inconsistentActorIdentities(): array
    {
        return ['overflow original aliases actual max admin' => ['unsafe_original'],
            'padded original aliases canonical admin' => ['padded_original'],
            'safe current retargets another actual admin' => ['retargeted_current']];
    }

    public function test_direct_generation_guard_accepts_canonical_string_original_and_equal_integer_current_actor(): void
    {
        $f = $this->fixture();
        $f['actor']->setRawAttributes(array_replace($f['actor']->getAttributes(), ['id' => '211']), true);
        $f['actor']->setAttribute('id', 211);
        $this->assertSame('211', $f['actor']->getRawOriginal('id'));
        $this->assertSame(211, $f['actor']->getAttributes()['id']);
        $before = $this->snapshot();
        $queries = [];
        $guarded = $this->queries($queries, fn () => DB::transaction(fn () => app(LegacyOfflinePackageGuard::class)
            ->lock($f['event'], $f['actor'], $this->builderInput())));
        $this->assertSame(301, $guarded['event']->getKey());
        $this->assertSame(211, $guarded['actor']->getKey());
        $this->assertSame(312, $guarded['master']->getKey());
        $this->assertSame(311, $guarded['clients'][0]['user']->getKey());
        $this->assertNotEmpty($queries);
        $this->assertSame([], array_values(array_filter($queries, static fn ($query): bool => preg_match('/\A\s*(insert|update|delete)\b/i', $query['sql']))));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }

    public function test_guard_cannot_claim_row_locks_outside_a_transaction(): void
    {
        $f = $this->fixture();
        $default = DB::getDefaultConnection();
        config(['database.connections.package_no_transaction' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('package_no_transaction');
        try {
            $this->assertSame(0, DB::connection()->transactionLevel());
            try {
                app(LegacyOfflinePackageGuard::class)->lock($f['event'], $f['actor'], $this->builderInput());
                $this->fail('The guard accepted an absent transaction.');
            } catch (\LogicException $exception) {
                $this->assertSame('Offline package checks require a transaction.', $exception->getMessage());
            }
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('package_no_transaction');
        }
    }

    private function protect(int $id, string $kind): void
    {
        if (str_starts_with($kind, 'member_')) {
            DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => $id, 'role' => 'owner', 'is_active' => $kind === 'member_active']);
        } else {
            $change = match ($kind) {
                'platform' => ['is_platform_admin' => true], 'platform_two' => ['is_platform_admin' => 2],
                'role_case' => ['role' => 'SCANNER'], 'active_two' => ['is_active' => 2],
            };
            DB::table('users')->where('id', $id)->update($change);
        }
    }

    private function builderInput(): array
    {
        return ['master_scanner_user_id' => 312, 'master_gate_name' => 'Main Gate',
            'clients' => [['scanner_user_id' => 311, 'assigned_gate' => 'Side Gate']],
            'package_expires_at' => now()->addDay()->toIso8601String(), 'package_password' => 'Str0ng-Package-Password!'];
    }

    private function webInput(): array
    {
        return ['master_scanner_user_id' => 312, 'master_gate_name' => 'Main Gate',
            'client_scanner_user_ids' => [311], 'client_gate_names' => [311 => 'Side Gate'],
            'package_expires_at' => now()->addDay()->toIso8601String(), 'package_password' => 'Str0ng-Package-Password!',
            'package_password_confirmation' => 'Str0ng-Package-Password!', 'confirm_generation' => '1'];
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

    private function snapshot(): array
    {
        $db = DB::connection();
        $result = [];
        foreach ($db->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $rows = $db->table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($rows, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            $result[$table->name] = $rows;
        }
        $result['_fake_files'] = [];
        foreach (Storage::disk(config('offline_scanner.disk'))->allFiles() as $file) {
            $result['_fake_files'][$file] = hash('sha256', Storage::disk(config('offline_scanner.disk'))->get($file));
        }
        ksort($result['_fake_files']);

        return $result;
    }

    private function fixture(): array
    {
        Storage::fake(config('offline_scanner.disk'));
        config(['services.offline_scanner_package.signature_algorithm' => 'hmac-sha256',
            'services.offline_scanner_package.allow_hmac_fallback' => true,
            'services.offline_scanner_package.hmac_key' => 'synthetic-only-signing-key']);
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic private workspace']);
        $event = Event::factory()->create(['id' => 301, 'event_name' => 'Synthetic package event']);
        Event::factory()->create(['id' => 302]);
        $actor = User::factory()->create(['id' => 211, 'role' => 'admin']);
        $master = User::factory()->create(['id' => 312, 'role' => 'scanner', 'event_id' => 301, 'name' => 'Private Master Identity', 'gate_name' => 'Main Gate']);
        $client = User::factory()->create(['id' => 311, 'role' => 'scanner', 'event_id' => 301, 'gate_name' => 'Side Gate']);

        $guest = Guest::query()->create(['id' => 401, 'event_id' => 301, 'name' => 'Synthetic package guest',
            'phone_number' => '+255712345678', 'manual_code' => '0042', 'pass_type' => Guest::PASS_DOUBLE, 'allowed_entries' => 2, 'used_entries' => 0]);
        QrCode::query()->create(['guest_id' => $guest->id, 'qr_token' => Guest::makeSecureToken(), 'is_active' => true, 'generated_at' => now()]);

        return compact('event', 'actor', 'master', 'client');
    }
}
