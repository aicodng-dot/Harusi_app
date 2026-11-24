<?php

namespace App\Services\Sharing;

use Illuminate\Support\Facades\DB;

/**
 * What the application genuinely knows about manual sharing:
 *  - initiated: the customer opened WhatsApp / SMS / the share sheet for this guest;
 *  - confirmed: the customer told us they pressed Send;
 *  - skipped:   the customer chose not to send to this guest (in a guided session).
 * There is deliberately no delivered/read state: native apps give no such evidence.
 *
 * One row per (guest, channel, session); individual sharing uses session NULL.
 * Recording never touches the guest, the QR code or admissions.
 */
class ShareRecords
{
    public const STATUSES = ['initiated', 'confirmed', 'skipped'];

    public const ACTIONS = ['initiated', 'confirmed', 'skipped', 'unconfirm'];

    /** Overall per-guest order: a confirmed send outranks an open attempt, which outranks a skip. */
    private const RANK = ['skipped' => 1, 'initiated' => 2, 'confirmed' => 3];

    /** @return array{status: ?string, id: ?int} the row's status after the action */
    public function record(int $eventId, int $guestId, ?int $userId, string $channel, string $action, ?int $sessionId = null, ?int $templateId = null): array
    {
        return DB::transaction(function () use ($eventId, $guestId, $userId, $channel, $action, $sessionId, $templateId): array {
            $query = DB::table('invitation_shares')->useWritePdo()->where('event_id', $eventId)->where('guest_id', $guestId)->where('channel', $channel);
            $sessionId === null ? $query->whereNull('session_id') : $query->where('session_id', $sessionId);
            $row = $query->lockForUpdate()->orderBy('id')->first();
            $now = now();

            if ($row === null) {
                if ($action === 'unconfirm') {
                    return ['status' => null, 'id' => null];
                }
                $id = DB::table('invitation_shares')->insertGetId([
                    'event_id' => $eventId, 'guest_id' => $guestId, 'session_id' => $sessionId, 'user_id' => $userId,
                    'template_id' => $templateId, 'channel' => $channel, 'status' => $action,
                    'attempts' => $action === 'initiated' ? 1 : 0,
                    'initiated_at' => $action === 'initiated' ? $now : null,
                    'confirmed_at' => $action === 'confirmed' ? $now : null,
                    'skipped_at' => $action === 'skipped' ? $now : null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                return ['status' => $action, 'id' => (int) $id];
            }

            $update = ['updated_at' => $now, 'user_id' => $userId ?? $row->user_id];
            if ($templateId !== null) {
                $update['template_id'] = $templateId;
            }
            switch ($action) {
                case 'initiated':
                    $update['attempts'] = min(65535, (int) $row->attempts + 1);
                    $update['initiated_at'] = $now;
                    // Opening the app again does not undo a confirmation.
                    $update['status'] = $row->status === 'confirmed' ? 'confirmed' : 'initiated';
                    break;
                case 'confirmed':
                    $update['status'] = 'confirmed';
                    // Idempotent: a double tap keeps the first confirmation time.
                    $update['confirmed_at'] = $row->confirmed_at ?? $now;
                    break;
                case 'skipped':
                    $update['skipped_at'] = $now;
                    $update['status'] = $row->status === 'confirmed' ? 'confirmed' : 'skipped';
                    break;
                case 'unconfirm':
                    // "I did not actually send it": back to the last thing that really happened.
                    $update['confirmed_at'] = null;
                    $update['status'] = $row->initiated_at !== null ? 'initiated' : ($row->skipped_at !== null ? 'skipped' : 'initiated');
                    break;
            }
            DB::table('invitation_shares')->where('id', $row->id)->update($update);

            return ['status' => $update['status'] ?? $row->status, 'id' => (int) $row->id];
        });
    }

    /**
     * Overall sharing state per guest across channels and sessions.
     *
     * @return array<int, array{status: string, channel: string, at: ?string}>
     */
    public function statuses(int $eventId, array $guestIds, ?int $sessionId = null): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_map('intval', $guestIds)), 900) as $chunk) {
            $query = DB::table('invitation_shares')->where('event_id', $eventId)->whereIn('guest_id', $chunk);
            if ($sessionId !== null) {
                $query->where('session_id', $sessionId);
            }
            foreach ($query->get(['guest_id', 'channel', 'status', 'updated_at']) as $row) {
                $id = (int) $row->guest_id;
                $current = $out[$id] ?? null;
                $rank = self::RANK[$row->status] ?? 0;
                if ($current === null || $rank > self::RANK[$current['status']]
                    || ($rank === self::RANK[$current['status']] && (string) $row->updated_at > (string) $current['at'])) {
                    $out[$id] = ['status' => (string) $row->status, 'channel' => (string) $row->channel, 'at' => $row->updated_at];
                }
            }
        }

        return $out;
    }

    /** @return array<int, array{status: string, channel: string, at: ?string}> for every guest of the event */
    public function eventStatuses(int $eventId): array
    {
        $ids = DB::table('invitation_shares')->where('event_id', $eventId)->distinct()->pluck('guest_id')->map(fn ($id) => (int) $id)->all();

        return $this->statuses($eventId, $ids);
    }
}
