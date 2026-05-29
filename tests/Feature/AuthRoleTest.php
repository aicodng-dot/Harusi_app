<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_redirects_to_admin_dashboard(): void
    {
        $this->makeUser(User::ROLE_ADMIN, 'admin@example.com');

        $this->post(route('login.attempt'), [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_scanner_login_redirects_to_scanner_dashboard(): void
    {
        $this->makeUser(User::ROLE_SCANNER, 'scanner@example.com', 'Main Gate');

        $this->post(route('login.attempt'), [
            'email' => 'scanner@example.com',
            'password' => 'password',
        ])->assertRedirect(route('scanner.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_admin_and_scanner_routes_are_protected_by_authentication(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->get(route('scanner.dashboard'))->assertRedirect(route('login'));
    }

    public function test_scanner_user_cannot_access_admin_pages(): void
    {
        $scanner = $this->makeUser(User::ROLE_SCANNER, 'scanner@example.com', 'Main Gate');

        $this->actingAs($scanner)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_admin_user_cannot_access_scanner_pages(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, 'admin@example.com');

        $this->actingAs($admin)
            ->get(route('scanner.dashboard'))
            ->assertForbidden();
    }

    public function test_logout_clears_authenticated_session(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, 'admin@example.com');

        $this->actingAs($admin)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();

        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    private function makeUser(string $role, string $email, ?string $gateName = null): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'gate_name' => $gateName,
        ]);
    }
}
