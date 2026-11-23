<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthSessionFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_remember_login_persists_hidden_token_and_logout_rotates_it(): void
    {
        $user = $this->user(User::ROLE_ADMIN);
        $passwordHash = $user->password;
        $cookieName = Auth::guard()->getRecallerName();

        $response = $this->withSession(['_token' => 'old-csrf-token'])
            ->post(route('login.attempt'), $this->credentials($user) + ['remember' => '1']);

        $response->assertRedirect(route('admin.dashboard'))->assertCookieNotExpired($cookieName);
        $rememberToken = $user->fresh()->getRememberToken();
        $this->assertNotEmpty($rememberToken);
        $this->assertTrue($response->getCookie($cookieName)->isHttpOnly());
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertArrayNotHasKey('remember_token', $user->fresh()->toArray());
        $this->assertNotSame('old-csrf-token', session()->token());
        $this->assertAuthenticatedAs($user);

        $this->withCookie($cookieName, $response->getCookie($cookieName)->getValue())
            ->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertCookieExpired($cookieName);

        $this->assertGuest();
        $this->assertNotSame($rememberToken, $user->fresh()->getRememberToken());
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_remember_cookie_restores_identity_without_an_authenticated_session(): void
    {
        $user = $this->user(User::ROLE_SCANNER);
        $cookieName = Auth::guard()->getRecallerName();
        $response = $this->post(route('login.attempt'), $this->credentials($user) + ['remember' => '1']);
        $cookie = $response->getCookie($cookieName);

        session()->forget(Auth::guard()->getName());
        Auth::forgetGuards();

        $this->withCookie($cookieName, $cookie->getValue())
            ->get(route('scanner.dashboard'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Auth::guard()->viaRemember());
    }

    public function test_same_origin_authorized_intended_destination_preserves_query(): void
    {
        $user = $this->user(User::ROLE_ADMIN);
        $destination = route('admin.guests.index').'?search=Asha%20Mushi&page=2';

        $this->withSession(['url.intended' => $destination])
            ->post(route('login.attempt'), $this->credentials($user))
            ->assertRedirect($destination)
            ->assertSessionMissing('url.intended');
    }

    public function test_relative_authorized_intended_destination_preserves_query(): void
    {
        $user = $this->user(User::ROLE_SCANNER);

        $this->withSession(['url.intended' => '/scanner/scan?gate=main'])
            ->post(route('login.attempt'), $this->credentials($user))
            ->assertRedirect(url('/scanner/scan?gate=main'))
            ->assertSessionMissing('url.intended');
    }

    #[DataProvider('unsafeDestinations')]
    public function test_unsafe_or_other_role_intended_destination_is_consumed(mixed $destination): void
    {
        $user = $this->user(User::ROLE_SCANNER);

        $this->withSession(['url.intended' => $destination])
            ->post(route('login.attempt'), $this->credentials($user))
            ->assertRedirect(route('scanner.dashboard'))
            ->assertSessionMissing('url.intended');

        $this->assertAuthenticatedAs($user);
    }

    public static function unsafeDestinations(): array
    {
        return [
            'other role' => ['/admin/dashboard'],
            'external host' => ['https://external.example/scanner/scan'],
            'protocol relative' => ['//external.example/scanner/scan'],
            'credentials' => ['http://name:password@localhost/scanner/scan'],
            'script scheme' => ['javascript:alert(1)'],
            'relative without leading slash' => ['scanner/scan'],
            'raw traversal' => ['/scanner/../admin/dashboard'],
            'encoded traversal' => ['/scanner/%2e%2e/admin/dashboard'],
            'double encoded traversal' => ['/scanner/%252e%252e/admin/dashboard'],
            'encoded slash' => ['/scanner/%2f%2fexternal.example'],
            'backslash' => ['/scanner/..\\admin/dashboard'],
            'control characters' => ["/scanner/scan\r\nLocation: https://external.example"],
            'encoded controls' => ['/scanner/scan?gate=%0d%0aLocation:external'],
            'array instead of URL' => [['/scanner/scan']],
            'malformed port' => ['http://localhost:999999/scanner/scan'],
            'prefix collision' => ['/scanner-extra/scan'],
        ];
    }

    public function test_same_host_different_scheme_or_port_is_not_an_authorized_origin(): void
    {
        $user = $this->user(User::ROLE_SCANNER);
        $origin = parse_url(route('login'));
        $scheme = $origin['scheme'] === 'https' ? 'http' : 'https';

        $this->withSession(['url.intended' => $scheme.'://'.$origin['host'].'/scanner/scan'])
            ->post(route('login.attempt'), $this->credentials($user))
            ->assertRedirect(route('scanner.dashboard'));

        $this->post(route('logout'));

        $this->withSession(['url.intended' => $origin['scheme'].'://'.$origin['host'].':65534/scanner/scan'])
            ->post(route('login.attempt'), $this->credentials($user))
            ->assertRedirect(route('scanner.dashboard'));
    }

    public function test_successful_account_replacement_clears_previous_context_and_remember_cookie(): void
    {
        $previous = $this->user(User::ROLE_ADMIN);
        $next = $this->user(User::ROLE_ADMIN);
        $cookieName = Auth::guard()->getRecallerName();
        $response = $this->post(route('login.attempt'), $this->credentials($previous) + ['remember' => '1']);

        $this->withCookie($cookieName, $response->getCookie($cookieName)->getValue())
            ->withSession([
                'selected_event_id' => 987,
                'url.intended' => route('admin.guests.index'),
                'guest_import' => ['rows' => [['name' => 'Previous account guest']], 'token' => 'previous-preview'],
            ])
            ->post(route('login.attempt'), $this->credentials($next))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionMissing('selected_event_id')
            ->assertSessionMissing('guest_import')
            ->assertSessionMissing('url.intended')
            ->assertCookieExpired($cookieName);

        $this->assertAuthenticatedAs($next);
    }

    public function test_failed_account_replacement_preserves_authenticated_identity_and_context(): void
    {
        $previous = $this->user(User::ROLE_ADMIN);
        $next = $this->user(User::ROLE_SCANNER);

        $this->actingAs($previous)
            ->withSession(['selected_event_id' => 987])
            ->post(route('login.attempt'), ['email' => $next->email, 'password' => 'incorrect'])
            ->assertSessionHasErrors('email')
            ->assertSessionHas('selected_event_id', 987);

        $this->assertAuthenticatedAs($previous);
    }

    public function test_login_aliases_are_public_and_share_the_existing_login_action(): void
    {
        foreach (['login' => 'Sign in to Kadify', 'admin.login' => 'Platform Administration', 'scanner.login' => 'Scan Guests'] as $route => $title) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee($title)
                ->assertSee('action="'.route('login.attempt').'"', false)
                ->assertSee('autocomplete="username"', false)
                ->assertSee('autocomplete="current-password"', false)
                ->assertDontSee('admin@example.com')
                ->assertDontSee('scanner@example.com');
        }
    }

    public function test_login_alias_does_not_change_role_or_grant_scanner_access(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->from(route('scanner.login'))
            ->post(route('login.attempt'), $this->credentials($admin))
            ->assertRedirect(route('admin.dashboard'));

        $this->get(route('scanner.login'))->assertRedirect(route('admin.dashboard'));
        $this->get(route('scanner.dashboard'))->assertForbidden();
        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);
    }

    public function test_failed_login_returns_to_labelled_page_without_flashing_password(): void
    {
        $user = $this->user(User::ROLE_SCANNER);

        $this->from(route('scanner.login'))
            ->post(route('login.attempt'), ['email' => $user->email, 'password' => 'never-flash-this-password'])
            ->assertRedirect(route('scanner.login'))
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('_old_input.password');

        $this->get(route('scanner.login'))
            ->assertOk()
            ->assertSee('Scan Guests')
            ->assertSee($user->email)
            ->assertDontSee('never-flash-this-password');

        $this->assertGuest();
    }

    public function test_login_renders_session_error_as_escaped_text(): void
    {
        $this->withSession(['error' => 'Your session expired. <script>alert(1)</script>'])
            ->get(route('login'))
            ->assertOk()
            ->assertSee('Your session expired.')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_scanner_https_redirect_precedes_guest_authentication_and_preserves_query(): void
    {
        config(['app.url' => 'https://192.168.100.109:8443']);

        $this->withServerVariables(['HTTP_HOST' => '192.168.100.109:8000', 'HTTPS' => 'off'])
            ->get('/scanner/scan?gate=main')
            ->assertRedirect('https://192.168.100.109:8443/scanner/scan?gate=main');
    }

    public function test_secure_scanner_guest_json_request_still_requires_authentication(): void
    {
        config(['app.url' => 'https://scanner.example']);

        $this->withServerVariables(['HTTP_HOST' => 'scanner.example', 'HTTPS' => 'on', 'SERVER_PORT' => 443])
            ->getJson('https://scanner.example/scanner/dashboard')
            ->assertUnauthorized();
    }

    public function test_localhost_scanner_login_is_exempt_from_https_redirect(): void
    {
        config(['app.url' => 'https://scanner.example']);

        $this->withServerVariables(['HTTP_HOST' => 'localhost', 'HTTPS' => 'off', 'SERVER_PORT' => 80])
            ->get('http://localhost/scanner/login')
            ->assertOk()
            ->assertSee('Scan Guests');
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => uniqid('auth-session-', true).'@example.com',
            'password' => 'password',
            'role' => $role,
            'gate_name' => $role === User::ROLE_SCANNER ? 'Main Gate' : null,
        ]);
    }

    private function credentials(User $user): array
    {
        return ['email' => $user->email, 'password' => 'password'];
    }
}
