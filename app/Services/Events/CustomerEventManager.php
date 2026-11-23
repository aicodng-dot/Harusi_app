<?php

namespace App\Services\Events;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Event owner/manager self-service: edit an event's details, and delete an event that
 * has not been used for admission. The caller authorizes the account and event through
 * WorkspaceAccess; this class re-reads and locks the event inside each transaction.
 */
class CustomerEventManager
{
    /** @param array{event_name: string, event_date: string, event_time: string, venue_name: string} $details */
    public function update(int $eventId, array $details): void
    {
        DB::transaction(function () use ($eventId, $details): void {
            $event = $this->lockEvent($eventId);
            abort_unless($event->status === Event::STATUS_ACTIVE, 409);
            DB::table('events')->where('id', $event->id)->update($details + ['updated_at' => now()]);
        });
    }

    /**
     * Why the event cannot be deleted, or null when it can. Admitted guests, offline
     * scanner sessions and assigned scanners keep an event (and its history) in place.
     */
    public function deletionBlocker(int $eventId): ?string
    {
        if (DB::table('guests')->where('event_id', $eventId)->where('used_entries', '>', 0)->exists()
            || DB::table('admissions')->where('event_id', $eventId)->exists()
            || DB::table('checkins')->where('event_id', $eventId)->where('scan_result', 'admitted')->exists()) {
            return 'admitted';
        }
        if (DB::table('offline_scanner_sessions')->where('event_id', $eventId)->exists()) {
            return 'offline';
        }
        if (DB::table('users')->where('event_id', $eventId)->exists()) {
            return 'scanners';
        }

        return null;
    }

    /** Deletes the event, its guests, credentials, invitation data and files. Returns the refusal reason or null. */
    public function delete(int $eventId): ?string
    {
        $blocked = DB::transaction(function () use ($eventId): ?string {
            $this->lockEvent($eventId);
            DB::table('guests')->where('event_id', $eventId)->orderBy('id')->lockForUpdate()->get(['id']);
            $reason = $this->deletionBlocker($eventId);
            if ($reason !== null) {
                return $reason;
            }
            // Non-admission scan history (invalid/wrong-event scans) recorded against this event.
            DB::table('checkins')->where('event_id', $eventId)->delete();
            DB::table('scan_logs')->where('event_id', $eventId)->delete();
            // Cascades: qr_codes, admissions, invitation_cards; other guest references are nulled.
            DB::table('guests')->where('event_id', $eventId)->delete();
            // Cascades: invitation designs, cards and activity; notification logs are kept (event nulled).
            DB::table('events')->where('id', $eventId)->delete();

            return null;
        });

        if ($blocked === null) {
            Storage::disk('invitations')->deleteDirectory('events/'.$eventId);
        }

        return $blocked;
    }

    private function lockEvent(int $eventId): Event
    {
        $event = Event::query()->useWritePdo()->whereKey($eventId)->whereNotNull('workspace_id')->lockForUpdate()->first();
        abort_if($event === null, 404);

        return $event;
    }
}
