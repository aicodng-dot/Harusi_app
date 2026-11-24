<?php

namespace App\Services\Invitations;

use App\Models\Guest;
use App\Models\NotificationLog;
use App\Services\Notifications\MetaWhatsappProvider;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Sends generated invitation cards through the existing WhatsApp notification
 * pipeline (NotificationService -> Meta Cloud API) and reports honest statuses:
 *  - `sent` only means Meta accepted the request; `delivered`/`read`/`failed` come from
 *    verified status webhooks (MetaWhatsappWebhookController).
 *  - When WhatsApp is not configured nothing is attempted and the UI says so; no
 *    success is simulated.
 * Duplicate protection: a per-guest lock plus a check for an earlier accepted/pending
 * invitation message; resending requires an explicit owner choice.
 */
class InvitationDelivery
{
    public const ACTIVE = [NotificationLog::STATUS_PENDING, NotificationLog::STATUS_SENT, NotificationLog::STATUS_DELIVERED, NotificationLog::STATUS_READ];

    public function __construct(private NotificationService $notifications) {}

    public function configured(): bool
    {
        return $this->notifications->provider() instanceof MetaWhatsappProvider;
    }

    /**
     * @param  list<int>  $guestIds  already verified to belong to $event
     * @return list<array{guest_id: int, status: string}>
     */
    public function send(object $event, array $guestIds, int $userId, InvitationStudio $studio, string $language, bool $resend = false): array
    {
        if (! $this->configured()) {
            return array_map(fn ($id) => ['guest_id' => (int) $id, 'status' => 'not_configured'], $guestIds);
        }
        ['doc' => $doc] = $studio->design($event, $language);
        ['spec' => $spec] = $studio->spec($event, $doc);
        $states = $studio->cardStates($event, $guestIds, $spec);
        $template = (string) config('services.whatsapp.meta.invitation_template', '');
        $results = [];

        foreach ($guestIds as $guestId) {
            $guestId = (int) $guestId;
            if (($states[$guestId]['state'] ?? 'none') !== 'current') {
                $results[] = ['guest_id' => $guestId, 'status' => 'needs_card'];

                continue;
            }
            $lock = Cache::lock('invitation-send:'.$event->id.':'.$guestId, 60);
            if (! $lock->get()) {
                $results[] = ['guest_id' => $guestId, 'status' => 'in_progress'];

                continue;
            }
            try {
                $guest = Guest::query()->whereKey($guestId)->where('event_id', $event->id)->firstOrFail();
                if (trim((string) $guest->phone_number) === '') {
                    // Guests may be added without a phone; their card can still be downloaded and shared by hand.
                    $results[] = ['guest_id' => $guestId, 'status' => 'no_phone'];

                    continue;
                }
                if (! $resend && $this->latest((int) $event->id, $guestId) !== null
                    && in_array($this->latest((int) $event->id, $guestId)->status, self::ACTIVE, true)) {
                    $results[] = ['guest_id' => $guestId, 'status' => 'already_sent'];

                    continue;
                }
                $card = $studio->card($event, $guestId);
                $url = URL::temporarySignedRoute('invitations.media', now()->addHours(48), ['card' => $card->id, 'v' => substr($card->design_fingerprint, 0, 16)]);
                $context = ['guest_id' => $guestId, 'event_id' => (int) $event->id, 'message_type' => NotificationLog::TYPE_DIGITAL_CARD, 'invitation_card_id' => (int) $card->id];
                $log = $template !== ''
                    ? $this->notifications->sendWhatsappTemplate($guest->phone_number, $template, [$guest->name, $event->event_name],
                        $context + ['header_image_url' => $url, 'language_code' => (string) config('services.whatsapp.meta.invitation_template_language', 'sw')])
                    : $this->notifications->sendWhatsappMedia($guest->phone_number, $url, (string) $event->event_name, $context);
                $studio->activity((int) $event->id, $userId, 'whatsapp_'.$log->status, $guestId, ['log_id' => $log->id]);
                $results[] = ['guest_id' => $guestId, 'status' => $log->status];
            } finally {
                $lock->release();
            }
        }

        return $results;
    }

    public function latest(int $eventId, int $guestId): ?NotificationLog
    {
        return NotificationLog::query()->where('event_id', $eventId)->where('guest_id', $guestId)
            ->where('message_type', NotificationLog::TYPE_DIGITAL_CARD)->orderByDesc('id')->first();
    }

    /** @return array<int, string> guest id => latest invitation message status */
    public function statuses(int $eventId, array $guestIds): array
    {
        if ($guestIds === []) {
            return [];
        }
        $latest = DB::table('notification_logs')->where('event_id', $eventId)->where('message_type', NotificationLog::TYPE_DIGITAL_CARD)
            ->whereIn('guest_id', $guestIds)->selectRaw('guest_id, MAX(id) as id')->groupBy('guest_id')->pluck('id');

        return DB::table('notification_logs')->whereIn('id', $latest)->pluck('status', 'guest_id')->mapWithKeys(fn ($s, $g) => [(int) $g => (string) $s])->all();
    }
}
