<?php

namespace App\Http\Middleware;

use App\Exceptions\OfflineEventLockedException;
use App\Models\Event;
use App\Services\OfflineEventLockService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLaravelAdmissionAllowed
{
    public function __construct(private readonly OfflineEventLockService $locks)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $event = $this->scannerEvent($request);

        if ($event) {
            try {
                $this->locks->assertLaravelAdmissionAllowed($event);
            } catch (OfflineEventLockedException $exception) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => $exception->getMessage()], 423);
                }

                return redirect()->route('scanner.dashboard')->with('error', $exception->getMessage());
            }
        }

        return $next($request);
    }

    private function scannerEvent(Request $request): ?Event
    {
        $user = $request->user();

        if ($user?->event) {
            return $user->event;
        }

        if ($user?->event_id) {
            return Event::query()->find($user->event_id);
        }

        $selectedEventId = $request->session()->get('selected_event_id');
        $selectedEvent = $selectedEventId ? Event::query()->find($selectedEventId) : null;

        return $selectedEvent ?: Event::defaultEvent();
    }
}
