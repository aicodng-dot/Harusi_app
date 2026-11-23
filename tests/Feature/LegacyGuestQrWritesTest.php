<?php

namespace Tests\Feature;

use App\Exceptions\OfflineEventLockedException;
use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerSession;
use App\Models\QrCode;
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

/** Named metadata boundaries; SQLite observations do not establish MySQL lock schedules. */
class LegacyGuestQrWritesTest extends TestCase
{
    use FakesGuestQrDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
            throw new LogicException('Guest QR fixtures require isolated in-memory SQLite.');
        }
        $pdo = $db->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new LogicException('Guest QR fixtures require actual SQLite PDO.');
        }
        $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC), static fn (array $row): bool => $row['name'] === 'main'));
        if (count($main) !== 1 || $main[0]['file'] !== '' || $db->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new LogicException('Guest QR fixtures require an in-memory main database and actual autocommit0.');
        }
        $temporaryRoot = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'codex-harusi-guest-qr-writes-'.bin2hex(random_bytes(8));
        (new Filesystem)->makeDirectory($temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views', 0777, true);
        $this->app->useStoragePath($temporaryRoot);
        config(['view.compiled' => $temporaryRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
            'logging.channels.single.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log',
            'logging.channels.daily.path' => $temporaryRoot.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log']);
        $this->beforeApplicationDestroyed(static function () use ($db, $temporaryRoot): void {
            $db->disconnect();
            $resolved = realpath($temporaryRoot);
            $prefix = rtrim((string) realpath(sys_get_temp_dir()), '/\\').DIRECTORY_SEPARATOR.'codex-harusi-guest-qr-writes-';
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
    public function test_http_late_authority_or_guest_parent_loss_preserves_post_change_state(string $action, string $change, int $status): void
    {
        $f = $this->fixture();
        $expected = null;
        $this->beforeFirstTransaction(function () use ($change, &$expected): void {
            $this->assertAutocommit();
            match ($change) {
                'inactive_actor' => DB::table('users')->where('id', 211)->update(['is_active' => false]),
                'customer_actor' => DB::table('users')->where('id', 211)->update(['role' => User::ROLE_CUSTOMER]),
                'owned_event' => DB::table('events')->where('id', 301)->update(['workspace_id' => 101]),
                'moved_guest' => DB::table('guests')->where('id', 901)->update(['event_id' => 302]),
            };
            $expected = $this->snapshot();
        });

        $response = $this->requestMutation($action, $f);

        $this->assertNotNull($expected, 'The change occurs after HTTP entry checks.');
        $response->assertStatus($status)->assertSessionMissing('success');
        $this->assertSame($expected, $this->snapshot(), 'Denied writes preserve the post-change rows, history, neighbors and bytes.');
        $this->assertContext($response);
        $this->assertAutocommit();
    }

    public static function lateAuthorityChanges(): array
    {
        $cases = [];
        foreach (['activate', 'deactivate', 'revoke', 'restore'] as $action) {
            foreach (['inactive_actor' => 403, 'customer_actor' => 403, 'owned_event' => 404, 'moved_guest' => 404] as $change => $status) {
                $cases[$action.' '.$change] = [$action, $change, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('positiveContexts')]
    public function test_http_existing_payloads_use_one_current_primary_scoped_transaction(string $action, string $variant): void
    {
        $f = $this->fixture();
        if ($variant === 'platform') {
            DB::table('users')->where('id', 211)->update(['is_platform_admin' => true]);
            DB::table('events')->where('id', 301)->update(['status' => Event::STATUS_ARCHIVED]);
        } elseif ($variant === 'member') {
            DB::table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 211, 'role' => 'owner', 'is_active' => false]);
            DB::table('events')->where('id', 301)->update(['status' => Event::STATUS_CANCELLED]);
        }
        $f['actor']->refresh();
        $before = $this->snapshot();
        $db = DB::connection();
        $ambient = $variant === 'nested' ? 1 : 0;
        if ($ambient) {
            $db->beginTransaction();
        }
        $starts = [];
        $db->beforeStartingTransaction(static function () use ($db, &$starts): void {
            $starts[] = [$db->transactionLevel(), $db->getPdo()->inTransaction()];
        });
        $grammar = $this->inspectReads();
        try {
            $response = $this->requestMutation($action, $f);
            $grammar->observing = false;
            $this->assertSuccess($response, $action);
            $this->assertSame([[$ambient, (bool) $ambient]], $starts, 'There is one transaction composition, with unchanged ambient behavior.');
            $this->assertSame(['events', 'users', 'events', 'offline_scanner_sessions', 'guests', 'qr_codes'], array_column($grammar->reads, 'table'));
            foreach ($grammar->reads as $read) {
                $this->assertSame([true, true, $ambient + 1, true], [$read['write'], $read['lock'], $read['level'], $read['pdo_transaction']]);
            }
            $this->assertSame([901, 301], $grammar->reads[4]['bindings']);
            $qrRead = $grammar->reads[5];
            $this->assertMatchesRegularExpression('/"(?:qr_codes"\.")?guest_id"\s*=\s*\?/', $qrRead['sql']);
            $this->assertSame([901], $qrRead['bindings']);
            $this->assertOnlyExpectedDelta($before, $action);
            $this->assertContext($response);
            $this->assertSame($ambient, $db->transactionLevel());
            $this->assertSame((bool) $ambient, $db->getPdo()->inTransaction());
        } finally {
            $grammar->observing = false;
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
        }
        if ($ambient) {
            $this->assertSame($before, $this->snapshot(), 'An enclosing caller retains ordinary rollback of both metadata writes.');
        }
        $this->assertAutocommit();
    }

    public static function positiveContexts(): array
    {
        return ['ordinary activate' => ['activate', 'ordinary'], 'platform deactivate' => ['deactivate', 'platform'],
            'member revoke' => ['revoke', 'member'], 'nested restore' => ['restore', 'nested']];
    }

    #[DataProvider('actions')]
    public function test_http_missing_qr_retains_the_existing_required_or_optional_behavior(string $action): void
    {
        $f = $this->fixture();
        DB::table('qr_codes')->where('id', 501)->delete();
        $before = $this->snapshot();

        $response = $this->requestMutation($action, $f);

        if (in_array($action, ['activate', 'deactivate'], true)) {
            $response->assertNotFound()->assertSessionMissing('success');
            $this->assertSame($before, $this->snapshot());
        } else {
            $this->assertSuccess($response, $action);
            $this->assertOnlyExpectedDelta($before, $action, null);
        }
        $this->assertContext($response);
        $this->assertAutocommit();
    }

    #[DataProvider('restoredUsage')]
    public function test_http_restoration_preserves_usage_and_uses_existing_status_rules(int $used, string $status): void
    {
        $f = $this->fixture();
        DB::table('guests')->where('id', 901)->update(['used_entries' => $used]);
        $before = $this->snapshot();

        $response = $this->requestMutation('restore', $f);

        $this->assertSuccess($response, 'restore');
        $this->assertOnlyExpectedDelta($before, 'restore', 501, $status);
        $this->assertSame($used, DB::table('guests')->where('id', 901)->value('used_entries'));
        $this->assertAutocommit();
    }

    public static function restoredUsage(): array
    {
        return ['unused' => [0, Guest::STATUS_ACTIVE], 'fully used' => [6, Guest::STATUS_FULLY_USED]];
    }

    #[DataProvider('offlineContexts')]
    public function test_http_late_offline_session_preserves_browser_and_json_feedback(string $action, bool $json): void
    {
        $f = $this->fixture();
        $expected = null;
        $this->beforeFirstTransaction(function () use (&$expected): void {
            OfflineScannerSession::factory()->active()->create(['id' => 401, 'event_id' => 301, 'created_by_user_id' => 215]);
            $expected = $this->snapshot();
        });

        $response = $this->requestMutation($action, $f, $json);

        if ($json) {
            $response->assertStatus(423)->assertExactJson(['message' => OfflineEventLockedException::MESSAGE]);
        } else {
            $response->assertRedirect(route('admin.guests.show', 901))->assertSessionHas('error', OfflineEventLockedException::MESSAGE);
        }
        $response->assertSessionMissing('success');
        $this->assertNotNull($expected);
        $this->assertSame($expected, $this->snapshot());
        $this->assertContext($response);
        $this->assertAutocommit();
    }

    public static function offlineContexts(): array
    {
        return ['activate JSON' => ['activate', true], 'restore browser' => ['restore', false]];
    }

    public function test_http_real_qr_sql_failure_rolls_back_the_preceding_restoration_guest_update(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $partial = null;
        QrCode::updated(function (QrCode $qr) use (&$partial): void {
            if ((int) $qr->getRawOriginal('id') === 501) {
                $partial = $this->snapshot();
                DB::statement('INSERT INTO "p4040_intentionally_absent_table" ("id") VALUES (1)');
            }
        });
        $this->withoutExceptionHandling();
        $failure = null;
        try {
            $this->requestMutation('restore', $f);
        } catch (QueryException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(QueryException::class, $failure);
        $this->assertStringContainsString('p4040_intentionally_absent_table', $failure->getSql());
        $this->assertNotNull($partial);
        $this->assertNotSame($before['guests'], $partial['guests'], 'The Guest restoration reached SQL before the real PDO failure.');
        $this->assertNotSame($before['qr_codes'], $partial['qr_codes'], 'The QR update also reached SQL before the failure.');
        $this->assertSame($before, $this->snapshot(), 'Both writes roll back together, preserving all other state and bytes.');
        $this->assertAutocommit();
    }

    #[DataProvider('retryActions')]
    public function test_http_three_sql_retry_attempts_keep_captured_identity_despite_selection_retarget(string $action): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        DB::connection()->unprepared('CREATE TEMP TRIGGER "p4040_retry_fault" AFTER UPDATE ON "qr_codes" '
            .'WHEN NEW."id" = 501 BEGIN SELECT RAISE(ABORT, \'database is locked\'); END');
        $starts = 0;
        DB::connection()->beforeStartingTransaction(function () use (&$starts): void {
            $this->assertAutocommit();
            $starts++;
            if ($starts === 2) {
                session()->put('selected_event_id', 302);
                request()->merge(['guest_id' => 903, 'event_id' => 302, 'is_active' => false]);
            }
        });
        $updates = [];
        DB::connection()->beforeExecuting(static function (string $sql, array $bindings) use (&$updates): void {
            if (preg_match('/\Aupdate "qr_codes"/i', $sql) === 1) {
                $updates[] = [$sql, $bindings];
            }
        });
        $this->withoutExceptionHandling();
        $failure = null;
        try {
            $this->requestMutation($action, $f);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(QueryException::class, $failure, 'A new selected Event cannot replace the final third SQL failure.');
        $this->assertStringContainsString('database is locked', $failure->getMessage());
        $this->assertSame(3, $starts);
        $this->assertCount(3, $updates);
        $this->assertSame($updates[0], $updates[1]);
        $this->assertSame($updates[0], $updates[2]);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(302, session('selected_event_id'));
        $this->assertAutocommit();
    }

    public static function retryActions(): array
    {
        return ['revoke' => ['revoke'], 'restore' => ['restore']];
    }

    public function test_direct_current_unique_qr_replaces_stale_cached_relations_without_retargeting_the_guest(): void
    {
        $f = $this->fixture();
        $f['guest']->setRelation('qrCode', QrCode::query()->findOrFail(503));
        $expected = null;
        $this->beforeFirstTransaction(function () use (&$expected): void {
            DB::table('qr_codes')->where('id', 501)->update(['guest_id' => 905]);
            DB::table('qr_codes')->where('id', 502)->update(['guest_id' => 901]);
            $expected = $this->snapshot();
        });
        $seen = null;
        app(LegacyGuestWrites::class)->mutateWithQr($f['guest'], $f['actor'], 301, function (Guest $guest, ?QrCode $qr) use (&$seen): void {
            $seen = [$guest->getRawOriginal('id'), $qr?->getRawOriginal('id'), $qr?->getRawOriginal('guest_id')];
        });

        $this->assertSame([901, 502, 901], $seen, 'Only the current unique association of the guarded Guest reaches the trusted callback.');
        $this->assertNotNull($expected);
        $this->assertSame($expected, $this->snapshot());
        $this->assertSame(503, $f['guest']->qrCode->id, 'The stale caller relation is not rewritten or used as authority.');
        $this->assertAutocommit();
    }

    #[DataProvider('malformedQrRows')]
    public function test_direct_hydrated_qr_raw_identity_or_parent_mismatch_refuses_before_callback(string $field, mixed $value): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        QrCode::retrieved(static function (QrCode $qr) use ($field, $value): void {
            if ((int) $qr->getRawOriginal('id') === 501) {
                $qr->setRawAttributes(array_replace($qr->getAttributes(), [$field => $value]), true);
            }
        });
        $callbacks = 0;
        $refused = false;
        try {
            app(LegacyGuestWrites::class)->mutateWithQr($f['guest'], $f['actor'], 301, static function () use (&$callbacks): void {
                $callbacks++;
            });
        } catch (HttpExceptionInterface $exception) {
            $refused = true;
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertTrue($refused);
        $this->assertSame(0, $callbacks);
        $this->assertSame($before, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function malformedQrRows(): array
    {
        return ['overflowing QR identity' => ['id', (string) PHP_INT_MAX.'0'],
            'padded QR parent' => ['guest_id', '0901'], 'same Event foreign Guest parent' => ['guest_id', 902]];
    }

    public function test_direct_invalid_selected_context_still_refuses_before_sql_or_transaction(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $armed = true;
        $queries = [];
        DB::connection()->beforeExecuting(static function (string $sql) use (&$armed, &$queries): void {
            if ($armed) {
                $queries[] = $sql;
                throw new LogicException('Invalid QR write context reached SQL.');
            }
        });
        $starts = 0;
        DB::connection()->beforeStartingTransaction(static function () use (&$starts): void {
            $starts++;
        });
        $callbacks = 0;
        $refused = false;
        try {
            app(LegacyGuestWrites::class)->mutateWithQr($f['guest'], $f['actor'], [301], static function () use (&$callbacks): void {
                $callbacks++;
            });
        } catch (HttpExceptionInterface $exception) {
            $refused = true;
            $this->assertSame(404, $exception->getStatusCode());
        } finally {
            $armed = false;
        }

        $this->assertTrue($refused);
        $this->assertSame([], $queries);
        $this->assertSame(0, $starts);
        $this->assertSame(0, $callbacks);
        $this->assertSame($before, $this->snapshot());
        $this->assertAutocommit();
    }

    public static function actions(): array
    {
        return ['activate' => ['activate'], 'deactivate' => ['deactivate'], 'revoke' => ['revoke'], 'restore' => ['restore']];
    }

    private function fixture(): array
    {
        DB::table('workspaces')->insert(['id' => 101, 'name' => 'Synthetic QR boundary workspace']);
        Event::factory()->create(['id' => 301, 'workspace_id' => null]);
        Event::factory()->create(['id' => 302, 'workspace_id' => null]);
        Event::factory()->create(['id' => 303, 'workspace_id' => 101]);
        $actor = User::factory()->create(['id' => 211, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        User::factory()->create(['id' => 215, 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $guest = null;
        foreach ([901 => 301, 902 => 301, 903 => 302, 904 => 303, 905 => 301] as $id => $parent) {
            $row = Guest::query()->forceCreate(['id' => $id, 'event_id' => $parent,
                'name' => 'Synthetic QR boundary Guest '.$id, 'phone_number' => '+255712345678',
                'manual_code' => '0'.$id, 'pass_type' => Guest::PASS_SPECIAL, 'allowed_entries' => 6,
                'used_entries' => 2, 'status' => Guest::STATUS_CANCELLED]);
            if ($id === 901) {
                $guest = $row;
            }
            if ($id !== 905) {
                $path = 'qr-codes/synthetic-qr-boundary/'.$id.'.png';
                QrCode::query()->forceCreate(['id' => $id - 400, 'guest_id' => $id,
                    'qr_token' => 'synthetic-qr-boundary-token-'.$id, 'qr_image_path' => $path,
                    'is_active' => true, 'generated_at' => now()->subDays(2), 'revoked_at' => now()->subDay()]);
                Storage::disk('guest_qr')->put($path, 'synthetic-retained-QR-bytes-'.$id);
            }
            Admission::query()->create(['event_id' => $parent, 'guest_id' => $id, 'quantity' => 2,
                'admitted_by' => 'Synthetic scanner', 'admitted_at' => now()->subHour()]);
            Checkin::query()->create(['event_id' => $parent, 'guest_id' => $id, 'user_id' => 215,
                'entries_added' => 2, 'used_entries_after_scan' => 2, 'remaining_entries_after_scan' => 4,
                'scan_result' => Checkin::RESULT_ADMITTED, 'checked_in_at' => now()->subHour()]);
        }
        Storage::disk('public')->put('retained/unrelated.txt', 'Synthetic unrelated preserved bytes.');
        $this->actingAs($actor)->withSession(['selected_event_id' => 301, 'guest_import' => ['marker' => 'preserved']]);

        return compact('actor', 'guest');
    }

    private function requestMutation(string $action, array $f, bool $json = false): TestResponse
    {
        $this->actingAs($f['actor'])->from(route('admin.guests.show', 901));
        $name = in_array($action, ['activate', 'deactivate'], true) ? 'admin.qr-codes.'.$action : 'admin.guests.'.$action;
        $url = route($name, 901);

        return $json ? $this->patchJson($url) : $this->patch($url);
    }

    private function beforeFirstTransaction(Closure $hook): void
    {
        $armed = true;
        DB::connection()->beforeStartingTransaction(static function () use (&$armed, $hook): void {
            if ($armed) {
                $armed = false;
                $hook();
            }
        });
    }

    private function assertSuccess(TestResponse $response, string $action): void
    {
        $message = match ($action) {
            'activate' => 'QR code activated.', 'deactivate' => 'QR code deactivated.',
            'revoke' => 'QR code revoked.', 'restore' => 'Pass restored.',
        };
        $response->assertRedirect(route('admin.guests.show', 901))->assertSessionHasNoErrors()->assertSessionHas('success', $message);
    }

    private function assertOnlyExpectedDelta(array $expected, string $action, ?int $qrId = 501, string $restoredStatus = Guest::STATUS_PARTIALLY_USED): void
    {
        $after = $this->snapshot();
        foreach ($expected['qr_codes'] as &$row) {
            if ($row['id'] === $qrId) {
                $row['is_active'] = in_array($action, ['activate', 'restore'], true) ? 1 : 0;
                if (in_array($action, ['activate', 'restore'], true)) {
                    $row['revoked_at'] = null;
                } elseif ($action === 'revoke') {
                    $row['revoked_at'] = now()->format('Y-m-d H:i:s');
                }
            }
        }
        unset($row);
        if ($action === 'restore') {
            foreach ($expected['guests'] as &$row) {
                if ($row['id'] === 901) {
                    $row['status'] = $restoredStatus;
                }
            }
            unset($row);
        }
        $this->assertSame($expected, $after, 'Only the declared QR flags/revocation and restored Guest status may change; token, path, usage, capacity, manual code, history, neighbors and files remain exact.');
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

    private function assertContext(TestResponse $response): void
    {
        $response->assertSessionHas('selected_event_id', 301)->assertSessionHas('guest_import.marker', 'preserved');
    }

    private function assertAutocommit(): void
    {
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function inspectReads(): GuestQrWritesInspectingGrammar
    {
        $db = DB::connection();
        $original = $db->getQueryGrammar();
        $grammar = new GuestQrWritesInspectingGrammar($db);
        $db->setQueryGrammar($grammar);
        $this->beforeApplicationDestroyed(static fn () => $db->setQueryGrammar($original));

        return $grammar;
    }
}

/** Builder intent only: SQLite does not emit FOR UPDATE. */
final class GuestQrWritesInspectingGrammar extends SQLiteGrammar
{
    public bool $observing = true;

    public array $reads = [];

    public function compileSelect(Builder $query)
    {
        $sql = parent::compileSelect($query);
        if ($this->observing && DB::connection()->transactionLevel() > 0
            && (($query->lock !== null && in_array($query->from, ['events', 'users', 'offline_scanner_sessions', 'guests'], true)) || $query->from === 'qr_codes')) {
            $this->reads[] = ['table' => $query->from, 'write' => $query->useWritePdo, 'lock' => $query->lock,
                'level' => DB::connection()->transactionLevel(), 'pdo_transaction' => DB::connection()->getPdo()->inTransaction(),
                'sql' => $sql, 'bindings' => $query->getBindings()];
        }

        return $sql;
    }
}
