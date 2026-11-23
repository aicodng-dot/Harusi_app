<?php

namespace Tests\Feature\ReferenceUi;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Screen03RegistrationScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_renders_the_reference_form_in_swahili_by_default(): void
    {
        $response = $this->get(route('register'))
            ->assertOk()
            ->assertViewIs('reference_ui.02_register_account')
            ->assertViewHas('language', 'sw')
            ->assertSee('<html lang="sw">', false)
            ->assertSeeInOrder([
                'Jisajili Akaunti Mpya',
                'Tengeneza akaunti yako kuanza.',
                'Jina Kamili',
                'Namba ya Simu',
                '+255 712 345 678',
                'Barua Pepe',
                'Nenosiri',
                'Angalau herufi 8.',
                'Thibitisha Nenosiri',
                'Lugha Inayopendelewa',
                'Kiswahili',
                'Nakubaliana na',
                'Sheria na Masharti',
                'Jisajili',
                'Tayari una akaunti?',
                'Ingia',
            ])
            ->assertSee('method="POST" action="'.route('register.store').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('type="tel" inputmode="tel"', false)
            ->assertSee('autocomplete="name"', false)
            ->assertSee('autocomplete="tel"', false)
            ->assertSee('type="email" inputmode="email"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('Tanzania +255', false)
            ->assertSee('<option value="sw" lang="sw" selected>', false)
            ->assertSee('href="'.e(route('terms', ['lang' => 'sw'])).'" target="_blank" rel="noopener"', false);

        $html = $response->getContent();
        $this->assertSame(2, substr_count($html, 'autocomplete="new-password"'));
        $this->assertSame(2, substr_count($html, 'type="password"'));
        $this->assertSame(2, substr_count($html, 'data-register-toggle'));
        $this->assertStringNotContainsString('name="role"', $html);
        $this->assertStringNotContainsString('name="workspace', $html);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_welcome_anza_back_arrow_and_login_link_navigate_with_the_selected_language(): void
    {
        $this->get(route('reference_ui.10_welcome_how_it_works.show', ['lang' => 'en']))
            ->assertSee('href="'.e(route('register', ['lang' => 'en'])).'"', false);

        $this->get(route('register', ['lang' => 'en']))
            ->assertOk()
            ->assertSee('class="reg-back" href="'.e(route('reference_ui.10_welcome_how_it_works.show', ['lang' => 'en'])).'"', false)
            ->assertSee('href="'.e(route('login', ['lang' => 'en'])).'"', false);

        $this->get(route('reference_ui.10_welcome_how_it_works.show', ['lang' => 'en']))->assertOk();
        $this->get(route('login', ['lang' => 'en']))->assertOk();
    }

    public function test_english_selection_translates_the_form_and_persists(): void
    {
        $this->get(route('register', ['lang' => 'en']))
            ->assertOk()
            ->assertSessionHas('login_language', 'en')
            ->assertSee('<html lang="en">', false)
            ->assertSeeInOrder(['Create a New Account', 'Full Name', 'Phone Number', 'Email Address', 'Password', 'At least 8 characters.', 'Confirm Password', 'Preferred Language', 'I agree to the', 'Terms and Conditions', 'Create Account', 'Already have an account?', 'Log in'])
            ->assertSee('<option value="en" lang="en" selected>', false);

        $english = $this->get(route('register'))->assertViewHas('language', 'en')->getContent();
        $this->assertMatchesRegularExpression('/class="reg-language is-active"\s+href="[^"]*lang=en"\s+lang="en"[^>]*aria-current="true"/', $english);

        $this->from(route('register'))->post(route('register.store'), [])
            ->assertSessionHasErrors(['name' => __('register.name_error', [], 'en')]);
    }

    public function test_valid_registration_creates_exactly_one_customer_and_follows_the_approved_redirect(): void
    {
        $workspaces = DB::table('workspaces')->count();

        $this->post(route('register.store'), $this->profile() + [
            'role' => User::ROLE_ADMIN,
            'is_platform_admin' => '1',
            'workspace_id' => 7,
            'event_id' => 9,
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('register.created', [], 'sw'));

        $user = User::query()->sole();
        $this->assertSame('Neema Kweka', $user->name);
        $this->assertSame('neema@example.invalid', $user->email);
        $this->assertSame('+255788000001', $user->phone_number);
        $this->assertNotSame('correct-horse-123', $user->password);
        $this->assertTrue(Hash::check('correct-horse-123', $user->password));
        $this->assertSame(User::ROLE_CUSTOMER, $user->role);
        $this->assertFalse((bool) $user->is_platform_admin);
        $this->assertNull($user->event_id);
        $this->assertTrue((bool) $user->is_active);
        // Approved contract: registration provisions no workspace; the customer creates one later.
        $this->assertSame($workspaces, DB::table('workspaces')->count());
        $this->assertDatabaseCount('workspace_users', 0);
        $this->assertGuest();

        $this->post(route('login.attempt'), ['identifier' => 'neema@example.invalid', 'password' => 'correct-horse-123'])
            ->assertRedirect(route('customer.home'));
        $this->assertAuthenticatedAs($user);
        $this->get(route('customer.home'))->assertOk();
        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    #[DataProvider('equivalentPhones')]
    public function test_equivalent_tanzanian_phone_formats_resolve_to_one_canonical_identity(string $first, string $second): void
    {
        $this->post(route('register.store'), $this->profile(['phone_number' => $first]))->assertRedirect(route('login'));
        $this->assertSame('+255788000001', User::query()->sole()->phone_number);

        $this->from(route('register'))
            ->post(route('register.store'), $this->profile(['phone_number' => $second, 'email' => 'other@example.invalid']))
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['phone_number' => __('register.identity_exists', [], 'sw')]);
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function equivalentPhones(): array
    {
        return [
            'local then international' => ['0788000001', '+255788000001'],
            'country code then local' => ['255788000001', '0788 000 001'],
            'international then country code' => ['+255788000001', '255788000001'],
        ];
    }

    public function test_duplicate_email_is_refused_with_the_safe_generic_message(): void
    {
        User::factory()->create(['email' => 'NEEMA@example.invalid']);

        $this->from(route('register'))->post(route('register.store'), $this->profile())
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['email' => __('register.identity_exists', [], 'sw')]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_password_mismatch_is_refused_and_shown_beside_the_confirmation_field(): void
    {
        $response = $this->followingRedirects()
            ->from(route('register'))
            ->post(route('register.store'), $this->profile(['password_confirmation' => 'different-horse-123']))
            ->assertOk()
            ->assertDontSee('correct-horse-123')
            ->assertSee('value="Neema Kweka"', false);

        $html = $response->getContent();
        $mismatch = preg_quote(e(__('register.password_mismatch', [], 'sw')), '/');
        $this->assertMatchesRegularExpression('/<p id="reg-error-password_confirmation" class="reg-error"\s*>'.$mismatch.'<\/p>/', $html);
        $this->assertMatchesRegularExpression('/<p id="reg-error-password" class="reg-error"\s+hidden\s*><\/p>/', $html);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_required_fields_and_terms_are_refused_with_field_level_errors(): void
    {
        $this->from(route('register'))->post(route('register.store'), [])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['name', 'email', 'phone_number', 'password', 'preferred_language', 'terms']);

        $this->from(route('register'))->post(route('register.store'), $this->profile(['terms' => null]))
            ->assertSessionHasErrors(['terms' => __('register.terms_required', [], 'sw')]);

        $this->followingRedirects()->from(route('register'))->post(route('register.store'), $this->profile(['terms' => null]))
            ->assertSee('id="reg-alert" class="reg-alert" role="alert"', false)
            ->assertSee(e(__('register.terms_required', [], 'sw')), false)
            ->assertSee('aria-invalid="true" aria-describedby="reg-error-terms"', false);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_preferred_language_is_saved_from_the_form(): void
    {
        $this->post(route('register.store'), $this->profile(['preferred_language' => 'en']))->assertRedirect(route('login'));

        $this->assertSame('en', User::query()->sole()->preferred_language);
    }

    public function test_repeated_submission_creates_only_one_account(): void
    {
        $this->post(route('register.store'), $this->profile())->assertRedirect(route('login'));
        $this->from(route('register'))->post(route('register.store'), $this->profile())
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_unexpected_storage_failure_is_reported_safely_and_can_be_retried(): void
    {
        User::creating(function (): void {
            throw new QueryException('sqlite', 'insert into "users" ("secret_column") values (?)', ['x'], new \RuntimeException('disk I/O error'));
        });

        $this->followingRedirects()
            ->from(route('register'))
            ->post(route('register.store'), $this->profile())
            ->assertOk()
            ->assertSee(e(__('register.unexpected_error', [], 'sw')), false)
            ->assertDontSee('secret_column')
            ->assertDontSee('disk I/O error')
            ->assertSee('value="neema@example.invalid"', false);

        $this->assertDatabaseCount('users', 0);
        User::flushEventListeners();

        $this->post(route('register.store'), $this->profile())->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('authenticatedRoles')]
    public function test_authenticated_users_are_redirected_away_from_registration(string $role, string $destination): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('register'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertRedirect(route($destination));
        $this->post(route('register.store'), $this->profile())->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authenticatedRoles(): array
    {
        return [
            'customer' => [User::ROLE_CUSTOMER, 'customer.home'],
            'administrator' => [User::ROLE_ADMIN, 'admin.dashboard'],
            'scanner' => [User::ROLE_SCANNER, 'scanner.dashboard'],
        ];
    }

    private function profile(array $changes = []): array
    {
        return array_replace([
            'name' => 'Neema Kweka',
            'phone_number' => '0788000001',
            'email' => 'neema@example.invalid',
            'password' => 'correct-horse-123',
            'password_confirmation' => 'correct-horse-123',
            'preferred_language' => 'sw',
            'terms' => '1',
        ], $changes);
    }
}
