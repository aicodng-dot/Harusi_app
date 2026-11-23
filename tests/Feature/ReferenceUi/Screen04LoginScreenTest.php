<?php

namespace Tests\Feature\ReferenceUi;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Screen04LoginScreenTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'synthetic-pass-123';

    public function test_login_screen_renders_the_reference_form_in_swahili_by_default(): void
    {
        $response = $this->get(route('login'))
            ->assertOk()
            ->assertViewIs('reference_ui.03_login')
            ->assertViewHas('language', 'sw')
            ->assertSee('<html lang="sw">', false)
            ->assertSeeInOrder([
                'Ingia kwenye Akaunti',
                'Simamia matukio yako, mialiko, wageni, skana na ripoti.',
                'Barua Pepe au Namba ya Simu',
                'Nenosiri',
                'Nikumbuke',
                'Umesahau Nenosiri?',
                'Ingia',
                'Huna akaunti?',
                'Jisajili',
            ])
            ->assertSee('method="POST" action="'.route('login.attempt').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('type="password"', false)
            ->assertSee('aria-controls="password" aria-pressed="false" data-password-toggle', false)
            ->assertSee('href="'.e(route('password.request', ['lang' => 'sw'])).'"', false)
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee('class="lg-back" href="'.e(route('reference_ui.10_welcome_how_it_works.show', ['lang' => 'sw'])).'"', false)
            // No Google OAuth route is configured, so neither the button nor its separator renders.
            ->assertDontSee('lg-google')
            ->assertDontSee('lg-divider');

        $this->assertStringNotContainsString('name="role"', $response->getContent());
        // Existing behaviour: remember-me starts selected, as in the reference.
        $this->assertMatchesRegularExpression('/type="checkbox" name="remember" value="1"\s+checked/', $response->getContent());
    }

    public function test_entry_points_link_to_login_and_login_links_out_with_the_selected_language(): void
    {
        $this->get(route('reference_ui.10_welcome_how_it_works.show', ['lang' => 'en']))
            ->assertSee('href="'.e(route('login', ['lang' => 'en'])).'"', false);
        $this->get(route('register', ['lang' => 'en']))
            ->assertSee('href="'.e(route('login', ['lang' => 'en'])).'"', false);

        $this->get(route('login', ['lang' => 'en']))
            ->assertOk()
            ->assertSee('href="'.e(route('reference_ui.10_welcome_how_it_works.show', ['lang' => 'en'])).'"', false)
            ->assertSee('href="'.e(route('password.request', ['lang' => 'en'])).'"', false);

        // The registration link carries the language through the shared session key.
        $this->get(route('register'))->assertOk()->assertViewHas('language', 'en');
        $this->get(route('password.request', ['lang' => 'en']))->assertOk();
        $this->get(route('reference_ui.10_welcome_how_it_works.show'))->assertOk()->assertViewHas('language', 'en');
    }

    public function test_english_selection_translates_the_screen_and_persists(): void
    {
        $this->get(route('login', ['lang' => 'en']))
            ->assertOk()
            ->assertSessionHas('login_language', 'en')
            ->assertSeeInOrder(['Log in to your account', 'Email or Phone Number', 'Password', 'Remember me', 'Forgot Password?', 'Log In', 'Don’t have an account?', 'Register']);

        $english = $this->get(route('login'))->assertViewHas('language', 'en')->getContent();
        $this->assertMatchesRegularExpression('/class="lg-language is-active"\s+href="[^"]*lang=en"\s+lang="en"[^>]*aria-current="true"/', $english);

        $this->from(route('login'))->post(route('login.attempt'), ['identifier' => '', 'password' => '']);
        $this->get(route('login'))
            ->assertSee(__('login.identifier_required', [], 'en'))
            ->assertSee(__('login.password_required', [], 'en'));
    }

    #[DataProvider('identifiers')]
    public function test_customer_without_a_workspace_logs_in_by_email_or_any_phone_format(string $identifier): void
    {
        $user = $this->account(User::ROLE_CUSTOMER);
        $workspaces = DB::table('workspaces')->count();
        $events = DB::table('events')->count();
        $this->get(route('login'));
        $sessionBefore = session()->getId();

        $this->post(route('login.attempt'), ['identifier' => $identifier, 'password' => self::PASSWORD, 'remember' => '1'])
            ->assertRedirect(route('customer.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionBefore, session()->getId(), 'The session must be regenerated on login.');
        $this->get(route('customer.home'))->assertOk();
        $this->assertSame($workspaces, DB::table('workspaces')->count(), 'Login must not create a workspace.');
        $this->assertSame($events, DB::table('events')->count(), 'Login must not create an event.');
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $user->id]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function identifiers(): array
    {
        return [
            'email' => ['synthetic.customer@example.invalid'],
            'local phone' => ['0788000001'],
            'country code phone' => ['255788000001'],
            'international phone' => ['+255788000001'],
            'spaced phone' => ['0788 000 001'],
        ];
    }

    #[DataProvider('roleDestinations')]
    public function test_each_role_reaches_its_existing_authorized_destination(string $role, string $destination): void
    {
        $user = $this->account($role);

        $this->post(route('login.attempt'), ['identifier' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route($destination));
        $this->assertAuthenticatedAs($user);
        $this->assertSame($role, $user->fresh()->role);
        $this->get(route('login'))->assertRedirect(route($destination));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function roleDestinations(): array
    {
        return [
            'customer' => [User::ROLE_CUSTOMER, 'customer.home'],
            'administrator' => [User::ROLE_ADMIN, 'admin.dashboard'],
            'scanner' => [User::ROLE_SCANNER, 'scanner.dashboard'],
        ];
    }

    public function test_wrong_password_unknown_account_and_inactive_account_share_one_generic_error(): void
    {
        $this->account(User::ROLE_CUSTOMER);
        $this->account(User::ROLE_CUSTOMER, ['email' => 'inactive@example.invalid', 'phone_number' => '+255788000002', 'is_active' => false]);

        $bags = [];
        foreach ([
            ['synthetic.customer@example.invalid', 'wrong-synthetic-pass'],
            ['nobody@example.invalid', self::PASSWORD],
            ['0799999999', self::PASSWORD],
            ['inactive@example.invalid', self::PASSWORD],
        ] as [$identifier, $password]) {
            $this->from(route('login'))
                ->post(route('login.attempt'), ['identifier' => $identifier, 'password' => $password])
                ->assertRedirect(route('login'))
                ->assertSessionMissing('_old_input.password');
            $bags[] = session('errors')->getBag('default')->toArray();
            $this->assertGuest();
        }
        $this->assertCount(1, array_unique(array_map('serialize', $bags)), 'Every failure must look identical.');

        $page = $this->get(route('login'))
            ->assertOk()
            ->assertSee(__('login.invalid_credentials', [], 'sw'))
            ->assertDontSee(self::PASSWORD)
            ->assertSee('value="inactive@example.invalid"', false)
            ->getContent();
        $this->assertSame(2, substr_count($page, 'aria-invalid="true"'));
    }

    public function test_missing_fields_are_refused_with_field_level_localized_errors(): void
    {
        // A browser always posts both named fields, even when empty.
        $this->from(route('login'))->post(route('login.attempt'), ['identifier' => '', 'password' => ''])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['identifier', 'password']);
        $this->assertGuest();

        $page = $this->get(route('login'))->getContent();
        $this->assertMatchesRegularExpression('/<p id="lg-error-identifier" class="lg-error"\s*>'.preg_quote(__('login.identifier_required', [], 'sw'), '/').'<\/p>/', $page);
        $this->assertMatchesRegularExpression('/<p id="lg-error-password" class="lg-error"\s*>'.preg_quote(__('login.password_required', [], 'sw'), '/').'<\/p>/', $page);
    }

    public function test_remember_me_issues_a_recaller_cookie_only_when_selected(): void
    {
        $user = $this->account(User::ROLE_CUSTOMER);
        $recaller = Auth::guard('web')->getRecallerName();

        $with = $this->post(route('login.attempt'), ['identifier' => $user->email, 'password' => self::PASSWORD, 'remember' => '1']);
        $this->assertNotNull($with->getCookie($recaller));
        $this->assertNotNull($user->fresh()->remember_token);

        $this->post(route('logout'));
        $this->assertGuest();

        $without = $this->post(route('login.attempt'), ['identifier' => $user->email, 'password' => self::PASSWORD]);
        $cookie = $without->getCookie($recaller);
        $this->assertTrue($cookie === null || $cookie->isCleared(), 'No persistent cookie without remember-me.');
    }

    public function test_browser_throttling_keeps_429_and_shows_localized_retry_guidance(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->from(route('login'))
                ->post(route('login.attempt'), ['identifier' => 'throttle@example.invalid', 'password' => 'wrong-synthetic-pass'])
                ->assertRedirect(route('login'));
        }

        $this->from(route('login'))
            ->post(route('login.attempt'), ['identifier' => 'throttle@example.invalid', 'password' => 'wrong-synthetic-pass'])
            ->assertStatus(429)
            ->assertViewIs('reference_ui.03_login')
            ->assertSee('Majaribio mengi mno ya kuingia. Jaribu tena baada ya sekunde', false)
            ->assertSee('value="throttle@example.invalid"', false)
            ->assertDontSee('wrong-synthetic-pass')
            ->assertSee('action="'.route('login.attempt').'"', false);

        // JSON clients keep the existing bare 429 contract.
        $json = $this->postJson(route('login.attempt'), ['identifier' => 'throttle@example.invalid', 'password' => 'x'])
            ->assertStatus(429);
        $this->assertStringNotContainsString('data-lg-form', $json->getContent());
        $this->assertGuest();
    }

    public function test_expired_session_notice_is_localized_and_other_errors_stay_escaped(): void
    {
        $this->withSession(['error' => 'Your session expired. Refresh the page and try that action again.'])
            ->get(route('login'))
            ->assertOk()
            ->assertSee(__('login.session_expired', [], 'sw'))
            ->assertDontSee('Refresh the page and try that action again.');

        $this->withSession(['error' => 'Unexpected <b>notice</b>'])
            ->get(route('login'))
            ->assertSee('Unexpected &lt;b&gt;notice&lt;/b&gt;', false);
    }

    public function test_storage_failure_during_sign_in_is_reported_safely(): void
    {
        $user = $this->account(User::ROLE_CUSTOMER);
        User::retrieved(function (): void {
            throw new QueryException('sqlite', 'select * from "users" where "secret_column" = ?', ['x'], new \RuntimeException('database is locked'));
        });

        $this->from(route('login'))
            ->post(route('login.attempt'), ['identifier' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['form' => __('login.unexpected_error', [], 'sw')]);
        $this->assertGuest();
        User::flushEventListeners();

        $this->get(route('login'))
            ->assertSee(__('login.unexpected_error', [], 'sw'))
            ->assertDontSee('secret_column')
            ->assertDontSee('database is locked');
    }

    public function test_registration_success_message_is_shown_on_login(): void
    {
        $this->withSession(['status' => __('register.created', [], 'sw')])
            ->get(route('login'))
            ->assertSee('role="status"', false)
            ->assertSee(__('register.created', [], 'sw'));
    }

    private function account(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_replace([
            'name' => 'Synthetic '.$role,
            'email' => $role === User::ROLE_CUSTOMER ? 'synthetic.customer@example.invalid' : 'synthetic.'.$role.'@example.invalid',
            'phone_number' => $role === User::ROLE_CUSTOMER ? '+255788000001' : null,
            'password' => self::PASSWORD,
            'role' => $role,
            'is_active' => true,
        ], $attributes));
    }
}
