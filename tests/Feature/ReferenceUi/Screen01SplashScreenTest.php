<?php

namespace Tests\Feature\ReferenceUi;

use App\Http\Controllers\SplashScreenController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Screen01SplashScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_unauthenticated_visit_displays_the_swahili_splash(): void
    {
        $this->get(route('reference_ui.01_splash_screen.show'))
            ->assertOk()
            ->assertViewIs('reference_ui.01_splash_screen')
            ->assertViewHas('language', 'sw')
            ->assertSee('Simamia Tukio Lako')
            ->assertSee('Gusa popote kuendelea')
            ->assertSee(route('reference_ui.10_welcome_how_it_works.show', ['lang' => 'sw']), false)
            ->assertCookieMissing(SplashScreenController::COMPLETION_COOKIE);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_supported_language_selection_persists_for_the_splash(): void
    {
        $this->get(route('reference_ui.01_splash_screen.show', ['lang' => 'en']))
            ->assertOk()
            ->assertViewHas('language', 'en')
            ->assertSee('Manage Your Event')
            ->assertSessionHas('login_language', 'en');

        $this->get(route('reference_ui.01_splash_screen.show'))
            ->assertOk()
            ->assertViewHas('language', 'en')
            ->assertSee('Tap anywhere to continue');
    }

    public function test_completion_sets_a_safe_cookie_and_hands_off_to_welcome(): void
    {
        $response = $this->get(route('reference_ui.10_welcome_how_it_works.show', ['lang' => 'en']))
            ->assertOk()
            ->assertViewIs('reference_ui.10_welcome_how_it_works')
            ->assertSessionHas('login_language', 'en')
            ->assertCookie(SplashScreenController::COMPLETION_COOKIE, '1');

        $cookie = $response->getCookie(SplashScreenController::COMPLETION_COOKIE);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertDatabaseCount('users', 0);
    }

    public function test_completed_guest_bypasses_the_splash_without_a_delay(): void
    {
        $this->withCookie(SplashScreenController::COMPLETION_COOKIE, '1')
            ->get(route('reference_ui.01_splash_screen.show'))
            ->assertRedirect(route('login'));
    }

    #[DataProvider('authorizedRoleDestinations')]
    public function test_authenticated_users_follow_the_existing_authorized_redirect(
        string $role,
        string $destinationRoute,
    ): void {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)
            ->get(route('reference_ui.01_splash_screen.show'))
            ->assertRedirect(route('login'));

        $this->get(route('login'))
            ->assertRedirect(route($destinationRoute));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authorizedRoleDestinations(): array
    {
        return [
            'administrator' => [User::ROLE_ADMIN, 'admin.dashboard'],
            'scanner' => [User::ROLE_SCANNER, 'scanner.dashboard'],
            'customer' => [User::ROLE_CUSTOMER, 'customer.home'],
        ];
    }
}
