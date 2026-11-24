<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CustomerHomeBoundaryTest extends TestCase
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

    #[DataProvider('languages')]
    public function test_home_lists_only_own_active_workspaces_with_escaped_localized_independent_html(string $language, string $title, string $empty): void
    {
        $users = $this->fixture();
        $customer = $users['customer']->forceFill(['preferred_language' => $language]);
        $customer->save();
        $composed = false;
        View::composer('layouts.admin', static function () use (&$composed): void {
            $composed = true;
        });

        $response = $this->readHome($customer);

        $response->assertOk()->assertViewIs('reference_ui.20_my_events_list')
            ->assertViewHas('language', $language)->assertViewHas('accountName', $customer->name)
            ->assertSee('lang="'.$language.'"', false)->assertSee($title)
            ->assertSee(e($customer->name), false)->assertDontSee($customer->name, false)
            ->assertSee(e('<img src=x onerror=alert(1)>'), false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertDontSee('Foreign customer workspace')->assertDontSee('Revoked own workspace')
            ->assertDontSee('Noncanonical active membership')->assertDontSee('Unassigned workspace')
            ->assertDontSee('Synthetic private event')->assertDontSee($users['other']->email);
        $workspaces = $this->workspaces($response);
        $this->assertCount(2, $workspaces);
        $this->assertEqualsCanonicalizing([101, 102], $workspaces->pluck('id')->all());
        $byId = $workspaces->keyBy('id');
        $this->assertSame('owner', $byId[101]->membership_role);
        $this->assertSame('manager', $byId[102]->membership_role);
        $this->assertFalse($composed);
        $this->assertPrivateHeaders($response);
        $this->assertLogoutOnlyHtml($response);
    }

    #[DataProvider('languages')]
    public function test_empty_home_does_not_adopt_foreign_or_revoked_memberships(string $language, string $title, string $empty): void
    {
        $users = $this->fixture(false);
        $customer = $users['customer']->forceFill(['preferred_language' => $language]);
        $customer->save();

        $response = $this->readHome($customer);

        $response->assertOk()->assertViewHas('language', $language)
            ->assertSee($title)->assertSee($empty)
            ->assertDontSee('Foreign customer workspace')->assertDontSee('Revoked own workspace');
        $this->assertCount(0, $this->workspaces($response));
        $this->assertNull($response->viewData('events'));
        $this->assertLogoutOnlyHtml($response);
        $this->assertPrivateHeaders($response);
    }

    public static function languages(): array
    {
        return ['English' => ['en', 'My Events', 'No events yet'],
            'Swahili' => ['sw', 'Matukio Yangu', 'Bado huna tukio']];
    }

    public function test_pagination_is_bounded_and_ignores_submitted_account_workspace_event_and_size(): void
    {
        $users = $this->fixture();
        $connection = DB::connection();
        $expected = [];
        for ($number = 1; $number <= 15; $number++) {
            $id = 600 + $number;
            $connection->table('events')->insert(['id' => $id, 'event_name' => sprintf('Synthetic own event %02d', $number),
                'workspace_id' => $number % 2 ? 101 : 102, 'status' => 'active', 'event_date' => sprintf('2031-01-%02d', $number)]);
            $expected[] = $id;
        }
        $query = '?user_id=212&workspace_id=103&event_id=301&per_page=1000&role=admin&is_platform_admin=1';

        $first = $this->readHome($users['customer'], '/customer'.$query);
        $second = $this->readHome($users['customer'], '/customer'.$query.'&page=2');

        foreach ([$first, $second] as $response) {
            $response->assertOk()->assertDontSee('Foreign customer workspace')->assertDontSee('Synthetic private event');
            $this->assertCount(2, $this->workspaces($response));
            $this->assertSame(15, $this->events($response)->total());
            $this->assertSame(10, $this->events($response)->perPage());
        }
        $this->assertCount(10, $this->events($first));
        $this->assertCount(5, $this->events($second));
        $all = [...$this->events($first)->pluck('id')->all(), ...$this->events($second)->pluck('id')->all()];
        $this->assertCount(15, array_unique($all));
        $this->assertEqualsCanonicalizing($expected, $all);
    }

    public function test_home_is_exact_customer_role_and_guest_protected_with_one_new_route(): void
    {
        $users = $this->fixture();
        $route = Route::getRoutes()->getByName('customer.home');
        $this->assertNotNull($route);
        $this->assertSame('customer', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('role:customer', $route->gatherMiddleware());
        // The 100-route inventory gained only the public onboarding pages (Working Flows 02-03).
        foreach (['reference_ui.10_welcome_how_it_works.show', 'register', 'register.store', 'terms'] as $name) {
            $public = Route::getRoutes()->getByName($name);
            $this->assertNotNull($public);
            $this->assertSame([], array_values(preg_grep('/^(auth|role:|admin|scanner|can:)/', $public->gatherMiddleware())));
        }
        // Plus the customer event-type (Working Flow 09) and event-details (Working Flow 10) routes.
        foreach (['customer.events.create', 'customer.events.type', 'customer.events.details', 'customer.events.store', 'customer.events.setup', 'customer.events.dashboard', 'customer.events.template', 'customer.events.template.store'] as $name) {
            $this->assertContains('role:customer', Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
        // Invitation workflow: 14 customer routes (incl. Continue Invitation) behind auth + role:customer, plus the signed media link.
        $invitation = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'customer.events.invitation'));
        $this->assertCount(14, $invitation);
        foreach ($invitation as $route) {
            $this->assertContains('role:customer', $route->gatherMiddleware());
            $this->assertSame('[0-9]+', $route->wheres['event']);
        }
        $this->assertContains('signed', Route::getRoutes()->getByName('invitations.media')->gatherMiddleware());
        // Add/Import/Edit/Delete Guest + paste/review/bulk/CSV template/bulk delete: 13 customer routes behind auth + role:customer, numeric event id.
        $guests = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'customer.events.guests'));
        $this->assertCount(13, $guests);
        foreach ($guests as $route) {
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('role:customer', $route->gatherMiddleware());
            $this->assertSame('[0-9]+', $route->wheres['event']);
        }
        // Invitation Sharing Center: 13 customer routes (auth + role:customer, numeric ids) and the
        // throttled guest-facing invitation link pair (/i/{key}, /i/{key}/card.png; key-scoped, no session).
        $share = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'customer.events.share'));
        $this->assertCount(13, $share);
        foreach ($share as $route) {
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('role:customer', $route->gatherMiddleware());
            $this->assertSame('[0-9]+', $route->wheres['event']);
        }
        foreach (['invitations.public', 'invitations.public.card'] as $name) {
            $this->assertContains('throttle:60,1', Route::getRoutes()->getByName($name)->gatherMiddleware());
            $this->assertSame('[A-Za-z0-9_-]{32}', Route::getRoutes()->getByName($name)->wheres['key']);
        }
        // + 3 guest edit/delete routes, 6 customer self-service routes, 7 admin customer-management routes,
        // + 3 guest entry routes (review, bulk, CSV template), the customer Help page and guest bulk delete,
        // + 13 sharing routes and 2 public invitation-link routes,
        // + 4 owner RSVP routes and the guest RSVP reply (throttle:rsvp, key-scoped).
        $this->assertContains('throttle:rsvp', Route::getRoutes()->getByName('invitations.public.rsvp')->gatherMiddleware());
        $this->assertCount(174, Route::getRoutes());
        $before = $this->snapshot(DB::connection());

        $this->get('/customer')->assertRedirect(route('login'));
        foreach (['admin', 'scanner'] as $role) {
            $this->actingAs($users[$role])->get('/customer')->assertForbidden();
        }

        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public function test_primary_account_and_membership_reads_work_without_a_replica_or_ambient_transaction(): void
    {
        $this->fixture();
        $source = DB::connection();
        $sourceBefore = $this->snapshot($source);
        $connection = $this->cloneConnection($source);
        $this->assertSame(0, $connection->transactionLevel());
        // This task-owned malformed row must not appear without its real parent.
        $connection->getSchemaBuilder()->disableForeignKeyConstraints();
        $connection->table('workspace_users')->insert(['workspace_id' => 999999, 'user_id' => 211, 'role' => 'owner', 'is_active' => true]);
        $connection->getSchemaBuilder()->enableForeignKeyConstraints();
        $customer = User::on($connection->getName())->findOrFail(211);
        $read = $connection->getReadPdo();
        $attempts = 0;
        $connection->setReadPdo(static function () use (&$attempts): never {
            $attempts++;
            throw new RuntimeException('private-customer-replica');
        });
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection($connection->getName());
        try {
            $response = $this->readHome($customer);
            $response->assertOk();
            $this->assertEqualsCanonicalizing([101, 102], $this->workspaces($response)->pluck('id')->all());
            $this->assertCount(2, $this->workspaces($response));
            $this->assertSame($connection->getName(), DB::getDefaultConnection());
        } finally {
            $connection->setReadPdo($read);
            DB::setDefaultConnection($default);
        }
        $this->assertSame(0, $attempts);
        $this->assertSame($sourceBefore, $this->snapshot($source));
    }

    #[DataProvider('currentAccountChanges')]
    public function test_home_query_rechecks_account_state_after_the_earlier_middleware_observation(array $change): void
    {
        $users = $this->fixture();
        $connection = DB::connection();
        $changed = false;
        $expected = null;
        $connection->beforeExecuting(function (string $sql) use ($connection, $change, &$changed, &$expected): void {
            if (! $changed && preg_match('/\bfrom\s+["\x60]?workspaces\b/i', $sql)) {
                // Deterministic fixture transition after role middleware, before
                // the home count/read. This is not a parallel-engine race test.
                $changed = true;
                $connection->table('users')->where('id', 211)->update($change);
                $expected = $this->snapshot($connection);
            }
        });

        $response = $this->actingAs($users['customer'])->get('/customer');

        $response->assertOk();
        $this->assertTrue($changed);
        $this->assertCount(0, $this->workspaces($response));
        $this->assertNull($response->viewData('events'));
        $this->assertSame($expected, $this->snapshot($connection));
    }

    public static function currentAccountChanges(): array
    {
        return ['suspended after middleware' => [['is_active' => false]],
            'role changed after middleware' => [['role' => 'admin']]];
    }

    public function test_legacy_index_lists_only_known_staff_accounts_without_customer_identity_details(): void
    {
        $users = $this->fixture();
        $before = $this->snapshot(DB::connection());

        $response = $this->actingAs($users['admin'])->get(route('admin.users.index'));

        $response->assertOk()->assertDontSee($users['customer']->email)
            ->assertDontSee($users['other']->email)->assertDontSee($users['unsupported']->email);
        $response->assertViewHas('users', function ($paginator): bool {
            $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
            $this->assertSame(2, $paginator->total());
            $this->assertEqualsCanonicalizing([213, 214], $paginator->pluck('id')->all());

            return true;
        });
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    #[DataProvider('protectedLegacyTargets')]
    public function test_legacy_customer_and_unsupported_crud_is_404_before_validation_even_for_platform_actor(bool $platform, string $targetKey): void
    {
        $users = $this->fixture();
        $admin = $users['admin']->forceFill(['is_platform_admin' => $platform]);
        $admin->save();
        $target = $users[$targetKey];
        $before = $this->snapshot(DB::connection());
        $writes = [];

        $this->captureWrites(DB::connection(), $writes, function () use ($admin, $target): void {
            $this->actingAs($admin)->get(route('admin.users.edit', $target))->assertNotFound();
            $this->putJson(route('admin.users.update', $target), ['name' => [], 'email' => 'invalid', 'role' => 'customer'])
                ->assertNotFound();
            $this->deleteJson(route('admin.users.destroy', $target))->assertNotFound();
        });

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function protectedLegacyTargets(): array
    {
        return ['customer / staff actor' => [false, 'customer'], 'unsupported / staff actor' => [false, 'unsupported'],
            'customer / platform actor' => [true, 'customer'], 'unsupported / platform actor' => [true, 'unsupported']];
    }

    #[DataProvider('legacyRoleInjectionMethods')]
    public function test_legacy_role_validation_cannot_create_or_promote_customer_accounts(string $method): void
    {
        $users = $this->fixture();
        $before = $this->snapshot(DB::connection());
        $payload = $this->validStaffPayload($users['scanner']) + ['is_platform_admin' => true];
        $payload['role'] = 'customer';
        $this->actingAs($users['admin']);
        $response = $method === 'store'
            ? $this->postJson(route('admin.users.store'), array_replace($payload, ['email' => 'synthetic-new@example.invalid']))
            : $this->putJson(route('admin.users.update', $users['scanner']), $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    public static function legacyRoleInjectionMethods(): array
    {
        return ['store' => ['store'], 'update' => ['update']];
    }

    public function test_stale_bound_staff_model_cannot_open_customer_validation_or_crud(): void
    {
        $users = $this->fixture();
        $stale = $users['scanner'];
        DB::table('users')->where('id', $stale->id)->update(['role' => 'customer']);
        Route::bind('user', static fn () => $stale);
        $before = $this->snapshot(DB::connection());

        $this->actingAs($users['admin'])->putJson(route('admin.users.update', $stale), ['name' => [], 'role' => 'admin'])
            ->assertNotFound();

        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    #[DataProvider('lockedConversions')]
    public function test_locked_target_rechecks_customer_conversion_after_prevalidation(string $method): void
    {
        $users = $this->fixture();
        $connection = DB::connection();
        $target = $users['scanner'];
        Route::bind('user', static fn () => $target);
        $checks = 0;
        $converted = false;
        $capturing = true;
        $expected = null;
        $writes = [];
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($target, &$checks, &$capturing, &$writes): void {
            if (preg_match('/\bfrom\s+["\x60]?users\b/i', $event->sql) && str_contains($event->sql, ' in (')
                && in_array($target->id, $event->bindings, true)) {
                $checks++;
            }
            if ($capturing && preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        $connection->setEventDispatcher($dispatcher);
        $connection->beforeStartingTransaction(function () use ($connection, $target, &$checks, &$converted, &$capturing, &$expected): void {
            if ($converted) {
                return;
            }
            // Simulate a committed-to-the-caller fixture change immediately
            // before the controller's nested write transaction begins.
            $this->assertSame(1, $checks);
            $this->assertSame(1, $connection->transactionLevel());
            $converted = true;
            $capturing = false;
            $connection->table('users')->where('id', $target->id)->update(['role' => 'customer']);
            $expected = $this->snapshot($connection);
            $capturing = true;
        });
        try {
            $this->actingAs($users['admin']);
            $response = $method === 'update'
                ? $this->putJson(route('admin.users.update', $target), $this->validStaffPayload($target))
                : $this->deleteJson(route('admin.users.destroy', $target));
            $response->assertNotFound();
        } finally {
            $capturing = false;
            if ($original !== null) {
                $connection->setEventDispatcher($original);
            } else {
                $connection->unsetEventDispatcher();
            }
        }
        $this->assertTrue($converted);
        $this->assertSame([], $writes);
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertSame($expected, $this->snapshot($connection));
        $this->assertSame('customer', $connection->table('users')->where('id', $target->id)->value('role'));
    }

    public static function lockedConversions(): array
    {
        return ['update' => ['update'], 'delete' => ['delete']];
    }

    private function readHome(User $customer, string $url = '/customer'): TestResponse
    {
        $connection = DB::connection();
        $before = $this->snapshot($connection);
        $level = $connection->transactionLevel();
        $transaction = $connection->getPdo()->inTransaction();
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $queries = [];
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $response = $this->actingAs($customer)->get($url);
        } finally {
            if ($original !== null) {
                $connection->setEventDispatcher($original);
            } else {
                $connection->unsetEventDispatcher();
            }
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query);
            $this->assertDoesNotMatchRegularExpression('/\b(?:from|join)\s+["\x60]?(?:qr_codes|checkins|admissions|scan_logs)\b/i', $query);
            if (preg_match('/\b(?:from|join)\s+["\x60]?(?:events|guests)\b/i', $query)) {
                // Working Flow 08: event reads must carry the active owner/manager membership check.
                $this->assertMatchesRegularExpression('/\bexists\s*\(\s*select\b.*["\x60]?workspace_users["\x60]?.*["\x60]?is_active["\x60]?\s*=\s*\?.*in\s*\(\s*\?\s*,\s*\?\s*\)/is', $query);
            }
        }
        $this->assertSame($level, $connection->transactionLevel());
        $this->assertSame($transaction, $connection->getPdo()->inTransaction());
        $this->assertSame($before, $this->snapshot($connection));

        return $response;
    }

    private function workspaces(TestResponse $response): Collection
    {
        $workspaces = $response->viewData('workspaces');
        $this->assertInstanceOf(Collection::class, $workspaces);

        return $workspaces;
    }

    private function events(TestResponse $response): LengthAwarePaginator
    {
        $paginator = $response->viewData('events');
        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);

        return $paginator;
    }

    private function assertPrivateHeaders(TestResponse $response): void
    {
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);
    }

    private function assertLogoutOnlyHtml(TestResponse $response): void
    {
        $html = $response->getContent();
        $document = new DOMDocument;
        $this->assertTrue($document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new DOMXPath($document);
        // The page and the navigation shell may each carry a sign-out form; nothing else posts anywhere.
        $forms = $xpath->query('//form');
        $this->assertGreaterThanOrEqual(1, $forms->length);
        foreach ($forms as $form) {
            $this->assertSame('post', strtolower($form->getAttribute('method')));
            $this->assertSame(route('logout'), $form->getAttribute('action'));
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        }
        foreach ($xpath->query('//a[@href]') as $link) {
            $path = parse_url($link->getAttribute('href'), PHP_URL_PATH);
            $this->assertTrue(in_array($path, ['/customer', '/customer/workspaces/create', '/customer/events/create', '/customer/profile', '/customer/help', null, ''], true), 'Only customer list, workspace creation, event-type and own-profile navigation is supported.');
        }
        $this->assertStringNotContainsString('/admin/', $html);
        $this->assertStringNotContainsString('/scanner/', $html);
    }

    private function captureWrites(Connection $connection, array &$writes, callable $operation): void
    {
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$writes): void {
            if (preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $operation();
        } finally {
            if ($original !== null) {
                $connection->setEventDispatcher($original);
            } else {
                $connection->unsetEventDispatcher();
            }
        }
    }

    private function validStaffPayload(User $target): array
    {
        return ['name' => 'Synthetic attempted replacement', 'email' => $target->email,
            'password' => 'SyntheticChangedPassword!123', 'password_confirmation' => 'SyntheticChangedPassword!123',
            'role' => 'admin', 'is_active' => true, 'preferred_language' => 'en', 'event_id' => null, 'gate_name' => null];
    }

    private function fixture(bool $ownMemberships = true): array
    {
        $connection = DB::connection();
        $password = bcrypt('synthetic-existing-password');
        foreach ([211 => ['customer', '<script>synthetic account</script>'], 212 => ['customer', 'Foreign customer name'],
            213 => ['admin', 'Synthetic legacy admin'], 214 => ['scanner', 'Synthetic legacy scanner'],
            215 => ['unsupported', 'Synthetic unsupported account']] as $id => [$role, $name]) {
            $connection->table('users')->insert(['id' => $id, 'name' => $name, 'email' => 'synthetic-'.$id.'@example.invalid',
                'phone_number' => '+255712340'.$id, 'password' => $password, 'remember_token' => 'synthetic-remember-'.$id,
                'role' => $role, 'is_active' => true, 'is_platform_admin' => false, 'preferred_language' => 'en']);
        }
        foreach ([101 => '<img src=x onerror=alert(1)>', 102 => 'Own manager workspace', 103 => 'Foreign customer workspace',
            104 => 'Revoked own workspace', 105 => 'Unassigned workspace', 106 => 'Noncanonical active membership'] as $id => $name) {
            $connection->table('workspaces')->insert(['id' => $id, 'name' => $name]);
        }
        $memberships = [[103, 212, 'owner', 1], [104, 211, 'owner', 0], [106, 211, 'owner', 2]];
        if ($ownMemberships) {
            $memberships = [...$memberships, [101, 211, 'owner', 1], [102, 211, 'manager', 1]];
        }
        foreach ($memberships as [$workspace, $user, $role, $active]) {
            $connection->table('workspace_users')->insert(['workspace_id' => $workspace, 'user_id' => $user, 'role' => $role, 'is_active' => $active]);
        }
        $connection->table('events')->insert(['id' => 301, 'event_name' => 'Synthetic private event', 'workspace_id' => 103, 'status' => 'active']);
        $connection->table('guests')->insert(['id' => 401, 'event_id' => 301, 'name' => 'Synthetic private guest',
            'phone_number' => '+255712345678', 'manual_code' => '0042', 'pass_type' => 'special', 'allowed_entries' => 6, 'used_entries' => 2, 'status' => 'partially_used']);
        $connection->table('qr_codes')->insert(['id' => 501, 'guest_id' => 401, 'qr_token' => str_repeat('a', 64), 'qr_image_path' => 'private/synthetic-do-not-open.png']);
        $users = [];
        foreach (['customer' => 211, 'other' => 212, 'admin' => 213, 'scanner' => 214, 'unsupported' => 215] as $key => $id) {
            $users[$key] = User::query()->findOrFail($id);
        }
        $users['customer']->createToken('Synthetic customer token');
        $users['unsupported']->createToken('Synthetic unsupported token');
        $users['scanner']->createToken('Synthetic scanner token');

        return $users;
    }

    private function cloneConnection(Connection $source): Connection
    {
        $name = 'customer_home_'.count($this->ownedConnections);
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
