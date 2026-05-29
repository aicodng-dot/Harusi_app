<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScannerManualSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanner_can_search_manually_by_guest_name(): void
    {
        $scanner = $this->scannerUser();
        $match = $this->guest('Asha Manual Match', '0714000001', Guest::PASS_DOUBLE, 2);
        $hidden = $this->guest('Baraka Hidden', '0714000002', Guest::PASS_SINGLE, 1);

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => 'Asha']))
            ->assertOk()
            ->assertSee('Asha Manual Match')
            ->assertSee('0714000001')
            ->assertSee('Double pass')
            ->assertSee('Admit')
            ->assertDontSee('Baraka Hidden');

        $this->assertTrue($match->exists);
        $this->assertTrue($hidden->exists);
    }

    public function test_scanner_can_search_manually_by_phone_number(): void
    {
        $scanner = $this->scannerUser();
        $this->guest('Phone Search Guest', '0714555123', Guest::PASS_SPECIAL, 5);
        $this->guest('Other Phone Guest', '0714666456', Guest::PASS_SINGLE, 1);

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => '555123']))
            ->assertOk()
            ->assertSee('Phone Search Guest')
            ->assertSee('0714555123')
            ->assertSee('Special pass')
            ->assertDontSee('Other Phone Guest');
    }

    public function test_scanner_can_admit_guest_from_manual_search(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guest('Manual Admission Guest', '0714777000', Guest::PASS_SPECIAL, 5, 2);

        $this->actingAs($scanner)
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 2,
                'search' => 'Manual Admission',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => 'Manual Admission']))
            ->assertSessionHas('success');

        $guest = $guest->fresh();
        $this->assertSame(4, $guest->used_entries);
        $this->assertSame(Guest::STATUS_PARTIALLY_USED, $guest->status);

        $this->assertDatabaseHas('checkins', [
            'guest_id' => $guest->id,
            'user_id' => $scanner->id,
            'gate_name' => 'Main Gate',
            'entries_added' => 2,
            'used_entries_after_scan' => 4,
            'remaining_entries_after_scan' => 1,
            'scan_result' => Checkin::RESULT_ADMITTED,
        ]);

        $this->assertDatabaseHas('admissions', [
            'guest_id' => $guest->id,
            'quantity' => 2,
            'admitted_by' => 'Manual Scanner',
            'device_label' => 'Manual search',
        ]);
    }

    public function test_manual_admission_cannot_exceed_remaining_entries(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guest('Manual Limit Guest', '0714888000', Guest::PASS_DOUBLE, 2, 1);

        $this->actingAs($scanner)
            ->from(route('scanner.manual-search', ['q' => 'Manual Limit']))
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 2,
                'search' => 'Manual Limit',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => 'Manual Limit']))
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
            ->get(route('scanner.manual-search', ['q' => 'Manual Stale']))
            ->assertOk()
            ->assertSee('Left')
            ->assertSee('2');

        $guest->update([
            'used_entries' => 1,
            'status' => Guest::STATUS_PARTIALLY_USED,
        ]);

        $this->actingAs($scanner)
            ->from(route('scanner.manual-search', ['q' => 'Manual Stale']))
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 2,
                'search' => 'Manual Stale',
            ])
            ->assertRedirect(route('scanner.manual-search', ['q' => 'Manual Stale']))
            ->assertSessionHasErrors('entries_to_admit');

        $this->assertSame(1, $guest->fresh()->used_entries);
        $this->assertDatabaseCount('admissions', 0);
    }

    public function test_manual_search_shows_fully_used_and_cancelled_states(): void
    {
        $scanner = $this->scannerUser();
        $this->guest('Fully Used Manual Guest', '0714999000', Guest::PASS_SINGLE, 1, 1);
        $this->guest('Cancelled Manual Guest', '0714999001', Guest::PASS_DOUBLE, 2, 0, Guest::STATUS_CANCELLED);

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => 'Manual Guest']))
            ->assertOk()
            ->assertSee('Fully Used Manual Guest')
            ->assertSee('Already Fully Used')
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
    ): Guest {
        $guest = Guest::query()->create([
            'name' => $name,
            'phone_number' => $phone,
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
            'used_entries' => $usedEntries,
            'status' => $status,
        ]);

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => Guest::makeSecureToken(),
            'is_active' => true,
            'generated_at' => now(),
        ]);

        return $guest->fresh('qrCode');
    }

    private function scannerUser(): User
    {
        return User::query()->create([
            'name' => 'Manual Scanner',
            'email' => uniqid('scanner', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SCANNER,
            'gate_name' => 'Main Gate',
        ]);
    }
}
