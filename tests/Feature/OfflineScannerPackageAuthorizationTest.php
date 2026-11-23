<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerSession;
use App\Models\QrCode;
use App\Models\User;
use App\Services\OfflineEventLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfflineScannerPackageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake($this->offlineScannerDisk());
        $this->configurePackageSigning();
    }

    public function test_unauthorized_user_cannot_create_offline_scanner_session(): void
    {
        [$event, , $master] = $this->eventWithExportableGuest();
        $scannerActor = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Side Gate',
        ]);

        $password = 'Str0ng-Package-Password!';

        $this->actingAs($scannerActor)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.offline-scanner-sessions.store', $event), [
                'master_scanner_user_id' => $master->id,
                'master_gate_name' => 'Main Gate',
                'package_expires_at' => now()->addDay()->format('Y-m-d H:i'),
                'package_password' => $password,
                'package_password_confirmation' => $password,
                'confirm_generation' => '1',
            ])
            ->assertForbidden();

        $this->assertSame(0, OfflineScannerSession::query()->count());
        $this->assertSame(0, OfflineScannerPackage::query()->count());
    }

    public function test_only_one_active_offline_session_exists_per_event(): void
    {
        [$event, $admin, $master] = $this->eventWithExportableGuest();
        $alternateMaster = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Backup Gate',
        ]);

        $this->generatePackage($admin, $event, $master)->assertOk();

        $this->generatePackage($admin, $event, $alternateMaster)
            ->assertSessionHasErrors('offline_session');

        $this->assertSame(1, OfflineScannerSession::query()
            ->where('event_id', $event->id)
            ->where('status', OfflineScannerSession::STATUS_ACTIVE)
            ->count());
        $this->assertNotNull(app(OfflineEventLockService::class)->getActiveSessionForEvent($event->fresh()));
    }

    public function test_failed_package_generation_does_not_leave_active_lock(): void
    {
        [$event, $admin, $master] = $this->eventWithExportableGuest(createQrToken: false);

        $this->generatePackage($admin, $event, $master)
            ->assertSessionHasErrors('offline_package');

        $this->assertSame(0, OfflineScannerSession::query()->count());
        $this->assertSame(0, OfflineScannerPackage::query()->count());
        $this->assertNull(app(OfflineEventLockService::class)->getActiveSessionForEvent($event->fresh()));
    }

    private function eventWithExportableGuest(bool $createQrToken = true): array
    {
        $event = Event::factory()->create([
            'event_name' => 'Authorization Test Wedding',
            'venue_name' => 'Main Hall',
            'event_date' => now()->addMonth()->toDateString(),
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $master = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Main Gate',
        ]);
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => 'Authorized Package Guest',
            'phone_number' => '+255700000501',
            'manual_code' => '0501',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
            'used_entries' => 0,
        ]);

        if ($createQrToken) {
            QrCode::query()->create([
                'guest_id' => $guest->id,
                'qr_token' => Guest::makeSecureToken(),
                'is_active' => true,
                'generated_at' => now(),
            ]);
        }

        return [$event, $admin, $master, $guest];
    }

    private function generatePackage(User $admin, Event $event, User $master)
    {
        $password = 'Str0ng-Package-Password!';

        return $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.offline-scanner-sessions.store', $event), [
                'master_scanner_user_id' => $master->id,
                'master_gate_name' => $master->gate_name ?? 'Main Gate',
                'package_expires_at' => now()->addDay()->format('Y-m-d H:i'),
                'package_password' => $password,
                'package_password_confirmation' => $password,
                'session_notes' => 'Authorization test package.',
                'confirm_generation' => '1',
            ]);
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
