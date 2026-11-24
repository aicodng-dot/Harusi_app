<?php

namespace App\Services\Events;

use App\Models\Checkin;
use App\Models\Guest;
use Illuminate\Database\Connection;

/**
 * Working Flow 12 — read-only dashboard statistics for one already-authorized event.
 *
 * Guest, invitation and scanner counts come from EventSetupProgress (one query) so the
 * dashboard and the checklist never disagree. Admission entries reuse the existing
 * administrator calculation: permitted = sum of allowed_entries for non-cancelled
 * guests; used = sum of entries_added on admitted check-ins. Individual guests and
 * admission entries are reported separately. RSVP comes from the setup query (attending replies).
 */
class EventDashboardStats
{
    public function __construct(private readonly EventSetupProgress $progress) {}

    public function forEvent(Connection $connection, object $event): array
    {
        $progress = $this->progress->forEvent($connection, $event);
        $counts = collect($progress['steps'])->mapWithKeys(fn (array $step) => [$step['key'] => $step['counts']]);
        $eventId = (int) $event->id;

        $admissions = $connection->query()->useWritePdo()
            ->selectRaw('(select coalesce(sum(g.allowed_entries), 0) from guests as g where g.event_id = ? and g.status <> ?) as permitted', [$eventId, Guest::STATUS_CANCELLED])
            ->selectRaw('(select coalesce(sum(c.entries_added), 0) from checkins as c where c.event_id = ? and c.scan_result = ?) as used', [$eventId, Checkin::RESULT_ADMITTED])
            ->selectRaw('(select count(distinct c2.guest_id) from checkins as c2 where c2.event_id = ? and c2.scan_result = ? and c2.guest_id is not null) as admitted_guests', [$eventId, Checkin::RESULT_ADMITTED])
            ->first();

        $permitted = (int) ($admissions->permitted ?? 0);
        $used = (int) ($admissions->used ?? 0);
        $guests = (int) $counts['guests']['guests'];
        $sent = (int) $counts['invitations']['sent'];

        return [
            'progress' => $progress,
            'guests' => $guests,
            'invitations_sent' => $sent,
            'invitations_remaining' => max(0, $guests - $sent),
            'rsvp_confirmed' => (int) $counts['rsvp']['attending'],
            'rsvp_pending' => (int) $counts['rsvp']['pending'],
            'rsvp_invited' => (int) $counts['rsvp']['invited'],
            'scanners' => (int) $counts['scanner']['scanners'],
            'permitted_entries' => $permitted,
            'used_entries' => $used,
            'remaining_entries' => max(0, $permitted - $used),
            'admitted_guests' => (int) ($admissions->admitted_guests ?? 0),
            'entries_percent' => $permitted === 0 ? 0 : (int) min(100, round($used * 100 / $permitted)),
        ];
    }
}
