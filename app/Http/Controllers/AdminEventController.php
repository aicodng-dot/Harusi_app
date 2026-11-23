<?php

namespace App\Http\Controllers;

use App\Models\Checkin;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminEventController extends Controller
{
    public function index(): View
    {
        $events = Event::query()->useWritePdo()->whereNull('workspace_id')->whereBetween('id', [1, PHP_INT_MAX])
            ->withCount('guests')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'archived' THEN 1 ELSE 2 END")
            ->orderByDesc('event_date')
            ->orderBy('event_name')
            ->get();

        return view('admin.events.index', [
            'events' => $events,
            'eventStats' => $this->eventStats($events),
        ]);
    }

    public function create(): View
    {
        return view('admin.events.create', [
            'event' => new Event(['status' => Event::STATUS_ACTIVE]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $event = Event::query()->create($this->validateEvent($request));
        $request->session()->forget('guest_import');
        $request->session()->put('selected_event_id', $event->id);

        return redirect()
            ->route('admin.dashboard')
            ->with('success', 'Event created. You are now managing '.$event->event_name.'.');
    }

    public function edit(Event $event): View
    {
        $event = $this->legacyEvent($event);

        return view('admin.events.edit', [
            'event' => $event,
        ]);
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $event = $this->legacyEvent($event);
        $validated = $this->validateEvent($request);

        $event->getConnection()->transaction(function () use ($event, $validated): void {
            $this->legacyEvent($event, lock: true)->update($validated);
        });

        return redirect()
            ->route('admin.events.index')
            ->with('success', 'Event updated.');
    }

    public function select(Request $request, Event $event): RedirectResponse
    {
        $event = $this->legacyEvent($event);

        if (! in_array($request->session()->get('selected_event_id'), [$event->id, (string) $event->id], true)) {
            $request->session()->forget('guest_import');
        }
        $request->session()->put('selected_event_id', $event->id);

        $route = $request->query('redirect_to') === 'offline_scanner'
            ? 'admin.offline-scanner-sessions.create'
            : 'admin.dashboard';

        return redirect()
            ->route($route, $route === 'admin.offline-scanner-sessions.create' ? [$event] : [])
            ->with('success', 'Now managing '.$event->event_name.'.');
    }

    public function archive(Request $request, Event $event): RedirectResponse
    {
        $event = $this->legacyEvent($event);
        $event = $event->getConnection()->transaction(function () use ($event): Event {
            $locked = $this->legacyEvent($event, lock: true);
            $locked->update(['status' => Event::STATUS_ARCHIVED]);

            return $locked;
        });
        $this->clearSelectedEventIfCurrent($request, $event);

        return back()->with('success', $event->event_name.' was archived.');
    }

    public function cancel(Request $request, Event $event): RedirectResponse
    {
        $event = $this->legacyEvent($event);
        $event = $event->getConnection()->transaction(function () use ($event): Event {
            $locked = $this->legacyEvent($event, lock: true);
            $locked->update(['status' => Event::STATUS_CANCELLED]);

            return $locked;
        });
        $this->clearSelectedEventIfCurrent($request, $event);

        return back()->with('success', $event->event_name.' was cancelled.');
    }

    private function legacyEvent(Event $event, bool $lock = false): Event
    {
        // Use the raw identity before Eloquent can clamp an unsigned ID or
        // normalize an ambiguous cached model into a different legacy event.
        $id = $event->getRawOriginal($event->getKeyName());
        $safe = (is_int($id) && $id > 0)
            || (is_string($id) && preg_match('/\A[1-9][0-9]*\z/', $id) === 1
                && filter_var($id, FILTER_VALIDATE_INT) !== false);
        abort_unless($safe, 404);

        $query = Event::query()->useWritePdo()->whereNull('workspace_id')->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function validateEvent(Request $request): array
    {
        return $request->validate([
            'event_name' => ['required', 'string', 'max:160'],
            'bride_name' => ['nullable', 'string', 'max:120'],
            'groom_name' => ['nullable', 'string', 'max:120'],
            'venue_name' => ['nullable', 'string', 'max:160'],
            'event_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(Event::statusOptions())],
        ], [
            'event_name.required' => 'Enter the event name.',
            'status.in' => 'Choose a valid event status.',
        ]);
    }

    private function eventStats($events): array
    {
        $stats = [];

        foreach ($events as $event) {
            $guestQuery = $event->guests()->useWritePdo();
            $checkinQuery = $event->checkins()->useWritePdo();

            $stats[$event->id] = [
                'total_invites' => (clone $guestQuery)->count(),
                'expected_admissions' => (int) (clone $guestQuery)
                    ->where('status', '<>', 'cancelled')
                    ->sum('allowed_entries'),
                'checked_in_count' => (int) (clone $checkinQuery)
                    ->where('scan_result', Checkin::RESULT_ADMITTED)
                    ->sum('entries_added'),
            ];
        }

        return $stats;
    }

    private function clearSelectedEventIfCurrent(Request $request, Event $event): void
    {
        if (in_array($request->session()->get('selected_event_id'), [$event->id, (string) $event->id], true)) {
            $request->session()->forget(['selected_event_id', 'guest_import']);
        }
    }
}
