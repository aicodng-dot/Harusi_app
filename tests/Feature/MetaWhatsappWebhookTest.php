<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MetaWhatsappWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_whatsapp_webhook_routes_exist(): void
    {
        $this->assertTrue(Route::has('webhooks.whatsapp.meta.verify'));
        $this->assertTrue(Route::has('webhooks.whatsapp.meta.receive'));
    }

    public function test_meta_whatsapp_webhook_verification_uses_configured_verify_token(): void
    {
        config(['services.whatsapp.meta.webhook_verify_token' => 'local-verify-token']);

        $this->get('/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=local-verify-token&hub.challenge=challenge-123')
            ->assertOk()
            ->assertSeeText('challenge-123');
    }

    public function test_meta_whatsapp_webhook_verification_rejects_invalid_token_without_exposing_secret(): void
    {
        config(['services.whatsapp.meta.webhook_verify_token' => 'local-verify-token']);

        $this->get('/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=wrong-token&hub.challenge=challenge-123')
            ->assertForbidden()
            ->assertDontSee('local-verify-token')
            ->assertDontSee('challenge-123');
    }

    public function test_meta_whatsapp_webhook_post_accepts_future_status_payload_without_creating_logs(): void
    {
        $this->postJson('/webhooks/whatsapp/meta', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.future-status-id',
                            'status' => 'delivered',
                        ]],
                    ],
                ]],
            ]],
        ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'provider' => 'meta',
            ]);

        $this->assertDatabaseCount('notification_logs', 0);
    }
}
