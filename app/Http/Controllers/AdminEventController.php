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
        $events = Event::query()
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
        $request->session()->put('selected_event_id', $event->id);

        return redirect()
            ->route('admin.dashboard')
            ->with('success', 'Event created. You are now managing '.$event->event_name.'.');
    }

    public function edit(Event $event): View
    {
        return view('admin.events.edit', [
            'event' => $event,
        ]);
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $event->update($this->validateEvent($request));

        return redirect()
            ->route('admin.events.index')
            ->with('success', 'Event updated.');
    }

    public function select(Request $request, Event $event): RedirectResponse
    {
        $request->session()->put('selected_event_id', $event->id);

        return redirect()
            ->route('admin.dashboard')
            ->with('success', 'Now managing '.$event->event_name.'.');
    }

    public function archive(Request $request, Event $event): RedirectResponse
    {
        $event->update(['status' => Event::STATUS_ARCHIVED]);
        $this->clearSelectedEventIfCurrent($request, $event);

        return back()->with('success', $event->event_name.' was archived.');
    }

    public function cancel(Request $request, Event $event): RedirectResponse
    {
        $event->update(['status' => Event::STATUS_CANCELLED]);
        $this->clearSelectedEventIfCurrent($request, $event);

        return back()->with('success', $event->event_name.' was cancelled.');
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
            $guestQuery = $event->guests();
            $checkinQuery = $event->checkins();

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
        if ((int) $request->session()->get('selected_event_id') === $event->id) {
            $request->session()->forget('selected_event_id');
        }
    }
}
