<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesGuestQrDisk;
use Tests\TestCase;

class AdminGuestManagementTest extends TestCase
{
    use FakesGuestQrDisk;
    use RefreshDatabase;

    public function test_admin_can_create_single_double_and_special_passes(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

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
            'phone_number' => '+255700000001',
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

        $singleGuest = Guest::query()->where('name', 'Single Guest')->firstOrFail();
        $this->assertMatchesRegularExpression('/^\d{4}$/', $singleGuest->manual_code);
        $this->assertNotSame($singleGuest->manual_code, $singleGuest->qrCode->qr_token);
    }

    #[DataProvider('invalidSpecialAllowances')]
    public function test_admin_cannot_create_special_pass_outside_allowed_range(int $capacity): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $this->actingAs($this->adminUser())
            ->from(route('admin.guests.create'))
            ->post(route('admin.guests.store'), [
                'name' => 'Too Large',
                'phone_number' => '+255 700 000 004',
                'pass_type' => Guest::PASS_SPECIAL,
                'allowed_entries' => $capacity,
            ])
            ->assertRedirect(route('admin.guests.create'))
            ->assertSessionHasErrors('allowed_entries');

        $this->assertDatabaseMissing('guests', ['name' => 'Too Large']);
    }

    #[DataProvider('largeSpecialAllowances')]
    public function test_admin_can_create_special_pass_above_ten_up_to_one_hundred(int $capacity): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $this->actingAs($this->adminUser())
            ->post(route('admin.guests.store'), [
                'name' => 'Large Family',
                'phone_number' => '+255700000006',
                'pass_type' => Guest::PASS_SPECIAL,
                'allowed_entries' => $capacity,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('guests', [
            'name' => 'Large Family',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => $capacity,
            'used_entries' => 0,
        ]);
        $this->assertDatabaseCount('qr_codes', 1);
    }

    #[DataProvider('largeSpecialAllowances')]
    public function test_admin_can_increase_special_pass_allowance_without_changing_usage(int $capacity): void
    {
        $guest = Guest::query()->create([
            'name' => 'Growing Family',
            'phone_number' => '+255700000007',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 5,
            'used_entries' => 3,
        ]);

        $this->actingAs($this->adminUser())
            ->put(route('admin.guests.update', $guest), [
                'name' => $guest->name,
                'phone_number' => $guest->phone_number,
                'pass_type' => Guest::PASS_SPECIAL,
                'allowed_entries' => $capacity,
            ])
            ->assertRedirect(route('admin.guests.show', $guest))
            ->assertSessionHasNoErrors();

        $this->assertSame($capacity, $guest->fresh()->allowed_entries);
        $this->assertSame(3, $guest->fresh()->used_entries);
        $this->assertSame($capacity - 3, $guest->fresh()->remainingEntries());
    }

    #[DataProvider('invalidSpecialAllowances')]
    public function test_admin_cannot_edit_special_pass_outside_allowed_range(int $capacity): void
    {
        $guest = Guest::query()->create([
            'name' => 'Unchanged Family',
            'phone_number' => '+255700000008',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 5,
        ]);

        $this->actingAs($this->adminUser())
            ->from(route('admin.guests.edit', $guest))
            ->put(route('admin.guests.update', $guest), [
                'name' => $guest->name,
                'phone_number' => $guest->phone_number,
                'pass_type' => Guest::PASS_SPECIAL,
                'allowed_entries' => $capacity,
            ])
            ->assertRedirect(route('admin.guests.edit', $guest))
            ->assertSessionHasErrors('allowed_entries');

        $this->assertSame(5, $guest->fresh()->allowed_entries);
    }

    public function test_admin_cannot_reduce_large_pass_below_entries_already_used(): void
    {
        $guest = Guest::query()->create([
            'name' => 'Used Large Family',
            'phone_number' => '+255700000009',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 100,
            'used_entries' => 90,
        ]);

        $this->actingAs($this->adminUser())
            ->from(route('admin.guests.edit', $guest))
            ->put(route('admin.guests.update', $guest), [
                'name' => $guest->name,
                'phone_number' => $guest->phone_number,
                'pass_type' => Guest::PASS_SPECIAL,
                'allowed_entries' => 89,
            ])
            ->assertRedirect(route('admin.guests.edit', $guest))
            ->assertSessionHasErrors('allowed_entries');

        $this->assertSame(100, $guest->fresh()->allowed_entries);
        $this->assertSame(90, $guest->fresh()->used_entries);
    }

    public static function largeSpecialAllowances(): array
    {
        return ['eleven entries' => [11], 'one hundred entries' => [100]];
    }

    public static function invalidSpecialAllowances(): array
    {
        return ['below special minimum' => [2], 'above special maximum' => [101]];
    }

    public function test_admin_cannot_create_guest_with_invalid_phone_number(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $this->actingAs($this->adminUser())
            ->from(route('admin.guests.create'))
            ->post(route('admin.guests.store'), [
                'name' => 'Invalid Phone Guest',
                'phone_number' => '25578800001',
                'pass_type' => Guest::PASS_SINGLE,
                'allowed_entries' => 1,
            ])
            ->assertRedirect(route('admin.guests.create'))
            ->assertSessionHasErrors('phone_number');

        $this->assertDatabaseMissing('guests', ['name' => 'Invalid Phone Guest']);
        $this->assertDatabaseCount('qr_codes', 0);
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
            ->from(route('admin.guests.edit', $guest))
            ->put(route('admin.guests.update', $guest), [
                'name' => 'Editable Guest',
                'phone_number' => '25578800001',
                'pass_type' => Guest::PASS_SPECIAL,
                'allowed_entries' => 5,
            ])
            ->assertRedirect(route('admin.guests.edit', $guest))
            ->assertSessionHasErrors('phone_number');

        $this->assertSame('+255700000005', $guest->fresh()->phone_number);

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
            'phone_number' => '+255700000555',
            'allowed_entries' => 4,
            'used_entries' => 3,
        ]);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $guest->fresh()->manual_code);

        $this->actingAs($admin)
            ->get(route('admin.guests.edit', $guest))
            ->assertOk()
            ->assertSee('Manual code')
            ->assertSee('readonly', false);

        $this->actingAs($admin)
            ->delete(route('admin.guests.destroy', $guest))
            ->assertRedirect(route('admin.guests.index'));

        $this->assertDatabaseMissing('guests', ['id' => $guest->id]);
    }

    public function test_admin_can_cancel_guest_pass(): void
    {
        $guest = Guest::query()->create([
            'name' => 'Cancelable Guest',
            'phone_number' => '+255 700 000 556',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.guests.cancel', $guest))
            ->assertRedirect();

        $this->assertDatabaseHas('guests', [
            'id' => $guest->id,
            'status' => Guest::STATUS_CANCELLED,
        ]);
    }

    public function test_admin_can_delete_multiple_guest_passes_from_the_selected_event(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $event = Event::defaultEvent();
        $otherEvent = Event::query()->create(['event_name' => 'Other Wedding', 'status' => Event::STATUS_ACTIVE]);
        $admin = $this->adminUser();
        $first = $this->guestWithQrImage('Delete First', '+255700000101', $event, 'qr-codes/delete-first.png');
        $second = $this->guestWithQrImage('Delete Second', '+255700000102', $event, 'qr-codes/delete-second.png');
        $otherEventGuest = $this->guestWithQrImage('Keep Other Event', '+255700000103', $otherEvent, 'qr-codes/keep-other.png');

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->from(route('admin.guests.index'))
            ->delete(route('admin.guests.bulk-destroy'), [
                'guest_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect(route('admin.guests.index'))
            ->assertSessionHas('success', '2 guest passes deleted.');

        $this->assertDatabaseMissing('guests', ['id' => $first->id]);
        $this->assertDatabaseMissing('guests', ['id' => $second->id]);
        $this->assertDatabaseHas('guests', ['id' => $otherEventGuest->id]);
        Storage::disk('guest_qr')->assertMissing('qr-codes/delete-first.png');
        Storage::disk('guest_qr')->assertMissing('qr-codes/delete-second.png');
        Storage::disk('guest_qr')->assertExists('qr-codes/keep-other.png');
    }

    public function test_bulk_guest_delete_cannot_cross_event_boundaries(): void
    {
        $event = Event::defaultEvent();
        $otherEvent = Event::query()->create(['event_name' => 'Other Wedding', 'status' => Event::STATUS_ACTIVE]);
        $selectedGuest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => 'Selected Event Guest',
            'phone_number' => '+255700000104',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);
        $otherEventGuest = Guest::query()->create([
            'event_id' => $otherEvent->id,
            'name' => 'Other Event Guest',
            'phone_number' => '+255700000105',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);

        $this->actingAs($this->adminUser())
            ->withSession(['selected_event_id' => $event->id])
            ->delete(route('admin.guests.bulk-destroy'), [
                'guest_ids' => [$selectedGuest->id, $otherEventGuest->id],
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('guests', ['id' => $selectedGuest->id]);
        $this->assertDatabaseHas('guests', ['id' => $otherEventGuest->id]);
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
            ->assertSee(Guest::query()->where('name', 'Asha Searchable')->value('manual_code'))
            ->assertDontSee('Baraka Hidden');
    }

    public function test_guest_list_displays_fifty_guests_per_page_and_sorts_names(): void
    {
        $admin = $this->adminUser();

        foreach (range(1, 51) as $number) {
            Guest::query()->create([
                'name' => sprintf('Guest %03d', $number),
                'phone_number' => '+2557'.str_pad((string) $number, 8, '0', STR_PAD_LEFT),
                'pass_type' => Guest::PASS_SINGLE,
                'allowed_entries' => 1,
            ]);
        }

        $ascending = $this->actingAs($admin)
            ->get(route('admin.guests.index', ['name_sort' => 'asc']));

        $ascending
            ->assertOk()
            ->assertSeeInOrder(['Guest 001', 'Guest 050'])
            ->assertDontSee('Guest 051')
            ->assertSee('Sort names Z to A');

        $descending = $this->actingAs($admin)
            ->get(route('admin.guests.index', ['name_sort' => 'desc']));

        $descending
            ->assertOk()
            ->assertSeeInOrder(['Guest 051', 'Guest 002'])
            ->assertDontSee('Guest 001')
            ->assertSee('Sort names A to Z');
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

    private function guestWithQrImage(string $name, string $phoneNumber, Event $event, string $imagePath): Guest
    {
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => $name,
            'phone_number' => $phoneNumber,
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => Guest::makeSecureToken(),
            'qr_image_path' => $imagePath,
            'generated_at' => now(),
        ]);
        Storage::disk('guest_qr')->put($imagePath, 'test QR image');

        return $guest;
    }
}
