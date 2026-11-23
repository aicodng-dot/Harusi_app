<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminUserController;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class LegacyStaffAccountBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private array $ownedConnections = [];

    protected function tearDown(): void
    {
        foreach ($this->ownedConnections as $name) {
            DB::purge($name);
        }
        parent::tearDown();
    }

    #[DataProvider('actorModes')]
    public function test_catalogue_excludes_workspace_and_platform_identities_for_every_admin_actor(bool $platform): void
    {
        $actor = $this->fixture($platform);
        foreach (array_keys(self::protectedKinds()) as $index => $kind) {
            $id = 231 + $index;
            DB::table('users')->insert(array_replace($this->staffRow($id), ['name' => 'Private identity marker '.$id]));
            $this->protect($kind, $id);
        }
        foreach ([0, -1] as $id) {
            DB::table('users')->insert(array_replace($this->staffRow($id), ['name' => 'Unsupported identity marker '.$id, 'event_id' => null]));
        }
        $before = $this->snapshot(DB::connection());
        $writes = [];
        $this->actingAs($actor)->withSession($this->context());
        $this->captureWrites(DB::connection(), $writes, function (): void {
            $response = $this->get(route('admin.users.index'))->assertOk();
            $this->assertEqualsCanonicalizing([221, 222, 223, 224, 225], $response->viewData('users')->pluck('id')->all());
            $this->assertSame(5, $response->viewData('users')->total());
            $response->assertDontSee('Private identity marker')->assertDontSee('Unsupported identity marker')->assertDontSee('Private event marker');
            $this->assertSame('Synthetic active event', $response->viewData('users')->firstWhere('id', 221)->event->event_name);
            $this->assertContext();
        });
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function actorModes(): array
    {
        return ['member administrator' => [false], 'platform member administrator' => [true]];
    }

    #[DataProvider('protectedOperations')]
    public function test_protected_targets_fail_before_validation_without_credential_or_context_writes(string $kind, string $verb, string $action): void
    {
        $actor = $this->fixture(true);
        $this->protect($kind);
        $before = $this->snapshot(DB::connection());
        $writes = [];
        $this->actingAs($actor)->withSession($this->context());
        $this->captureWrites(DB::connection(), $writes, function () use ($verb, $action): void {
            $this->json($verb, route('admin.users.'.$action, 221), ['name' => [], 'email' => [], 'role' => 'invalid', 'is_platform_admin' => false])
                ->assertNotFound()->assertDontSee('Private event marker')->assertSessionMissing('errors')->assertSessionMissing('success');
            $this->assertContext();
        });
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function protectedOperations(): array
    {
        $cases = [];
        foreach (array_keys(self::protectedKinds()) as $kind) {
            foreach ([['GET', 'edit'], ['PUT', 'update'], ['DELETE', 'destroy']] as [$verb, $action]) {
                $cases[$kind.' '.$action] = [$kind, $verb, $action];
            }
        }

        return $cases;
    }

    private static function protectedKinds(): array
    {
        // P4-048: a staff scanner assigned to a customer ("owned") Event is a supported staff
        // account and is covered positively in ScannerCustomerEventAssignmentTest.
        return array_fill_keys(['platform', 'unknown platform', 'active member', 'inactive member', 'invalid member', 'customer', 'case folded role'], true);
    }

    public function test_create_and_edit_options_offer_only_safe_active_events_including_customer_events(): void
    {
        $actor = $this->fixture();
        DB::table('events')->insert([['id' => 0, 'event_name' => 'Unsupported zero option', 'status' => 'active'], ['id' => -1, 'event_name' => 'Unsupported negative option', 'status' => 'active']]);
        $before = $this->snapshot(DB::connection());
        $this->actingAs($actor)->withSession($this->context());
        foreach ([route('admin.users.create'), route('admin.users.edit', 221)] as $url) {
            // P4-048: active customer Events are offered, labelled as customer events.
            $response = $this->get($url)->assertOk()->assertSee('Private event marker')->assertSee('Customer:')
                ->assertDontSee('Synthetic archived event')->assertDontSee('Synthetic cancelled event')
                ->assertDontSee('Unsupported zero option')->assertDontSee('Unsupported negative option')
                ->assertDontSee('Default active event')->assertSee('Choose an event');
            $this->assertEqualsCanonicalizing([1, 301, 401], $response->viewData('activeEvents')->pluck('id')->all());
            $this->assertContext();
        }
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    #[DataProvider('invalidEventInputs')]
    public function test_scanner_creation_rejects_private_inactive_missing_and_noncanonical_events_without_writes(mixed $event): void
    {
        $actor = $this->fixture();
        $before = $this->snapshot(DB::connection());
        $writes = [];
        $this->actingAs($actor)->withSession($this->context());
        $this->captureWrites(DB::connection(), $writes, function () use ($event): void {
            $response = $this->postJson(route('admin.users.store'), $this->payload() + ['event_id' => $event])
                ->assertUnprocessable()->assertJsonValidationErrors('event_id')->assertDontSee('Private event marker');
            $this->assertSame(['Choose an active event.'], $response->json('errors.event_id'));
            $this->assertContext();
        });
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function invalidEventInputs(): array
    {
        return ['missing' => [999999], 'archived' => [302], 'cancelled' => [303],
            'leading zero' => ['0301'], 'signed' => ['+301'], 'exponent' => ['3.01e2'],
            'fraction' => [301.5], 'zero' => [0], 'array' => [[301]], 'overflow' => [(string) PHP_INT_MAX.'0']];
    }

    #[DataProvider('ambiguousEventCounts')]
    public function test_missing_scanner_event_requires_one_safe_active_choice_without_creating_a_default(int $count): void
    {
        $actor = $this->fixture();
        DB::table('events')->whereNull('workspace_id')->update(['status' => 'archived']);
        if ($count === 2) {
            DB::table('events')->whereIn('id', [301, 302])->update(['status' => 'active']);
        }
        $before = $this->snapshot(DB::connection());
        $writes = [];
        $this->actingAs($actor);
        $this->captureWrites(DB::connection(), $writes, function (): void {
            foreach ([null, ''] as $empty) {
                $response = $this->postJson(route('admin.users.store'), $this->payload() + ['event_id' => $empty])
                    ->assertUnprocessable()->assertJsonValidationErrors('event_id');
                $this->assertSame(['Choose an active event.'], $response->json('errors.event_id'));
            }
        });
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function ambiguousEventCounts(): array
    {
        return ['none' => [0], 'multiple' => [2]];
    }

    public function test_missing_scanner_event_uses_the_sole_safe_active_event_without_adopting_owned_events(): void
    {
        $actor = $this->fixture();
        DB::table('events')->whereNull('workspace_id')->where('id', '!=', 301)->update(['status' => 'archived']);
        $events = DB::table('events')->orderBy('id')->get()->all();
        $this->actingAs($actor)->post(route('admin.users.store'), $this->payload())
            ->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $created = User::query()->where('email', 'new-staff@example.invalid')->sole();
        $this->assertSame(301, $created->event_id);
        $this->assertEquals($events, DB::table('events')->orderBy('id')->get()->all());
        $this->assertDatabaseCount('workspace_users', 1);
    }

    #[DataProvider('compatibleTargets')]
    public function test_safe_staff_remain_editable_for_all_legacy_assignment_lifecycles(int $id): void
    {
        $actor = $this->fixture();
        $private = $this->privateRows();
        $target = User::query()->findOrFail($id);
        $oldHash = $target->password;
        $oldRemember = $target->remember_token;
        $this->actingAs($actor)->get(route('admin.users.edit', $target))->assertOk();
        $this->put(route('admin.users.update', $target), array_replace($this->payload(), ['email' => $target->email, 'event_id' => '301']))
            ->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $target->refresh();
        $this->assertSame(301, $target->event_id);
        $this->assertSame('Synthetic replacement gate', $target->gate_name);
        $this->assertNotSame($oldHash, $target->password);
        $this->assertNotSame($oldRemember, $target->remember_token);
        $this->assertTrue(Hash::check('replacement-password', $target->password));
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame($private, $this->privateRows());
    }

    public static function compatibleTargets(): array
    {
        return ['active' => [221], 'archived' => [223], 'cancelled' => [224], 'null' => [225]];
    }

    public function test_explicit_scanner_creation_admin_clearing_and_deletion_preserve_ownership(): void
    {
        $actor = $this->fixture();
        $private = $this->privateRows();
        $this->actingAs($actor)->post(route('admin.users.store'), $this->payload() + ['event_id' => 301, 'is_platform_admin' => true, 'workspace_id' => 101])
            ->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $created = User::query()->where('email', 'new-staff@example.invalid')->sole();
        $this->assertSame(301, $created->event_id);
        $this->assertFalse($created->is_platform_admin);
        $this->put(route('admin.users.update', $created), array_replace($this->payload(), ['email' => $created->email, 'role' => 'admin', 'event_id' => 301, 'is_platform_admin' => true]))
            ->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $created->refresh();
        $this->assertNull($created->event_id);
        $this->assertNull($created->gate_name);
        $this->assertFalse($created->is_platform_admin);
        $this->delete(route('admin.users.destroy', $created))->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseMissing('users', ['id' => $created->id]);
        $this->assertSame($private, $this->privateRows());
    }

    public function test_staff_routes_cannot_create_customer_identities(): void
    {
        $actor = $this->fixture();
        $before = $this->snapshot(DB::connection());
        $this->actingAs($actor)->postJson(route('admin.users.store'), array_replace($this->payload(), ['role' => 'customer']))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    #[DataProvider('preTransactionChanges')]
    public function test_update_rechecks_target_and_old_event_after_preflight_without_credential_side_effects(string $change): void
    {
        $actor = $this->fixture();
        $connection = DB::connection();
        $changed = false;
        $capturing = true;
        $expected = null;
        $writes = [];
        $connection->beforeStartingTransaction(function () use ($connection, $change, &$changed, &$capturing, &$expected): void {
            if ($changed) {
                return;
            }
            $this->assertSame(1, $connection->transactionLevel());
            $changed = true;
            $capturing = false;
            match ($change) {
                'membership' => $connection->table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 221, 'role' => 'owner', 'is_active' => false]),
                'platform' => $connection->table('users')->where('id', 221)->update(['is_platform_admin' => true]),
                'old event ownership' => $connection->table('events')->where('id', 301)->update(['workspace_id' => 101]),
                'assignment drift' => $connection->table('users')->where('id', 221)->update(['event_id' => 302]),
            };
            $expected = $this->snapshot($connection);
            $capturing = true;
        });
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$writes, &$capturing): void {
            if ($capturing && preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $this->actingAs($actor)->withSession($this->context())->putJson(route('admin.users.update', 221), $this->payload() + ['event_id' => 301])->assertNotFound();
        } finally {
            $capturing = false;
            $this->restoreDispatcher($connection, $original);
        }
        $this->assertTrue($changed);
        $this->assertSame([], $writes);
        $this->assertSame($expected, $this->snapshot($connection));
        $this->assertContext();
    }

    public static function preTransactionChanges(): array
    {
        // P4-048: the old Event becoming customer-owned is no longer a refusal reason.
        return ['membership' => ['membership'], 'platform' => ['platform'], 'assignment' => ['assignment drift']];
    }

    public function test_current_membership_is_checked_after_the_user_lock_before_credentials_are_written(): void
    {
        $actor = $this->fixture();
        $connection = DB::connection();
        $before = $this->snapshot($connection);
        $changed = false;
        $capturing = true;
        $writes = [];
        $tables = [];
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use ($connection, &$changed, &$capturing, &$writes, &$tables): void {
            $table = $this->selectedTable($query->sql);
            if ($connection->transactionLevel() > 1 && $table !== null) {
                $tables[] = $table;
            }
            if ($capturing && preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
            if (! $changed && $connection->transactionLevel() > 1 && $table === 'users' && in_array(221, $query->bindings, true)) {
                // A same-transaction fixture proves a fresh membership lookup;
                // SQLite does not establish cross-connection lock blocking.
                $changed = true;
                $capturing = false;
                $connection->table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 221, 'role' => 'manager', 'is_active' => false]);
                $capturing = true;
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $this->actingAs($actor)->putJson(route('admin.users.update', 221), $this->payload() + ['event_id' => 301])->assertNotFound();
        } finally {
            $capturing = false;
            $this->restoreDispatcher($connection, $original);
        }
        $this->assertTrue($changed);
        $this->assertContains('workspace_users', $tables);
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($connection));
    }

    #[DataProvider('parentOrderCases')]
    public function test_parent_events_are_locked_in_exact_integer_order_before_user_and_membership(int $old, int $new): void
    {
        $actor = $this->fixture();
        $connection = DB::connection();
        if ($old !== 303) {
            $connection->table('events')->insert([
                ['id' => $old, 'event_name' => 'Synthetic large old parent', 'status' => 'cancelled'],
                ['id' => $new, 'event_name' => 'Synthetic large new parent', 'status' => 'active'],
            ]);
            $connection->table('users')->where('id', 224)->update(['event_id' => $old]);
        }
        $queries = [];
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use ($connection, &$queries): void {
            if ($connection->transactionLevel() > 1 && $this->selectedTable($query->sql) !== null) {
                $queries[] = ['table' => $this->selectedTable($query->sql), 'bindings' => $query->bindings];
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $this->actingAs($actor)->put(route('admin.users.update', 224), array_replace($this->payload(), ['email' => 'staff-224@example.invalid', 'event_id' => (string) $new]))
                ->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        } finally {
            $this->restoreDispatcher($connection, $original);
        }
        $eventIds = [];
        $userIndex = null;
        $membershipIndex = null;
        foreach ($queries as $index => $query) {
            if ($query['table'] === 'events') {
                $this->assertNull($userIndex, 'No new parent may be locked after the User.');
                foreach ($query['bindings'] as $binding) {
                    if (in_array($binding, [$old, $new], true) && ! in_array($binding, $eventIds, true)) {
                        $eventIds[] = $binding;
                    }
                }
            } elseif ($query['table'] === 'users') {
                $userIndex ??= $index;
            } elseif ($query['table'] === 'workspace_users') {
                $membershipIndex = $index;
            }
        }
        $this->assertSame([$new, $old], $eventIds);
        $this->assertNotNull($userIndex);
        $this->assertNotNull($membershipIndex);
        $this->assertGreaterThan($userIndex, $membershipIndex);
    }

    public static function parentOrderCases(): array
    {
        return ['ordinary integers' => [303, 301], 'adjacent maximum signed integers' => [PHP_INT_MAX, PHP_INT_MAX - 1]];
    }

    public function test_token_failure_rolls_back_password_remember_token_assignment_and_user_row(): void
    {
        $actor = $this->fixture();
        $connection = DB::connection();
        $before = $this->snapshot($connection);
        $armed = true;
        $connection->beforeExecuting(static function (string $sql) use (&$armed): void {
            if ($armed && preg_match('/\A\s*delete\s+from\s+["\x60]?personal_access_tokens\b/i', $sql)) {
                throw new RuntimeException('synthetic-staff-token-delete-failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($actor)->put(route('admin.users.update', 221), $this->payload() + ['event_id' => 301]);
            $this->fail('Token deletion failure must roll the credential change back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic-staff-token-delete-failure', $exception->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertSame($before, $this->snapshot($connection));
    }

    #[DataProvider('unsafeIdentityIds')]
    public function test_noncanonical_bound_identity_fails_before_any_target_lookup(mixed $id): void
    {
        $actor = $this->fixture();
        $target = User::query()->findOrFail(221);
        $target->setRawAttributes(array_replace($target->getRawOriginal(), ['id' => $id]), true);
        $connection = DB::connection();
        $before = $this->snapshot($connection);
        $queries = [];
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $this->actingAs($actor);
            $this->assertNotFoundOperation(static fn () => app(AdminUserController::class)->edit($target));
        } finally {
            $this->restoreDispatcher($connection, $original);
        }
        $this->assertSame([], $queries);
        $this->assertSame($before, $this->snapshot($connection));
    }

    public static function unsafeIdentityIds(): array
    {
        return ['leading zero' => ['0221'], 'signed' => ['+221'], 'float' => [221.0], 'zero' => [0], 'negative' => [-1], 'overflow' => [(string) PHP_INT_MAX.'0']];
    }

    public function test_stale_bound_staff_cannot_bypass_new_workspace_membership(): void
    {
        $actor = $this->fixture();
        $stale = User::query()->findOrFail(221);
        $this->protect('inactive member');
        Route::bind('user', static fn () => $stale);
        $before = $this->snapshot(DB::connection());
        $this->actingAs($actor)->get(route('admin.users.edit', 221))->assertNotFound();
        $this->putJson(route('admin.users.update', 221), $this->payload() + ['event_id' => 301])->assertNotFound();
        $this->deleteJson(route('admin.users.destroy', 221))->assertNotFound();
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public function test_catalogue_options_and_targets_use_primary_without_an_ambient_transaction(): void
    {
        $actor = $this->fixture();
        $source = DB::connection();
        $sourceBefore = $this->snapshot($source);
        $connection = $this->cloneConnection($source);
        $target = User::on($connection->getName())->useWritePdo()->findOrFail(221);
        $before = $this->snapshot($connection);
        $read = $connection->getReadPdo();
        $attempts = 0;
        $connection->setReadPdo(static function () use (&$attempts): never {
            $attempts++;
            throw new RuntimeException('synthetic-staff-unavailable-replica');
        });
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection($connection->getName());
        $this->actingAs($actor);
        try {
            $controller = app(AdminUserController::class);
            $index = $controller->index()->getData()['users'];
            $this->assertEqualsCanonicalizing([221, 222, 223, 224, 225], $index->pluck('id')->all());
            $this->assertSame('Synthetic active event', $index->firstWhere('id', 221)->event->event_name);
            $this->assertEqualsCanonicalizing([1, 301, 401], $controller->create()->getData()['activeEvents']->pluck('id')->all());
            $this->assertSame(221, $controller->edit($target)->getData()['userRecord']->id);
        } finally {
            $connection->setReadPdo($read);
            DB::setDefaultConnection($default);
        }
        $this->assertSame(0, $attempts);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame($sourceBefore, $this->snapshot($source));
    }

    #[DataProvider('proposedParentChanges')]
    public function test_store_rechecks_proposed_parent_after_validation_and_before_user_insert(string $change): void
    {
        $actor = $this->fixture();
        $connection = DB::connection();
        $connection->table('events')->insert(['id' => 901, 'event_name' => 'Synthetic proposed parent', 'status' => 'active', 'workspace_id' => null]);
        $changed = false;
        $capturing = true;
        $expected = null;
        $writes = [];
        $connection->beforeStartingTransaction(function () use ($connection, $change, &$changed, &$capturing, &$expected): void {
            if ($changed) {
                return;
            }
            $this->assertSame(1, $connection->transactionLevel());
            $changed = true;
            $capturing = false;
            match ($change) {
                'owned' => $connection->table('events')->where('id', 901)->update(['workspace_id' => 101]),
                'archived' => $connection->table('events')->where('id', 901)->update(['status' => 'archived']),
                'deleted' => $connection->table('events')->where('id', 901)->delete(),
            };
            $expected = $this->snapshot($connection);
            $capturing = true;
        });
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$writes, &$capturing): void {
            if ($capturing && preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $response = $this->actingAs($actor)->withSession($this->context())->postJson(route('admin.users.store'), $this->payload() + ['event_id' => 901])
                ->assertUnprocessable()->assertJsonValidationErrors('event_id');
            $this->assertSame(['Choose an active event.'], $response->json('errors.event_id'));
        } finally {
            $capturing = false;
            $this->restoreDispatcher($connection, $original);
        }
        $this->assertTrue($changed);
        $this->assertSame([], $writes);
        $this->assertSame($expected, $this->snapshot($connection));
        $this->assertContext();
    }

    public static function proposedParentChanges(): array
    {
        // P4-048: a proposed Event becoming customer-owned stays assignable; lifecycle/deletion still refuse.
        return ['archived' => ['archived'], 'deleted' => ['deleted']];
    }

    #[DataProvider('destroyChanges')]
    public function test_destroy_rechecks_membership_and_assignment_before_deleting_credentials(string $change): void
    {
        $actor = $this->fixture();
        $connection = DB::connection();
        $changed = false;
        $capturing = true;
        $expected = null;
        $writes = [];
        $connection->beforeStartingTransaction(function () use ($connection, $change, &$changed, &$capturing, &$expected): void {
            if ($changed) {
                return;
            }
            $this->assertSame(1, $connection->transactionLevel());
            $changed = true;
            $capturing = false;
            if ($change === 'membership') {
                $connection->table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 221, 'role' => 'manager', 'is_active' => false]);
            } else {
                $connection->table('users')->where('id', 221)->update(['event_id' => 302]);
            }
            $expected = $this->snapshot($connection);
            $capturing = true;
        });
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$writes, &$capturing): void {
            if ($capturing && preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $this->actingAs($actor)->withSession($this->context())->deleteJson(route('admin.users.destroy', 221))->assertNotFound();
        } finally {
            $capturing = false;
            $this->restoreDispatcher($connection, $original);
        }
        $this->assertTrue($changed);
        $this->assertSame([], $writes);
        $this->assertSame($expected, $this->snapshot($connection));
        $this->assertContext();
    }

    public static function destroyChanges(): array
    {
        return ['membership appeared' => ['membership'], 'assignment drift' => ['assignment']];
    }

    private function fixture(bool $platform = false): User
    {
        $connection = DB::connection();
        $connection->table('workspaces')->insert([['id' => 101, 'name' => 'Synthetic workspace'], ['id' => 102, 'name' => 'Other synthetic workspace']]);
        $connection->table('events')->insert([
            ['id' => 301, 'event_name' => 'Synthetic active event', 'status' => 'active', 'workspace_id' => null],
            ['id' => 302, 'event_name' => 'Synthetic archived event', 'status' => 'archived', 'workspace_id' => null],
            ['id' => 303, 'event_name' => 'Synthetic cancelled event', 'status' => 'cancelled', 'workspace_id' => null],
            ['id' => 401, 'event_name' => 'Private event marker', 'status' => 'active', 'workspace_id' => 101],
        ]);
        $connection->table('users')->insert(array_replace($this->staffRow(211), ['name' => 'Synthetic actor', 'role' => 'admin', 'event_id' => null, 'gate_name' => null, 'is_platform_admin' => $platform]));
        foreach ([221 => 301, 222 => null, 223 => 302, 224 => 303, 225 => null] as $id => $event) {
            $connection->table('users')->insert(array_replace($this->staffRow($id), ['role' => $id === 222 ? 'admin' : 'scanner', 'event_id' => $event]));
        }
        $connection->table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 211, 'role' => 'owner', 'is_active' => true]);
        foreach ([221, 223, 224, 225] as $id) {
            User::query()->findOrFail($id)->createToken('synthetic-staff-device');
        }
        $connection->table('password_reset_tokens')->insert(['email' => 'staff-221@example.invalid', 'token' => 'synthetic-hashed-reset-token', 'created_at' => '2026-10-22 00:00:00']);

        return User::query()->findOrFail(211);
    }

    private function staffRow(int $id): array
    {
        return ['id' => $id, 'name' => 'Synthetic scanner '.$id, 'email' => 'staff-'.$id.'@example.invalid',
            'password' => Hash::make('old-staff-password'), 'role' => 'scanner', 'event_id' => 301,
            'gate_name' => 'Synthetic old gate', 'is_active' => true, 'is_platform_admin' => false,
            'preferred_language' => 'en', 'remember_token' => 'synthetic-old-remember-token'];
    }

    private function protect(string $kind, int $id = 221): void
    {
        $connection = DB::connection();
        match ($kind) {
            'owned' => $connection->table('users')->where('id', $id)->update(['event_id' => 401]),
            'platform' => $connection->table('users')->where('id', $id)->update(['is_platform_admin' => true]),
            'unknown platform' => $connection->table('users')->where('id', $id)->update(['is_platform_admin' => 2]),
            'active member' => $connection->table('workspace_users')->insert(['workspace_id' => 102, 'user_id' => $id, 'role' => 'manager', 'is_active' => true]),
            'inactive member' => $connection->table('workspace_users')->insert(['workspace_id' => 102, 'user_id' => $id, 'role' => 'owner', 'is_active' => false]),
            'invalid member' => $this->insertInvalidMembership($connection, $id),
            'customer' => $connection->table('users')->where('id', $id)->update(['role' => 'customer', 'event_id' => null]),
            'case folded role' => $connection->table('users')->where('id', $id)->update(['role' => 'ADMIN', 'event_id' => null]),
        };
    }

    private function insertInvalidMembership(Connection $connection, int $id): bool
    {
        // Simulate pre-existing corrupt membership data without changing schema.
        $connection->statement('PRAGMA ignore_check_constraints = ON');
        try {
            return $connection->table('workspace_users')->insert(['workspace_id' => 102, 'user_id' => $id, 'role' => 'unrecognized', 'is_active' => true]);
        } finally {
            $connection->statement('PRAGMA ignore_check_constraints = OFF');
        }
    }

    private function payload(): array
    {
        return ['name' => 'Synthetic replacement staff', 'email' => 'new-staff@example.invalid', 'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password', 'role' => 'scanner', 'gate_name' => 'Synthetic replacement gate'];
    }

    private function context(): array
    {
        return ['selected_event_id' => 301, 'selected_workspace_id' => 101, 'guest_import' => ['preview_token' => 'synthetic-preserve-token', 'result' => ['rows' => 2]]];
    }

    private function assertContext(): void
    {
        foreach ($this->context() as $key => $value) {
            $this->assertSame($value, session($key));
        }
    }

    private function selectedTable(string $sql): ?string
    {
        if (preg_match('/\A\s*select\b/i', $sql) && preg_match('/\bfrom\s+["\x60]?([a-z_]+)["\x60]?/i', $sql, $match)) {
            return strtolower($match[1]);
        }

        return null;
    }

    private function assertNotFoundOperation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The protected staff identity must fail before use.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    private function captureWrites(Connection $connection, array &$writes, callable $operation): void
    {
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $operation();
        } finally {
            $this->restoreDispatcher($connection, $original);
        }
    }

    private function restoreDispatcher(Connection $connection, mixed $dispatcher): void
    {
        if ($dispatcher !== null) {
            $connection->setEventDispatcher($dispatcher);
        } else {
            $connection->unsetEventDispatcher();
        }
    }

    private function privateRows(): array
    {
        return ['workspaces' => DB::table('workspaces')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'memberships' => DB::table('workspace_users')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'events' => DB::table('events')->whereNotNull('workspace_id')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all()];
    }

    private function cloneConnection(Connection $source): Connection
    {
        $name = 'legacy_staff_boundary_'.count($this->ownedConnections);
        $this->ownedConnections[] = $name;
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        $connection = DB::connection($name);
        $objects = $source->selectFromWriteConnection("SELECT type, name, sql FROM sqlite_master WHERE type IN ('table','index') AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name");
        foreach ($objects as $object) {
            $connection->statement($object->sql);
        }
        foreach ($objects as $object) {
            if ($object->type === 'table') {
                foreach ($source->table($object->name)->useWritePdo()->get() as $row) {
                    $connection->table($object->name)->insert((array) $row);
                }
            }
        }
        $connection->getSchemaBuilder()->enableForeignKeyConstraints();

        return $connection;
    }

    private function snapshot(Connection $connection): array
    {
        $rows = [];
        foreach ($connection->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $table) {
            $contents = array_map(static fn (object $row): array => (array) $row, $connection->table($table->name)->useWritePdo()->get()->all());
            usort($contents, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            $rows[$table->name] = $contents;
        }

        return ['schema' => array_map(static fn (object $row): array => (array) $row,
            $connection->selectFromWriteConnection('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')), 'rows' => $rows];
    }
}
