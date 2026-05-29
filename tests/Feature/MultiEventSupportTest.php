<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultiEventSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_switch_events_and_guest_lists_are_isolated(): void
    {
        Storage::fake('public');

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.events.store'), [
                'event_name' => 'John & Mary Wedding',
                'bride_name' => 'Mary',
                'groom_name' => 'John',
                'venue_name' => 'Garden Hall',
                'event_date' => '2026-06-20',
                'status' => Event::STATUS_ACTIVE,
            ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('selected_event_id');

        $weddingA = Event::query()->where('event_name', 'John & Mary Wedding')->firstOrFail();

        $weddingB = Event::query()->create([
            'event_name' => 'Peter & Grace Wedding',
            'bride_name' => 'Grace',
            'groom_name' => 'Peter',
            'status' => Event::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $weddingA->id])
            ->post(route('admin.guests.store'), [
                'name' => 'Wedding A Guest',
                'phone_number' => '0711111111',
                'pass_type' => Guest::PASS_SINGLE,
                'allowed_entries' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('guests', [
            'event_id' => $weddingA->id,
            'name' => 'Wedding A Guest',
        ]);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $weddingB->id])
            ->get(route('admin.guests.index'))
            ->assertOk()
            ->assertDontSee('Wedding A Guest');

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $weddingB->id])
            ->post(route('admin.guests.store'), [
                'name' => 'Wedding B Guest',
                'phone_number' => '0722222222',
                'pass_type' => Guest::PASS_DOUBLE,
                'allowed_entries' => 2,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $weddingA->id])
            ->get(route('admin.guests.index'))
            ->assertOk()
            ->assertSee('Wedding A Guest')
            ->assertDontSee('Wedding B Guest');
    }

    public function test_admin_dashboard_is_scoped_to_selected_event(): void
    {
        $admin = $this->adminUser();
        $weddingA = $this->event('Wedding A');
        $weddingB = $this->event('Wedding B');

        $guestA = $this->guestWithQr($weddingA, 'A Guest', Guest::PASS_SPECIAL, 5, 2);
        $guestB = $this->guestWithQr($weddingB, 'B Guest', Guest::PASS_DOUBLE, 2, 0);

        Checkin::query()->create([
            'event_id' => $weddingA->id,
            'guest_id' => $guestA->id,
            'qr_code_id' => $guestA->qrCode->id,
            'scan_result' => Checkin::RESULT_ADMITTED,
            'entries_added' => 2,
            'used_entries_after_scan' => 2,
            'remaining_entries_after_scan' => 3,
            'checked_in_at' => now(),
        ]);

        Checkin::query()->create([
            'event_id' => $weddingB->id,
            'guest_id' => $guestB->id,
            'qr_code_id' => $guestB->qrCode->id,
            'scan_result' => Checkin::RESULT_ADMITTED,
            'entries_added' => 1,
            'used_entries_after_scan' => 1,
            'remaining_entries_after_scan' => 1,
            'checked_in_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['selected_event_id' => $weddingA->id])
            ->get(route('admin.dashboard'))
            ->assertOk();

        $stats = $response->viewData('stats');

        $this->assertSame(1, $stats['total_guests']);
        $this->assertSame(5, $stats['total_allowed_entries']);
        $this->assertSame(2, $stats['admitted_entries']);
        $this->assertSame(3, $stats['remaining_entries']);
    }

    public function test_scanner_cannot_validate_or_admit_qr_from_another_event(): void
    {
        $weddingA = $this->event('Wedding A');
        $weddingB = $this->event('Wedding B');
        $scannerA = $this->scannerUser($weddingA, 'Main Gate');
        $guestB = $this->guestWithQr($weddingB, 'Wrong Event Guest', Guest::PASS_SINGLE, 1);
        $token = $guestB->qrCode->qr_token;

        $this->actingAs($scannerA)
            ->postJson(route('scanner.validate'), ['qr_token' => $token])
            ->assertOk()
            ->assertExactJson([
                'status' => Checkin::RESULT_WRONG_EVENT,
                'message' => 'This QR code belongs to a different event.',
            ]);

        $this->assertDatabaseHas('checkins', [
            'event_id' => $weddingA->id,
            'guest_id' => null,
            'user_id' => $scannerA->id,
            'scan_result' => Checkin::RESULT_WRONG_EVENT,
        ]);

        $this->actingAs($scannerA)
            ->postJson(route('scanner.admit'), [
                'guest_id' => $guestB->id,
                'qr_token' => $token,
                'entries_to_admit' => 1,
                'event_id' => $weddingB->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', Checkin::RESULT_WRONG_EVENT);

        $this->assertSame(0, $guestB->fresh()->used_entries);
    }

    public function test_scanner_assigned_to_event_can_admit_that_event_and_checkin_saves_event_id(): void
    {
        $wedding = $this->event('Wedding C');
        $scanner = $this->scannerUser($wedding, 'VIP Gate');
        $guest = $this->guestWithQr($wedding, 'Correct Event Guest', Guest::PASS_DOUBLE, 2);

        $this->actingAs($scanner)
            ->postJson(route('scanner.admit'), [
                'guest_id' => $guest->id,
                'qr_token' => $guest->qrCode->qr_token,
                'entries_to_admit' => 2,
                'event_id' => Event::defaultEvent()->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', Checkin::RESULT_ADMITTED);

        $this->assertDatabaseHas('checkins', [
            'event_id' => $wedding->id,
            'guest_id' => $guest->id,
            'user_id' => $scanner->id,
            'scan_result' => Checkin::RESULT_ADMITTED,
            'entries_added' => 2,
        ]);
    }

    public function test_admin_cannot_edit_guest_from_another_selected_event(): void
    {
        $admin = $this->adminUser();
        $weddingA = $this->event('Wedding A');
        $weddingB = $this->event('Wedding B');
        $guestA = $this->guestWithQr($weddingA, 'Protected Guest', Guest::PASS_SINGLE, 1);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $weddingB->id])
            ->get(route('admin.guests.edit', $guestA))
            ->assertNotFound();
    }

    private function event(string $name): Event
    {
        return Event::query()->create([
            'event_name' => $name,
            'status' => Event::STATUS_ACTIVE,
        ]);
    }

    private function guestWithQr(
        Event $event,
        string $name,
        string $passType,
        int $allowedEntries,
        int $usedEntries = 0,
    ): Guest {
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => $name,
            'phone_number' => '0712345678',
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
            'used_entries' => $usedEntries,
        ]);

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => Guest::makeSecureToken(),
            'is_active' => true,
            'generated_at' => now(),
        ]);

        return $guest->fresh('qrCode');
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

    private function scannerUser(Event $event, string $gateName): User
    {
        return User::query()->create([
            'name' => 'Scanner User',
            'email' => uniqid('scanner', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => $gateName,
        ]);
    }
}
