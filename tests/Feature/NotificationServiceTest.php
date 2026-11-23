<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\QrCode;
use App\Services\Notifications\MetaWhatsappProvider;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NullWhatsappProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_whatsapp_provider_is_used_when_credentials_are_missing(): void
    {
        Http::fake();
        config([
            'services.whatsapp.provider' => 'meta',
            'services.whatsapp.meta.access_token' => '',
            'services.whatsapp.meta.phone_number_id' => '',
        ]);

        $service = app(NotificationService::class);
        $log = $service->sendWhatsappText('0788000001', 'Hello guest.', [
            'message_type' => NotificationLog::TYPE_GENERAL_UPDATE,
        ]);

        $this->assertInstanceOf(NullWhatsappProvider::class, $service->provider());
        $this->assertSame(NotificationLog::STATUS_SKIPPED, $log->status);
        $this->assertSame('+255788000001', $log->recipient_phone);
        $this->assertSame(NotificationLog::PROVIDER_META, $log->provider);
        $this->assertSame('WhatsApp credentials are not configured.', $log->error_message);
        Http::assertNothingSent();
    }

    public function test_invalid_phone_numbers_are_rejected_and_logged_without_sending(): void
    {
        Http::fake();

        $log = app(NotificationService::class)
            ->sendWhatsappText('25578800001', 'Hello guest.', [
                'message_type' => NotificationLog::TYPE_GENERAL_UPDATE,
            ]);

        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
        $this->assertSame('25578800001', $log->recipient_phone);
        $this->assertSame('Invalid phone number format.', $log->error_message);
        $this->assertDatabaseCount('notification_logs', 1);
        Http::assertNothingSent();
    }

    public function test_meta_provider_is_used_when_credentials_are_configured(): void
    {
        config([
            'services.whatsapp.provider' => 'meta',
            'services.whatsapp.meta.api_base_url' => 'https://graph.facebook.com',
            'services.whatsapp.meta.api_version' => 'v21.0',
            'services.whatsapp.meta.access_token' => 'test-access-token',
            'services.whatsapp.meta.phone_number_id' => '123456789',
        ]);
        Http::fake([
            'https://graph.facebook.com/v21.0/123456789/messages' => Http::response([
                'messages' => [
                    ['id' => 'wamid.test-message-id'],
                ],
            ], 200),
        ]);

        $service = app(NotificationService::class);
        $log = $service->sendWhatsappTemplate('0788000001', 'guest_code', ['5820'], [
            'message_type' => NotificationLog::TYPE_GUEST_CODE,
        ]);

        $this->assertInstanceOf(MetaWhatsappProvider::class, $service->provider());
        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);
        $this->assertSame('wamid.test-message-id', $log->provider_message_id);
        $this->assertNotNull($log->sent_at);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://graph.facebook.com/v21.0/123456789/messages'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $request['to'] === '255788000001'
                && $request['type'] === 'template'
                && $request['template']['name'] === 'guest_code';
        });
    }

    public function test_notification_template_config_exists_and_declares_supported_placeholders(): void
    {
        $this->assertFileExists(config_path('notification_templates.php'));
        $this->assertSame([
            'manual_code',
            'phone_number',
            'event_name',
            'allowed_entries',
            'pledge_amount',
            'pledge_balance',
            'meeting_date',
            'meeting_venue',
            'card_link',
        ], config('notification_templates.supported_placeholders'));
    }

    public function test_guest_code_notification_uses_configured_message_template(): void
    {
        Http::fake();
        $guest = $this->guestWithQr('5820');

        $log = app(NotificationService::class)->sendGuestCodeNotification($guest);

        $this->assertSame(
            'Your guest verification code is 5820. Please keep it safe for entrance verification.',
            $log->message
        );
        $this->assertSame('guest_code', $log->template_name);
        $this->assertSame('+255788000001', $log->recipient_phone);
    }

    public function test_missing_placeholders_do_not_crash_template_rendering(): void
    {
        Http::fake();
        $guest = $this->guestWithQr('5820');

        $log = app(NotificationService::class)->sendNextMeetingNotification($guest);

        $this->assertSame(
            'Reminder: The next meeting is scheduled for . Venue: .',
            $log->message
        );
        $this->assertSame(NotificationLog::STATUS_SKIPPED, $log->status);
    }

    public function test_configurable_meta_template_name_is_used_for_named_notifications(): void
    {
        config([
            'services.whatsapp.provider' => 'meta',
            'services.whatsapp.meta.api_base_url' => 'https://graph.facebook.com',
            'services.whatsapp.meta.api_version' => 'v21.0',
            'services.whatsapp.meta.access_token' => 'test-access-token',
            'services.whatsapp.meta.phone_number_id' => '123456789',
            'notification_templates.whatsapp_templates.guest_code' => 'approved_guest_code_v2',
        ]);
        Http::fake([
            'https://graph.facebook.com/v21.0/123456789/messages' => Http::response([
                'messages' => [
                    ['id' => 'wamid.configured-template'],
                ],
            ], 200),
        ]);
        $guest = $this->guestWithQr('5820');

        $log = app(NotificationService::class)->sendGuestCodeNotification($guest);

        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);
        $this->assertSame('approved_guest_code_v2', $log->template_name);

        Http::assertSent(function ($request): bool {
            return $request['type'] === 'template'
                && $request['template']['name'] === 'approved_guest_code_v2'
                && $request['template']['components'][0]['parameters'][0]['text'] === '5820';
        });
    }

    public function test_notification_templates_do_not_include_qr_tokens(): void
    {
        $configText = json_encode(config('notification_templates'), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('qr_token', $configText);
        $this->assertStringNotContainsString('{token}', $configText);
        $this->assertStringContainsString('{manual_code}', $configText);
    }

    public function test_guest_code_notification_logs_manual_code_without_qr_token(): void
    {
        Http::fake();
        $guest = $this->guestWithQr('5820');

        $log = app(NotificationService::class)->sendGuestCodeNotification($guest);

        $this->assertSame(NotificationLog::STATUS_SKIPPED, $log->status);
        $this->assertSame(NotificationLog::TYPE_GUEST_CODE, $log->message_type);
        $this->assertStringContainsString('5820', (string) $log->message);
        $this->assertStringNotContainsString($guest->qrCode->qr_token, (string) $log->message);
        $this->assertSame('5820', $log->context['manual_code']);
        $this->assertArrayNotHasKey('qr_token', $log->context);
        $this->assertArrayNotHasKey('token', $log->context);
    }

    private function guestWithQr(string $manualCode): Guest
    {
        $guest = Guest::query()->create([
            'name' => 'WhatsApp Guest',
            'phone_number' => '0788000001',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
            'used_entries' => 0,
            'status' => Guest::STATUS_ACTIVE,
            'manual_code' => $manualCode,
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
