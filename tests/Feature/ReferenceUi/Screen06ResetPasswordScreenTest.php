<?php

namespace Tests\Feature\ReferenceUi;

use App\Http\Controllers\PasswordRecoveryController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Screen06ResetPasswordScreenTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'old-synthetic-pass';

    private const NEW = 'new-synthetic-pass-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.driver' => null]);
        Notification::fake();
    }

    public function test_recovery_email_link_opens_the_reset_screen_in_swahili_without_showing_the_email(): void
    {
        $user = $this->customer();
        $this->post(route('password.email'), ['identifier' => '0788000001']);

        $url = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$url): bool {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/reset-password/', $url);

        $response = $this->get($url)
            ->assertOk()
            ->assertViewIs('reference_ui.reset_password')
            ->assertViewHas('linkValid', true)
            ->assertViewHas('language', 'sw')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSeeInOrder(['PangaEvents', 'Weka Nenosiri Jipya', 'Tengeneza nenosiri jipya kwa ajili ya akaunti yako.', 'Nenosiri Jipya', 'Weka nenosiri jipya', 'Angalau herufi 8.', 'Thibitisha Nenosiri Jipya', 'Rudia nenosiri jipya', 'Badilisha Nenosiri', 'Rudi kwenye ukurasa wa kuingia'])
            ->assertSee('method="POST" action="'.route('password.update').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('class="fp-back" href="'.e(route('login', ['lang' => 'sw'])).'"', false);

        $html = $response->getContent();
        $this->assertSame(2, substr_count($html, 'autocomplete="new-password"'));
        $this->assertSame(2, substr_count($html, 'data-rs-toggle'));
        $this->assertSame(0, substr_count($html, 'type="email"'), 'The account email is never shown as a field.');
        $this->assertStringNotContainsString('>'.$user->email.'<', $html);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_english_selection_keeps_the_same_valid_link_and_persists(): void
    {
        $user = $this->customer();
        $token = Password::broker('users')->createToken($user);

        $sw = $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->getContent();
        $this->assertStringContainsString(e(route('password.reset', ['token' => $token, 'email' => $user->email, 'lang' => 'en'])), $sw);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email, 'lang' => 'en']))
            ->assertOk()
            ->assertViewHas('linkValid', true)
            ->assertSessionHas('login_language', 'en')
            ->assertSeeInOrder(['Reset Your Password', 'Create a new password for your account.', 'New Password', 'Confirm New Password', 'Reset Password', 'Back to Login']);

        $this->assertTrue(Password::broker('users')->tokenExists($user->fresh(), $token), 'Switching language must not consume the token.');

        $this->post(route('password.update'), $this->resetData($user, $token))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('password_reset.success', [], 'en'))
            ->assertSessionHas('login_language', 'en');
        $this->get(route('login'))->assertSee(__('password_reset.success', [], 'en'));
    }

    public function test_valid_reset_hashes_the_new_password_consumes_the_token_and_requires_a_fresh_login(): void
    {
        $user = $this->customer();
        $user->forceFill(['remember_token' => 'old-remember'])->save();
        $device = $user->createToken('synthetic-device')->plainTextToken;
        $token = Password::broker('users')->createToken($user);

        $this->post(route('password.update'), $this->resetData($user, $token))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('password_reset.success', [], 'sw'));

        $fresh = $user->fresh();
        $this->assertGuest();
        $this->assertNotSame(self::NEW, $fresh->password);
        $this->assertTrue(Hash::check(self::NEW, $fresh->password));
        $this->assertNotSame('old-remember', $fresh->remember_token);
        $this->assertSame(0, $fresh->tokens()->count(), 'Device tokens are revoked.');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertNotEmpty($device);

        $this->get(route('login'))->assertSee(__('password_reset.success', [], 'sw'));

        // Replay of the consumed link: refused and shown as an invalid link.
        $this->from(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->post(route('password.update'), $this->resetData($user, $token, 'another-synthetic-pass'))
            ->assertSessionHasErrors(['email' => PasswordRecoveryController::INVALID_RESET]);
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertViewHas('linkValid', false);
        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password));

        // Old password refused, new password accepted.
        $this->post(route('login.attempt'), ['identifier' => $user->email, 'password' => self::OLD])->assertSessionHasErrors('identifier');
        $this->assertGuest();
        $this->post(route('login.attempt'), ['identifier' => $user->email, 'password' => self::NEW])->assertRedirect(route('customer.home'));
        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('invalidLinks')]
    public function test_invalid_expired_foreign_and_ineligible_links_show_one_safe_state(string $case): void
    {
        $user = $this->customer();
        $other = $this->customer(['email' => 'other.synthetic@example.invalid', 'phone_number' => '+255788000003']);
        $token = Password::broker('users')->createToken($user);
        $email = $user->email;

        match ($case) {
            'invalid' => $token = 'not-a-real-token',
            'expired' => $this->travel(61)->minutes(),
            'other account' => $email = $other->email,
            'unknown account' => $email = 'nobody@example.invalid',
            'inactive' => $user->update(['is_active' => false]),
        };

        $page = $this->get(route('password.reset', ['token' => $token, 'email' => $email]))
            ->assertOk()
            ->assertViewHas('linkValid', false)
            ->assertSee('Kiungo hiki si halali au muda wake umeisha.')
            ->assertSee('href="'.e(route('password.request', ['lang' => 'sw'])).'"', false)
            ->assertSee('Omba Kiungo Kipya')
            ->assertDontSee('autocomplete="new-password"', false)
            ->getContent();
        $this->assertStringNotContainsString($token, strip_tags(preg_replace('/href="[^"]*"/', '', $page)), 'The token is never printed as text.');

        $before = [$user->fresh()->password, $other->fresh()->password];
        $this->from(route('password.reset', ['token' => $token, 'email' => $email]))
            ->post(route('password.update'), ['token' => $token, 'email' => $email, 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertSessionHasErrors(['email' => PasswordRecoveryController::INVALID_RESET]);
        $this->assertSame($before, [$user->fresh()->password, $other->fresh()->password]);
        $this->assertGuest();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidLinks(): array
    {
        return [
            'invalid' => ['invalid'],
            'expired' => ['expired'],
            'other account' => ['other account'],
            'unknown account' => ['unknown account'],
            'inactive' => ['inactive'],
        ];
    }

    public function test_missing_short_and_mismatched_passwords_get_localized_field_errors(): void
    {
        $user = $this->customer();
        $token = Password::broker('users')->createToken($user);
        $link = route('password.reset', ['token' => $token, 'email' => $user->email]);

        foreach ([
            [['password' => '', 'password_confirmation' => ''], 'password_required', 'rs-error-password'],
            [['password' => 'short', 'password_confirmation' => 'short'], 'password_min', 'rs-error-password'],
            [['password' => self::NEW, 'password_confirmation' => 'different-synthetic'], 'password_mismatch', 'rs-error-password_confirmation'],
        ] as [$passwords, $key, $errorId]) {
            $this->from($link)
                ->post(route('password.update'), ['token' => $token, 'email' => $user->email] + $passwords)
                ->assertRedirect($link)
                ->assertSessionHasErrors(['password' => __('password_reset.'.$key, [], 'sw')])
                ->assertSessionMissing('_old_input.password')
                ->assertSessionMissing('_old_input.password_confirmation')
                ->assertSessionMissing('_old_input.token');

            $page = $this->get($link)->assertViewHas('linkValid', true)->getContent();
            $this->assertMatchesRegularExpression('/<p id="'.$errorId.'" class="fp-error"\s*>'.preg_quote(e(__('password_reset.'.$key, [], 'sw')), '/').'<\/p>/', $page);
            $this->assertStringNotContainsString('different-synthetic', $page);
        }

        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password));
        $this->assertTrue(Password::broker('users')->tokenExists($user->fresh(), $token), 'Validation failures do not consume the token.');
    }

    public function test_browser_throttling_keeps_429_and_the_reset_screen(): void
    {
        $user = $this->customer();
        $token = Password::broker('users')->createToken($user);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short']);
        }

        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(429)
            ->assertViewIs('reference_ui.reset_password')
            ->assertSee('Majaribio mengi mno. Jaribu tena baada ya sekunde', false)
            ->assertSee('action="'.route('password.update').'"', false);

        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password));
    }

    public function test_storage_failure_is_reported_without_credentials_and_changes_nothing(): void
    {
        $user = $this->customer();
        $token = Password::broker('users')->createToken($user);
        User::saving(function (): void {
            throw new QueryException('sqlite', 'update "users" set "password" = ?', ['$2y$secret-hash'], new \RuntimeException('disk I/O error'));
        });
        Log::shouldReceive('error')->once()->with('Password reset unavailable.', ['exception_type' => QueryException::class]);

        $link = route('password.reset', ['token' => $token, 'email' => $user->email]);
        $this->from($link)
            ->post(route('password.update'), $this->resetData($user, $token))
            ->assertRedirect($link)
            ->assertSessionHasErrors(['form' => __('password_reset.unexpected_error', [], 'sw')]);
        User::flushEventListeners();

        $this->get($link)
            ->assertSee(e(__('password_reset.unexpected_error', [], 'sw')), false)
            ->assertDontSee('secret-hash')
            ->assertDontSee('disk I/O error');
        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password));
        $this->assertTrue(Password::broker('users')->tokenExists($user->fresh(), $token));
    }

    public function test_authenticated_visitor_keeps_existing_behaviour_and_is_signed_out_by_a_reset(): void
    {
        $user = $this->customer();
        $this->actingAs($user);
        $token = Password::broker('users')->createToken($user);
        $counts = [DB::table('users')->count(), DB::table('workspaces')->count(), DB::table('events')->count()];

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk()->assertViewHas('linkValid', true);
        $this->post(route('password.update'), $this->resetData($user, $token))->assertRedirect(route('login'));

        Auth::forgetGuards();
        $this->assertGuest();
        $this->assertSame($counts, [DB::table('users')->count(), DB::table('workspaces')->count(), DB::table('events')->count()], 'No accounts, workspaces or events are created.');
        $this->assertSame(User::ROLE_CUSTOMER, $user->fresh()->role);
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_replace([
            'name' => 'Synthetic Reset',
            'email' => 'reset.synthetic@example.invalid',
            'phone_number' => '+255788000001',
            'password' => self::OLD,
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
        ], $attributes));
    }

    private function resetData(User $user, string $token, string $password = self::NEW): array
    {
        return ['token' => $token, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password];
    }
}
