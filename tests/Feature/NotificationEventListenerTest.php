<?php

namespace Tests\Feature;

use App\Events\CongratulationsMessageRequested;
use App\Events\DigitalCardReady;
use App\Events\NextMeetingScheduled;
use App\Events\PaymentCompleted;
use App\Events\PledgeCompleted;
use App\Events\PledgeCreated;
use App\Events\PledgeReminderDue;
use App\Events\ThankYouMessageRequested;
use App\Listeners\SendCongratulationsNotification;
use App\Listeners\SendDigitalCardNotification;
use App\Listeners\SendNextMeetingNotification;
use App\Listeners\SendPaymentCompletedNotification;
use App\Listeners\SendPledgeCompletedNotification;
use App\Listeners\SendPledgeCreatedNotification;
use App\Listeners\SendPledgeReminderNotification;
use App\Listeners\SendThankYouNotification;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NotificationEventListenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_events_and_listeners_exist(): void
    {
        foreach ($this->eventClasses() as $eventClass) {
            $this->assertTrue(class_exists($eventClass), $eventClass.' should exist.');
        }

        foreach ($this->listenerClasses() as $listenerClass) {
            $this->assertTrue(class_exists($listenerClass), $listenerClass.' should exist.');
        }
    }

    public function test_notification_events_are_registered_with_listeners(): void
    {
        foreach ($this->eventClasses() as $eventClass) {
            $this->assertTrue(app('events')->hasListeners($eventClass), $eventClass.' should have a listener.');
        }
    }

    public function test_pledge_created_event_creates_skipped_whatsapp_log_when_credentials_are_missing(): void
    {
        Http::fake();
        $this->forceMissingWhatsappCredentials();
        $guest = $this->guest();

        PledgeCreated::dispatch($guest, [
            'amount' => 100000,
            'balance' => 50000,
        ]);

        $this->assertDatabaseHas('notification_logs', [
            'guest_id' => $guest->id,
            'event_id' => $guest->event_id,
            'channel' => NotificationLog::CHANNEL_WHATSAPP,
            'message_type' => NotificationLog::TYPE_PLEDGE_CREATED,
            'recipient_phone' => '+255788000001',
            'status' => NotificationLog::STATUS_SKIPPED,
            'provider' => NotificationLog::PROVIDER_META,
        ]);

        $log = NotificationLog::query()->firstOrFail();
        $this->assertStringContainsString($guest->manual_code, (string) $log->message);
        $this->assertArrayNotHasKey('qr_token', $log->context);
        Http::assertNothingSent();
    }

    public function test_payment_completed_event_prepares_thank_you_congratulations_and_digital_card_logs(): void
    {
        Http::fake();
        $this->forceMissingWhatsappCredentials();
        $guest = $this->guest();

        PaymentCompleted::dispatch($guest, ['amount' => 100000], ['link' => 'https://example.test/card']);

        $this->assertDatabaseCount('notification_logs', 3);
        $this->assertDatabaseHas('notification_logs', [
            'guest_id' => $guest->id,
            'message_type' => NotificationLog::TYPE_THANK_YOU,
            'status' => NotificationLog::STATUS_SKIPPED,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'guest_id' => $guest->id,
            'message_type' => NotificationLog::TYPE_CONGRATULATIONS,
            'status' => NotificationLog::STATUS_SKIPPED,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'guest_id' => $guest->id,
            'message_type' => NotificationLog::TYPE_DIGITAL_CARD,
            'message' => 'Thank you for completing your payment. Your digital card is ready: https://example.test/card',
            'status' => NotificationLog::STATUS_SKIPPED,
        ]);
    }

    public function test_event_context_qr_tokens_are_stripped_before_logging_or_sending(): void
    {
        Http::fake();
        $this->forceMissingWhatsappCredentials();
        $guest = $this->guest();

        ThankYouMessageRequested::dispatch($guest, [
            'qr_token' => 'secret-qr-token',
            'token' => 'secret-token',
            'manual_code' => $guest->manual_code,
        ]);

        $log = NotificationLog::query()->firstOrFail();
        $this->assertStringNotContainsString('secret-qr-token', (string) $log->message);
        $this->assertStringNotContainsString('secret-token', (string) $log->message);
        $this->assertArrayNotHasKey('qr_token', $log->context);
        $this->assertArrayNotHasKey('token', $log->context);
        $this->assertSame($guest->manual_code, $log->context['manual_code']);
    }

    public function test_notification_listener_failures_do_not_break_event_dispatch(): void
    {
        $notifications = Mockery::mock(NotificationService::class);
        $notifications
            ->shouldReceive('sendPledgeCreatedNotification')
            ->once()
            ->andThrow(new RuntimeException('WhatsApp failed.'));
        $this->app->instance(NotificationService::class, $notifications);

        PledgeCreated::dispatch($this->guest());

        $this->assertDatabaseCount('notification_logs', 0);
    }

    private function forceMissingWhatsappCredentials(): void
    {
        config([
            'services.whatsapp.provider' => 'meta',
            'services.whatsapp.meta.access_token' => '',
            'services.whatsapp.meta.phone_number_id' => '',
        ]);
    }

    private function guest(): Guest
    {
        return Guest::query()->create([
            'name' => 'Notification Event Guest',
            'phone_number' => '0788000001',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
            'used_entries' => 0,
            'status' => Guest::STATUS_ACTIVE,
            'manual_code' => '5820',
        ]);
    }

    /**
     * @return array<int, class-string>
     */
    private function eventClasses(): array
    {
        return [
            PledgeCreated::class,
            PledgeReminderDue::class,
            PledgeCompleted::class,
            PaymentCompleted::class,
            NextMeetingScheduled::class,
            DigitalCardReady::class,
            ThankYouMessageRequested::class,
            CongratulationsMessageRequested::class,
        ];
    }

    /**
     * @return array<int, class-string>
     */
    private function listenerClasses(): array
    {
        return [
            SendPledgeCreatedNotification::class,
            SendPledgeReminderNotification::class,
            SendPledgeCompletedNotification::class,
            SendPaymentCompletedNotification::class,
            SendNextMeetingNotification::class,
            SendDigitalCardNotification::class,
            SendThankYouNotification::class,
            SendCongratulationsNotification::class,
        ];
    }
}
