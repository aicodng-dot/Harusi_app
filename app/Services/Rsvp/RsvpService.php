<?php

namespace App\Services\Rsvp;

use App\Models\Event;
use App\Models\Guest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Guest RSVP (docs/RSVP_AND_GUEST_TICKETS.md).
 *
 * Three separate state models stay separate: invitation sharing (InvitationShares), RSVP
 * (this service) and admission (guests.used_entries / check-ins). An RSVP never changes the
 * guest, the pass capacity, the QR code or admissions; the expected headcount is planning data.
 *
 * Rules (owner-configurable per event):
 *  - A pending RSVP row is created when the guest's personal invitation link is issued
 *    (invited_at = "invitation issued", not "received").
 *  - Guests may answer while the event is active, RSVP is on and the closing time has not
 *    passed. Closing time = the owner's deadline, else the end of the event day in Tanzania
 *    time, else open while the event is active.
 *  - After a first answer a guest may change it only if the owner allows changes. There is no
 *    guest reset to pending. The owner can record an answer at any time (e.g. a phone call).
 *  - Every accepted submission is keyed: an exact replay returns the current state unchanged,
 *    the same key with different content is a conflict, and a stale revision is a conflict.
 *    Repeating the current answer with a new key records a no-change receipt.
 */
class RsvpService
{
    public const RESPONSES = ['attending', 'not_attending'];

    public const STATES = ['pending', 'attending', 'not_attending'];

    public const TIMEZONE = 'Africa/Dar_es_Salaam';

    public const NOTE_MAX = 300;

    /** @return array{enabled: bool, deadline: ?Carbon, allow_changes: bool, closes_at: ?Carbon} */
    public function settings(int $eventId): array
    {
        $row = DB::table('events')->useWritePdo()->where('id', $eventId)->first(['event_date', 'rsvp_enabled', 'rsvp_deadline', 'rsvp_allow_changes']);
        abort_if($row === null, 404);
        $deadline = $row->rsvp_deadline === null ? null : Carbon::parse($row->rsvp_deadline, 'UTC');
        $closes = $deadline;
        if ($closes === null && ! empty($row->event_date)) {
            $closes = Carbon::parse(substr((string) $row->event_date, 0, 10).' 23:59:59', self::TIMEZONE)->utc();
        }

        return ['enabled' => (bool) $row->rsvp_enabled, 'deadline' => $deadline, 'allow_changes' => (bool) $row->rsvp_allow_changes, 'closes_at' => $closes];
    }

    public function isOpen(object $event, array $settings): bool
    {
        return $event->status === Event::STATUS_ACTIVE && $settings['enabled']
            && ($settings['closes_at'] === null || now()->lt($settings['closes_at']));
    }

