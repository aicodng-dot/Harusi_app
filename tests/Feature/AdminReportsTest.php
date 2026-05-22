<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reports_show_accurate_summary_pass_status_gate_and_time_numbers(): void
    {
        $admin = $this->adminUser();
        $this->seedReportData();

        $response = $this->actingAs($admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Export guest list CSV')
            ->assertSee('Gate Report')
            ->assertSee('Time Report');

        $this->assertSame([
            'total_invites' => 4,
            'total_expected_admissions' => 8,
            'total_admitted_people' => 6,
            'total_remaining_admissions' => 2,
        ], $response->viewData('admissionSummary'));

        $this->assertSame(1, $response->viewData('passTypeReport')[Guest::PASS_SINGLE]);
        $this->assertSame(1, $response->viewData('passTypeReport')[Guest::PASS_DOUBLE]);
        $this->assertSame(2, $response->viewData('passTypeReport')[Guest::PASS_SPECIAL]);

        $this->assertSame(1, $response->viewData('statusReport')['unused']);
        $this->assertSame(1, $response->viewData('statusReport')[Guest::STATUS_PARTIALLY_USED]);
        $this->assertSame(1, $response->viewData('statusReport')[Guest::STATUS_FULLY_USED]);
        $this->assertSame(1, $response->viewData('statusReport')[Guest::STATUS_CANCELLED]);

        $gateReport = collect($response->viewData('gateReport'))->keyBy('gate_name');
        $this->assertSame(2, $gateReport['Main Gate']['admissions']);
        $this->assertSame(2, $gateReport['Main Gate']['invalid_attempts']);
        $this->assertSame(3, $gateReport['Side Gate']['admissions']);
        $this->assertSame(1, $gateReport['Side Gate']['invalid_attempts']);

        $timeReport = collect($response->viewData('timeReport'))->keyBy('hour');
        $this->assertSame(2, $timeReport['10:00']['admissions']);
        $this->assertSame(3, $timeReport['11:00']['admissions']);
    }

    public function test_report_summary_matches_dashboard_numbers_and_database_totals(): void
    {
        $admin = $this->adminUser();
        $this->seedReportData();

        $reportResponse = $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk();
        $dashboardResponse = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $summary = $reportResponse->viewData('admissionSummary');
        $dashboardStats = $dashboardResponse->viewData('stats');

        $this->assertSame(Guest::query()->count(), $summary['total_invites']);
        $this->assertSame((int) Guest::query()->where('status', '<>', Guest::STATUS_CANCELLED)->sum('allowed_entries'), $summary['total_expected_admissions']);
        $this->assertSame((int) Guest::query()->sum('used_entries'), $summary['total_admitted_people']);
        $this->assertSame(2, $summary['total_remaining_admissions']);

        $this->assertSame($dashboardStats['total_guests'], $summary['total_invites']);
        $this->assertSame($dashboardStats['total_allowed_entries'], $summary['total_expected_admissions']);
        $this->assertSame($dashboardStats['admitted_entries'], $summary['total_admitted_people']);
        $this->assertSame($dashboardStats['remaining_entries'], $summary['total_remaining_admissions']);
    }

    public function test_report_csv_exports_download_expected_rows(): void
    {
        $admin = $this->adminUser();
        $this->seedReportData();

        $guestList = $this->actingAs($admin)->get(route('admin.reports.export.guest-list'));
        $guestList->assertOk()->assertDownload();
        $guestListCsv = $guestList->streamedContent();
        $this->assertStringContainsString('Guest name', $guestListCsv);
        $this->assertStringContainsString('Single Unused Guest', $guestListCsv);
        $this->assertStringContainsString('Double Partial Guest', $guestListCsv);
        $this->assertStringContainsString('Special Full Guest', $guestListCsv);
        $this->assertStringContainsString('Cancelled Guest', $guestListCsv);

        $checkedIn = $this->actingAs($admin)->get(route('admin.reports.export.checked-in-guests'));
        $checkedIn->assertOk()->assertDownload();
        $checkedInCsv = $checkedIn->streamedContent();
        $this->assertStringContainsString('Double Partial Guest', $checkedInCsv);
        $this->assertStringContainsString('Special Full Guest', $checkedInCsv);
        $this->assertStringNotContainsString('Single Unused Guest', $checkedInCsv);
        $this->assertStringNotContainsString('Cancelled Guest', $checkedInCsv);

        $remaining = $this->actingAs($admin)->get(route('admin.reports.export.remaining-guests'));
        $remaining->assertOk()->assertDownload();
        $remainingCsv = $remaining->streamedContent();
        $this->assertStringContainsString('Single Unused Guest', $remainingCsv);
        $this->assertStringContainsString('Double Partial Guest', $remainingCsv);
        $this->assertStringNotContainsString('Special Full Guest', $remainingCsv);
        $this->assertStringNotContainsString('Cancelled Guest', $remainingCsv);

        $invalidScans = $this->actingAs($admin)->get(route('admin.reports.export.invalid-scans'));
        $invalidScans->assertOk()->assertDownload();
        $invalidCsv = $invalidScans->streamedContent();
        $this->assertStringContainsString('invalid', $invalidCsv);
        $this->assertStringContainsString('already_used', $invalidCsv);
        $this->assertStringContainsString('revoked', $invalidCsv);
        $this->assertStringNotContainsString('admitted', $invalidCsv);
    }

    private function seedReportData(): void
    {
        $singleUnused = $this->guest('Single Unused Guest', '0713000001', Guest::PASS_SINGLE, 1, 0);
        $doublePartial = $this->guest('Double Partial Guest', '0713000002', Guest::PASS_DOUBLE, 2, 1);
        $specialFull = $this->guest('Special Full Guest', '0713000003', Guest::PASS_SPECIAL, 5, 5);
        $cancelled = $this->guest('Cancelled Guest', '0713000004', Guest::PASS_SPECIAL, 4, 0, Guest::STATUS_CANCELLED);
        $scanner = $this->scannerUser();

        $this->checkin($doublePartial, $scanner, Checkin::RESULT_ADMITTED, 'Main Gate', 2, now()->setTime(10, 15), 2, 0);
        $this->checkin($specialFull, $scanner, Checkin::RESULT_ADMITTED, 'Side Gate', 3, now()->setTime(11, 20), 5, 0);
        $this->checkin($singleUnused, $scanner, Checkin::RESULT_INVALID, 'Main Gate', 0, now()->setTime(12, 10), 0, 1);
        $this->checkin($doublePartial, $scanner, Checkin::RESULT_ALREADY_USED, 'Side Gate', 0, now()->setTime(13, 5), 1, 1);
        $this->checkin($cancelled, $scanner, Checkin::RESULT_REVOKED, 'Main Gate', 0, now()->setTime(14, 25), 0, 4);
    }

    private function guest(
        string $name,
        string $phone,
        string $passType,
        int $allowedEntries,
        int $usedEntries,
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

    private function checkin(
        Guest $guest,
        User $scanner,
        string $result,
        string $gateName,
        int $entriesAdded,
        $checkedInAt,
        int $usedAfter,
        int $remainingAfter,
    ): Checkin {
        return Checkin::query()->create([
            'guest_id' => $guest->id,
            'qr_code_id' => $guest->qrCode?->id,
            'user_id' => $scanner->id,
            'gate_name' => $gateName,
            'entries_added' => $entriesAdded,
            'used_entries_after_scan' => $usedAfter,
            'remaining_entries_after_scan' => $remainingAfter,
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

    private function scannerUser(): User
    {
        return User::query()->create([
            'name' => 'Report Scanner',
            'email' => uniqid('scanner', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SCANNER,
            'gate_name' => 'Main Gate',
        ]);
    }
}
