<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScannerManualSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanner_cannot_search_manually_by_guest_name(): void
    {
        $scanner = $this->scannerUser();
        $match = $this->guest('Asha Manual Match', '0714000001', Guest::PASS_DOUBLE, 2);
        $hidden = $this->guest('Baraka Hidden', '0714000002', Guest::PASS_SINGLE, 1);

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => 'Asha']))
            ->assertOk()
            ->assertSee('Invalid phone number format.')
            ->assertDontSee('Asha Manual Match')
            ->assertDontSee('Baraka Hidden');

        $this->assertTrue($match->exists);
        $this->assertTrue($hidden->exists);
    }

    public function test_manual_search_copy_does_not_offer_name_search(): void
    {
        $this->actingAs($this->scannerUser())
            ->get(route('scanner.manual-search'))
            ->assertOk()
            ->assertSee('Phone number or 4-digit code')
            ->assertSee('Enter phone number or 4-digit code')
            ->assertSee('Search by phone number or 4-digit guest code.')
            ->assertDontSee('Guest name or phone number')
            ->assertDontSee('guest name');
    }

    public function test_guest_name_is_displayed_only_after_a_valid_manual_search_result(): void
    {
        $scanner = $this->scannerUser();
        $this->guest('John Peter', '0788000001', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE, '5820');

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search'))
            ->assertOk()
            ->assertDontSee('John Peter');

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => '5820']))
            ->assertOk()
            ->assertSee('GUEST:')
            ->assertSee('John Peter');
    }

    public function test_scanner_can_search_manually_by_phone_number(): void
    {
        $scanner = $this->scannerUser();
        $this->guest('John Peter', '0788000001', Guest::PASS_SPECIAL, 6, 2, Guest::STATUS_ACTIVE, '5820');
        $this->guest('Other Phone Guest', '0714666456', Guest::PASS_SINGLE, 1);

        foreach (['+255788000001', '255788000001', '0788000001', '788000001'] as $input) {
            $this->actingAs($scanner)
                ->get(route('scanner.manual-search', ['q' => $input]))
                ->assertOk()
                ->assertSee('GUEST:')
                ->assertSee('John Peter')
                ->assertSee('PHONE:')
                ->assertSee('+255788000001')
                ->assertSee('CODE:')
                ->assertSee('5820')
                ->assertSee('PASS:')
                ->assertSee('Special / Family')
                ->assertSee('ALLOWED:')
                ->assertSee('6')
                ->assertSee('USED:')
                ->assertSee('2')
                ->assertSee('REMAINING:')
                ->assertSee('4')
                ->assertSee('STATUS:')
                ->assertSee('Partially Used')
                ->assertSee('Guest counter')
                ->assertSee('Confirm Admission')
                ->assertDontSee('Other Phone Guest');
        }
    }

    public function test_scanner_can_search_manually_by_four_digit_manual_code(): void
    {
        $scanner = $this->scannerUser();
        $this->guest('Manual Code Search Guest', '0714555123', Guest::PASS_SPECIAL, 5, 0, Guest::STATUS_ACTIVE, '0047');
        $this->guest('Other Code Guest', '0714666456', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE, '0048');

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => '0047']))
            ->assertOk()
            ->assertSee('Manual Code Search Guest')
            ->assertDontSee('Other Code Guest');
    }

    public function test_manual_phone_search_rejects_invalid_phone_format(): void
    {
        $scanner = $this->scannerUser();
        $this->guest('Invalid Search Hidden Guest', '0714555123', Guest::PASS_SPECIAL, 5);

        foreach ([
            '25578800001',
            '+25578800001',
            '078800001',
            '2550788000001',
            'abc0788000001',
            '+2557880000011',
        ] as $invalidPhoneNumber) {
            $this->actingAs($scanner)
                ->get(route('scanner.manual-search', ['q' => $invalidPhoneNumber]))
                ->assertOk()
                ->assertSee('Invalid phone number format.')
                ->assertDontSee('Invalid Search Hidden Guest');
        }
    }

    public function test_manual_search_scopes_to_scanner_event_and_ignores_request_event_id(): void
    {
        $weddingA = $this->event('Wedding A');
        $weddingB = $this->event('Wedding B');
        $scanner = $this->scannerUser($weddingA);
        $this->guest('Wedding A Guest', '0788000001', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE, '5820', $weddingA);
        $this->guest('Wedding B Guest', '0788000001', Guest::PASS_DOUBLE, 2, 0, Guest::STATUS_ACTIVE, '5820', $weddingB);

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => '5820', 'event_id' => $weddingB->id]))
            ->assertOk()
            ->assertSee('Wedding A Guest')
            ->assertDontSee('Wedding B Guest');

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => '0788000001', 'event_id' => $weddingB->id]))
            ->assertOk()
            ->assertSee('Wedding A Guest')
            ->assertDontSee('Wedding B Guest');
    }

    public function test_unassigned_scanner_manual_search_uses_selected_event_session_context(): void
    {
        $selectedEvent = $this->event('Selected Wedding');
        $defaultEvent = Event::defaultEvent();
        $scanner = $this->scannerUser();
        $scanner->forceFill(['event_id' => null])->save();

        $this->guest('Selected Event Guest', '0788000001', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE, '5820', $selectedEvent);
        $this->guest('Default Event Guest', '0788000001', Guest::PASS_DOUBLE, 2, 0, Guest::STATUS_ACTIVE, '5820', $defaultEvent);

        $this->actingAs($scanner)
            ->withSession(['selected_event_id' => $selectedEvent->id])
            ->get(route('scanner.manual-search', ['q' => '5820']))
            ->assertOk()
            ->assertSee('Selected Event Guest')
            ->assertDontSee('Default Event Guest');
    }

    public function test_manual_phone_search_lists_multiple_guests_sharing_one_phone_number(): void
    {
        $scanner = $this->scannerUser();
        $this->guest('Anna Maro', '0712999000', Guest::PASS_SPECIAL, 4, 1, Guest::STATUS_ACTIVE, '2804');
        $this->guest('Neema Maro', '0712999000', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE, '5193');

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => '0712999000']))
            ->assertOk()
            ->assertSee('Multiple guests use this phone number. Select the correct guest.')
            ->assertSee('Anna Maro')
            ->assertSee('2804')
            ->assertSee('Neema Maro')
            ->assertSee('5193');
    }
    public function test_scanner_can_admit_guest_from_manual_search(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guest('Manual Admission Guest', '0714777000', Guest::PASS_SPECIAL, 5, 2);

        $this->actingAs($scanner)
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 2,
                'search' => '0714777000',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714777000']))
            ->assertSessionHas('success');

        $guest = $guest->fresh();
        $this->assertSame(4, $guest->used_entries);
        $this->assertSame(Guest::STATUS_PARTIALLY_USED, $guest->status);

        $this->assertDatabaseHas('checkins', [
            'event_id' => $guest->event_id,
            'guest_id' => $guest->id,
            'user_id' => $scanner->id,
            'gate_name' => 'Main Gate',
            'entries_added' => 2,
            'used_entries_after_scan' => 4,
            'remaining_entries_after_scan' => 1,
            'scan_result' => Checkin::RESULT_ADMITTED,
        ]);

        $this->assertDatabaseHas('admissions', [
            'event_id' => $guest->event_id,
            'guest_id' => $guest->id,
            'quantity' => 2,
            'admitted_by' => 'Manual Scanner',
            'device_label' => 'Manual search',
        ]);
    }

    public function test_manual_single_pass_admits_only_one_entry(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guest('Manual Single Guest', '0714777001', Guest::PASS_SINGLE, 1);

        $this->actingAs($scanner)
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 1,
                'search' => '0714777001',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714777001']))
            ->assertSessionHas('success');

        $guest = $guest->fresh();
        $this->assertSame(1, $guest->used_entries);
        $this->assertSame(Guest::STATUS_FULLY_USED, $guest->status);

        $this->actingAs($scanner)
            ->from(route('scanner.manual-search', ['q' => '0714777001']))
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 1,
                'search' => '0714777001',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714777001']))
            ->assertSessionHasErrors('entries_to_admit');

        $this->assertSame(1, $guest->fresh()->used_entries);
        $this->assertDatabaseCount('admissions', 1);
    }

    public function test_manual_double_pass_admits_up_to_two_entries(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guest('Manual Double Guest', '0714777002', Guest::PASS_DOUBLE, 2);

        $this->actingAs($scanner)
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 2,
                'search' => '0714777002',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714777002']))
            ->assertSessionHas('success');

        $guest = $guest->fresh();
        $this->assertSame(2, $guest->used_entries);
        $this->assertSame(Guest::STATUS_FULLY_USED, $guest->status);
        $this->assertDatabaseHas('checkins', [
            'guest_id' => $guest->id,
            'entries_added' => 2,
            'used_entries_after_scan' => 2,
            'remaining_entries_after_scan' => 0,
            'scan_result' => Checkin::RESULT_ADMITTED,
        ]);
    }

    public function test_manual_special_family_pass_admits_up_to_selected_allowed_entries(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guest('Manual Family Guest', '0714777003', Guest::PASS_SPECIAL, 100);

        $this->actingAs($scanner)
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 100,
                'search' => '0714777003',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714777003']))
            ->assertSessionHas('success');

        $guest = $guest->fresh();
        $this->assertSame(100, $guest->allowed_entries);
        $this->assertSame(100, $guest->used_entries);
        $this->assertSame(Guest::STATUS_FULLY_USED, $guest->status);
        $this->assertDatabaseHas('checkins', [
            'guest_id' => $guest->id,
            'entries_added' => 100,
            'used_entries_after_scan' => 100,
            'remaining_entries_after_scan' => 0,
            'scan_result' => Checkin::RESULT_ADMITTED,
        ]);
    }

    public function test_manual_large_special_pass_cannot_exceed_its_remaining_allowance(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guest('Manual Large Family', '0714777005', Guest::PASS_SPECIAL, 100, 89);

        $this->actingAs($scanner)
            ->from(route('scanner.manual-search', ['q' => '0714777005']))
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 12,
                'search' => '0714777005',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714777005']))
            ->assertSessionHasErrors('entries_to_admit');

        $this->assertSame(100, $guest->fresh()->allowed_entries);
        $this->assertSame(89, $guest->fresh()->used_entries);
        $this->assertDatabaseCount('admissions', 0);

        $this->actingAs($scanner)
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 11,
                'search' => '0714777005',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714777005']))
            ->assertSessionHas('success');

        $this->assertSame(100, $guest->fresh()->allowed_entries);
        $this->assertSame(100, $guest->fresh()->used_entries);
        $this->assertSame(Guest::STATUS_FULLY_USED, $guest->fresh()->status);
        $this->assertDatabaseHas('checkins', [
            'guest_id' => $guest->id,
            'entries_added' => 11,
            'used_entries_after_scan' => 100,
            'remaining_entries_after_scan' => 0,
            'scan_result' => Checkin::RESULT_ADMITTED,
        ]);
        $this->assertDatabaseCount('admissions', 1);
    }

    public function test_manual_admission_cannot_exceed_remaining_entries(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guest('Manual Limit Guest', '0714888000', Guest::PASS_DOUBLE, 2, 1);

        $this->actingAs($scanner)
            ->from(route('scanner.manual-search', ['q' => '0714888000']))
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 2,
                'search' => '0714888000',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714888000']))
            ->assertSessionHasErrors('entries_to_admit');

        $this->assertSame(1, $guest->fresh()->used_entries);
        $this->assertDatabaseCount('checkins', 0);
        $this->assertDatabaseCount('admissions', 0);
    }

    public function test_manual_admission_recalculates_remaining_after_stale_search_result(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guest('Manual Stale Guest', '0714888999', Guest::PASS_DOUBLE, 2);

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => '0714888999']))
            ->assertOk()
            ->assertSee('REMAINING:')
            ->assertSee('2');

        $guest->update([
            'used_entries' => 1,
            'status' => Guest::STATUS_PARTIALLY_USED,
        ]);

        $this->actingAs($scanner)
            ->from(route('scanner.manual-search', ['q' => '0714888999']))
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 2,
                'search' => '0714888999',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714888999']))
            ->assertSessionHasErrors('entries_to_admit');

        $this->assertSame(1, $guest->fresh()->used_entries);
        $this->assertDatabaseCount('admissions', 0);
    }

    public function test_manual_admission_cannot_admit_fully_used_pass(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guest('Manual Fully Used Guest', '0714777004', Guest::PASS_SINGLE, 1, 1);

        $this->actingAs($scanner)
            ->from(route('scanner.manual-search', ['q' => '0714777004']))
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 1,
                'search' => '0714777004',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714777004']))
            ->assertSessionHasErrors('entries_to_admit');

        $this->assertSame(1, $guest->fresh()->used_entries);
        $this->assertDatabaseCount('admissions', 0);
    }

    public function test_manual_admission_cannot_admit_cancelled_pass(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guest('Manual Cancelled Guest', '0714777005', Guest::PASS_DOUBLE, 2, 0, Guest::STATUS_CANCELLED);

        $this->actingAs($scanner)
            ->from(route('scanner.manual-search', ['q' => '0714777005']))
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 1,
                'search' => '0714777005',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714777005']))
            ->assertSessionHasErrors('entries_to_admit');

        $this->assertSame(0, $guest->fresh()->used_entries);
        $this->assertDatabaseCount('admissions', 0);
    }

    public function test_manual_admission_cannot_admit_revoked_qr_pass(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guest('Manual Revoked Guest', '0714777006', Guest::PASS_DOUBLE, 2);
        $guest->qrCode->update([
            'is_active' => false,
            'revoked_at' => now(),
        ]);

        $this->actingAs($scanner)
            ->from(route('scanner.manual-search', ['q' => '0714777006']))
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 1,
                'search' => '0714777006',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714777006']))
            ->assertSessionHasErrors('entries_to_admit');

        $this->assertSame(0, $guest->fresh()->used_entries);
        $this->assertDatabaseCount('admissions', 0);
    }

    public function test_manual_admission_ignores_request_event_id_and_scopes_to_scanner_event(): void
    {
        $weddingA = $this->event('Manual Admission Wedding A');
        $weddingB = $this->event('Manual Admission Wedding B');
        $scanner = $this->scannerUser($weddingA);
        $guestA = $this->guest('Wedding A Manual Admission Guest', '0714777007', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE, null, $weddingA);
        $guestB = $this->guest('Wedding B Manual Admission Guest', '0714777008', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE, null, $weddingB);

        $this->actingAs($scanner)
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guestA->id,
                'entries_to_admit' => 1,
                'search' => '0714777007',
                'event_id' => $weddingB->id,
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714777007']))
            ->assertSessionHas('success');

        $this->actingAs($scanner)
            ->from(route('scanner.manual-search', ['q' => '0714777008']))
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guestB->id,
                'entries_to_admit' => 1,
                'search' => '0714777008',
                'event_id' => $weddingB->id,
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => '0714777008']))
            ->assertSessionHasErrors('entries_to_admit');

        $this->assertSame(1, $guestA->fresh()->used_entries);
        $this->assertSame(0, $guestB->fresh()->used_entries);
        $this->assertDatabaseHas('checkins', [
            'event_id' => $weddingA->id,
            'guest_id' => $guestA->id,
            'user_id' => $scanner->id,
            'gate_name' => 'Main Gate',
            'scan_result' => Checkin::RESULT_ADMITTED,
        ]);
    }

    public function test_manual_search_shows_fully_used_and_cancelled_states(): void
    {
        $scanner = $this->scannerUser();
        $this->guest('Fully Used Manual Guest', '0714999000', Guest::PASS_SINGLE, 1, 1, Guest::STATUS_ACTIVE, '9900');
        $this->guest('Cancelled Manual Guest', '0714999001', Guest::PASS_DOUBLE, 2, 0, Guest::STATUS_CANCELLED, '9901');

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => '9900']))
            ->assertOk()
            ->assertSee('Fully Used Manual Guest')
            ->assertSee('Already Fully Used')
            ->assertDontSee('name="entries_to_admit"', false);

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => '9901']))
            ->assertOk()
            ->assertSee('Cancelled Manual Guest')
            ->assertSee('Cancelled Pass')
            ->assertDontSee('name="entries_to_admit"', false);
    }

    public function test_manual_search_is_protected_from_admin_users(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => uniqid('admin', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin)
            ->get(route('scanner.manual-search'))
            ->assertForbidden();
    }

    private function guest(
        string $name,
        string $phone,
        string $passType,
        int $allowedEntries,
        int $usedEntries = 0,
        string $status = Guest::STATUS_ACTIVE,
        ?string $manualCode = null,
        ?Event $event = null,
    ): Guest {
        $attributes = [
            'name' => $name,
            'phone_number' => $phone,
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
            'used_entries' => $usedEntries,
            'status' => $status,
            'manual_code' => $manualCode,
        ];

        if ($event) {
            $attributes['event_id'] = $event->id;
        }

        $guest = Guest::query()->create($attributes);

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => Guest::makeSecureToken(),
            'is_active' => true,
            'generated_at' => now(),
        ]);

        return $guest->fresh('qrCode');
    }

    private function event(string $name): Event
    {
        return Event::query()->create([
            'event_name' => $name,
            'status' => Event::STATUS_ACTIVE,
        ]);
    }

    private function scannerUser(?Event $event = null): User
    {
        $attributes = [
            'name' => 'Manual Scanner',
            'email' => uniqid('scanner', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SCANNER,
            'gate_name' => 'Main Gate',
        ];

        if ($event) {
            $attributes['event_id'] = $event->id;
        }

        return User::query()->create($attributes);
    }
}
