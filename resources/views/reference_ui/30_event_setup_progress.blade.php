@php
    use App\Services\Events\EventSetupProgress;
    use Illuminate\Support\Carbon;

    $t = fn (string $key, array $replace = []) => __('event_setup.'.$key, $replace, $language);
    $choice = fn (string $key, int $count, array $replace = []) => trans_choice('event_setup.'.$key, $count, ['count' => number_format($count)] + $replace, $language);
    $date = $event->event_date === null ? null : Carbon::parse($event->event_date)->locale($language)->isoFormat('D MMMM YYYY');
    $time = is_string($event->event_time) && preg_match('/\A\d{2}:\d{2}/', $event->event_time) ? substr($event->event_time, 0, 5) : null;
    $typeLabel = in_array($event->event_type, ['wedding', 'send_off', 'kitchen_party', 'other'], true) ? __('event_type.types.'.$event->event_type, [], $language) : null;
    $stepIcons = [
        'details' => '<rect x="5" y="3.5" width="14" height="17" rx="2"/><path d="M8.5 8h7M8.5 11.5h7M8.5 15h4"/>',
        'guests' => '<circle cx="9" cy="8" r="3.4"/><path d="M3 20c0-3.5 2.7-5.6 6-5.6s6 2.1 6 5.6"/><path d="M15.5 4.8a3.3 3.3 0 0 1 0 6.4M17.8 14.6c2 .6 3.2 2.5 3.2 5.4"/>',
        'invitations' => '<path d="M21 3 3 10.5l7.2 2.7L13 21z"/><path d="m10.2 13.2 5-5"/>',
        'rsvp' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="m4.5 7 7.5 6 7.5-6"/>',
        'scanner' => '<path d="M4 8V5.5A1.5 1.5 0 0 1 5.5 4H8M16 4h2.5A1.5 1.5 0 0 1 20 5.5V8M20 16v2.5a1.5 1.5 0 0 1-1.5 1.5H16M8 20H5.5A1.5 1.5 0 0 1 4 18.5V16"/><rect x="8" y="8" width="3" height="3" rx=".5"/><rect x="13" y="8" width="3" height="3" rx=".5"/><rect x="8" y="13" width="3" height="3" rx=".5"/><path d="M13 13h3v3"/>',
        'reports' => '<path d="M6 20v-7M12 20V5M18 20v-10"/>',
    ];
    $typeIcons = [
        'send_off' => '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/>',
        'kitchen_party' => '<path d="M7 3v8M5 3v5a2 2 0 0 0 4 0V3M7 11v10M16 3c-1.7 1.5-2.5 3.5-2.5 6.5 0 1.4.7 2 2 2H16v9.5"/>',
        'other' => '<circle cx="12" cy="9" r="5.2"/><path d="M12 14.2v2.3M12 16.5c-1.5 1.4-1.5 2.8 0 4.5"/>',
        null => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.4"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    ];
    $description = function (array $step) use ($t, $choice): string {
        $c = $step['counts'];
        return match ($step['key']) {
            'details' => $step['status'] === EventSetupProgress::COMPLETED ? $t('descriptions.details_completed') : $t('descriptions.details_missing'),
            'guests' => $c['guests'] > 0 ? $choice('descriptions.guests_completed', $c['guests']) : $t('descriptions.guests_none'),
            'invitations' => match (true) {
                $c['guests'] === 0 && $c['sent'] === 0 => $t('descriptions.invitations_no_guests'),
                $step['status'] === EventSetupProgress::COMPLETED => $t('descriptions.invitations_completed', ['guests' => number_format($c['guests'])]),
                $c['sent'] > 0 => $t('descriptions.invitations_partial', ['sent' => number_format($c['sent']), 'guests' => number_format($c['guests'])]),
                default => $t('descriptions.invitations_none'),
            },
            'rsvp' => $t('descriptions.rsvp'),
            'scanner' => $c['scanners'] > 0 ? $choice('descriptions.scanner_completed', $c['scanners']) : $t('descriptions.scanner_none'),
            'reports' => $c['checkins'] > 0 ? $choice('descriptions.reports_completed', $c['checkins']) : $t('descriptions.reports_none'),
        };
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('page_title') }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/30_event_setup_progress.css', 'resources/js/reference_ui/30_event_setup_progress.js', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body class="sp-body">
@include('reference_ui.partials.customer_shell')
    <div class="sp-page">
        <header class="sp-header">
            <a class="sp-back" href="{{ route('customer.home') }}" aria-label="{{ $t('back') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
            </a>
            <span class="sp-brand">PangaEvents</span>
            <nav class="sp-languages" aria-label="{{ $t('language') }}">
                @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $label)
                    <a @class(['sp-language', 'is-active' => $language === $locale])
                       href="{{ route('customer.events.setup', ['event' => $event->id, 'lang' => $locale]) }}" lang="{{ $locale }}" hreflang="{{ $locale }}"
                       @if ($language === $locale) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </header>

        <main class="sp-main" aria-labelledby="sp-heading">
            <h1 id="sp-heading" class="sp-heading">{{ $t('heading') }}</h1>
            <p class="sp-intro">{{ $t('intro') }}</p>

            @if (is_string($created) && $created !== '')
                <p class="sp-alert sp-alert-status" role="status" data-sp-created>{{ $t('created', ['name' => $created]) }}</p>
            @endif

            <section class="sp-event" aria-label="{{ $event->event_name }}">
                <span @class(['sp-event-art', 'is-photo' => $event->event_type === 'wedding']) aria-hidden="true">
                    @if ($event->event_type === 'wedding')
                        <img src="{{ asset('assets/reference_ui/30_Event_Setup_Progress/30_Event_Setup_Progress_Wedding_Ring.webp') }}" width="86" height="86" alt="">
                    @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $typeIcons[$event->event_type] ?? $typeIcons[null] !!}</svg>
                    @endif
                </span>
                <div class="sp-event-body">
                    <h2 class="sp-event-name">{{ $event->event_name }}</h2>
                    <p @class(['sp-event-date', 'is-missing' => $date === null])>
                        @if ($date){{ $date }}@if ($time) <span aria-hidden="true">·</span> {{ $time }}@endif @else{{ $t('date_missing') }}@endif
                    </p>
                    <p @class(['sp-event-venue', 'is-missing' => blank($event->venue_name)])>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-6.5-5.6-6.5-11.2a6.5 6.5 0 0 1 13 0C18.5 15.4 12 21 12 21z"/><circle cx="12" cy="9.8" r="2.4"/></svg>
                        <span>{{ filled($event->venue_name) ? $event->venue_name : $t('venue_missing') }}</span>
                    </p>
                    @if ($typeLabel)
                        <p class="sp-event-type" data-event-type="{{ $event->event_type }}">{{ $typeLabel }}</p>
                    @endif
                </div>
                <button class="sp-edit" type="button" aria-describedby="sp-note" data-sp-pending="{{ $t('edit_pending') }}">{{ $t('edit') }}</button>
            </section>

            @if (in_array($progress['lifecycle'] ?? null, ['archived', 'cancelled'], true))
                <p class="sp-alert sp-alert-info" role="status">{{ $t('lifecycle.'.$progress['lifecycle']) }}</p>
            @endif

            @if ($progress === null)
                <section class="sp-error" role="alert">
                    <h2>{{ $t('error_title') }}</h2>
                    <p>{{ $t('error_body') }}</p>
                    <a class="sp-continue" href="{{ route('customer.events.setup', ['event' => $event->id]) }}">{{ $t('retry') }}</a>
                </section>
            @else
                <div class="sp-progress">
                    <div class="sp-progress-head">
                        <span class="sp-progress-label">{{ $t('progress_label', ['done' => $progress['completed'], 'total' => $progress['total']]) }}</span>
                        <span class="sp-progress-percent" data-sp-percent="{{ $progress['percent'] }}">{{ $progress['percent'] }}%</span>
                    </div>
                    <div class="sp-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}"
                         aria-valuetext="{{ $t('progress_aria', ['done' => $progress['completed'], 'total' => $progress['total'], 'percent' => $progress['percent']]) }}">
                        <span style="width: {{ $progress['percent'] }}%"></span>
                    </div>
                </div>

                <ol class="sp-steps" aria-label="{{ $t('checklist') }}">
                    @foreach ($progress['steps'] as $step)
                        @php
                            $state = $step['status'];
                            $isCurrent = $progress['current'] === $step['key'];
                            $marker = $state === EventSetupProgress::COMPLETED ? 'done' : ($isCurrent ? 'current' : 'todo');
                            $stepName = $t('steps.'.$step['key']);
                        @endphp
                        <li @class(['sp-step', 'is-'.$marker, 'is-'.str_replace('_', '-', $state)]) data-step="{{ $step['key'] }}" data-status="{{ $state }}" @if ($isCurrent) aria-current="step" @endif>
                            <span class="sp-marker" aria-hidden="true">
                                @if ($marker === 'done')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m6 12.5 4 4 8-9"/></svg>
                                @endif
                            </span>
                            @if ($step['key'] === 'invitations')
                                {{-- Working Flow 14: invitations start with the card design. --}}
                                <a class="sp-step-card" href="{{ route('customer.events.template', ['event' => $event->id]) }}" data-sp-template>
                            @else
                                <button class="sp-step-card" type="button" aria-describedby="sp-note" data-sp-pending="{{ $t('step_pending', ['step' => $stepName]) }}">
                            @endif
                                <span class="sp-step-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $stepIcons[$step['key']] !!}</svg></span>
                                <span class="sp-step-text">
                                    <span class="sp-step-title">{{ $stepName }}</span>
                                    <span class="sp-step-desc">{{ $description($step) }}</span>
                                </span>
                                <span class="sp-pill">{{ $t('statuses.'.$state) }}</span>
                                <svg class="sp-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg>
                            @if ($step['key'] === 'invitations')
                                </a>
                            @else
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ol>

                <p id="sp-note" class="sp-alert sp-alert-info" role="status" hidden></p>

                {{-- Working Flow 12: Continue opens this event's authorized dashboard. --}}
                <a class="sp-continue" href="{{ route('customer.events.dashboard', ['event' => $event->id]) }}" data-sp-dashboard>
                    <span>{{ $t('continue') }}</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg>
                </a>
            @endif
        </main>
    </div>
</body>
</html>
