<?php

namespace App\Http\Middleware;

use App\Models\Event;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminEventSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $eventId = $request->session()->get('selected_event_id');
        $event = $eventId ? Event::query()->find($eventId) : null;

        if (! $event) {
            $activeEvents = Event::query()
                ->where('status', Event::STATUS_ACTIVE)
                ->orderBy('event_date')
                ->orderBy('event_name')
                ->get();

            if ($activeEvents->count() === 1) {
                $event = $activeEvents->first();
                $request->session()->put('selected_event_id', $event->id);
            }
        }

        if (! $event) {
            return redirect()
                ->route('admin.events.index')
                ->with('info', 'Please select an event to manage.');
        }

        return $next($request);
    }
}
