<?php

namespace Tests\Feature;

use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use App\Services\Tenancy\AuthorityContextResolver;
use App\Services\Tenancy\WorkspaceAccess;
use App\Services\Tenancy\WorkspaceProvisioner;
use App\Services\Tenancy\WorkspaceProvisioningException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://localhost/api/v1/auth/';

    private array $ownedConnections = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.driver' => null]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        foreach ($this->ownedConnections as $name) {
            DB::purge($name);
        }
        parent::tearDown();
    }

    public function test_customer_identity_is_web_only_and_creation_never_creates_or_assigns_a_default_event(): void
    {
        $events = DB::table('events')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        $user = $this->customer();

        $this->assertSame('customer', User::ROLE_CUSTOMER);
        $this->assertTrue($user->isCustomer());
        $this->assertTrue($user->canAuthenticate());
        $this->assertFalse($user->canUseDeviceTokens());
        $this->assertFalse($user->isAdmin());
        $this->assertFalse($user->isScanner());
        $this->assertNull($user->event_id);
        $this->assertFalse($user->fresh()->is_platform_admin);
        $this->assertSame($events, DB::table('events')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all());
        $this->assertArrayNotHasKey('is_platform_admin', $user->toArray());
        $this->assertDatabaseCount('workspace_users', 0);
        $user->is_active = false;
        $this->assertFalse($user->canAuthenticate());
        $this->assertFalse($user->canUseDeviceTokens());
        foreach ([User::ROLE_ADMIN, User::ROLE_SCANNER] as $role) {
            $user->forceFill(['role' => $role, 'is_active' => true]);
            $this->assertTrue($user->canUseDeviceTokens());
            $user->is_active = false;
            $this->assertFalse($user->canUseDeviceTokens());
        }
    }

    #[DataProvider('unsafeRawCustomerIds')]
    public function test_customer_web_predicate_rejects_ambiguous_or_out_of_range_raw_ids(mixed $id): void
    {
        $user = $this->customer();
        $user->setRawAttributes(array_replace($user->fresh()->getRawOriginal(), ['id' => $id]), true);

        $this->assertTrue($user->isCustomer());
        $this->assertFalse($user->canAuthenticate());
        $this->assertFalse($user->canUseDeviceTokens());
    }

    public static function unsafeRawCustomerIds(): array
    {
        return [
            'missing' => [null],
            'zero' => [0],
            'negative' => [-1],
            'leading zero' => ['01'],
            'float' => [1.0],
            'outside PHP integer range' => [(string) PHP_INT_MAX.'0'],
        ];
    }

    #[DataProvider('cachedCustomerIdAliases')]
    public function test_cached_customer_raw_id_alias_is_rejected_before_any_account_read_or_write(int $victimId, string $unsafeId): void
    {
        // Create only this account: PHP_INT_MAX intentionally exhausts SQLite's
        // allocator for this case, and RefreshDatabase rolls it back afterward.
        $victim = $this->customer(['id' => $victimId, 'remember_token' => 'victim-remember-token']);
        $cached = clone $victim;
        $cached->setRawAttributes(array_replace($victim->fresh()->getRawOriginal(), ['id' => $unsafeId]), true);
        $this->assertSame($victimId, $cached->getAuthIdentifier());
        $this->assertFalse($cached->canAuthenticate());
        $connection = DB::connection();
        $before = $this->snapshot($connection);
        $queryCount = 0;
        $observing = false;
        $connection->beforeExecuting(function () use (&$observing, &$queryCount): void {
            if ($observing) {
                $queryCount++;
            }
        });
        Auth::guard('web')->setUser($cached);
        session()->put([
            'selected_workspace_id' => 71, 'selected_event_id' => 81,
            'guest_import' => ['token' => 'private-import-context'],
        ]);
        $request = Request::create('/customer', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession(session()->driver());
        $called = false;
        $observing = true;
        try {
            $response = app(EnsureActiveAccount::class)->handle($request, function () use (&$called) {
                $called = true;

                return response('private-workspaces');
            });
        } finally {
            $observing = false;
        }

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => 'Unauthenticated.'], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertFalse($called);
        $this->assertGuest('web');
        foreach (['selected_workspace_id', 'selected_event_id', 'guest_import'] as $key) {
            $this->assertFalse(session()->has($key));
        }
        $this->assertSame(0, $queryCount, 'An unsafe cached customer ID must never be cast into a database lookup.');
        $this->assertSame($before, $this->snapshot($connection));
        $this->assertSame('victim-remember-token', $victim->fresh()->remember_token);
    }

    public static function cachedCustomerIdAliases(): array
    {
        return [
            'leading-zero alias' => [211, '0211'],
            'unsigned integer clamped alias' => [PHP_INT_MAX, (string) PHP_INT_MAX.'0'],
        ];
    }

    #[DataProvider('customerIdentifiers')]
    public function test_customer_email_and_phone_sign_in_without_trusting_submitted_privileges(string $field, string $identifier): void
    {
        $user = $this->customer(['phone_number' => '0712345678']);
        $before = $user->fresh()->getRawOriginal();
        $events = DB::table('events')->count();

        $this->post(route('login.attempt'), [
            $field => $identifier, 'password' => 'password', 'role' => User::ROLE_ADMIN,
            'event_id' => 999, 'workspace_id' => 999, 'is_platform_admin' => true,
        ])->assertRedirect(route('customer.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame('+255712345678', $user->fresh()->phone_number);
        $this->assertSame($events, DB::table('events')->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->get(route('customer.home'))->assertOk();
    }

    public static function customerIdentifiers(): array
    {
        return [
            'legacy email' => ['email', 'customer@example.invalid'],
            'email identifier' => ['identifier', 'customer@example.invalid'],
            'canonical phone' => ['identifier', '+255712345678'],
            'local phone' => ['identifier', '0712345678'],
        ];
    }

    public function test_customer_login_aliases_preserve_existing_labels_and_always_redirect_by_persisted_role(): void
    {
        foreach (['login' => 'Sign in to Kadify', 'admin.login' => 'Platform Administration', 'scanner.login' => 'Scan Guests'] as $name => $label) {
            $this->get(route($name))->assertOk()->assertSee($label);
        }
        $this->assertFalse(Route::has('customer.login'));
        $user = $this->customer();
        $this->from(route('admin.login'))->post(route('login.attempt'), $this->credentials($user))
            ->assertRedirect(route('customer.home'));
        foreach (['login', 'admin.login', 'scanner.login'] as $name) {
            $this->get(route($name))->assertRedirect(route('customer.home'));
        }
        $this->assertSame(User::ROLE_CUSTOMER, $user->fresh()->role);
    }

    public function test_same_origin_customer_intended_destination_preserves_safe_query_and_clears_foreign_context(): void
    {
        $user = $this->customer();
        $destination = route('customer.home').'?page=2&label=Asha%20Mushi';
        $this->withSession([
            'url.intended' => $destination, 'selected_workspace_id' => 71, 'selected_event_id' => 91,
            'guest_import' => ['token' => 'previous-preview'],
        ])->post(route('login.attempt'), $this->credentials($user))
            ->assertRedirect($destination)->assertSessionMissing('url.intended')
            ->assertSessionMissing('selected_workspace_id')->assertSessionMissing('selected_event_id')
            ->assertSessionMissing('guest_import');
    }

    #[DataProvider('unsafeCustomerDestinations')]
    public function test_customer_intended_destination_cannot_escape_its_role_boundary(mixed $destination): void
    {
        $user = $this->customer();
        $this->withSession(['url.intended' => $destination])
            ->post(route('login.attempt'), $this->credentials($user))
            ->assertRedirect(route('customer.home'))->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($user);
    }

    public static function unsafeCustomerDestinations(): array
    {
        return [
            'admin' => ['/admin/dashboard'],
            'scanner' => ['/scanner/scan'],
            'external host' => ['https://external.example/customer'],
            'prefix collision' => ['/customer-other'],
            'raw traversal' => ['/customer/../admin/dashboard'],
            'encoded traversal' => ['/customer/%2e%2e/admin/dashboard'],
            'double encoded traversal' => ['/customer/%252e%252e/admin/dashboard'],
            'array' => [['/customer']],
        ];
    }

    public function test_replacing_an_authenticated_identity_clears_workspace_context_and_previous_intended_destination(): void
    {
        $previous = User::factory()->create();
        $next = $this->customer();
        $this->actingAs($previous)->withSession([
            'selected_workspace_id' => 77, 'selected_event_id' => 88,
            'guest_import' => ['rows' => [['name' => 'Previous private guest']]],
            'url.intended' => route('admin.users.index'),
        ])->post(route('login.attempt'), $this->credentials($next))
            ->assertRedirect(route('customer.home'))->assertSessionMissing('selected_workspace_id')
            ->assertSessionMissing('selected_event_id')->assertSessionMissing('guest_import')
            ->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($next);
    }

    public function test_customer_remember_cookie_restores_a_fresh_session_and_logout_rotates_it(): void
    {
        $user = $this->customer();
        $name = Auth::guard('web')->getRecallerName();
        $response = $this->post(route('login.attempt'), $this->credentials($user) + ['remember' => true]);
        $response->assertRedirect(route('customer.home'))->assertCookieNotExpired($name);
        $cookie = $response->getCookie($name);
        $this->assertTrue($cookie->isHttpOnly());
        $remember = $user->fresh()->remember_token;
        $this->assertNotEmpty($remember);
        session()->flush();
        Auth::forgetGuards();

        $this->withCookie($name, $cookie->getValue())->get(route('customer.home'))->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->assertNotEmpty(session('password_hash_web'));
        $this->post(route('logout'))->assertRedirect(route('login'))->assertCookieExpired($name);
        $this->assertGuest();
        $this->assertNotSame($remember, $user->fresh()->remember_token);
    }

    public function test_customer_has_no_access_to_any_staff_or_scanner_area_or_mutation(): void
    {
        $user = $this->customer();
        $this->actingAs($user);
        $before = $this->snapshot(DB::connection());
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName() ?? '';
            foreach (['admin' => User::ROLE_ADMIN, 'scanner' => User::ROLE_SCANNER] as $prefix => $role) {
                if (str_starts_with($name, $prefix.'.') && $name !== $prefix.'.login') {
                    $this->assertContains('role:'.$role, $route->gatherMiddleware(), $name);
                }
            }
        }
        foreach ([
            '/admin/dashboard', '/admin/events', '/admin/users', '/admin/guests', '/admin/qr-codes',
            '/admin/checkins', '/admin/notification-logs', '/admin/reports', '/admin/settings',
            '/scanner/dashboard', '/scanner/scan', '/scanner/manual-search', '/scanner/recent-scans',
        ] as $path) {
            $this->get($path)->assertForbidden();
        }
        foreach ([
            '/admin/events', '/admin/users', '/admin/guests', '/admin/guests/import',
            '/scanner/manual-admit', '/scanner/validate', '/scanner/verify', '/scanner/admit',
        ] as $path) {
            $this->postJson($path, ['role' => User::ROLE_ADMIN, 'workspace_id' => 1])->assertForbidden();
        }
        $this->assertSame($before, $this->snapshot(DB::connection()));
    }

    #[DataProvider('unavailableCustomerSessions')]
    public function test_persisted_customer_unavailability_invalidates_a_cached_web_identity(string $change): void
    {
        $user = $this->customer();
        $this->actingAs($user);
        if ($change === 'deleted') {
            DB::table('users')->where('id', $user->id)->delete();
        } else {
            DB::table('users')->where('id', $user->id)->update($change === 'inactive'
                ? ['is_active' => false] : ['role' => 'unsupported']);
        }
        $this->withSession(['selected_workspace_id' => 19, 'selected_event_id' => 29, 'guest_import' => ['token' => 'stale']])
            ->getJson(route('customer.home'))->assertUnauthorized()
            ->assertSessionMissing('selected_workspace_id')->assertSessionMissing('selected_event_id')
            ->assertSessionMissing('guest_import')->assertCookieExpired(Auth::guard('web')->getRecallerName());
        $this->assertGuest();
    }

    public static function unavailableCustomerSessions(): array
    {
        return [['inactive'], ['deleted'], ['unsupported role']];
    }

    public function test_persisted_role_change_is_seen_before_customer_role_middleware(): void
    {
        $user = $this->customer();
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_ADMIN]);
        $this->assertTrue($user->isCustomer());
        $this->get(route('customer.home'))->assertForbidden();
        $this->assertTrue(Auth::user()->isAdmin());
    }

    public function test_current_web_account_read_uses_primary_before_role_checks_even_with_a_different_read_pdo(): void
    {
        $customer = $this->customer();
        $connection = $this->cloneDatabase();
        $originalDefault = DB::getDefaultConnection();
        $cached = User::on($connection->getName())->findOrFail($customer->id);
        $cached->role = User::ROLE_ADMIN;
        $trap = new \PDO('sqlite::memory:');
        $trap->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, is_active INTEGER)');
        $trap->exec('INSERT INTO users (id, role, is_active) VALUES ('.(int) $customer->id.', \'admin\', 0)');
        $connection->setReadPdo($trap);
        DB::setDefaultConnection($connection->getName());
        try {
            Auth::guard('web')->setUser($cached);
            $request = Request::create('/customer');
            $request->setLaravelSession(session()->driver());
            $response = app(EnsureActiveAccount::class)->handle($request, function () {
                $this->assertTrue(Auth::guard('web')->user()->isCustomer());

                return response('fresh-customer');
            });
            $this->assertSame('fresh-customer', $response->getContent());
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertSame('admin', $trap->query('SELECT role FROM users')->fetchColumn());
        } finally {
            DB::setDefaultConnection($originalDefault);
            Auth::forgetGuards();
        }
    }

    public function test_inactive_customer_and_wrong_password_keep_the_generic_credential_error_and_issue_nothing(): void
    {
        $user = $this->customer();
        $response = $this->postJson(route('login.attempt'), ['identifier' => $user->email, 'password' => 'wrong']);
        $response->assertUnprocessable()->assertJsonPath('errors.identifier.0', 'The provided credentials do not match our records.');
        $expected = $response->json();
        $user->update(['is_active' => false]);
        $this->postJson(route('login.attempt'), $this->credentials($user) + ['remember' => true])
            ->assertUnprocessable()->assertExactJson($expected);
        $this->assertGuest();
        $this->assertNull($user->fresh()->remember_token);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_customer_login_shares_canonical_identity_throttle_across_ips(): void
    {
        $this->freezeTime();
        $this->customer(['phone_number' => '+255712345678']);
        for ($index = 0; $index < 10; $index++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)])
                ->postJson(route('login.attempt'), [
                    'identifier' => $index % 2 ? '0712345678' : '+255712345678', 'password' => 'wrong',
                ])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100'])
            ->postJson(route('login.attempt'), ['identifier' => '255712345678', 'password' => 'wrong'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_customer_recovery_reaches_registered_email_through_real_array_mail_and_remains_generic(): void
    {
        $user = $this->customer(['phone_number' => '+255712345678']);
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $transport->flush();
        $this->postJson(route('password.email'), ['identifier' => '0712345678'])
            ->assertOk()->assertExactJson(['message' => PasswordRecoveryController::REQUEST_STATUS]);
        $this->assertCount(1, $transport->messages());
        $message = $transport->messages()->sole()->getOriginalMessage();
        $this->assertSame([$user->email], array_map(fn ($address) => $address->getAddress(), $message->getTo()));
        $html = $message->getHtmlBody();
        $this->assertSame(1, preg_match('#/reset-password/([a-zA-Z0-9]+)\?#', $html, $matches));
        $this->assertTrue(Password::broker('users')->tokenExists($user, $matches[1]));
        $this->assertStringNotContainsString($matches[1], DB::table('password_reset_tokens')->value('token'));
        $this->postJson(route('password.email'), ['identifier' => 'missing@example.invalid'])
            ->assertOk()->assertExactJson(['message' => PasswordRecoveryController::REQUEST_STATUS]);
        $this->assertCount(1, $transport->messages());
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_deferred_customer_recovery_rechecks_account_availability_before_issuing_a_token(): void
    {
        Notification::fake();
        $user = $this->customer();
        $job = new SendPasswordResetLink($user->email);
        $user->update(['is_active' => false]);
        $job->handle();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $user->update(['is_active' => true]);
        $job->handle();
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_customer_password_reset_consumes_once_revokes_sessions_remember_and_all_own_tokens_only(): void
    {
        Notification::fake();
        $user = $this->customer();
        $other = User::factory()->create();
        $user->createToken('Old device one', ['auth:read'], now()->addDay());
        $user->createToken('Old device two', ['*'], now()->addDay());
        $otherToken = $other->createToken('Unrelated device', ['auth:read'], now()->addDay())->accessToken;
        $otherReset = Password::broker('users')->createToken($other);
        $otherBefore = $other->fresh()->getRawOriginal();
        $otherTokenBefore = (array) DB::table('personal_access_tokens')->where('id', $otherToken->id)->sole();
        $otherResetBefore = (array) DB::table('password_reset_tokens')->where('email', $other->email)->sole();
        $name = Auth::guard('web')->getRecallerName();
        $login = $this->post(route('login.attempt'), $this->credentials($user) + ['remember' => true]);
        $oldCookie = $login->getCookie($name)->getValue();
        $oldRemember = $user->fresh()->remember_token;
        $oldSession = session()->all();
        session()->flush();
        Auth::forgetGuards();
        $token = Password::broker('users')->createToken($user->fresh());
        $this->withSession(['selected_workspace_id' => 71, 'selected_event_id' => 81, 'guest_import' => ['token' => 'old'], '_token' => 'old-csrf'])
            ->post(route('password.update'), $this->resetData($user, $token))
            ->assertRedirect(route('login'))->assertSessionMissing('selected_workspace_id')
            ->assertSessionMissing('selected_event_id')->assertSessionMissing('guest_import');
        $this->assertGuest();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertNotSame($oldRemember, $user->fresh()->remember_token);
        $this->assertNotSame('old-csrf', session()->token());
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertSame($otherBefore, $other->fresh()->getRawOriginal());
        $this->assertSame($otherTokenBefore, (array) DB::table('personal_access_tokens')->where('id', $otherToken->id)->sole());
        $this->assertSame($otherResetBefore, (array) DB::table('password_reset_tokens')->where('email', $other->email)->sole());
        $this->assertTrue(Password::broker('users')->tokenExists($other, $otherReset));
        $this->postJson(route('password.update'), $this->resetData($user, $token))
            ->assertUnprocessable()->assertJsonPath('errors.email.0', PasswordRecoveryController::INVALID_RESET);
        session()->flush();
        Auth::forgetGuards();
        $this->withSession($oldSession)->get(route('customer.home'))->assertRedirect(route('login'));
        $this->assertGuest();
        session()->flush();
        Auth::forgetGuards();
        $this->withCookie($name, $oldCookie)->get(route('customer.home'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->postJson(route('login.attempt'), $this->credentials($user))->assertUnprocessable();
        $this->post(route('login.attempt'), ['identifier' => $user->email, 'password' => 'new-password'])
            ->assertRedirect(route('customer.home'));
        $this->get(route('customer.home'))->assertOk();
    }

    public function test_inactive_customer_cannot_consume_a_previously_valid_reset_token(): void
    {
        $user = $this->customer();
        $token = Password::broker('users')->createToken($user);
        $user->update(['is_active' => false]);
        $before = $this->snapshot(DB::connection());
        $this->postJson(route('password.update'), $this->resetData($user, $token))
            ->assertUnprocessable()->assertJsonPath('errors.email.0', PasswordRecoveryController::INVALID_RESET);
        $this->assertSame($before, $this->snapshot(DB::connection()));
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
    }

    public function test_customer_device_login_and_existing_bearer_tokens_are_rejected_without_issuance_or_deletion(): void
    {
        $user = $this->customer();
        $token = $user->createToken('Fixture old token', ['auth:read', 'auth:logout'], now()->addDay())->plainTextToken;
        $before = $this->snapshot(DB::connection());
        $this->freshGuards();
        $this->postJson(self::API.'login', $this->credentials($user) + ['device_name' => 'Customer device'])
            ->assertUnauthorized()->assertJsonPath('success', false);
        $this->assertSame($before, $this->snapshot(DB::connection()));
        foreach (['me' => 'GET', 'logout' => 'POST'] as $action => $method) {
            $this->freshGuards();
            $this->json($method, self::API.$action, [], ['Authorization' => 'Bearer '.$token])
                ->assertUnauthorized()->assertJsonPath('success', false)
                ->assertHeader('Referrer-Policy', 'no-referrer');
        }
        $this->assertOnlyTokenTelemetryMayChange($before, $this->snapshot(DB::connection()));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_staff_token_stops_working_immediately_after_its_account_becomes_a_customer(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson(self::API.'login', $this->credentials($user) + ['device_name' => 'Former staff'])
            ->assertCreated()->json('data.access_token');
        DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_CUSTOMER]);
        $before = $this->snapshot(DB::connection());
        foreach (['me' => 'GET', 'logout' => 'POST'] as $action => $method) {
            $this->freshGuards();
            $this->json($method, self::API.$action, [], ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        }
        $this->assertOnlyTokenTelemetryMayChange($before, $this->snapshot(DB::connection()));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_customer_web_identity_cannot_substitute_for_a_bearer_token(): void
    {
        $user = $this->customer();
        $this->post(route('login.attempt'), $this->credentials($user))->assertRedirect(route('customer.home'));
        $this->getJson(self::API.'me')->assertUnauthorized();
        $this->postJson(self::API.'logout')->assertUnauthorized();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_customer_can_automatically_own_a_new_workspace_without_staff_platform_or_legacy_event_grants(): void
    {
        $user = $this->customer();
        $connection = $this->cloneDatabase();
        $defaultBefore = $this->snapshot(DB::connection());
        $connection->table('events')->insert(['id' => 881, 'event_name' => 'Unowned legacy event', 'workspace_id' => null, 'status' => 'active']);
        $accountBefore = (array) $connection->table('users')->where('id', $user->id)->sole();
        $eventsBefore = $connection->table('events')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        $key = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';
        $result = app(WorkspaceProvisioner::class)->provision($connection, $user->id, 'Customer self-service workspace', $key);
        $this->assertTrue($result->wasCreated());
        $this->assertSame('Customer self-service workspace', $connection->table('workspaces')->where('id', $result->workspaceId())->value('name'));
        $membership = $connection->table('workspace_users')->where('workspace_id', $result->workspaceId())->sole();
        $this->assertSame([$user->id, 'owner', 1], [$membership->user_id, $membership->role, $membership->is_active]);
        $facts = app(AuthorityContextResolver::class)->resolve($connection, $user->id, $result->workspaceId());
        $this->assertTrue($facts->isAccountEligible());
        $this->assertFalse($facts->hasPlatformCapability());
        $this->assertSame('owner', $facts->workspaceRole());
        $this->assertTrue(app(WorkspaceAccess::class)->allows($connection, $user->id, $result->workspaceId(), 'event.create'));
        $this->assertFalse(app(WorkspaceAccess::class)->allows($connection, $user->id, $result->workspaceId(), 'event.view', 881));
        $after = $this->snapshot($connection);
        $retry = app(WorkspaceProvisioner::class)->provision($connection, $user->id, 'Customer self-service workspace', $key);
        $this->assertFalse($retry->wasCreated());
        $this->assertSame($result->workspaceId(), $retry->workspaceId());
        $this->assertSame($after, $this->snapshot($connection));
        $this->assertSame($accountBefore, (array) $connection->table('users')->where('id', $user->id)->sole());
        $this->assertSame($eventsBefore, $connection->table('events')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame($defaultBefore, $this->snapshot(DB::connection()));
        $connection->table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->assertFalse(app(AuthorityContextResolver::class)->resolve($connection, $user->id, $result->workspaceId())->isAccountEligible());
        $this->assertFalse(app(WorkspaceAccess::class)->allows($connection, $user->id, $result->workspaceId(), 'workspace.view'));
        $disabled = $this->snapshot($connection);
        try {
            app(WorkspaceProvisioner::class)->provision($connection, $user->id, 'Customer self-service workspace', $key);
            $this->fail('A deactivated customer cannot reuse provisioning authority.');
        } catch (WorkspaceProvisioningException $exception) {
            $this->assertSame('creator_unavailable', $exception->reason());
        }
        $this->assertSame($disabled, $this->snapshot($connection));
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_replace([
            'name' => 'Customer fixture', 'email' => 'customer@example.invalid',
            'role' => User::ROLE_CUSTOMER, 'event_id' => null, 'is_active' => true,
        ], $attributes));
    }

    private function credentials(User $user): array
    {
        return ['identifier' => $user->email, 'password' => 'password'];
    }

    private function resetData(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
    }

    private function freshGuards(): void
    {
        Auth::shouldUse('web');
        Auth::forgetGuards();
    }

    private function assertOnlyTokenTelemetryMayChange(array $before, array $after): void
    {
        // Sanctum records these timestamps before application role middleware.
        // Token IDs, hashes, names, abilities, expiry and all other rows remain exact.
        foreach (['before', 'after'] as $snapshot) {
            foreach (${$snapshot}['personal_access_tokens'] as &$row) {
                unset($row['last_used_at'], $row['updated_at']);
            }
            unset($row);
        }
        $this->assertSame($before, $after);
    }

    private function cloneDatabase(): Connection
    {
        $name = 'customer_identity_'.count($this->ownedConnections);
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        $this->ownedConnections[] = $name;
        $connection = DB::connection($name);
        $source = DB::connection('sqlite');
        $objects = $source->select("SELECT type, name, sql FROM sqlite_master WHERE type IN ('table', 'index') AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name");
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
        $this->assertSame(0, $connection->transactionLevel());

        return $connection;
    }

    private function snapshot(Connection $connection): array
    {
        $rows = [];
        foreach ($connection->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name") as $table) {
            $contents = array_map(static fn (object $row): array => (array) $row,
                $connection->table($table->name)->useWritePdo()->get()->all());
            usort($contents, static fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));
            $rows[$table->name] = $contents;
        }

        return $rows;
    }
}
