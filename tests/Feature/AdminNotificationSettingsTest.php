<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_show_safe_whatsapp_readiness_without_exposing_secrets(): void
    {
        config([
            'services.whatsapp.meta.access_token' => 'secret-access-token-should-not-render',
            'services.whatsapp.meta.phone_number_id' => 'phone-number-id-should-not-render',
            'services.whatsapp.meta.business_account_id' => 'business-account-id-should-not-render',
            'services.whatsapp.meta.webhook_verify_token' => 'webhook-token-should-not-render',
            'services.whatsapp.meta.app_secret' => 'app-secret-should-not-render',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Notification Settings')
            ->assertSee('Meta WhatsApp Cloud API')
            ->assertSee('WhatsApp credentials status')
            ->assertSee('Phone Number ID status')
            ->assertSee('Business Account ID status')
            ->assertSee('Configured')
            ->assertSee('Verification token configured; delivery status processing pending')
            ->assertSee('Pending Meta template approval review')
            ->assertSee(route('admin.notification-logs.index'), false)
            ->assertDontSee('secret-access-token-should-not-render')
            ->assertDontSee('phone-number-id-should-not-render')
            ->assertDontSee('business-account-id-should-not-render')
            ->assertDontSee('webhook-token-should-not-render')
            ->assertDontSee('app-secret-should-not-render');
    }

    public function test_admin_settings_show_not_configured_status_when_meta_credentials_are_missing(): void
    {
        config([
            'services.whatsapp.meta.access_token' => '',
            'services.whatsapp.meta.phone_number_id' => '',
            'services.whatsapp.meta.business_account_id' => '',
            'services.whatsapp.meta.webhook_verify_token' => '',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Not configured')
            ->assertSee('Pending webhook verify token');
    }

    public function test_admin_notification_logs_page_does_not_expose_context_or_secret_values(): void
    {
        $admin = $this->adminUser();
        $event = Event::defaultEvent();
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => 'Logged Guest',
            'phone_number' => '0788000001',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
            'used_entries' => 0,
            'status' => Guest::STATUS_ACTIVE,
        ]);

        NotificationLog::query()->create([
            'guest_id' => $guest->id,
            'event_id' => $event->id,
            'channel' => NotificationLog::CHANNEL_WHATSAPP,
            'message_type' => NotificationLog::TYPE_GUEST_CODE,
            'template_name' => 'guest_code',
            'recipient_phone' => '+255788000001',
            'message' => 'Your guest verification code is '.$guest->manual_code.'.',
            'status' => NotificationLog::STATUS_SKIPPED,
            'provider' => NotificationLog::PROVIDER_META,
            'provider_message_id' => 'wamid.visible-provider-message-id',
            'error_message' => 'WhatsApp credentials are not configured.',
            'context' => [
                'manual_code' => $guest->manual_code,
                'access_token' => 'context-token-should-not-render',
            ],
        ]);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->get(route('admin.notification-logs.index'))
            ->assertOk()
            ->assertSee('Notification Logs')
            ->assertSee('Logged Guest')
            ->assertSee('+255788000001')
            ->assertSee('wamid.visible-provider-message-id')
            ->assertSee('WhatsApp credentials are not configured.')
            ->assertDontSee('context-token-should-not-render')
            ->assertDontSee($guest->manual_code);
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
}