    /** Creates pending RSVP rows for guests whose invitation has been issued (idempotent). */
    public function issue(int $eventId, array $guestIds): void
    {
        $now = now();
        foreach (array_chunk(array_values(array_unique(array_map('intval', $guestIds))), 500) as $chunk) {
            $owned = DB::table('guests')->where('event_id', $eventId)->whereIn('id', $chunk)->pluck('id');
            DB::table('guest_rsvps')->insertOrIgnore($owned->map(fn ($id) => [
                'event_id' => $eventId, 'guest_id' => (int) $id, 'response' => 'pending', 'invited_at' => $now,
                'revision' => 0, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }
    }

    public function forGuest(int $eventId, int $guestId): ?object
    {
        return DB::table('guest_rsvps')->useWritePdo()->where('event_id', $eventId)->where('guest_id', $guestId)->first();
    }

    /** Plain text, single spaces kept, at most NOTE_MAX characters; '' becomes null. */
    public static function cleanNote(?string $note): ?string
    {
        $note = strip_tags((string) $note);
        $note = (string) preg_replace('/[^\P{C}\n]+/u', '', str_replace(["\r\n", "\r"], "\n", $note));
        $note = trim((string) preg_replace("/\n{3,}/", "\n\n", $note));

        return $note === '' ? null : mb_substr($note, 0, self::NOTE_MAX);
    }

    /**
     * @param  string  $source  'guest' (personal link) or 'owner' (recorded by the event owner/manager)
     * @return array{status: string, rsvp: ?object}
     *                                               status: saved | no_change | replay | key_conflict | stale | closed | locked | invalid | unavailable
     */
    public function submit(object $event, int $guestId, string $response, ?int $count, ?string $note, ?int $expectedRevision, string $key, string $source, ?int $userId = null): array
    {
        if (! in_array($response, self::RESPONSES, true) || strlen($key) < 16 || strlen($key) > 128) {
            return ['status' => 'invalid', 'rsvp' => null];
        }

        return DB::transaction(function () use ($event, $guestId, $response, $count, $note, $expectedRevision, $key, $source, $userId): array {
            // Lock order: guest, then RSVP row (same order everywhere).
            $guest = DB::table('guests')->useWritePdo()->where('id', $guestId)->where('event_id', $event->id)->lockForUpdate()->first(['id', 'allowed_entries', 'status']);
            if ($guest === null || $guest->status === Guest::STATUS_CANCELLED) {
                return ['status' => 'unavailable', 'rsvp' => null];
            }
            $rsvp = DB::table('guest_rsvps')->useWritePdo()->where('event_id', $event->id)->where('guest_id', $guestId)->lockForUpdate()->first();
            if ($rsvp === null) {
                if ($source !== 'owner') {
                    return ['status' => 'unavailable', 'rsvp' => null];
                }
                $this->issue((int) $event->id, [$guestId]);
                $rsvp = DB::table('guest_rsvps')->useWritePdo()->where('event_id', $event->id)->where('guest_id', $guestId)->lockForUpdate()->first();
            }

            $allowed = max(1, (int) $guest->allowed_entries);
            if ($response === 'attending') {
                $count ??= 1;
                if ($count < 1 || $count > $allowed) {
                    return ['status' => 'invalid', 'rsvp' => $rsvp];
                }
            } else {
                $count = null;
            }
            $note = self::cleanNote($note);
            $fingerprint = hash('sha256', $response.'|'.($count ?? '').'|'.($note ?? ''));
            $keyHash = hash('sha256', $key);

            $receipt = DB::table('guest_rsvp_submissions')->useWritePdo()->where('rsvp_id', $rsvp->id)->where('request_key_hash', $keyHash)->first();
            if ($receipt !== null) {
                // A lost response, refresh or double tap: never re-apply (an old YES must not undo a later NO).
                return ['status' => hash_equals($receipt->request_fingerprint, $fingerprint) ? 'replay' : 'key_conflict', 'rsvp' => $rsvp];
            }

            $same = $rsvp->response === $response && (int) ($rsvp->attending_count ?? 0) === (int) ($count ?? 0) && (string) $rsvp->note === (string) $note;
            if ($source === 'guest') {
                $settings = $this->settings((int) $event->id);
                if (! $this->isOpen($event, $settings)) {
                    return ['status' => 'closed', 'rsvp' => $rsvp];
                }
                if (! $same && $rsvp->response !== 'pending' && ! $settings['allow_changes']) {
                    return ['status' => 'locked', 'rsvp' => $rsvp];
                }
                if (! $same && $expectedRevision !== null && $expectedRevision !== (int) $rsvp->revision) {
                    return ['status' => 'stale', 'rsvp' => $rsvp];
                }
            }

            $now = now();
            if (! $same) {
                $previous = $rsvp->response;
                DB::table('guest_rsvps')->where('id', $rsvp->id)->update([
                    'response' => $response, 'attending_count' => $count, 'note' => $note,
                    'responded_at' => $rsvp->responded_at ?? $now,
                    'response_changed_at' => $now,
                    'response_source' => $source, 'revision' => (int) $rsvp->revision + 1, 'updated_at' => $now,
                ]);
            } else {
                $previous = $rsvp->response;
            }
            DB::table('guest_rsvp_submissions')->insert([
                'rsvp_id' => $rsvp->id, 'request_key_hash' => $keyHash, 'request_fingerprint' => $fingerprint,
                'previous_response' => $previous, 'result_response' => $response,
                'result_revision' => $same ? (int) $rsvp->revision : (int) $rsvp->revision + 1,
                'source' => $source, 'user_id' => $userId, 'no_change' => $same, 'accepted_at' => $now,
            ]);

            return ['status' => $same ? 'no_change' : 'saved', 'rsvp' => DB::table('guest_rsvps')->useWritePdo()->where('id', $rsvp->id)->first()];
        });
    }

    /**
     * Event summary. invited = attending + not_attending + pending (issued invitations, not deliveries).
     * expected_people is the sum of the headcounts guests gave: planning only, not capacity or admissions.
     *
     * @return array{invited: int, attending: int, not_attending: int, pending: int, expected_people: int}
     */
    public function summary(int $eventId): array
    {
        $rows = DB::table('guest_rsvps as r')->join('guests as g', 'g.id', '=', 'r.guest_id')
            ->where('r.event_id', $eventId)->where('g.event_id', $eventId)->where('g.status', '!=', Guest::STATUS_CANCELLED)
            ->groupBy('r.response')->selectRaw('r.response, count(*) as n, coalesce(sum(r.attending_count), 0) as people')->get()->keyBy('response');
        $n = fn (string $state) => (int) ($rows[$state]->n ?? 0);

        return [
            'invited' => $n('pending') + $n('attending') + $n('not_attending'),
            'attending' => $n('attending'),
            'not_attending' => $n('not_attending'),
            'pending' => $n('pending'),
            'expected_people' => (int) ($rows['attending']->people ?? 0),
        ];
    }

    /** @return array<int, string> guest id => pending | attending | not_attending (no row = not invited) */
    public function statuses(int $eventId, array $guestIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_map('intval', $guestIds)), 900) as $chunk) {
            foreach (DB::table('guest_rsvps')->where('event_id', $eventId)->whereIn('guest_id', $chunk)->get(['guest_id', 'response']) as $row) {
                $out[(int) $row->guest_id] = (string) $row->response;
            }
        }

        return $out;
    }

    /** Local (Tanzania) wall-clock string for a UTC instant, for forms and display. */
    public static function local(?Carbon $instant, string $format = 'Y-m-d\TH:i'): string
    {
        return $instant === null ? '' : $instant->copy()->setTimezone(self::TIMEZONE)->format($format);
    }
}
