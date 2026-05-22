<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScannerConfirmAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_pass_admission_marks_guest_fully_used(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_SINGLE, 1);
        $scanner = $this->scannerUser();

        $this->actingAs($scanner)
            ->postJson(route('scanner.admit'), [
                'guest_id' => $guest->id,
                'qr_token' => $token,
                'entries_to_admit' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'admitted')
            ->assertJsonPath('guest.used_entries', 1)
            ->assertJsonPath('guest.remaining_entries', 0)
            ->assertJsonPath('guest.system_status', Guest::STATUS_FULLY_USED);

        $this->assertDatabaseHas('guests', [
            'id' => $guest->id,
            'used_entries' => 1,
            'status' => Guest::STATUS_FULLY_USED,
        ]);
        $this->assertDatabaseHas('checkins', [
            'guest_id' => $guest->id,
            'qr_code_id' => $guest->qrCode->id,
            'user_id' => $scanner->id,
            'gate_name' => 'Main Gate',
            'entries_added' => 1,
            'used_entries_after_scan' => 1,
            'remaining_entries_after_scan' => 0,
            'scan_result' => Checkin::RESULT_ADMITTED,
        ]);
    }

    public function test_double_pass_partial_admission_marks_guest_partially_used(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_DOUBLE, 2);

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.admit'), [
                'guest_id' => $guest->id,
                'qr_token' => $token,
                'entries_to_admit' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'admitted')
            ->assertJsonPath('guest.used_entries', 1)
            ->assertJsonPath('guest.remaining_entries', 1)
            ->assertJsonPath('guest.system_status', Guest::STATUS_PARTIALLY_USED);

        $this->assertDatabaseHas('guests', [
            'id' => $guest->id,
            'used_entries' => 1,
            'status' => Guest::STATUS_PARTIALLY_USED,
        ]);
    }

    public function test_double_pass_full_admission_marks_guest_fully_used(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_DOUBLE, 2);

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.admit'), [
                'guest_id' => $guest->id,
                'qr_token' => $token,
                'entries_to_admit' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'admitted')
            ->assertJsonPath('guest.used_entries', 2)
            ->assertJsonPath('guest.remaining_entries', 0)
            ->assertJsonPath('guest.system_status', Guest::STATUS_FULLY_USED);

        $this->assertDatabaseHas('guests', [
            'id' => $guest->id,
            'used_entries' => 2,
            'status' => Guest::STATUS_FULLY_USED,
        ]);
    }

    public function test_special_pass_partial_admission_keeps_remaining_entries(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_SPECIAL, 6);

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.admit'), [
                'guest_id' => $guest->id,
                'qr_token' => $token,
                'entries_to_admit' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'admitted')
            ->assertJsonPath('guest.used_entries', 3)
            ->assertJsonPath('guest.remaining_entries', 3)
            ->assertJsonPath('guest.system_status', Guest::STATUS_PARTIALLY_USED);

        $this->assertDatabaseHas('checkins', [
            'guest_id' => $guest->id,
            'entries_added' => 3,
            'used_entries_after_scan' => 3,
            'remaining_entries_after_scan' => 3,
            'scan_result' => Checkin::RESULT_ADMITTED,
        ]);
    }

    public function test_admission_rejects_more_than_remaining_entries(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_DOUBLE, 2, 1);

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.admit'), [
                'guest_id' => $guest->id,
                'qr_token' => $token,
                'entries_to_admit' => 2,
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Requested entries exceed the remaining allowance.');

        $this->assertSame(1, $guest->fresh()->used_entries);
    }

    public function test_repeated_qr_usage_after_fully_used_is_rejected(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_SINGLE, 1, 1);

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.admit'), [
                'guest_id' => $guest->id,
                'qr_token' => $token,
                'entries_to_admit' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'already_used')
            ->assertJsonPath('message', 'This pass has already been fully used.');

        $this->assertSame(1, $guest->fresh()->used_entries);
        $this->assertDatabaseHas('checkins', [
            'guest_id' => $guest->id,
            'scan_result' => Checkin::RESULT_ALREADY_USED,
            'entries_added' => 0,
        ]);
    }

    private function makeGuest(string $passType, int $allowedEntries, int $usedEntries = 0): array
    {
        $token = Guest::makeSecureToken();
        $guest = Guest::query()->create([
            'name' => 'Admission Guest',
            'phone_number' => '0712000000',
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
            'used_entries' => $usedEntries,
        ]);

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => $token,
            'is_active' => true,
            'generated_at' => now(),
        ]);

        return [$guest->fresh('qrCode'), $token];
    }

    private function scannerUser(): User
    {
        return User::query()->create([
            'name' => 'Scanner User',
            'email' => uniqid('scanner', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SCANNER,
            'gate_name' => 'Main Gate',
        ]);
    }
}
