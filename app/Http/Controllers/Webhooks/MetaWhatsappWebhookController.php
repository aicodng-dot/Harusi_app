<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MetaWhatsappWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));
        $expectedToken = (string) config('services.whatsapp.meta.webhook_verify_token', '');

        if ($expectedToken !== '' && $mode === 'subscribe' && hash_equals($expectedToken, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403)->header('Content-Type', 'text/plain');
    }

    /**
     * Status updates are applied only when the X-Hub-Signature-256 HMAC verifies with
     * WHATSAPP_APP_SECRET; anything else is acknowledged and ignored. Statuses only
     * move forward (sent -> delivered -> read), so duplicated or out-of-order webhooks
     * cannot regress a log, and `failed` never overrides a confirmed delivery.
     * The payload itself is never logged.
     */
    public function receive(Request $request): JsonResponse
    {
        $secret = (string) config('services.whatsapp.meta.app_secret', '');
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $verified = $secret !== '' && $signature !== ''
            && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);

        if ($verified) {
            foreach ((array) $request->json('entry', []) as $entry) {
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                        $this->applyStatus($status);
                    }
                }
            }
        }

        return response()->json([
            'ok' => true,
            'provider' => 'meta',
        ]);
    }

    private function applyStatus(mixed $status): void
    {
        $rank = [NotificationLog::STATUS_PENDING => 0, NotificationLog::STATUS_SENT => 1, NotificationLog::STATUS_DELIVERED => 2, NotificationLog::STATUS_READ => 3];
        $id = is_array($status) ? ($status['id'] ?? null) : null;
        $value = is_array($status) ? ($status['status'] ?? null) : null;
        if (! is_string($id) || $id === '' || strlen($id) > 120 || ! in_array($value, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        DB::transaction(function () use ($id, $value, $status, $rank): void {
            $log = NotificationLog::query()->where('provider_message_id', $id)->lockForUpdate()->first();
            if ($log === null) {
                return;
            }
            $current = $rank[$log->status] ?? null;
            if ($value === 'failed') {
                if ($current !== null && $current >= 2) {
                    return;
                }
                $error = $status['errors'][0]['title'] ?? $status['errors'][0]['message'] ?? 'Delivery failed.';
                $log->update(['status' => NotificationLog::STATUS_FAILED, 'error_message' => mb_substr((string) $error, 0, 500)]);

                return;
            }
            if ($current !== null && $rank[$value] <= $current) {
                return;
            }
            $log->update(['status' => $value]);
        });
    }
}
