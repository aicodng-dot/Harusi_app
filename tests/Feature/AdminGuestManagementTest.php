<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminGuestManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_single_double_and_special_passes(): void
    {
        Storage::fake('public');

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.guests.store'), [
                'name' => 'Single Guest',
                'phone_number' => '+255 700 000 001',
                'pass_type' => Guest::PASS_SINGLE,
                'allowed_entries' => 7,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.guests.store'), [
                'name' => 'Double Guest',
                'phone_number' => '+255 700 000 002',
                'pass_type' => Guest::PASS_DOUBLE,
                'allowed_entries' => 9,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.guests.store'), [
                'name' => 'Special Guest',
                'phone_number' => '+255 700 000 003',
                'pass_type' => Guest::PASS_SPECIAL,
                'allowed_entries' => 6,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('guests', [
            'name' => 'Single Guest',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);
        $this->assertDatabaseHas('guests', [
            'name' => 'Double Guest',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);
        $this->assertDatabaseHas('guests', [
            'name' => 'Special Guest',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 6,
        ]);
        $this->assertDatabaseCount('qr_codes', 3);
    }

    public function test_admin_cannot_create_more_than_ten_entries(): void
    {
        Storage::fake('public');

        $this->actingAs($this->adminUser())
            ->from(route('admin.guests.create'))
            ->post(route('admin.guests.store'), [
                'name' => 'Too Large',
                'phone_number' => '+255 700 000 004',
                'pass_type' => Guest::PASS_SPECIAL,
                'allowed_entries' => 11,
            ])
            ->assertRedirect(route('admin.guests.create'))
            ->assertSessionHasErrors('allowed_entries');

        $this->assertDatabaseMissing('guests', ['name' => 'Too Large']);
    }

    public function test_admin_can_edit_and_delete_guest_passes(): void
    {
        $admin = $this->adminUser();
        $guest = Guest::query()->create([
            'name' => 'Editable Guest',
            'phone_number' => '+255 700 000 005',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 5,
            'used_entries' => 3,
        ]);

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => Guest::makeSecureToken(),
            'generated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.guests.edit', $guest))
            ->put(route('admin.guests.update', $guest), [
                'name' => 'Editable Guest',
                'phone_number' => '+255 700 000 005',
                'pass_type' => Guest::PASS_DOUBLE,
                'allowed_entries' => 2,
            ])
            ->assertRedirect(route('admin.guests.edit', $guest))
            ->assertSessionHasErrors('allowed_entries');

        $this->actingAs($admin)
            ->put(route('admin.guests.update', $guest), [
                'name' => 'Edited Guest',
                'phone_number' => '+255 700 000 555',
                'pass_type' => Guest::PASS_SPECIAL,
                'allowed_entries' => 4,
            ])
            ->assertRedirect(route('admin.guests.show', $guest));

        $this->assertDatabaseHas('guests', [
            'id' => $guest->id,
            'name' => 'Edited Guest',
            'phone_number' => '+255 700 000 555',
            'allowed_entries' => 4,
            'used_entries' => 3,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.guests.destroy', $guest))
            ->assertRedirect(route('admin.guests.index'));

        $this->assertDatabaseMissing('guests', ['id' => $guest->id]);
    }

    public function test_guest_list_can_search_and_filter(): void
    {
        $admin = $this->adminUser();

        Guest::query()->create([
            'name' => 'Asha Searchable',
            'phone_number' => '+255 700 000 006',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);

        Guest::query()->create([
            'name' => 'Baraka Hidden',
            'phone_number' => '+255 700 000 007',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 6,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.guests.index', [
                'search' => 'Asha',
                'pass_type' => Guest::PASS_SINGLE,
                'status' => 'unused',
            ]))
            ->assertOk()
            ->assertSee('Asha Searchable')
            ->assertDontSee('Baraka Hidden');
    }

    private function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Admin User',
            'email' => uniqid('admin', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
        ]);
    }
}
