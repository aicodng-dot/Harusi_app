<?php

namespace Tests\Feature\ReferenceUi;

use App\Http\Controllers\SplashScreenController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Screen02WelcomeScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_visitor_sees_the_swahili_welcome_instead_of_a_redirect(): void
    {
        $this->get(route('reference_ui.10_welcome_how_it_works.show'))
            ->assertOk()
            ->assertViewIs('reference_ui.10_welcome_how_it_works')
            ->assertViewHas('language', 'sw')
            ->assertSeeInOrder([
                'Karibu PangaEvents',
                'Rahisisha mipango ya matukio yako.',
                'Unda Tukio',
                'Tuma Mialiko',
                'Skana Wageni',
                'Anza',
                'Tayari una akaunti?',
                'Ingia',
            ])
            ->assertSee('10_Welcome_How_It_Works_Card_1.webp')
            ->assertSee('10_Welcome_How_It_Works_Card_2.webp')
            ->assertSee('10_Welcome_How_It_Works_Card_3.webp');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_splash_navigation_opens_welcome(): void
    {
        $welcome = route('reference_ui.10_welcome_how_it_works.show', ['lang' => 'sw']);

        $this->get(route('reference_ui.01_splash_screen.show'))
            ->assertOk()
            ->assertSee('href="'.e($welcome).'"', false);

        $this->get($welcome)->assertOk()->assertViewIs('reference_ui.10_welcome_how_it_works');
    }

    public function test_anza_and_ingia_link_to_existing_registration_and_login_routes(): void
    {
        $this->get(route('reference_ui.10_welcome_how_it_works.show'))
            ->assertOk()
            ->assertSee('href="'.e(route('register', ['lang' => 'sw'])).'"', false)
            ->assertSee('href="'.e(route('login', ['lang' => 'sw'])).'"', false);

        $this->get(route('register', ['lang' => 'sw']))->assertOk();
        $this->get(route('login', ['lang' => 'sw']))->assertOk();
    }

    public function test_english_selection_is_indicated_and_persists_through_navigation(): void
    {
        $english = $this->get(route('reference_ui.10_welcome_how_it_works.show', ['lang' => 'en']));
        $this->assertMatchesRegularExpression(
            '/class="welcome-language is-active"\s+href="[^"]*lang=en"\s+lang="en"[^>]*aria-current="true"/',
            $english->getContent(),
        );

        $english
            ->assertOk()
            ->assertViewHas('language', 'en')
            ->assertSessionHas('login_language', 'en')
            ->assertSeeInOrder(['Welcome to PangaEvents', 'Create an Event', 'Send Invitations', 'Scan Guests', 'Get Started', 'Already have an account?', 'Log in'])
            ->assertSee('href="'.e(route('register', ['lang' => 'en'])).'"', false)
            ->assertSee('href="'.e(route('login', ['lang' => 'en'])).'"', false)
            ->assertSee('class="welcome-language is-active"', false)
            ->assertSee('aria-current="true"', false);

        // The preference survives a later visit without ?lang= and reaches registration.
        $this->get(route('reference_ui.10_welcome_how_it_works.show'))
            ->assertViewHas('language', 'en')
            ->assertSee('Get Started');
        $this->get(route('register'))->assertOk()->assertViewHas('language', 'en');

        // SW switches back.
        $this->get(route('reference_ui.10_welcome_how_it_works.show', ['lang' => 'sw']))
            ->assertViewHas('language', 'sw')
            ->assertSessionHas('login_language', 'sw')
            ->assertSee('Karibu PangaEvents');
    }

    public function test_unsupported_language_falls_back_to_swahili(): void
    {
        $this->get(route('reference_ui.10_welcome_how_it_works.show', ['lang' => 'fr']))
            ->assertOk()
            ->assertViewHas('language', 'sw')
            ->assertSessionMissing('login_language');
    }

    public function test_welcome_records_the_existing_splash_cookie_safely(): void
    {
        $response = $this->get(route('reference_ui.10_welcome_how_it_works.show'))
            ->assertCookie(SplashScreenController::COMPLETION_COOKIE, '1');

        $cookie = $response->getCookie(SplashScreenController::COMPLETION_COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());

        // Already-marked visitors are not re-issued the cookie.
        $this->withCookie(SplashScreenController::COMPLETION_COOKIE, '1')
            ->get(route('reference_ui.10_welcome_how_it_works.show'))
            ->assertOk()
            ->assertCookieMissing(SplashScreenController::COMPLETION_COOKIE);
    }

    public function test_returning_visitor_entry_goes_to_login_but_welcome_stays_reachable(): void
    {
        $this->withCookie(SplashScreenController::COMPLETION_COOKIE, '1')
            ->get(route('reference_ui.01_splash_screen.show'))
            ->assertRedirect(route('login'));

        $this->withCookie(SplashScreenController::COMPLETION_COOKIE, '1')
            ->get(route('reference_ui.10_welcome_how_it_works.show'))
            ->assertOk()
            ->assertViewIs('reference_ui.10_welcome_how_it_works');

        // Login renders (no loop back through splash or welcome).
        $this->withCookie(SplashScreenController::COMPLETION_COOKIE, '1')
            ->get(route('login'))
            ->assertOk();
    }

    #[DataProvider('authorizedRoleDestinations')]
    public function test_authenticated_users_reach_their_authorized_destination(string $role, string $destinationRoute): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)
            ->get(route('reference_ui.10_welcome_how_it_works.show'))
            ->assertRedirect(route('login'));

        $this->get(route('login'))->assertRedirect(route($destinationRoute));
        $this->get(route($destinationRoute))->assertOk();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authorizedRoleDestinations(): array
    {
        return [
            'customer' => [User::ROLE_CUSTOMER, 'customer.home'],
            'administrator' => [User::ROLE_ADMIN, 'admin.dashboard'],
            'scanner' => [User::ROLE_SCANNER, 'scanner.dashboard'],
        ];
    }

    public function test_direct_authentication_routes_remain_accessible(): void
    {
        foreach (['login', 'register', 'admin.login', 'password.request'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }
}
