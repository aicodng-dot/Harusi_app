<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCheckinHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_logged_admission_appears_on_admin_checkin_history(): void
    {
        [$guest, $token] = $this->makeGuest('Logged Admission Guest', '0712111000', Guest::PASS_DOUBLE, 2);
        $scanner = $this->scannerUser('Scanner User', 'Main Gate');

        $this->actingAs($scanner)
            ->postJson(route('scanner.admit'), [
                'guest_id' => $guest->id,
                'qr_token' => $token,
                'entries_to_admit' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('status', Checkin::RESULT_ADMITTED);

        $this->actingAs($this->adminUser())
            ->get(route('admin.checkins.index'))
            ->assertOk()
            ->assertSee('Logged Admission Guest')
            ->assertSee('0712111000')
            ->assertSee('Double pass')
            ->assertSee('Main Gate')
            ->assertSee('Scanner User')
            ->assertSee('admitted');
    }

    public function test_admin_can_filter_checkins_by_search_result_gate_and_date(): void
    {
        [$mainGuest] = $this->makeGuest('Asha Filter Match', '0712222000', Guest::PASS_SINGLE, 1);
        [$sideGuest] = $this->makeGuest('Baraka Side Filter', '0712333000', Guest::PASS_SPECIAL, 5);
        [$oldGuest] = $this->makeGuest('Neema Old Date', '0712444000', Guest::PASS_DOUBLE, 2);
        $scanner = $this->scannerUser('Gate Operator', 'Main Gate');
        $admin = $this->adminUser();

        $this->makeCheckin($mainGuest, $scanner, Checkin::RESULT_ADMITTED, 'Main Gate', now(), 1, 1, 0);
        $this->makeCheckin($sideGuest, $scanner, Checkin::RESULT_INVALID, 'Side Gate', now(), 0, 0, 5);
        $this->makeCheckin($oldGuest, $scanner, Checkin::RESULT_ALREADY_USED, 'Main Gate', now()->subDays(3), 0, 2, 0);

        $this->actingAs($admin)
            ->get(route('admin.checkins.index', ['search' => 'Asha']))
            ->assertOk()
            ->assertSee('Asha Filter Match')
            ->assertDontSee('Baraka Side Filter')
            ->assertDontSee('Neema Old Date');

        $this->actingAs($admin)
            ->get(route('admin.checkins.index', ['scan_result' => Checkin::RESULT_INVALID]))
            ->assertOk()
            ->assertSee('Baraka Side Filter')
            ->assertDontSee('Asha Filter Match')
            ->assertDontSee('Neema Old Date');

        $this->actingAs($admin)
            ->get(route('admin.checkins.index', ['gate_name' => 'Side Gate']))
            ->assertOk()
            ->assertSee('Baraka Side Filter')
            ->assertDontSee('Asha Filter Match')
            ->assertDontSee('Neema Old Date');

        $this->actingAs($admin)
            ->get(route('admin.checkins.index', ['date' => now()->subDays(3)->toDateString()]))
            ->assertOk()
            ->assertSee('Neema Old Date')
            ->assertDontSee('Asha Filter Match')
            ->assertDontSee('Baraka Side Filter');
    }

    public function test_admin_checkin_history_is_paginated(): void
    {
        $admin = $this->adminUser();
        $scanner = $this->scannerUser('Pagination Scanner', 'Main Gate');

        for ($i = 1; $i <= 25; $i++) {
            [$guest] = $this->makeGuest('Paged Guest '.$i, '0712555'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), Guest::PASS_SINGLE, 1);
            $this->makeCheckin($guest, $scanner, Checkin::RESULT_VALID, 'Main Gate', now()->subMinutes($i), 0, 0, 1);
        }

        $this->actingAs($admin)
            ->get(route('admin.checkins.index'))
            ->assertOk()
            ->assertViewHas('checkins', function ($checkins): bool {
                return $checkins->perPage() === 20
                    && $checkins->total() === 25
                    && $checkins->hasPages();
            });
    }

    public function test_admin_can_export_filtered_checkins_to_csv(): void
    {
        $admin = $this->adminUser();
        $scanner = $this->scannerUser('CSV Scanner', 'Main Gate');
        [$matchGuest] = $this->makeGuest('CSV Match Guest', '0712666000', Guest::PASS_DOUBLE, 2);
        [$otherGuest] = $this->makeGuest('CSV Other Guest', '0712777000', Guest::PASS_SINGLE, 1);

        $this->makeCheckin($matchGuest, $scanner, Checkin::RESULT_ADMITTED, 'Main Gate', now(), 2, 2, 0);
        $this->makeCheckin($otherGuest, $scanner, Checkin::RESULT_INVALID, 'Side Gate', now(), 0, 0, 1);

        $response = $this->actingAs($admin)
            ->get(route('admin.checkins.export', ['search' => 'CSV Match']));

        $response->assertOk();
        $response->assertDownload();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Guest name', $csv);
        $this->assertStringContainsString('CSV Match Guest', $csv);
        $this->assertStringContainsString('0712666000', $csv);
        $this->assertStringContainsString('admitted', $csv);
        $this->assertStringNotContainsString('CSV Other Guest', $csv);
    }

    private function makeGuest(string $name, string $phone, string $passType, int $allowedEntries): array
    {
        $token = Guest::makeSecureToken();
        $guest = Guest::query()->create([
            'name' => $name,
            'phone_number' => $phone,
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
            'used_entries' => 0,
        ]);

        $qrCode = QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => $token,
            'is_active' => true,
            'generated_at' => now(),
        ]);

        return [$guest->fresh('qrCode'), $token, $qrCode];
    }

    private function makeCheckin(
        Guest $guest,
        User $scanner,
        string $result,
        string $gate,
        $checkedInAt,
        int $entriesAdded,
        int $usedEntriesAfterScan,
        int $remainingEntriesAfterScan,
    ): Checkin {
        return Checkin::query()->create([
            'guest_id' => $guest->id,
            'qr_code_id' => $guest->qrCode?->id,
            'user_id' => $scanner->id,
            'gate_name' => $gate,
            'entries_added' => $entriesAdded,
            'used_entries_after_scan' => $usedEntriesAfterScan,
            'remaining_entries_after_scan' => $remainingEntriesAfterScan,
            'scan_result' => $result,
            'device_info' => 'Feature test',
            'ip_address' => '127.0.0.1',
            'checked_in_at' => $checkedInAt,
        ]);
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

    private function scannerUser(string $name, string $gateName): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => uniqid('scanner', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SCANNER,
            'gate_name' => $gateName,
        ]);
    }
}
