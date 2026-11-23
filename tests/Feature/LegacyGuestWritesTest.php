<?php

namespace Tests\Feature;

use App\Exceptions\OfflineEventLockedException;
use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerSession;
use App\Models\QrCode;
use App\Models\ScanLog;
use App\Models\User;
use App\Services\Tenancy\LegacyGuestWrites;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Concerns\FakesGuestQrDisk;
use Tests\TestCase;

/** Independent current-write contract; SQLite does not establish actual MySQL lock schedules. */
class LegacyGuestWritesTest extends TestCase
{
    use FakesGuestQrDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new LogicException('Guest write fixtures require isolated in-memory SQLite.');
        }
        $pdo = $db->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new LogicException('Guest write fixtures require actual SQLite PDO.');
        }
        $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool => $row['name'] === 'main'));
        if (count($main) !== 1 || $main[0]['file'] !== '' || $db->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new LogicException('Guest write fixtures require an in-memory main database and actual autocommit0.');
        }
        $temporaryRoot = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'codex-harusi-guest-writes-'.bin2hex(random_bytes(8));
        (new Filesystem)->makeDirectory($temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views', 0777, true);
        $this->app->useStoragePath($temporaryRoot);
        config(['view.compiled' => $temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            'logging.channels.single.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log',
            'logging.channels.daily.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log']);
        $this->beforeApplicationDestroyed(static function () use ($db, $temporaryRoot): void {
            $db->disconnect();
            $resolved = realpath($temporaryRoot);
            $prefix = rtrim((string) realpath(sys_get_temp_dir()), '/\\').DIRECTORY_SEPARATOR.'codex-harusi-guest-writes-';
            if ($resolved !== false && str_starts_with(strtolower($resolved), strtolower($prefix))) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        });
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->app[Kernel::class]->setArtisan(null);
        $this->freezeTime();
        Storage::fake('public');
        $this->fakeGuestQrDisk();
    }

    #[DataProvider('lateAuthorityChanges')]
    public function test_http_late_authority_or_guest_parent_change_refuses_without_other_effects(string $action, string $change, int $status): void
    {
        $f = $this->fixture();
        $expected = null;
        $changed = false;
        $this->beforeFirstTransaction(function () use ($change, &$expected, &$changed): void {
            $this->assertAutocommit();
            $changed = true;
            match ($change) {
                'inactive_actor' => DB::table('users')->where('id', 211)->update(['is_active' => false]),
                'customer_actor' => DB::table('users')->where('id', 211)->update(['role' => User::ROLE_CUSTOMER]),
                'owned_event' => DB::table('events')->where('id', 301)->update(['workspace_id' => 101]),
                'moved_guest' => DB::table('guests')->where('id', 901)->update(['event_id' => 302]),
                'guest_and_selection' => $this->reparentGuestAndSelection(),
            };
            $expected = $this->snapshot();
        });
        $response = $this->requestMutation($action, $f);

        $this->assertTrue($changed, 'The authority mutation runs after HTTP entry checks and update validation.');
        $this->assertNotNull($expected);
        $response->assertStatus($status)->assertSessionMissing('success');
        $this->assertSame($expected, $this->snapshot(), 'Refusal preserves every post-mutation row and QR byte, including neighbors and existing history.');
        $this->assertRequestContext($response, $change === 'guest_and_selection' ? 302 : 301);
        $this->assertAutocommit();
    }

    public static function lateAuthorityChanges(): array
    {
        $cases = [];
        foreach (['update', 'cancel'] as $action) {
            foreach (['inactive_actor' => 403, 'customer_actor' => 403, 'owned_event' => 404,
                'moved_guest' => 404, 'guest_and_selection' => 404] as $change => $status) {
                $cases[$action.' '.$change] = [$action, $change, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('actions')]
    public function test_http_selected_context_and_request_retarget_cannot_replace_the_captured_write(string $action): void
    {
        $f = $this->fixture();
        $expected = null;
        $changed = false;
        $this->beforeFirstTransaction(function () use (&$changed, &$expected): void {
            $this->assertAutocommit();
            $changed = true;
            session()->put('selected_event_id', 302);
            request()->merge(['name' => 'Late mutable request name', 'phone_number' => '0788000000',
                'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1, 'event_id' => 302]);
            $expected = $this->snapshot();
        });
        $response = $this->requestMutation($action, $f);

        $this->assertTrue($changed);
        $this->assertSuccess($response, $action, $f);
        $this->assertNotNull($expected);
        $this->assertOnlyGuestDelta($expected, $action, $f, $this->input());
        $this->assertRequestContext($response, 302);
        $this->assertAutocommit();
    }

    #[DataProvider('lateAllowanceChanges')]
    public function test_http_late_admissions_cannot_be_clamped_by_an_already_validated_lower_allowance(string $passType, int $requestedEntries): void
    {
        $f = $this->fixture();
        $expected = null;
        $changed = false;
        $this->beforeFirstTransaction(function () use (&$expected, &$changed): void {
            $this->assertAutocommit();
            $changed = true;
            DB::table('guests')->where('id', 901)->update(['used_entries' => 4, 'status' => Guest::STATUS_PARTIALLY_USED]);
            Admission::query()->create(['event_id' => 301, 'guest_id' => 901, 'quantity' => 3,
                'admitted_by' => 'Synthetic late scanner', 'admitted_at' => now()]);
            $expected = $this->snapshot();
        });
        $response = $this->requestMutation('update', $f, $this->input($passType, $requestedEntries));

        $this->assertTrue($changed, 'Four used entries appear after the initial one-used-entry validation.');
        $response->assertRedirect(route('admin.guests.edit', 901))
            ->assertSessionHasErrors(['allowed_entries' => 'Allowed entries cannot be less than the 4 entries already used.'])
            ->assertSessionMissing('success');
        $this->assertNotNull($expected);
        $this->assertSame($expected, $this->snapshot(), 'All four admissions and the late history row survive unchanged; no metadata or QR mutation follows validation failure.');
        $this->assertRequestContext($response);
        $this->assertAutocommit();
    }

    public static function lateAllowanceChanges(): array
    {
        return ['Special three' => [Guest::PASS_SPECIAL, 3], 'Single normalizes nine to one' => [Guest::PASS_SINGLE, 9]];
    }

    #[DataProvider('positiveContexts')]
    public function test_http_allowed_administrators_use_one_transaction_and_current_primary_scoped_lock_order(string $action, string $variant): void
    {
        $f = $this->fixture();
        $input = match ($variant) {
            'platform' => $this->input(Guest::PASS_SINGLE, 9),
            'member' => $this->input(Guest::PASS_DOUBLE, 8),
            'nested' => $this->input(Guest::PASS_SPECIAL, 3),
            default => $this->input(),
        };
        if ($variant === 'platform') {
            DB::table('users')->where('id', 211)->update(['is_platform_admin' => true]);
            DB::table('events')->where('id', 301)->update(['status' => Event::STATUS_ARCHIVED]);
        } elseif ($variant === 'member') {
            DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 211, 'role' => 'owner', 'is_active' => false]);
            DB::table('events')->where('id', 301)->update(['status' => Event::STATUS_CANCELLED]);
        }
        $f['actor']->refresh();
        $this->actingAs($f['actor']);
        $before = $this->snapshot();
        $db = DB::connection();
        $ambient = $variant === 'nested' ? 1 : 0;
        if ($ambient) {
            $db->beginTransaction();
        }
        $starts = [];
        $db->beforeStartingTransaction(static function () use ($db, &$starts): void {
            $starts[] = ['level' => $db->transactionLevel(), 'pdo_transaction' => $db->getPdo()->inTransaction()];
        });
        $grammar = $this->inspectLockedReads();
        try {
            $response = $this->requestMutation($action, $f, $input);
            $grammar->observing = false;
            $this->assertSuccess($response, $action, $f);
            $this->assertSame([['level' => $ambient, 'pdo_transaction' => (bool) $ambient]], $starts, 'The guard reuses the existing transaction instead of wrapping it in another transaction.');
            $this->assertSame(['events', 'users', 'events', 'offline_scanner_sessions', 'guests'], array_column($grammar->reads, 'table'),
                'The existing admission lock helper contributes its own Event reread and blocking Session check.');
            foreach ($grammar->reads as $read) {
                $this->assertTrue($read['write']);
                $this->assertSame(true, $read['lock']);
                $this->assertSame($ambient + 1, $read['level']);
                $this->assertTrue($read['pdo_transaction']);
            }
            $this->assertSame([301], $grammar->reads[0]['bindings']);
            $this->assertSame([211], $grammar->reads[1]['bindings']);
            $this->assertSame([301], $grammar->reads[2]['bindings']);
            $this->assertSame(301, $grammar->reads[3]['bindings'][0]);
            $guestRead = $grammar->reads[4];
            $this->assertMatchesRegularExpression('/"(?:guests"\.")?id"\s*=\s*\?/', $guestRead['sql']);
            $this->assertMatchesRegularExpression('/"(?:guests"\.")?event_id"\s*=\s*\?/', $guestRead['sql']);
            $this->assertSame([901, 301], $guestRead['bindings']);
            $this->assertOnlyGuestDelta($before, $action, $f, $input);
            $this->assertRequestContext($response);
            $this->assertSame($ambient, $db->transactionLevel());
            $this->assertSame((bool) $ambient, $db->getPdo()->inTransaction());
        } finally {
            $grammar->observing = false;
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
        }
        if ($ambient) {
            $this->assertSame($before, $this->snapshot(), 'The recorded ambient transaction retains its ordinary rollback behavior.');
        }
        $this->assertAutocommit();
    }

    public static function positiveContexts(): array
    {
        $cases = [];
        foreach (['update', 'cancel'] as $action) {
            foreach (['ordinary', 'platform', 'member', 'nested'] as $variant) {
                $cases[$action.' '.$variant] = [$action, $variant];
            }
        }

        return $cases;
    }

    #[DataProvider('lateOfflineContexts')]
    public function test_http_blocking_offline_session_appearing_after_entry_preserves_existing_feedback(string $action, bool $json): void
    {
        $f = $this->fixture();
        $changed = false;
        $expected = null;
        $this->beforeFirstTransaction(function () use (&$changed, &$expected): void {
            $this->assertAutocommit();
            $changed = true;
            OfflineScannerSession::factory()->active()->create(['id' => 401, 'event_id' => 301, 'created_by_user_id' => 215]);
            $expected = $this->snapshot();
        });
        $response = $this->requestMutation($action, $f, null, $json);

        $this->assertTrue($changed);
        if ($json) {
            $response->assertStatus(423)->assertExactJson(['message' => OfflineEventLockedException::MESSAGE]);
        } else {
            $response->assertRedirect(route('admin.guests.show', 901))->assertSessionHas('error', OfflineEventLockedException::MESSAGE);
        }
        $response->assertSessionMissing('success');
        $this->assertNotNull($expected);
        $this->assertSame($expected, $this->snapshot());
        $this->assertRequestContext($response);
        $this->assertAutocommit();
    }

    public static function lateOfflineContexts(): array
    {
        return ['update JSON' => ['update', true], 'cancel browser' => ['cancel', false]];
    }

    #[DataProvider('actions')]
    public function test_http_real_sql_failure_after_guest_write_rolls_back_the_partial_write(string $action): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $partial = null;
        $faults = 0;
        Guest::updated(function (Guest $guest) use (&$partial, &$faults): void {
            if ((int) $guest->getRawOriginal('id') !== 901) {
                return;
            }
            $faults++;
            $partial = $this->snapshot();
            // A real PDO query fails after the UPDATE reached SQLite.
            DB::statement('INSERT INTO "p4039_intentionally_absent_table" ("id") VALUES (1)');
        });
        $this->withoutExceptionHandling();
        $failed = false;
        try {
            $this->requestMutation($action, $f);
        } catch (QueryException $exception) {
            $failed = true;
            $this->assertStringContainsString('p4039_intentionally_absent_table', $exception->getSql());
        }

        $this->assertTrue($failed);
        $this->assertSame(1, $faults);
        $this->assertNotNull($partial);
        $this->assertNotSame($before['guests'], $partial['guests'], 'The failure follows a real, visible Guest UPDATE within the transaction.');
        $this->assertSame($before, $this->snapshot(), 'The partial Guest write rolls back while all QR/manual-code/counter/history/neighbor state remains exact.');
        $this->assertAutocommit();
    }

    #[DataProvider('actions')]
    public function test_http_three_real_sql_retry_attempts_reuse_frozen_identity_and_payload(string $action): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        DB::connection()->unprepared('CREATE TEMP TRIGGER "p4039_retry_fault" AFTER UPDATE ON "guests" '
            .'WHEN NEW."id" = 901 BEGIN SELECT RAISE(ABORT, \'database is locked\'); END');
        $starts = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$starts): void {
            $this->assertAutocommit();
            $starts++;
            if ($starts === 2) {
                session()->put('selected_event_id', 302);
                request()->merge(['name' => 'Retargeted retry payload', 'phone_number' => '0788000000',
                    'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1]);
            }
        });
        $updates = [];
        DB::connection()->beforeExecuting(static function (string $sql, array $bindings) use (&$updates): void {
            if (preg_match('/\Aupdate "guests"/i', $sql) === 1) {
                $updates[] = ['sql' => $sql, 'bindings' => $bindings];
            }
        });
        $this->withoutExceptionHandling();
        $failure = null;
        try {
            $this->requestMutation($action, $f);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(QueryException::class, $failure, 'A retargeted lookup cannot replace the final third SQL failure.');
        $this->assertStringContainsString('database is locked', $failure->getMessage());
        $this->assertSame(3, $starts, 'The existing transaction has exactly three attempts.');
        $this->assertCount(3, $updates);
        $this->assertSame($updates[0], $updates[1]);
        $this->assertSame($updates[0], $updates[2], 'Neither current session selection nor mutable request replaces a retry identity or validated payload.');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(302, session('selected_event_id'));
        $this->assertAutocommit();
    }

    #[DataProvider('actions')]
    public function test_http_maximum_signed_integer_guest_actor_and_event_identities_remain_valid(string $action): void
    {
        $f = $this->fixture(true);
        $before = $this->snapshot();
        $response = $this->requestMutation($action, $f);

        $this->assertSuccess($response, $action, $f);
        $this->assertOnlyGuestDelta($before, $action, $f, $this->input());
        $this->assertRequestContext($response, PHP_INT_MAX);
        $this->assertAutocommit();
    }

    public static function actions(): array
    {
        return ['update' => ['update'], 'cancel' => ['cancel']];
    }

    #[DataProvider('invalidRawContexts')]
    public function test_direct_invalid_original_or_current_identity_refuses_before_any_sql_or_transaction(string $change, int $status): void
    {
        $f = $this->fixture();
        $guest = clone $f['guest'];
        $actor = clone $f['actor'];
        $selected = 301;
        match ($change) {
            'guest_current_retarget' => $guest->setAttribute('id', 902),
            'guest_original_overflow' => $this->rawOriginal($guest, 'id', (string) PHP_INT_MAX.'0'),
            'guest_current_event' => $guest->setAttribute('event_id', 302),
            'guest_original_event_padded' => $this->rawOriginal($guest, 'event_id', '0301'),
            'actor_current_retarget' => $actor->setAttribute('id', 215),
            'actor_original_float' => $this->rawOriginal($actor, 'id', 211.0),
            'selected_array' => $selected = [301],
            'selected_mismatch' => $selected = 302,
        };
        $before = $this->snapshot();
        $armed = true;
        $queries = [];
        DB::connection()->beforeExecuting(static function (string $sql) use (&$armed, &$queries): void {
            if ($armed) {
                $queries[] = $sql;
                throw new LogicException('Invalid caller context reached SQL before refusal.');
            }
        });
        $starts = 0;
        DB::connection()->beforeStartingTransaction(static function () use (&$starts): void {
            $starts++;
        });
        $callbacks = 0;
        $refused = false;
        try {
            app(LegacyGuestWrites::class)->mutate($guest, $actor, $selected, static function () use (&$callbacks): void {
                $callbacks++;
            });
        } catch (HttpExceptionInterface $exception) {
            $refused = true;
            $this->assertSame($status, $exception->getStatusCode());
        } finally {
            $armed = false;
        }

        $this->assertTrue($refused);
        $this->assertSame([], $queries, 'The query trap applies to every SQL statement, not only lock reads.');
        $this->assertSame(0, $starts);
        $this->assertSame(0, $callbacks);
        $this->assertSame($before, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function invalidRawContexts(): array
    {
        return ['current Guest ID retarget' => ['guest_current_retarget', 404],
            'original overflowing Guest ID' => ['guest_original_overflow', 404],
            'current Guest Event retarget' => ['guest_current_event', 404],
            'original padded Guest Event' => ['guest_original_event_padded', 404],
            'current actor retarget' => ['actor_current_retarget', 403],
            'original floating actor ID' => ['actor_original_float', 403],
            'array selection' => ['selected_array', 404], 'different selected Event' => ['selected_mismatch', 404]];
    }

    private function fixture(bool $maximumIds = false): array
    {
        $eventId = $maximumIds ? PHP_INT_MAX : 301;
        $actorId = $maximumIds ? PHP_INT_MAX : 211;
        $guestId = $maximumIds ? PHP_INT_MAX : 901;
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic Guest write neighbor workspace']);
        Event::factory()->create(['id' => $eventId, 'workspace_id' => null, 'event_name' => 'Synthetic Guest write Event']);
        Event::factory()->create(['id' => 302, 'workspace_id' => null, 'event_name' => 'Synthetic second legacy Event']);
        Event::factory()->create(['id' => 303, 'workspace_id' => 101, 'event_name' => 'Synthetic owned neighbor Event']);
        $actor = User::factory()->create(['id' => $actorId, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        User::factory()->create(['id' => 215, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $guest = Guest::query()->forceCreate(['id' => $guestId, 'event_id' => $eventId,
            'name' => 'Synthetic target Guest', 'phone_number' => '+255712345678', 'manual_code' => '0901',
            'pass_type' => Guest::PASS_SPECIAL, 'allowed_entries' => 6, 'used_entries' => 1, 'status' => Guest::STATUS_PARTIALLY_USED]);
        foreach ([902 => [$eventId, '0902'], 903 => [302, '0903'], 904 => [303, '0904']] as $id => [$parent, $code]) {
            Guest::query()->forceCreate(['id' => $id, 'event_id' => $parent, 'name' => 'Synthetic neighbor Guest '.$id,
                'phone_number' => '+255712345678', 'manual_code' => $code, 'pass_type' => Guest::PASS_DOUBLE,
                'allowed_entries' => 2, 'used_entries' => 1, 'status' => Guest::STATUS_PARTIALLY_USED]);
        }
        foreach ([$guestId, 902, 903, 904] as $index => $id) {
            $path = 'qr-codes/synthetic-guest-writes/'.$index.'.png';
            QrCode::query()->forceCreate(['id' => 501 + $index, 'guest_id' => $id,
                'qr_token' => 'synthetic-guest-write-token-'.$index, 'qr_image_path' => $path,
                'is_active' => $index !== 0, 'generated_at' => now()->subDays(2),
                'revoked_at' => $index === 0 ? now()->subDay() : null]);
            Storage::disk('guest_qr')->put($path, 'synthetic-QR-byte-fixture-'.$index);
        }
        Storage::disk('public')->put('retained/unrelated.txt', 'Synthetic unrelated retained public bytes.');
        foreach ([$guestId => $eventId, 902 => $eventId, 903 => 302, 904 => 303] as $id => $parent) {
            Admission::query()->create(['event_id' => $parent, 'guest_id' => $id, 'quantity' => 1,
                'admitted_by' => 'Synthetic retained scanner', 'device_label' => 'Synthetic history', 'admitted_at' => now()->subHour()]);
            Checkin::query()->create(['event_id' => $parent, 'guest_id' => $id, 'user_id' => 215,
                'qr_code_id' => $id === $guestId ? 501 : null, 'gate_name' => 'Synthetic retained gate',
                'entries_added' => 1, 'used_entries_after_scan' => 1, 'remaining_entries_after_scan' => 1,
                'scan_result' => Checkin::RESULT_ADMITTED, 'checked_in_at' => now()->subHour()]);
            ScanLog::query()->create(['event_id' => $parent, 'guest_id' => $id, 'action' => 'admit',
                'result' => 'admitted', 'quantity' => 1, 'message' => 'Synthetic retained scan log',
                'metadata' => ['preserved' => 'exact'], 'scanned_at' => now()->subHour()]);
        }
        $this->actingAs($actor)->withSession(['selected_event_id' => $eventId, 'guest_import' => ['marker' => 'preserved']]);

        return compact('actor', 'guest', 'eventId', 'actorId', 'guestId');
    }

    private function requestMutation(string $action, array $f, ?array $input = null, bool $json = false): TestResponse
    {
        $this->from(route($action === 'update' ? 'admin.guests.edit' : 'admin.guests.show', $f['guestId']));
        $url = route($action === 'update' ? 'admin.guests.update' : 'admin.guests.cancel', $f['guestId']);
        if ($action === 'update') {
            return $json ? $this->putJson($url, $input ?? $this->input()) : $this->put($url, $input ?? $this->input());
        }

        return $json ? $this->patchJson($url) : $this->patch($url);
    }

    private function input(string $passType = Guest::PASS_SPECIAL, int $allowedEntries = 4): array
    {
        return ['name' => 'Revised synthetic Guest', 'phone_number' => '0712 345 679',
            'pass_type' => $passType, 'allowed_entries' => $allowedEntries,
            'event_id' => 302, 'manual_code' => '9999', 'used_entries' => 0,
            'status' => Guest::STATUS_ACTIVE, 'qr_token' => 'must-not-replace-the-retained-token'];
    }

    private function beforeFirstTransaction(Closure $hook): void
    {
        $armed = true;
        DB::connection()->beforeStartingTransaction(static function () use (&$armed, $hook): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            $hook();
        });
    }

    private function reparentGuestAndSelection(): void
    {
        DB::table('guests')->where('id', 901)->update(['event_id' => 302]);
        session()->put('selected_event_id', 302);
    }

    private function rawOriginal(Guest|User $model, string $field, mixed $value): void
    {
        $model->setRawAttributes(array_replace($model->getAttributes(), [$field => $value]), true);
    }

    private function assertSuccess(TestResponse $response, string $action, array $f): void
    {
        $response->assertRedirect(route('admin.guests.show', $f['guestId']))->assertSessionHasNoErrors()
            ->assertSessionHas('success', $action === 'update' ? 'Guest pass updated.' : 'Pass cancelled.');
    }

    private function assertOnlyGuestDelta(array $before, string $action, array $f, array $input): void
    {
        $after = $this->snapshot();
        $target = array_values(array_filter($after['guests'], static fn (array $row): bool => $row['id'] === $f['guestId']))[0];
        $expectedFields = $action === 'cancel' ? ['status' => Guest::STATUS_CANCELLED]
            : ['name' => $input['name'], 'phone_number' => '+255712345679', 'pass_type' => $input['pass_type'],
                'allowed_entries' => Guest::allowedEntriesForPassType($input['pass_type'], $input['allowed_entries']),
                'status' => $input['pass_type'] === Guest::PASS_SINGLE ? Guest::STATUS_FULLY_USED : Guest::STATUS_PARTIALLY_USED];
        foreach ($expectedFields as $field => $value) {
            $this->assertSame($value, $target[$field]);
        }
        $this->assertSame(1, $target['used_entries']);
        foreach ($before['guests'] as &$row) {
            if ($row['id'] === $f['guestId']) {
                $row = array_replace($row, $expectedFields, ['updated_at' => $target['updated_at']]);
            }
        }
        unset($row);
        $this->assertSame($before, $after, 'Only the allowed metadata/status and updated_at of the captured Guest change; counters, manual codes, QR associations/tokens/files and all history/neighbors stay exact.');
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (DB::connection()->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $contents = DB::table($table->name)->useWritePdo()->get()->map(static fn ($row) => (array) $row)->all();
            usort($contents, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
            $rows[$table->name] = $contents;
        }
        foreach (['_files' => 'public', '_qr_files' => 'guest_qr'] as $key => $disk) {
            $files = Storage::disk($disk)->allFiles();
            sort($files);
            $rows[$key] = [];
            foreach ($files as $file) {
                $rows[$key][$file] = hash('sha256', Storage::disk($disk)->get($file));
            }
        }

        return $rows;
    }

    private function assertRequestContext(TestResponse $response, int $selected = 301): void
    {
        $response->assertSessionHas('selected_event_id', $selected)->assertSessionHas('guest_import.marker', 'preserved');
    }

    private function assertAutocommit(): void
    {
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function inspectLockedReads(): GuestWritesInspectingGrammar
    {
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $grammar = new GuestWritesInspectingGrammar($db);
        $db->setQueryGrammar($grammar);
        $this->beforeApplicationDestroyed(static fn () => $db->setQueryGrammar($original));

        return $grammar;
    }
}

/** Primary/locking builder intent only: SQLite emits no actual FOR UPDATE. */
final class GuestWritesInspectingGrammar extends SQLiteGrammar
{
    public bool $observing = true;

    public array $reads = [];

    public function compileSelect(Builder $query)
    {
        $sql = parent::compileSelect($query);
        if ($this->observing && $query->lock !== null && in_array($query->from, ['events', 'users', 'offline_scanner_sessions', 'guests'], true)) {
            $this->reads[] = ['table' => $query->from, 'write' => $query->useWritePdo, 'lock' => $query->lock,
                'level' => DB::connection()->transactionLevel(), 'pdo_transaction' => DB::connection()->getPdo()->inTransaction(),
                'sql' => $sql, 'bindings' => $query->getBindings()];
        }

        return $sql;
    }
}
