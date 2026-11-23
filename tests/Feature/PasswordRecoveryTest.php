<?php

namespace Tests\Feature;

use App\Http\Controllers\PasswordRecoveryController;
use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.driver' => null]);
        Notification::fake();
    }

    public function test_forms_are_public_escaped_private_and_linked_from_login(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('password.request'));
        $this->get(route('password.request'))->assertOk()
            // The redesigned page defaults to Kiswahili (Working Flow 05); English via ?lang=en.
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('Barua Pepe au Namba ya Simu');
        $this->get(route('password.request', ['lang' => 'en']))->assertOk()->assertSee('Email or Phone Number');
        $response = $this->get(route('password.reset', ['token' => 'test-token', 'email' => '"><script>alert(1)</script>']));
        // Working Flow 06: a bogus link renders the generic invalid-link state, not the form.
        $response->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('autocomplete="new-password"', false)
            ->assertSee(route('password.request'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $user = $this->user();
        $valid = $this->get(route('password.reset', ['token' => Password::broker('users')->createToken($user), 'email' => $user->email]));
        $valid->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('autocomplete="new-password"', false);
        $this->assertStringContainsString('no-store', $valid->headers->get('Cache-Control'));
    }

    #[DataProvider('identifiers')]
    public function test_email_or_phone_delivers_only_to_registered_email(string $identifier): void
    {
        $user = $this->user();
        $this->postJson(route('password.email'), ['identifier' => $identifier])
            ->assertOk()->assertExactJson(['message' => PasswordRecoveryController::REQUEST_STATUS]);
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
            $this->assertNotSame($notification->token, $stored);
            $this->assertTrue(Hash::check($notification->token, $stored));
            $mail = $notification->toMail($user);
            $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/reset-password/', $mail->actionUrl);
            $this->assertStringContainsString('email=recovery%40example.com', $mail->actionUrl);

            return true;
        });
        Notification::assertCount(1);
    }

    public static function identifiers(): array
    {
        return [['recovery@example.com'], ['0712345678'], ['255712345678'], ['+255 712 345 678']];
    }

    #[DataProvider('unknownIdentifiers')]
    public function test_unknown_and_malformed_recovery_inputs_are_generic(mixed $identifier): void
    {
        $this->user();
        $this->postJson(route('password.email'), ['identifier' => $identifier])
            ->assertOk()->assertExactJson(['message' => PasswordRecoveryController::REQUEST_STATUS]);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public static function unknownIdentifiers(): array
    {
        return [['missing@example.com'], ['0712345679'], ['not-an-identifier'], [null], [[]], [str_repeat('x', 256)]];
    }

    public function test_legacy_email_and_explicit_identifier_precedence_are_preserved(): void
    {
        $user = $this->user();
        $this->post(route('password.email'), ['identifier' => 'unknown@example.com', 'email' => $user->email])
            ->assertRedirect(route('password.request'))->assertSessionHas('status', PasswordRecoveryController::REQUEST_STATUS);
        Notification::assertNothingSent();
        $this->post(route('password.email'), ['email' => $user->email])->assertRedirect(route('password.request'));
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_inactive_or_unsupported_accounts_do_not_receive_recovery(): void
    {
        $user = $this->user(['is_active' => false]);
        (new SendPasswordResetLink($user->email))->handle();
        $user->forceFill(['is_active' => true, 'role' => 'unsupported'])->save();
        (new SendPasswordResetLink($user->email))->handle();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_broker_issuance_throttle_and_trusted_reset_host(): void
    {
        // These requests model one burst even when the test host pauses.
        $this->freezeTime();
        $user = $this->user();
        config(['app.url' => 'https://trusted.example/kadify']);
        $this->withServerVariables(['HTTP_HOST' => 'attacker.example'])
            ->postJson('/forgot-password', ['identifier' => $user->email])->assertOk();
        (new SendPasswordResetLink($user->phone_number))->handle();
        Notification::assertCount(1);
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->assertStringStartsWith('https://trusted.example/kadify/reset-password/', $notification->toMail($user)->actionUrl);

            return true;
        });
    }

    public function test_recovery_is_limited_by_canonical_identifier_across_ips(): void
    {
        // These requests model one burst even when the test host pauses.
        $this->freezeTime();
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])
                ->postJson(route('password.email'), ['identifier' => $i % 2 ? '+255712345678' : '0712345678'])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100'])
            ->postJson(route('password.email'), ['identifier' => '255712345678'])->assertStatus(429);
    }

    public function test_recovery_is_limited_by_ip_across_identifiers(): void
    {
        // These requests model one burst even when the test host pauses.
        $this->freezeTime();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('password.email'), ['identifier' => "unknown$i@example.com"])->assertOk();
        }
        $this->postJson(route('password.email'), ['identifier' => 'another@example.com'])->assertStatus(429);

        $this->travel(61)->seconds();
        $this->postJson(route('password.email'), ['identifier' => 'another@example.com'])->assertOk();
    }

    public function test_reset_throttle_uses_email_even_with_extraneous_identifiers_and_different_ips(): void
    {
        // These requests model one burst even when the test host pauses.
        $this->freezeTime();
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])
                ->postJson(route('password.update'), ['email' => 'target@example.com', 'identifier' => "noise$i@example.com"])
                ->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100'])
            ->postJson(route('password.update'), ['email' => 'target@example.com', 'identifier' => 'new-noise@example.com'])
            ->assertStatus(429);
    }

    public function test_successful_reset_rotates_remember_token_consumes_token_and_requires_login(): void
    {
        Event::fake([PasswordReset::class]);
        $user = $this->user();
        $user->forceFill(['remember_token' => 'old-remember-token'])->save();
        $token = Password::broker('users')->createToken($user);
        $this->withSession(['selected_event_id' => 999, '_token' => 'old-csrf'])
            ->post(route('password.update'), $this->resetData($user, $token))
            ->assertRedirect(route('login'))->assertSessionMissing('selected_event_id');
        $this->assertGuest();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertNotSame('old-csrf', session()->token());
        Event::assertDispatched(PasswordReset::class, fn ($event) => $event->user->is($user));
        $this->postJson(route('password.update'), $this->resetData($user, $token))
            ->assertUnprocessable()->assertJsonPath('errors.email.0', PasswordRecoveryController::INVALID_RESET);
        $this->postJson(route('login.attempt'), ['identifier' => $user->email, 'password' => 'password'])->assertUnprocessable();
        $this->post(route('login.attempt'), ['identifier' => $user->email, 'password' => 'new-password'])
            ->assertRedirect(route('admin.dashboard'));
    }

    #[DataProvider('invalidResetCases')]
    public function test_invalid_expired_or_unavailable_account_cannot_reset(string $case): void
    {
        $user = $this->user();
        $oldHash = $user->password;
        $token = Password::broker('users')->createToken($user);
        if ($case === 'expired') {
            $this->travel(61)->minutes();
        }
        if ($case === 'inactive') {
            $user->update(['is_active' => false]);
        }
        if ($case === 'invalid') {
            $token = 'invalid-token';
        }
        if ($case === 'unknown') {
            $user->email = 'missing@example.com';
        }
        $this->postJson(route('password.update'), $this->resetData($user, $token))
            ->assertUnprocessable()->assertJsonPath('errors.email.0', PasswordRecoveryController::INVALID_RESET);
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public static function invalidResetCases(): array
    {
        return [['expired'], ['inactive'], ['invalid'], ['unknown']];
    }

    public function test_invalid_password_keeps_credentials_and_never_flashes_token_or_password(): void
    {
        $user = $this->user();
        $token = Password::broker('users')->createToken($user);
        $data = $this->resetData($user, $token);
        $data['password_confirmation'] = 'does-not-match';
        $hash = $user->fresh()->password;
        $this->post(route('password.update'), $data)->assertSessionHasErrors('password');
        $this->assertArrayNotHasKey('token', session()->getOldInput());
        $this->assertArrayNotHasKey('password', session()->getOldInput());
        $this->assertArrayNotHasKey('password_confirmation', session()->getOldInput());
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_password_change_revokes_existing_browser_session_on_next_request(): void
    {
        $user = $this->user();
        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->assertNotEmpty(session('password_hash_web'));
        $user->update(['password' => 'changed-elsewhere']);
        Auth::forgetGuards();
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_hash_shaped_password_is_hashed_as_the_submitted_password(): void
    {
        $user = $this->user();
        $password = Hash::make('a-different-password');
        $data = $this->resetData($user, Password::broker('users')->createToken($user));
        $data['password'] = $data['password_confirmation'] = $password;
        $this->post(route('password.update'), $data)->assertRedirect(route('login'));
        $this->assertNotSame($password, $user->fresh()->password);
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->post(route('login.attempt'), ['identifier' => $user->email, 'password' => $password])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_reset_revokes_a_different_browser_session_but_new_login_works(): void
    {
        $user = $this->user();
        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $oldSession = session()->all();
        session()->flush();
        Auth::forgetGuards();
        $this->post(route('password.update'), $this->resetData($user, Password::broker('users')->createToken($user)))
            ->assertRedirect(route('login'));
        session()->flush();
        Auth::forgetGuards();
        $this->withSession($oldSession)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'new-password'])->assertRedirect();
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_failed_reset_transaction_preserves_password_remember_token_and_reset_token(): void
    {
        $user = $this->user();
        $user->forceFill(['remember_token' => 'original-remember-token'])->save();
        $password = $user->password;
        $token = Password::broker('users')->createToken($user);
        Event::listen(PasswordReset::class, fn () => throw new \RuntimeException('Simulated reset failure'));
        $this->withoutExceptionHandling();

        try {
            $this->post(route('password.update'), $this->resetData($user, $token));
            $this->fail('The simulated failure must leave the reset uncommitted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated reset failure', $exception->getMessage());
        }

        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame('original-remember-token', $user->fresh()->remember_token);
        $this->assertTrue(Password::broker('users')->tokenExists($user->fresh(), $token));
    }

    public function test_recovery_and_reset_require_csrf_when_web_middleware_is_enforced(): void
    {
        $user = $this->user();
        $token = Password::broker('users')->createToken($user);
        $password = $user->password;
        // Laravel bypasses CSRF only in its testing environment. Keep all
        // isolated test drivers while exercising the production middleware.
        $this->app->instance('env', 'csrf-test');
        $this->postJson(route('password.email'), ['identifier' => $user->email])->assertStatus(419);
        $this->postJson(route('password.update'), $this->resetData($user, $token))->assertStatus(419);
        Notification::assertNothingSent();
        $this->assertSame($password, $user->fresh()->password);
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
    }

    public function test_legacy_persisted_session_without_marker_requires_reauthentication(): void
    {
        $user = $this->user();
        $this->withSession([Auth::guard('web')->getName() => $user->id, 'selected_event_id' => 123])
            ->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(session('selected_event_id'));
    }

    public function test_stale_remember_cookie_cannot_restore_reset_account(): void
    {
        $user = $this->user();
        $name = Auth::guard('web')->getRecallerName();
        $login = $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
        $cookie = $login->getCookie($name)->getValue();
        session()->flush();
        Auth::forgetGuards();
        $token = Password::broker('users')->createToken($user->fresh());
        $this->post(route('password.update'), $this->resetData($user, $token))->assertRedirect(route('login'));
        session()->flush();
        Auth::forgetGuards();
        $this->withCookie($name, $cookie)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_valid_remember_cookie_restores_a_fresh_session_and_seeds_its_marker(): void
    {
        $user = $this->user();
        $name = Auth::guard('web')->getRecallerName();
        $login = $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
        $cookie = $login->getCookie($name)->getValue();
        session()->flush();
        Auth::forgetGuards();
        $this->assertFalse(session()->has('password_hash_web'));
        $this->withCookie($name, $cookie)->get(route('admin.dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->assertNotEmpty(session('password_hash_web'));
    }

    #[DataProvider('unsafeMailers')]
    public function test_unsafe_mail_configuration_cannot_create_or_log_reset_token(array $mailers, string $default): void
    {
        $user = $this->user();
        config(['mail.mailers' => $mailers, 'mail.default' => $default]);
        Log::shouldReceive('warning')->once()->withArgs(fn ($message, $context) => $message === 'Password recovery delivery unavailable.' && array_keys($context) === ['reason']);
        (new SendPasswordResetLink($user->email))->handle();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public static function unsafeMailers(): array
    {
        return [
            [['test' => ['transport' => 'log']], 'test'],
            [['test' => ['transport' => 'smtp', 'url' => 'log://localhost']], 'test'],
            [['test' => ['transport' => 'smtp', 'url' => 'smtp://localhost?driver=log']], 'test'],
            [['test' => ['transport' => 'failover', 'mailers' => ['child']], 'child' => ['transport' => 'smtp']], 'test'],
            [['test' => ['transport' => 'roundrobin', 'mailers' => ['test']]], 'test'],
            [['test' => ['transport' => 'unsupported']], 'test'],
            [[], 'missing'],
        ];
    }

    public function test_transport_and_logger_failures_do_not_report_sensitive_exception_details(): void
    {
        $this->user();
        Password::shouldReceive('broker')->with('users')->once()->andThrow(new \RuntimeException('private-recipient-token-marker'));
        Log::shouldReceive('warning')->once()->with('Password recovery delivery unavailable.', ['exception_type' => \RuntimeException::class])
            ->andThrow(new \RuntimeException('logger-failure-marker'));
        (new SendPasswordResetLink('recovery@example.com'))->handle();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_notification_failure_after_token_creation_keeps_response_generic_and_logs_only_class(): void
    {
        $user = $this->user();
        Notification::shouldReceive('send')->once()->andReturnUsing(function ($recipient, $notification) use ($user): void {
            $this->assertTrue($recipient->is($user));
            $this->assertTrue(Password::broker('users')->tokenExists($user, $notification->token));
            throw new \RuntimeException('Sensitive delivery details: '.$notification->toMail($user)->actionUrl);
        });
        Log::shouldReceive('warning')->once()->with('Password recovery delivery unavailable.', ['exception_type' => \RuntimeException::class]);
        $this->postJson(route('password.email'), ['identifier' => $user->email])
            ->assertOk()->assertExactJson(['message' => PasswordRecoveryController::REQUEST_STATUS]);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    private function user(array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Recovery Fixture', 'email' => 'recovery@example.com',
            'phone_number' => '+255712345678', 'password' => 'password', 'role' => User::ROLE_ADMIN,
        ], $attributes));
    }

    private function resetData(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
    }
}
