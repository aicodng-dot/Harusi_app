<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Services\OfflineScannerPackageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OfflineScannerAccountEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_package_form_excludes_inactive_scanners(): void
    {
        [$event, $admin, $master, $client] = $this->accounts();
        $client->update(['name' => 'Disabled Package Scanner', 'is_active' => false]);

        $response = $this->actingAs($admin)
            ->get(route('admin.offline-scanner-sessions.create', $event))
            ->assertOk()
            ->assertSee($master->name)
            ->assertDontSee('Disabled Package Scanner');

        $this->assertSame([$master->id], $response->viewData('scannerUsers')->pluck('id')->all());
    }

    #[DataProvider('disabledRoles')]
    public function test_request_cannot_provision_an_inactive_master_or_client(string $disabledRole): void
    {
        [$event, $admin, $master, $client] = $this->accounts();
        ($disabledRole === 'master' ? $master : $client)->update(['is_active' => false]);
        $password = 'Str0ng-Package-Password!';

        $this->actingAs($admin)
            ->post(route('admin.offline-scanner-sessions.store', $event), [
                'master_scanner_user_id' => $master->id,
                'master_gate_name' => 'Main Gate',
                'client_scanner_user_ids' => [$client->id],
                'client_gate_names' => [$client->id => 'Side Gate'],
                'package_expires_at' => now()->addDay()->format('Y-m-d H:i'),
                'package_password' => $password,
                'package_password_confirmation' => $password,
                'confirm_generation' => '1',
            ])
            ->assertSessionHasErrors($disabledRole === 'master' ? 'master_scanner_user_id' : 'client_scanner_user_ids.0');

        $this->assertDatabaseCount('offline_scanner_sessions', 0);
        $this->assertDatabaseCount('offline_scanner_packages', 0);
    }

    #[DataProvider('disabledRoles')]
    public function test_direct_builder_rechecks_persisted_account_state_before_provisioning(string $disabledRole): void
    {
        [$event, $admin, $master, $client] = $this->accounts();
        ($disabledRole === 'master' ? $master : $client)->update(['is_active' => false]);

        try {
            app(OfflineScannerPackageBuilder::class)->generate($event, $admin, [
                'master_scanner_user_id' => $master->id,
                'master_gate_name' => 'Main Gate',
                'clients' => [['scanner_user_id' => $client->id, 'assigned_gate' => 'Side Gate']],
                'package_expires_at' => now()->addDay()->toIso8601String(),
                'package_password' => 'Str0ng-Package-Password!',
            ]);
            $this->fail('The builder accepted an inactive scanner account.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Choose a valid scanner user assigned to this event.', $exception->getMessage());
        }

        $this->assertDatabaseCount('offline_scanner_sessions', 0);
        $this->assertDatabaseCount('offline_scanner_session_users', 0);
        $this->assertDatabaseCount('offline_scanner_packages', 0);
    }

    public static function disabledRoles(): array
    {
        return ['master' => ['master'], 'client' => ['client']];
    }

    private function accounts(): array
    {
        $event = Event::factory()->create();
        $admin = User::factory()->create();
        $master = User::factory()->create([
            'name' => 'Active Package Master', 'role' => User::ROLE_SCANNER,
            'event_id' => $event->id, 'gate_name' => 'Main Gate',
        ]);
        $client = User::factory()->create([
            'name' => 'Package Client', 'role' => User::ROLE_SCANNER,
            'event_id' => $event->id, 'gate_name' => 'Side Gate',
        ]);

        return [$event, $admin, $master, $client];
    }
}
