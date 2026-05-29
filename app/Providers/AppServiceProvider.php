<?php

namespace App\Providers;

use App\Models\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.admin', function ($view): void {
            $selectedEventId = session('selected_event_id');
            $selectedEvent = $selectedEventId ? Event::query()->find($selectedEventId) : null;

            $view->with([
                'selectedEvent' => $selectedEvent,
                'eventSwitcherEvents' => Event::query()
                    ->where('status', Event::STATUS_ACTIVE)
                    ->orderBy('event_date')
                    ->orderBy('event_name')
                    ->get(),
            ]);
        });
    }
}
