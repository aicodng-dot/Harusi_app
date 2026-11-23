<?php

namespace Tests\Feature;

use App\Exceptions\OfflineEventLockedException;
use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\QrCode;
use App\Models\User;
use App\Services\OfflineScannerPackageCryptoService;
use App\Services\QrTokenCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfflineScannerPackageExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_generate_encrypted_master_scanner_package_and_lock_event(): void
    {
        Storage::fake($this->offlineScannerDisk());
        $this->configurePackageSigning();

        $event = Event::factory()->create([
            'event_name' => 'On Wedding',
            'venue_name' => 'Ocean Hall',
            'event_date' => '2026-08-01',
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $master = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Main Gate',
        ]);
        $client = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Garden Gate',
        ]);
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => 'Asha Package Guest',
            'phone_number' => '+255700000123',
            'manual_code' => '0047',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 100,
            'used_entries' => 89,
        ]);
        $token = Guest::makeSecureToken();
        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => $token,
            'is_active' => true,
            'generated_at' => now(),
        ]);

        $password = 'Str0ng-Package-Password!';
        $response = $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.offline-scanner-sessions.store', $event), [
                'master_scanner_user_id' => $master->id,
                'master_gate_name' => 'Main Gate',
                'client_scanner_user_ids' => [$client->id],
                'client_gate_names' => [$client->id => 'Garden Gate'],
                'package_expires_at' => now()->addDay()->format('Y-m-d H:i'),
                'package_password' => $password,
                'package_password_confirmation' => $password,
                'session_notes' => 'Use the north Android master kit.',
                'confirm_generation' => '1',
            ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringContainsString('.wqrpkg', (string) $response->headers->get('Content-Disposition'));

        $contents = $response->getContent();
        $this->assertStringNotContainsString('Asha Package Guest', $contents);
        $this->assertStringNotContainsString('+255700000123', $contents);
        $this->assertStringNotContainsString($token, $contents);
        $this->assertStringNotContainsString('0047', $contents);

        $envelope = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('harusi.offline-scanner.package', $envelope['format']);
        $this->assertSame('AES-256-GCM', $envelope['encryption_algorithm']);
        $this->assertSame('PBKDF2-HMAC-SHA256', $envelope['password_kdf_algorithm']);
        $this->assertSame('HMAC-SHA256', $envelope['signature_algorithm']);
        $this->assertArrayHasKey('digital_signature', $envelope);
        $this->assertArrayNotHasKey('guests', $envelope);

        $payload = app(OfflineScannerPackageCryptoService::class)->decryptPayload($envelope, $password);
        $this->assertSame('1.0', $payload['schema_version']);
        $this->assertSame($event->id, $payload['event']['event_id']);
        $this->assertSame('Ocean Hall', $payload['event']['venue']);
        $this->assertSame(1, $payload['snapshot']['guest_count']);
        $this->assertSame(1, count($payload['guests']));

        $packageGuest = $payload['guests'][0];
        $this->assertSame((string) $guest->id, $packageGuest['external_guest_id']);
        $this->assertSame('Asha Package Guest', $packageGuest['display_name']);
        $this->assertSame('0047', $packageGuest['manual_code']);
        $this->assertSame(100, $packageGuest['allowed_entries']);
        $this->assertSame(89, $packageGuest['used_entries']);
        $this->assertSame(11, $packageGuest['remaining_entries']);
        $this->assertSame(app(QrTokenCanonicalizer::class)->hash($token), $packageGuest['qr_token_hash']);
        $this->assertArrayNotHasKey('qr_token', $packageGuest);
        $this->assertArrayNotHasKey('qr_image_path', $packageGuest);

        $scannerUsers = collect($payload['scanner_users'])->keyBy('scanner_user_id');
        $this->assertSame('master', $scannerUsers[$master->id]['role']);
        $this->assertSame('client', $scannerUsers[$client->id]['role']);
        $this->assertArrayNotHasKey('password', $scannerUsers[$master->id]);
        $this->assertNotEmpty($scannerUsers[$master->id]['offline_pin_verifier']);

        $session = OfflineScannerSession::query()->firstOrFail();
        $package = OfflineScannerPackage::query()->firstOrFail();
        $this->assertSame(OfflineScannerSession::STATUS_ACTIVE, $session->status);
        $this->assertSame('Use the north Android master kit.', $session->notes);
        $this->assertSame($package->id, $session->package_export_id);
        $this->assertSame(hash('sha256', $contents), $package->package_fingerprint);
        $this->assertSame($package->package_fingerprint, $response->headers->get('X-Offline-Package-Fingerprint'));
        $this->assertStringEndsWith('.wqrpkg', $package->file_name);
        Storage::disk($this->offlineScannerDisk())->assertExists($package->file_path);
        $this->assertSame($this->offlineScannerDisk(), $package->storage_disk);
        $this->assertSame('harusi.offline-scanner.package', $package->internal_format);
        $this->assertSame($admin->id, $package->downloaded_by_user_id);

        $this->assertNotSame($payload['security']['session_bootstrap_secret'], $session->session_bootstrap_secret_encrypted);
        $this->assertSame($payload['security']['session_bootstrap_secret'], Crypt::decryptString($session->session_bootstrap_secret_encrypted));
        $this->assertNotEmpty($session->result_file_encryption_secret_encrypted);
        $this->assertNotEmpty($session->result_file_authentication_secret_encrypted);
        $this->assertNotEmpty($session->device_pairing_secret_encrypted);

        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $session->id,
            'action' => OfflineScannerSessionAudit::ACTION_EVENT_LOCKED,
        ]);
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $session->id,
            'action' => OfflineScannerSessionAudit::ACTION_SESSION_ACTIVATED,
        ]);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->put(route('admin.guests.update', $guest), [
                'name' => 'Changed After Export',
                'phone_number' => '+255700000123',
                'pass_type' => Guest::PASS_SPECIAL,
                'allowed_entries' => 100,
            ])
            ->assertSessionHas('error', OfflineEventLockedException::MESSAGE);

        $this->assertSame('Asha Package Guest', $guest->fresh()->name);

        $download = $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->get(route('admin.offline-scanner-packages.download', $package));

        $download->assertOk();
        $this->assertSame($contents, $download->getContent());
        $this->assertNotNull($package->fresh()->downloaded_at);
        $this->assertSame($admin->id, $package->fresh()->downloaded_by_user_id);
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $session->id,
            'action' => OfflineScannerSessionAudit::ACTION_PACKAGE_DOWNLOADED,
        ]);
    }

    public function test_package_generation_rejects_invalid_guest_snapshot_without_partial_session(): void
    {
        Storage::fake($this->offlineScannerDisk());
        $this->configurePackageSigning();

        $event = Event::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $master = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Main Gate',
        ]);
        Guest::query()->create([
            'event_id' => $event->id,
            'name' => 'Missing QR Guest',
            'phone_number' => '+255700000321',
            'manual_code' => '0280',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);

        $password = 'Str0ng-Package-Password!';
        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.offline-scanner-sessions.store', $event), [
                'master_scanner_user_id' => $master->id,
                'master_gate_name' => 'Main Gate',
                'package_expires_at' => now()->addDay()->format('Y-m-d H:i'),
                'package_password' => $password,
                'package_password_confirmation' => $password,
                'session_notes' => 'Use the north Android master kit.',
                'confirm_generation' => '1',
            ])
            ->assertSessionHasErrors('offline_package');

        $this->assertSame(0, OfflineScannerSession::query()->count());
        $this->assertSame(0, OfflineScannerPackage::query()->count());
        $this->assertCount(0, Storage::disk($this->offlineScannerDisk())->allFiles('offline-packages'));
    }

    public function test_create_session_page_shows_snapshot_summary_and_disables_generation_for_critical_errors(): void
    {
        $event = Event::factory()->create([
            'event_name' => 'Snapshot Preview Wedding',
            'venue_name' => 'Harbor Hall',
            'event_date' => '2026-09-10',
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Main Gate',
        ]);

        $activeGuest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => 'Active Snapshot Guest',
            'phone_number' => '+255700000101',
            'manual_code' => '1111',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
            'used_entries' => 1,
        ]);
        $fullyUsedGuest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => 'Fully Used Snapshot Guest',
            'phone_number' => '+255700000102',
            'manual_code' => '2222',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
            'used_entries' => 1,
        ]);
        Guest::query()->create([
            'event_id' => $event->id,
            'name' => 'Missing QR Snapshot Guest',
            'phone_number' => '+255700000103',
            'manual_code' => '3333',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);

        foreach ([$activeGuest, $fullyUsedGuest] as $guest) {
            QrCode::query()->create([
                'guest_id' => $guest->id,
                'qr_token' => Guest::makeSecureToken(),
                'is_active' => true,
                'generated_at' => now(),
            ]);
        }

        $response = $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->get(route('admin.offline-scanner-sessions.create', $event));

        $response->assertOk()
            ->assertSee('Snapshot Preview Wedding')
            ->assertSee('Harbor Hall')
            ->assertSee('Guest snapshot summary')
            ->assertSee('Guests missing QR tokens')
            ->assertSee('Package generation is disabled until critical guest snapshot issues are resolved.')
            ->assertSee('Generate .wqrpkg')
            ->assertSee('disabled', false);

        $summary = $response->viewData('snapshotSummary');
        $this->assertSame(3, $summary['total_guests']);
        $this->assertSame(1, $summary['active_passes']);
        $this->assertSame(1, $summary['fully_used_passes']);
        $this->assertSame(1, $summary['disabled_passes']);
        $this->assertSame(1, $summary['guests_missing_qr_tokens']);
        $this->assertTrue($summary['has_critical_errors']);
    }

    public function test_events_page_has_offline_scanner_action_that_selects_event(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $event = Event::factory()->create(['event_name' => 'Action Link Wedding']);

        $this->actingAs($admin)
            ->get(route('admin.events.index'))
            ->assertOk()
            ->assertSee('Offline Android Scanner')
            ->assertSee(route('admin.events.select', ['event' => $event, 'redirect_to' => 'offline_scanner']), false);

        $this->actingAs($admin)
            ->get(route('admin.events.select', ['event' => $event, 'redirect_to' => 'offline_scanner']))
            ->assertRedirect(route('admin.offline-scanner-sessions.create', $event))
            ->assertSessionHas('selected_event_id', $event->id);
    }

    private function offlineScannerDisk(): string
    {
        return (string) config('offline_scanner.disk', 'offline_scanner');
    }
    private function configurePackageSigning(): void
    {
        config([
            'services.offline_scanner_package.signature_algorithm' => 'hmac-sha256',
            'services.offline_scanner_package.allow_hmac_fallback' => true,
            'services.offline_scanner_package.hmac_key' => 'test-offline-package-signing-key',
            'services.offline_scanner_package.signing_key_id' => 'test-key-1',
            'services.offline_scanner_package.pbkdf2_iterations' => 1000,
        ]);
    }
}
