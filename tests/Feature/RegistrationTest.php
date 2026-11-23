<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_and_terms_render_in_both_languages_with_real_form_controls_and_navigation(): void
    {
        foreach (['sw' => 'Jisajili Akaunti Mpya', 'en' => 'Create a New Account'] as $language => $title) {
            $response = $this->get(route('register', ['lang' => $language]))
                ->assertOk()->assertSee($title)->assertSee('<html lang="'.$language.'">', false)
                ->assertSee('method="POST" action="'.route('register.store').'"', false)
                ->assertSee('name="_token"', false)
                ->assertSee('href="'.route('terms', ['lang' => $language]).'"', false)
                ->assertSee('aria-controls="register-password"', false)
                ->assertSee('aria-controls="register-password_confirmation"', false)
                ->assertSessionHas('login_language', $language);

            foreach (['name', 'phone_number', 'email', 'password', 'password_confirmation', 'preferred_language', 'terms'] as $field) {
                $response->assertSee('name="'.$field.'"', false);
            }
            $this->assertSame(2, substr_count($response->getContent(), 'data-register-toggle'));
            $this->get(route('register'))->assertOk()->assertSee('<html lang="'.$language.'">', false);

            $this->get(route('terms', ['lang' => $language]))->assertOk()
                ->assertSee('<html lang="'.$language.'">', false)
                ->assertSee(__('terms.title', [], $language))
                ->assertSee(__('terms.draft_title', [], $language))
                ->assertSee('href="'.route('register', ['lang' => $language]).'"', false);

            $this->get(route('login'))->assertOk()
                ->assertSee('<html lang="'.$language.'">', false)
                ->assertSee('href="'.route('register').'"', false)
                ->assertDontSee('id="registration-availability"', false);
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_signup_creates_only_a_customer_with_a_hashed_password_and_canonical_phone(): void
    {
        $events = DB::table('events')->count();
        $workspaces = DB::table('workspaces')->count();

        $this->post(route('register.store'), $this->profile() + [
            'role' => User::ROLE_ADMIN, 'is_platform_admin' => true, 'is_active' => false,
            'event_id' => 999, 'workspace_id' => 999, 'gate_name' => 'Privileged gate',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $user = User::query()->sole();
        $this->assertSame('Asha Mushi', $user->name);
        $this->assertSame('asha@example.invalid', $user->email);
        $this->assertSame('+255712345678', $user->phone_number);
        $this->assertTrue(Hash::check('correct-horse-123', $user->password));
        $this->assertSame(User::ROLE_CUSTOMER, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->is_platform_admin);
        $this->assertNull($user->event_id);
        $this->assertNull($user->gate_name);
        $this->assertSame('sw', $user->preferred_language);
        $this->assertSame($events, DB::table('events')->count());
        $this->assertSame($workspaces, DB::table('workspaces')->count());
        $this->assertDatabaseCount('workspace_users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertGuest();

        $this->post(route('login.attempt'), [
            'identifier' => '0712345678', 'password' => 'correct-horse-123',
        ])->assertRedirect(route('customer.home'));
        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('invalidProfiles')]
    public function test_invalid_signup_does_not_create_an_account(array $changes, string $field): void
    {
        $this->from('/register')->post(route('register.store'), array_replace($this->profile(), $changes))
            ->assertRedirect('/register')->assertSessionHasErrors($field)
            ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public static function invalidProfiles(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'invalid phone' => [['phone_number' => 'invalid'], 'phone_number'],
            'invalid email' => [['email' => 'invalid'], 'email'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'confirmation mismatch' => [['password_confirmation' => 'different-password'], 'password'],
            'unsupported language' => [['preferred_language' => 'fr'], 'preferred_language'],
            'terms not accepted' => [['terms' => '0'], 'terms'],
            'non-scalar identity' => [['phone_number' => ['0712345678']], 'phone_number'],
        ];
    }

    public function test_duplicate_email_is_rejected_with_case_insensitive_comparison(): void
    {
        User::factory()->create(['email' => 'ASHA@example.invalid']);

        $this->post(route('register.store'), $this->profile())->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_duplicate_phone_is_rejected_after_canonicalization(): void
    {
        User::factory()->create(['phone_number' => '+255712345678']);

        $this->post(route('register.store'), $this->profile())->assertSessionHasErrors('phone_number');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_authenticated_visitor_cannot_create_another_account(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $before = $user->fresh()->getRawOriginal();

        $this->actingAs($user)->get(route('register'))->assertRedirect(route('login'));
        $this->post(route('register.store'), $this->profile())->assertRedirect(route('login'));

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_attempts_are_rate_limited_without_creating_users(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('register.store'), [])->assertSessionHasErrors();
        }
        $this->post(route('register.store'), $this->profile())->assertTooManyRequests();
        $this->assertDatabaseCount('users', 0);
    }

    private function profile(): array
    {
        return [
            'name' => 'Asha Mushi', 'phone_number' => '0712 345 678',
            'email' => ' ASHA@example.invalid ', 'password' => 'correct-horse-123',
            'password_confirmation' => 'correct-horse-123', 'preferred_language' => 'sw', 'terms' => '1',
        ];
    }
}
