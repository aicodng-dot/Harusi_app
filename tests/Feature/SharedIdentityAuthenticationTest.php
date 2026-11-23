<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SharedIdentityAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('phoneFormats')]
    public function test_equivalent_phone_identifiers_sign_in_to_the_same_account(string $identifier): void
    {
        $user = $this->user(['phone_number' => '0712345678']);

        $this->post(route('login.attempt'), ['identifier' => $identifier, 'password' => 'password'])
            ->assertRedirect(route('scanner.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('+255712345678', $user->fresh()->phone_number);
    }

    public static function phoneFormats(): array
    {
        return [
            'local' => ['0712345678'],
            'country code' => ['255712345678'],
            'international' => ['+255712345678'],
            'formatted' => ['+255 712 345 678'],
            'national significant number' => ['712345678'],
        ];
    }

    public function test_legacy_email_request_and_new_email_identifier_both_work_without_rewriting_email(): void
    {
        $user = $this->user(['email' => 'Legacy.User@example.com']);

        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('scanner.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'));

        $this->post(route('login.attempt'), ['identifier' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('scanner.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Legacy.User@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->phone_number);
    }

    public function test_explicit_identifier_takes_precedence_over_legacy_email(): void
    {
        $user = $this->user();

        $this->post(route('login.attempt'), [
            'identifier' => 'unknown@example.com', 'email' => $user->email, 'password' => 'password',
        ])->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    #[DataProvider('invalidIdentifiers')]
    public function test_invalid_or_unknown_identifier_uses_generic_credential_error(string $identifier): void
    {
        $this->user(['phone_number' => '0712345678']);

        $this->postJson(route('login.attempt'), ['identifier' => $identifier, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.identifier.0', 'The provided credentials do not match our records.');

        $this->assertGuest();
    }

    public static function invalidIdentifiers(): array
    {
        return [
            'invalid text' => ['not-an-account'],
            'missing digit' => ['071234567'],
            'wrong country structure' => ['2550712345678'],
            'unknown phone' => ['0799999999'],
            'unknown email' => ['missing@example.com'],
        ];
    }

    #[DataProvider('unavailableAccounts')]
    public function test_inactive_and_unknown_role_accounts_cannot_login(array $attributes): void
    {
        $user = $this->user($attributes);

        $this->postJson(route('login.attempt'), ['identifier' => $user->email, 'password' => 'password', 'remember' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.identifier.0', 'The provided credentials do not match our records.');

        $this->assertGuest();
        $this->assertNull($user->fresh()->getAttribute('remember_token'));
    }

    public static function unavailableAccounts(): array
    {
        return [
            'inactive scanner' => [['is_active' => false]],
            'inactive admin' => [['is_active' => false, 'role' => User::ROLE_ADMIN]],
            'unknown role' => [['role' => 'unrecognized']],
        ];
    }

    public function test_persisted_deactivation_invalidates_a_cached_authenticated_session(): void
    {
        $user = $this->user(['role' => User::ROLE_ADMIN]);
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

        $this->assertTrue($user->is_active);

        $this->withSession(['selected_event_id' => 99, 'guest_import' => ['token' => 'old-context']])
            ->getJson(route('admin.users.index'))
            ->assertUnauthorized()
            ->assertSessionMissing('selected_event_id')
            ->assertSessionMissing('guest_import')
            ->assertCookieExpired(Auth::guard()->getRecallerName());

        $this->assertGuest();
    }

    public function test_deactivated_account_cannot_restore_access_using_a_remember_cookie(): void
    {
        $user = $this->user();
        $cookieName = Auth::guard()->getRecallerName();
        $response = $this->post(route('login.attempt'), ['identifier' => $user->email, 'password' => 'password', 'remember' => true]);
        $cookie = $response->getCookie($cookieName);
        $oldToken = $user->fresh()->getRememberToken();

        session()->forget(Auth::guard()->getName());
        Auth::forgetGuards();
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

        $this->withCredentials()
            ->withCookie($cookieName, $cookie->getValue())
            ->getJson(route('scanner.dashboard'))
            ->assertUnauthorized()
            ->assertCookieExpired($cookieName);

        $this->assertGuest();
        $this->assertNotSame($oldToken, $user->fresh()->getRememberToken());
    }

    public function test_persisted_role_change_is_applied_before_role_authorization(): void
    {
        $user = $this->user(['role' => User::ROLE_ADMIN]);
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update([
            'role' => User::ROLE_SCANNER,
            'event_id' => Event::defaultEvent()->id,
            'gate_name' => 'Main Gate',
        ]);

        $this->get(route('scanner.dashboard'))->assertOk();
        $this->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_deleted_account_is_rejected_even_when_guard_has_cached_identity(): void
    {
        $user = $this->user(['role' => User::ROLE_ADMIN]);
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->delete();

        $this->getJson(route('admin.users.index'))->assertUnauthorized();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_identifier_limit_groups_phone_formats_across_ip_addresses(): void
    {
        // These requests model one burst even when the test host pauses.
        $this->freezeTime();
        $this->user(['phone_number' => '0712345678']);
        $formats = ['0712345678', '255712345678', '+255712345678'];

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($attempt + 1)])
                ->postJson(route('login.attempt'), ['identifier' => $formats[$attempt % 3], 'password' => 'incorrect'])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->postJson(route('login.attempt'), ['identifier' => '+255712345678', 'password' => 'password'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->post(route('login.attempt'), ['identifier' => '+255712345678', 'password' => 'password'])
            ->assertRedirect(route('scanner.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_ip_limit_applies_even_when_identifiers_change(): void
    {
        // These requests model one burst even when the test host pauses.
        $this->freezeTime();
        $user = $this->user();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.100'])
                ->postJson(route('login.attempt'), ['identifier' => 'missing-'.$attempt.'@example.com', 'password' => 'incorrect'])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.100'])
            ->postJson(route('login.attempt'), ['identifier' => $user->email, 'password' => 'password'])
            ->assertTooManyRequests();

        $this->assertGuest();
    }

    public function test_database_defaults_preserve_legacy_email_only_accounts(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Legacy account', 'email' => 'legacy-defaults@example.com',
            'password' => 'existing-password-hash', 'role' => User::ROLE_ADMIN,
        ]);

        $user = User::query()->findOrFail($id);
        $this->assertNull($user->phone_number);
        $this->assertTrue($user->is_active);
        $this->assertSame('sw', $user->preferred_language);
        $this->assertSame('existing-password-hash', $user->password);
    }

    public function test_model_and_database_enforce_canonical_account_phone_uniqueness(): void
    {
        $this->user(['phone_number' => '0712345678']);
        $this->expectException(QueryException::class);

        $this->user(['phone_number' => '255712345678']);
    }

    public function test_missing_phone_is_allowed_for_multiple_accounts(): void
    {
        $first = $this->user(['phone_number' => null]);
        $second = $this->user(['phone_number' => '']);

        $this->assertNull($first->fresh()->phone_number);
        $this->assertNull($second->fresh()->phone_number);
        $this->assertNotSame($first->id, $second->id);
    }

    private function user(array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Identity User',
            'email' => uniqid('identity-', true).'@example.com',
            'password' => 'password',
            'role' => User::ROLE_SCANNER,
            'gate_name' => 'Main Gate',
        ], $attributes));
    }
}
