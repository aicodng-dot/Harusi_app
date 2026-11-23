<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScannerInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanner_dashboard_shows_user_gate_and_today_stats(): void
    {
        $scanner = $this->scannerUser();
        $guest = $this->guestWithQr();

        Checkin::query()->create([
            'guest_id' => $guest->id,
            'qr_code_id' => $guest->qrCode->id,
            'user_id' => $scanner->id,
            'gate_name' => 'Main Gate',
            'entries_added' => 2,
            'used_entries_after_scan' => 2,
            'remaining_entries_after_scan' => 1,
            'scan_result' => Checkin::RESULT_ADMITTED,
            'checked_in_at' => now(),
        ]);

        Checkin::query()->create([
            'user_id' => $scanner->id,
            'gate_name' => 'Main Gate',
            'scan_result' => Checkin::RESULT_INVALID,
            'checked_in_at' => now(),
        ]);

        $this->actingAs($scanner)
            ->get(route('scanner.dashboard'))
            ->assertOk()
            ->assertSee('Main Gate')
            ->assertSee('Start Scanning')
            ->assertSee('Manual Search')
            ->assertSee('Recent Scans')
            ->assertSee('2')
            ->assertSee('Invalid');
    }

    public function test_scanner_pages_render_mobile_navigation(): void
    {
        $scanner = $this->scannerUser();

        $this->actingAs($scanner)
            ->get(route('scanner.scan'))
            ->assertOk()
            ->assertSee('Start Camera')
            ->assertSee('Manual Search')
            ->assertSee('Recent result')
            ->assertSee('Scan a QR code to show the latest result.')
            ->assertSee('Scan Next')
            ->assertSee('Confirm Admission')
            ->assertSee('Home')
            ->assertDontSee('Manual code')
            ->assertDontSee('Token or verification URL')
            ->assertDontSee('Verify pass');

        $this->actingAs($scanner)
            ->get(route('scanner.recent-scans'))
            ->assertOk()
            ->assertSee('Recent Scans')
            ->assertSee('Start Scanning');
    }

    public function test_scanner_lan_http_requests_redirect_to_configured_https_url(): void
    {
        config(['app.url' => 'https://192.168.100.109:8443']);

        $this->withServerVariables([
            'HTTP_HOST' => '192.168.100.109:8000',
            'HTTPS' => 'off',
        ])
            ->get('/scanner/scan?gate=main')
            ->assertRedirect('https://192.168.100.109:8443/scanner/scan?gate=main');
    }

    private function scannerUser(): User
    {
        return User::query()->create([
            'name' => 'Main Gate Scanner',
            'email' => uniqid('scanner', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SCANNER,
            'gate_name' => 'Main Gate',
        ]);
    }

    private function guestWithQr(): Guest
    {
        $guest = Guest::query()->create([
            'name' => 'Scanner Guest',
            'phone_number' => '+255 700 200 300',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 3,
        ]);

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => Guest::makeSecureToken(),
            'is_active' => true,
            'generated_at' => now(),
        ]);

        return $guest->fresh('qrCode');
    }
}
