<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_creation_does_not_create_notification_logs(): void
    {
        Guest::query()->create([
            'name' => 'WhatsApp Log Guest',
            'phone_number' => '0788000001',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
            'used_entries' => 0,
            'status' => Guest::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_notification_log_records_whatsapp_tracking_state(): void
    {
        $guest = Guest::query()->create([
            'name' => 'WhatsApp Tracking Guest',
            'phone_number' => '0788000001',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
            'used_entries' => 0,
            'status' => Guest::STATUS_ACTIVE,
        ]);

        $log = NotificationLog::query()->create([
            'guest_id' => $guest->id,
            'event_id' => $guest->event_id,
            'channel' => NotificationLog::CHANNEL_WHATSAPP,
            'message_type' => NotificationLog::TYPE_GUEST_CODE,
            'template_name' => 'guest_code',
            'recipient_phone' => '+255788000001',
            'message' => 'Your guest code is 5820.',
            'status' => NotificationLog::STATUS_SIMULATED,
            'provider' => NotificationLog::PROVIDER_META,
            'provider_message_id' => null,
            'error_message' => null,
            'context' => ['manual_code' => '5820'],
            'sent_at' => null,
        ]);

        $this->assertDatabaseHas('notification_logs', [
            'id' => $log->id,
            'guest_id' => $guest->id,
            'event_id' => $guest->event_id,
            'channel' => 'whatsapp',
            'message_type' => 'guest_code',
            'status' => 'simulated',
            'provider' => 'meta',
        ]);
        $this->assertSame(['manual_code' => '5820'], $log->fresh()->context);
    }
}
