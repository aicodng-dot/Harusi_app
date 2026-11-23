<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\LegacyEventResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminEventSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $resolver = app(LegacyEventResolver::class);
        $eventId = $request->session()->get('selected_event_id');
        if ($request->session()->exists('selected_event_id')) {
            $event = $resolver->find($eventId);
            if ($event !== null) {
                return $next($request);
            }
            // An explicit invalid target must never silently switch a POST to
            // a different event, even when just one legacy event is available.
            $request->session()->forget(['selected_event_id', 'guest_import']);
        } else {
            $request->session()->forget('guest_import');
            $event = $resolver->soleActive();
            if ($event !== null) {
                $request->session()->put('selected_event_id', $event->id);

                return $next($request);
            }
        }

        return redirect()->route('admin.events.index')
            ->with('info', 'Please select an event to manage.');
    }
}
