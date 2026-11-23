<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Working Flow 11 — read-only setup progress for one already-authorized event.
 *
 * Every step is derived from persisted rows; nothing is written and no percentage
 * is stored. Steps without backend data yet (RSVP) are "unavailable" and are left
 * out of the applicable total, so they can never count as completed.
 */
class EventSetupProgress
{
    /** Order and items follow reference UI/30_Event_Setup_Progress.png. */
    public const STEPS = ['details', 'guests', 'invitations', 'rsvp', 'scanner', 'reports'];

    public const COMPLETED = 'completed';

    public const IN_PROGRESS = 'in_progress';

    public const NOT_STARTED = 'not_started';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @return array{steps: list<array{key: string, status: string, counts: array<string, int>}>, completed: int, total: int, percent: int, current: ?string}
     */
    public function forEvent(Connection $connection, object $event): array
    {
        $eventId = (int) $event->id;
        $stats = $connection->table('events as e')->useWritePdo()->where('e.id', $eventId)
            ->selectSub(fn (Builder $q) => $q->from('guests as g')->whereColumn('g.event_id', 'e.id')
                ->where('g.status', '!=', Guest::STATUS_CANCELLED)->selectRaw('count(*)'), 'guests')
            ->selectSub(fn (Builder $q) => $q->from('notification_logs as n')
                ->join('guests as ig', 'ig.id', '=', 'n.guest_id')
                ->whereColumn('n.event_id', 'e.id')->whereColumn('ig.event_id', 'e.id')
                ->where('ig.status', '!=', Guest::STATUS_CANCELLED)
                ->where('n.message_type', NotificationLog::TYPE_DIGITAL_CARD)
                ->where('n.status', NotificationLog::STATUS_SENT)
                ->selectRaw('count(distinct n.guest_id)'), 'invited')
            ->selectSub(fn (Builder $q) => $q->from('users as s')->whereColumn('s.event_id', 'e.id')
                ->where('s.role', 'scanner')->where('s.is_active', true)->selectRaw('count(*)'), 'scanners')
            ->selectSub(fn (Builder $q) => $q->from('checkins as c')->whereColumn('c.event_id', 'e.id')->selectRaw('count(*)'), 'checkins')
            ->first();

        $guests = (int) ($stats->guests ?? 0);
        $invited = min((int) ($stats->invited ?? 0), $guests);
        $scanners = (int) ($stats->scanners ?? 0);
        $checkins = (int) ($stats->checkins ?? 0);

        $detailFields = [filled($event->event_name ?? null), filled($event->event_date ?? null), filled($event->venue_name ?? null)];
        $filledDetails = count(array_filter($detailFields));

        $steps = [
            ['key' => 'details', 'status' => match (true) {
                $filledDetails === 3 => self::COMPLETED,
                $filledDetails > 0 => self::IN_PROGRESS,
                default => self::NOT_STARTED,
            }, 'counts' => ['missing' => 3 - $filledDetails]],
            ['key' => 'guests', 'status' => $guests > 0 ? self::COMPLETED : self::NOT_STARTED, 'counts' => ['guests' => $guests]],
            ['key' => 'invitations', 'status' => match (true) {
                $guests > 0 && $invited >= $guests => self::COMPLETED,
                $invited > 0 => self::IN_PROGRESS,
                default => self::NOT_STARTED,
            }, 'counts' => ['sent' => $invited, 'guests' => $guests]],
            // No RSVP records exist in the schema yet: never completed, never counted.
            ['key' => 'rsvp', 'status' => self::UNAVAILABLE, 'counts' => []],
            ['key' => 'scanner', 'status' => $scanners > 0 ? self::COMPLETED : self::NOT_STARTED, 'counts' => ['scanners' => $scanners]],
            ['key' => 'reports', 'status' => $checkins > 0 ? self::COMPLETED : self::NOT_STARTED, 'counts' => ['checkins' => $checkins]],
        ];

        $applicable = array_values(array_filter($steps, fn (array $step) => $step['status'] !== self::UNAVAILABLE));
        $completed = count(array_filter($applicable, fn (array $step) => $step['status'] === self::COMPLETED));
        $total = count($applicable);
        $current = null;
        foreach ($applicable as $step) {
            if ($step['status'] !== self::COMPLETED) {
                $current = $step['key'];
                break;
            }
        }

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => $total,
            'percent' => $total === 0 ? 0 : (int) round($completed * 100 / $total),
            'current' => $current,
            'lifecycle' => in_array($event->status ?? null, [Event::STATUS_ARCHIVED, Event::STATUS_CANCELLED], true) ? $event->status : Event::STATUS_ACTIVE,
        ];
    }
}
