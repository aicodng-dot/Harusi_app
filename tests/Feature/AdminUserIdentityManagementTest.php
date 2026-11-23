<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserIdentityManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_canonical_phone_language_and_active_state(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->fields() + [
                'phone_number' => '0712345678', 'preferred_language' => 'en', 'is_active' => '0',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'identity-managed@example.com', 'phone_number' => '+255712345678',
            'preferred_language' => 'en', 'is_active' => false,
        ]);
    }

    public function test_admin_rejects_duplicate_phone_after_canonicalization(): void
    {
        $admin = User::factory()->create(['phone_number' => '+255712345678']);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->fields() + ['phone_number' => '0712345678'])
            ->assertSessionHasErrors('phone_number');

        $this->assertDatabaseMissing('users', ['email' => 'identity-managed@example.com']);
    }

    public function test_admin_rejects_invalid_phone_and_language(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->fields() + [
                'phone_number' => '071234567', 'preferred_language' => 'invalid',
            ])
            ->assertSessionHasErrors(['phone_number', 'preferred_language']);

        $this->assertDatabaseMissing('users', ['email' => 'identity-managed@example.com']);
    }

    public function test_legacy_update_omitting_new_fields_preserves_existing_values(): void
    {
        $admin = User::factory()->create();
        $managed = User::factory()->create([
            'email' => 'identity-managed@example.com', 'phone_number' => '0712345678',
            'preferred_language' => 'en', 'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $managed), $this->fields())
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $managed->refresh();
        $this->assertSame('+255712345678', $managed->phone_number);
        $this->assertSame('en', $managed->preferred_language);
        $this->assertFalse($managed->is_active);
    }

    public function test_explicit_blank_phone_clears_optional_phone(): void
    {
        $admin = User::factory()->create();
        $managed = User::factory()->create(['email' => 'identity-managed@example.com', 'phone_number' => '0712345678']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $managed), $this->fields() + ['phone_number' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($managed->fresh()->phone_number);
    }

    public function test_admin_can_update_same_phone_in_another_format(): void
    {
        $admin = User::factory()->create();
        $managed = User::factory()->create(['email' => 'identity-managed@example.com', 'phone_number' => '0712345678']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $managed), $this->fields() + ['phone_number' => '255712345678'])
            ->assertSessionHasNoErrors();

        $this->assertSame('+255712345678', $managed->fresh()->phone_number);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $admin = User::factory()->create(['email' => 'identity-managed@example.com']);
        $fields = array_merge($this->fields(), ['role' => User::ROLE_ADMIN, 'is_active' => '0']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), $fields)
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_form_exposes_optional_phone_supported_languages_and_account_state(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('name="phone_number"', false)
            ->assertSee('name="preferred_language"', false)
            ->assertSee('Kiswahili')
            ->assertSee('English')
            ->assertSee('name="is_active"', false);
    }

    public function test_user_list_labels_an_inactive_account_correctly(): void
    {
        User::factory()->create(['name' => 'Disabled Scanner', 'role' => User::ROLE_SCANNER, 'is_active' => false]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Disabled Scanner')
            ->assertSee('Inactive');

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $row = (new \DOMXPath($document))->query('//tr[td[contains(., "Disabled Scanner")]]')->item(0);
        $this->assertNotNull($row);
        $this->assertStringContainsString('Inactive', $row->textContent);
        $this->assertStringNotContainsString('Active', $row->textContent);
    }

    private function fields(): array
    {
        return [
            'name' => 'Managed Identity', 'email' => 'identity-managed@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => User::ROLE_SCANNER, 'gate_name' => 'Main Gate',
        ];
    }
}
