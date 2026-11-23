<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LogoutLanguageTest extends TestCase
{
    use RefreshDatabase;

    public static function languages(): array
    {
        return ['Kiswahili' => ['sw', 'en'], 'English' => ['en', 'sw']];
    }

    public static function accountLanguages(): array
    {
        return ['Kiswahili' => ['sw'], 'English' => ['en']];
    }

    #[DataProvider('languages')]
    public function test_logout_keeps_only_the_chosen_language_and_fully_invalidates_the_session(string $chosen, string $accountLanguage): void
    {
        $user = $this->customer($accountLanguage);
        $this->get(route('login', ['lang' => $chosen]))->assertOk();
        $this->post(route('login.attempt'), ['identifier' => $user->email, 'password' => 'synthetic-pass-123'])
            ->assertRedirect(route('customer.home'));
        session()->put(['selected_workspace_id' => 7, 'selected_event_id' => 9, 'synthetic_marker' => 'private']);
        $sessionId = session()->getId();
        $csrf = session()->token();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($csrf, session()->token());
        $this->assertSame($chosen, session('login_language'));
        foreach (['password_hash_web', 'selected_workspace_id', 'selected_event_id', 'synthetic_marker', 'url.intended'] as $key) {
            $this->assertFalse(session()->has($key), $key);
        }
        $this->assertSame([], array_values(array_filter(array_keys(session()->all()), fn ($key) => str_starts_with($key, 'login_web_'))));

        $login = $this->get(route('login'))->assertOk();
        $login->assertSee('<html lang="'.$chosen.'">', false);
        $this->assertMatchesRegularExpression('/class="lg-language is-active"[^>]*lang="'.$chosen.'"/', $login->getContent());
        $this->get(route('customer.home'))->assertRedirect(route('login'));
    }

    #[DataProvider('accountLanguages')]
    public function test_logout_without_a_session_choice_uses_the_account_language(string $accountLanguage): void
    {
        $user = $this->customer($accountLanguage);

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame($accountLanguage, session('login_language'));
        $this->get(route('login'))->assertSee('<html lang="'.$accountLanguage.'">', false);
    }

    public function test_logout_ignores_an_unsupported_stored_language(): void
    {
        $user = $this->customer('sw');

        $this->actingAs($user)->withSession(['login_language' => 'fr'])->post(route('logout'))->assertRedirect(route('login'));

        $this->assertSame('sw', session('login_language'));
        $this->get(route('login'))->assertSee('<html lang="sw">', false);
    }

    private function customer(string $language): User
    {
        return User::query()->create([
            'name' => 'Synthetic Logout',
            'email' => uniqid('logout-', true).'@example.invalid',
            'phone_number' => '+2557'.random_int(10000000, 99999999),
            'password' => 'synthetic-pass-123',
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
            'preferred_language' => $language,
        ]);
    }
}
