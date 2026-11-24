<?php

namespace App\Services\Sharing;

use App\Models\Event;
use App\Models\Guest;
use App\Services\Rsvp\RsvpService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Personal invitation links (/i/{key}) that the customer pastes into WhatsApp or SMS.
 *
 * - The key is 32 random URL-safe characters (~190 bits). Only its SHA-256 is used to
 *   find the link; the key is stored encrypted (APP_KEY) so the same link can be copied again.
 * - A link shows exactly one guest's own card and only public event facts. It never
 *   shows the phone number, other guests or any account data.
 * - A link is bound to the guest's QR credential by fingerprint. It is only issued when the
 *   guest has an active credential and a card generated for that credential, and it stops
 *   working when the guest is cancelled, the credential is revoked or replaced, the card is
 *   removed, or the event is no longer active.
 * - Creating or opening a link never changes the guest, the QR code or admissions.
 */
class InvitationLinks
{
    /**
     * Shareable state per guest: ['url' => ?string, 'reason' => ok|no_card|revoked|cancelled].
     *
     * @param  list<int>  $guestIds  already verified to belong to $event
     * @return array<int, array{url: ?string, reason: string}>
     */
    public function forGuests(object $event, array $guestIds, bool $create = true): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $guestIds))), 500) as $chunk) {
            $guests = DB::table('guests')->where('event_id', $event->id)->whereIn('id', $chunk)->pluck('status', 'id');
            $qrs = DB::table('qr_codes')->whereIn('guest_id', $chunk)->orderBy('id')->get(['guest_id', 'qr_token', 'is_active', 'revoked_at'])
                ->unique('guest_id')->keyBy('guest_id');
            $cards = DB::table('invitation_cards')->where('event_id', $event->id)->whereIn('guest_id', $chunk)->pluck('token_fingerprint', 'guest_id');
            $links = DB::table('invitation_links')->where('event_id', $event->id)->whereIn('guest_id', $chunk)->get()->keyBy('guest_id');

            foreach ($chunk as $id) {
                if (! isset($guests[$id])) {
                    continue;
                }
                $qr = $qrs[$id] ?? null;
                $reason = match (true) {
                    $guests[$id] === Guest::STATUS_CANCELLED => 'cancelled',
                    $qr !== null && (! $qr->is_active || $qr->revoked_at !== null) => 'revoked',
                    $qr === null || ! isset($cards[$id]) || $cards[$id] !== self::fingerprint($qr->qr_token) => 'no_card',
                    default => 'ok',
                };
                if ($reason !== 'ok') {
                    $out[$id] = ['url' => null, 'reason' => $reason];

                    continue;
                }
                $fingerprint = self::fingerprint($qr->qr_token);
                $link = $links[$id] ?? null;
                $key = null;
                if ($link !== null && hash_equals($link->token_fingerprint, $fingerprint)) {
                    try {
                        $key = Crypt::decryptString($link->key_encrypted);
                    } catch (DecryptException) {
                        $key = null; // APP_KEY changed: issue a fresh link below.
                    }
                }
                if ($key === null && $create) {
                    $key = $this->issue((int) $event->id, $id, $fingerprint);
                }
                // Without $create a guest can be "ok" (shareable) before a link has been issued.
                $out[$id] = ['url' => $key === null ? null : route('invitations.public', ['key' => $key]), 'reason' => $key === null && $create ? 'no_card' : 'ok'];
            }
            // Issuing a personal link is issuing the invitation: the guest gets a pending RSVP.
            $issued = array_keys(array_filter($out, fn ($row, $id) => in_array($id, $chunk, true) && $row['url'] !== null, ARRAY_FILTER_USE_BOTH));
            if ($issued !== []) {
                app(RsvpService::class)->issue((int) $event->id, $issued);
            }
        }

        return $out;
    }

    public function urlFor(object $event, int $guestId): ?string
    {
        return $this->forGuests($event, [$guestId])[$guestId]['url'] ?? null;
    }

    /**
     * The guest, event and card behind a public key, or null when the link is unknown or no
     * longer valid (always the same answer to the visitor, so nothing is revealed).
     *
     * @return array{event: object, guest: object, card: object}|null
     */
    public function resolve(string $key): ?array
    {
        if (preg_match('/\A[A-Za-z0-9_-]{32}\z/', $key) !== 1) {
            return null;
        }
        $link = DB::table('invitation_links')->where('key_hash', hash('sha256', $key))->first();
        if ($link === null) {
            return null;
        }
        $guest = DB::table('guests')->where('id', $link->guest_id)->where('event_id', $link->event_id)
            ->first(['id', 'event_id', 'name', 'pass_type', 'allowed_entries', 'status']);
        $event = DB::table('events')->where('id', $link->event_id)
            ->first(['id', 'event_name', 'bride_name', 'groom_name', 'venue_name', 'event_date', 'event_time', 'event_type', 'status']);
        $qr = DB::table('qr_codes')->where('guest_id', $link->guest_id)->orderBy('id')->first(['qr_token', 'is_active', 'revoked_at']);
        $card = DB::table('invitation_cards')->where('event_id', $link->event_id)->where('guest_id', $link->guest_id)->first();
        if ($guest === null || $event === null || $qr === null || $card === null
            || $guest->status === Guest::STATUS_CANCELLED || $event->status !== Event::STATUS_ACTIVE
            || ! $qr->is_active || $qr->revoked_at !== null) {
            return null;
        }
        $fingerprint = self::fingerprint($qr->qr_token);
        if (! hash_equals($link->token_fingerprint, $fingerprint) || ! hash_equals((string) $card->token_fingerprint, $fingerprint)) {
            return null;
        }

        return ['event' => $event, 'guest' => $guest, 'card' => $card];
    }

    /** Same fingerprint the generated cards use (never the token itself). */
    public static function fingerprint(string $token): string
    {
        return hash('sha256', 'invitation-card|'.$token);
    }

    private function issue(int $eventId, int $guestId, string $fingerprint): string
    {
        // Lock the guest row so two tabs asking at once cannot hand out two different links.
        return DB::transaction(function () use ($eventId, $guestId, $fingerprint): string {
            DB::table('guests')->where('id', $guestId)->where('event_id', $eventId)->lockForUpdate()->first(['id']);
            $link = DB::table('invitation_links')->useWritePdo()->where('guest_id', $guestId)->first();
            if ($link !== null && (int) $link->event_id === $eventId && hash_equals($link->token_fingerprint, $fingerprint)) {
                try {
                    return Crypt::decryptString($link->key_encrypted);
                } catch (DecryptException) {
                    // fall through and replace it
                }
            }
            $key = self::randomKey();
            DB::table('invitation_links')->upsert([[
                'event_id' => $eventId, 'guest_id' => $guestId, 'key_hash' => hash('sha256', $key),
                'key_encrypted' => Crypt::encryptString($key), 'token_fingerprint' => $fingerprint,
                'created_at' => now(), 'updated_at' => now(),
            ]], ['guest_id'], ['event_id', 'key_hash', 'key_encrypted', 'token_fingerprint', 'updated_at']);

            return $key;
        });
    }

    private static function randomKey(): string
    {
        return substr(rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='), 0, 32);
    }
}
