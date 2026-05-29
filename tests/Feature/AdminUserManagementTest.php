<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_scanner_user_and_scanner_is_restricted_to_scanner_area(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'admin-users@example.com');

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Main Gate Scanner',
                'email' => 'main-gate@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => User::ROLE_SCANNER,
                'gate_name' => 'Main Gate',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'name' => 'Main Gate Scanner',
            'email' => 'main-gate@example.com',
            'role' => User::ROLE_SCANNER,
            'gate_name' => 'Main Gate',
        ]);

        $this->post(route('logout'));

        $this->post(route('login.attempt'), [
            'email' => 'main-gate@example.com',
            'password' => 'secret123',
        ])->assertRedirect(route('scanner.dashboard'));

        $this->get(route('scanner.dashboard'))->assertOk();
        $this->get(route('admin.guests.index'))->assertForbidden();
        $this->get(route('admin.reports.index'))->assertForbidden();
        $this->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_admin_can_edit_scanner_gate_and_reset_password(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'admin-editor@example.com');
        $scanner = $this->user(User::ROLE_SCANNER, 'scanner-editor@example.com', 'Old Gate');

        $this->actingAs($admin)
            ->put(route('admin.users.update', $scanner), [
                'name' => 'Updated Scanner',
                'email' => 'updated-scanner@example.com',
                'password' => 'newsecret123',
                'password_confirmation' => 'newsecret123',
                'role' => User::ROLE_SCANNER,
                'gate_name' => 'VIP Gate',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        $scanner->refresh();

        $this->assertSame('Updated Scanner', $scanner->name);
        $this->assertSame('updated-scanner@example.com', $scanner->email);
        $this->assertSame('VIP Gate', $scanner->gate_name);
        $this->assertTrue(Hash::check('newsecret123', $scanner->password));

        $this->post(route('logout'));

        $this->post(route('login.attempt'), [
            'email' => 'updated-scanner@example.com',
            'password' => 'newsecret123',
        ])->assertRedirect(route('scanner.dashboard'));
    }

    public function test_admin_can_delete_scanner_user(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'admin-delete@example.com');
        $scanner = $this->user(User::ROLE_SCANNER, 'scanner-delete@example.com', 'Side Gate');

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $scanner))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', [
            'email' => 'scanner-delete@example.com',
        ]);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'admin-self-delete@example.com');

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $admin))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', [
            'email' => 'admin-self-delete@example.com',
        ]);
    }

    public function test_scanner_user_cannot_open_admin_user_management(): void
    {
        $scanner = $this->user(User::ROLE_SCANNER, 'scanner-denied@example.com', 'Main Gate');

        $this->actingAs($scanner)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_scanner_users_require_a_gate_name(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'admin-validation@example.com');

        $this->actingAs($admin)
            ->from(route('admin.users.create'))
            ->post(route('admin.users.store'), [
                'name' => 'No Gate Scanner',
                'email' => 'no-gate@example.com',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'role' => User::ROLE_SCANNER,
                'gate_name' => '',
            ])
            ->assertRedirect(route('admin.users.create'))
            ->assertSessionHasErrors('gate_name');

        $this->assertDatabaseMissing('users', [
            'email' => 'no-gate@example.com',
        ]);
    }

    public function test_admin_users_cannot_access_scanner_pages_by_default(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, 'admin-scanner-denied@example.com');

        $this->actingAs($admin)
            ->get(route('scanner.dashboard'))
            ->assertForbidden();
    }

    private function user(string $role, string $email, ?string $gateName = null): User
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
