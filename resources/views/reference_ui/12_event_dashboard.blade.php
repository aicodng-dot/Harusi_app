@php
    use Illuminate\Support\Carbon;

    $t = fn (string $key, array $replace = []) => __('event_dashboard.'.$key, $replace, $language);
    $choice = fn (string $key, int $count, array $replace = []) => trans_choice('event_dashboard.'.$key, $count, ['count' => number_format($count)] + $replace, $language);
    $type = in_array($event->event_type, ['wedding', 'send_off', 'kitchen_party', 'other'], true) ? $event->event_type : null;
    $art = ['wedding' => 'Wedding', 'send_off' => 'Send_Off', 'kitchen_party' => 'Kitchen_Party', 'other' => 'Other_Celebration'][$type] ?? null;
    $date = $event->event_date === null ? null : Carbon::parse($event->event_date)->locale($language);
    $time = null;
    if (is_string($event->event_time) && preg_match('/\A(\d{2}):(\d{2})/', $event->event_time, $m)) {
        $hour = (int) $m[1];
        $period = match (true) {
            $hour < 4 || $hour >= 19 => 'night',
            $hour < 7 => 'dawn',
            $hour < 12 => 'morning',
            $hour < 16 => 'afternoon',
            default => 'evening',
        };
        // Swahili time counts from 06:00/18:00 (18:00 is "saa 12 jioni").
        $time = $language === 'sw'
            ? $t('time', ['time' => (($hour + 6) % 12 ?: 12).':'.$m[2]]).' '.$t('periods.'.$period)
            : Carbon::createFromTime($hour, (int) $m[2])->format('g:i A');
    }
    $nameParts = explode(' & ', $event->event_name, 2);
    $stats = $stats ?? null;
    $progress = $stats['progress'] ?? null;
    $next = $progress === null ? null : ($progress['current'] ?? 'done');
    $lifecycle = in_array($event->status, ['archived', 'cancelled'], true) ? $event->status : null;
    $setupUrl = route('customer.events.setup', ['event' => $event->id]);
    $dashboardUrl = route('customer.events.dashboard', ['event' => $event->id]);
    $icons = [
        'guests' => '<circle cx="9" cy="8" r="3.4"/><path d="M3 20c0-3.5 2.7-5.6 6-5.6s6 2.1 6 5.6"/><circle cx="16.5" cy="8.5" r="2.6"/><path d="M17 14.6c2.3.4 4 2.2 4 5.4"/>',
        'mail' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="m4.5 7 7.5 6 7.5-6"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'scanner' => '<path d="M4 8V5.5A1.5 1.5 0 0 1 5.5 4H8M16 4h2.5A1.5 1.5 0 0 1 20 5.5V8M20 16v2.5a1.5 1.5 0 0 1-1.5 1.5H16M8 20H5.5A1.5 1.5 0 0 1 4 18.5V16"/><rect x="8" y="8" width="3" height="3" rx=".5"/><rect x="13" y="8" width="3" height="3" rx=".5"/><rect x="8" y="13" width="3" height="3" rx=".5"/><path d="M13 13h3v3"/>',
        'plane' => '<path d="M21 3 3 10.5l7.2 2.7L13 21z"/><path d="m10.2 13.2 5-5"/>',
        'door' => '<path d="M5 21V4.5A1.5 1.5 0 0 1 6.5 3H15l4 2v16"/><path d="M3 21h18M12 12h.01" stroke-width="2.6"/>',
    ];
    $navIcons = [
        'home' => '<path d="M3.8 10.2 12 3.6l8.2 6.6v9.4a1.4 1.4 0 0 1-1.4 1.4H5.2a1.4 1.4 0 0 1-1.4-1.4z"/><path d="M9.6 21v-6.2h4.8V21"/>',
        'events' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.4"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 17.5h.01M12 17.5h.01" stroke-width="2.4"/>',
        'guests' => $icons['guests'],
        'reports' => '<path d="M4 20h16M7 17v-5M11 17V8M15 17v-7M19 17V5"/>',
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('page_title') }} · {{ $event->event_name }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/12_event_dashboard.css', 'resources/js/reference_ui/12_event_dashboard.js', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body class="db-body">
@include('reference_ui.partials.customer_shell')
    <div class="db-page">
        <header class="db-header">
            <a class="db-back" href="{{ route('customer.home') }}" aria-label="{{ $t('back') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
            </a>
            <span class="db-brand">PangaEvents</span>
            <nav class="db-languages" aria-label="{{ $t('language') }}">
                @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $label)
                    <a @class(['db-language', 'is-active' => $language === $locale])
                       href="{{ route('customer.events.dashboard', ['event' => $event->id, 'lang' => $locale]) }}" lang="{{ $locale }}" hreflang="{{ $locale }}"
                       @if ($language === $locale) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </header>

        <main class="db-main" aria-labelledby="db-heading">
            <h1 id="db-heading" class="db-heading">{{ $t('heading') }}</h1>
            <p class="db-intro">{{ $t('intro') }}</p>
            @if (session('customer_event_notice'))
                <p class="db-intro" role="status" style="color:#17603f;font-weight:700">{{ session('customer_event_notice') }}</p>
            @endif
            <p class="db-intro"><a href="{{ route('customer.events.edit', ['event' => $event->id]) }}" style="color:inherit;font-weight:700;text-decoration:underline;display:inline-flex;min-height:44px;align-items:center">{{ __('customer_manage.event.page_title', [], $language) }}</a></p>

            {{-- Hero: type illustration (never a stock photo presented as the customer's own), name, date, venue, time. --}}
            <section class="db-hero" aria-label="{{ $event->event_name }}" data-event-id="{{ $event->id }}">
                <div @class(['db-hero-art', 'is-placeholder' => $art === null]) aria-hidden="true">
                    @if ($art)
                        <img src="{{ asset('assets/reference_ui/04_Choose_Event_Type/04_Choose_Event_Type_'.$art.'.webp') }}" alt="" width="368" height="340" decoding="async">
                    @else
                        <img class="db-hero-mark" src="{{ asset('assets/pangaevents-mark.svg') }}" alt="" width="68" height="76">
                    @endif
                </div>
                <div class="db-hero-body">
                    <svg class="db-sparkle" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2c.6 4.6 2.4 6.9 7 8-4.6 1.1-6.4 3.4-7 8-.6-4.6-2.4-6.9-7-8 4.6-1.1 6.4-3.4 7-8z" fill="currentColor"/></svg>
                    <p class="db-hero-label">{{ $t('invitation_label.'.($type ?? 'none')) }}</p>
                    <h2 class="db-hero-name">
                        @if (count($nameParts) === 2)
                            {{ $nameParts[0] }} <span class="db-amp">&amp;</span> {{ $nameParts[1] }}
                        @else
                            {{ $event->event_name }}
                        @endif
                    </h2>
                    <span class="db-hero-rule" aria-hidden="true"></span>
                    @if ($date)
                        <p class="db-hero-date">
                            <span class="db-day">{{ $date->format('j') }}</span>
                            <span class="db-month-year"><span>{{ $date->isoFormat('MMMM') }}</span><span>{{ $date->format('Y') }}</span></span>
                        </p>
                    @else
                        <p class="db-hero-missing">{{ $t('date_missing') }}</p>
                    @endif
                    <p @class(['db-hero-line', 'db-hero-missing' => blank($event->venue_name)])>{{ filled($event->venue_name) ? $event->venue_name : $t('venue_missing') }}</p>
                    @if ($time)
                        <p class="db-hero-line">{{ $time }}</p>
                    @endif
                    <p class="db-status is-{{ $displayStatus }}">{{ $t('status.'.$displayStatus) }}</p>
                    <a class="db-hero-open" href="{{ $setupUrl }}" aria-label="{{ $t('open_setup') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg>
                    </a>
                </div>
            </section>

            @if ($lifecycle)
                <p class="db-alert db-alert-info" role="status">{{ $t('lifecycle.'.$lifecycle) }}</p>
            @endif

            @if ($stats === null)
                <section class="db-error" role="alert">
                    <h2>{{ $t('error_title') }}</h2>
                    <p>{{ $t('error_body') }}</p>
                    <a class="db-continue" href="{{ $dashboardUrl }}">{{ $t('retry') }}</a>
                </section>
            @else
                <section class="db-stats" aria-label="{{ $t('stats') }}">
                    <article class="db-stat" data-stat="guests">
                        <span class="db-stat-icon is-green" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons['guests'] !!}</svg></span>
                        <div class="db-stat-body">
                            <h3 class="db-stat-label">{{ $t('guests') }}</h3>
                            <p class="db-stat-value">{{ number_format($stats['guests']) }}</p>
                        </div>
                        <p class="db-stat-desc">{{ $t('guests_desc') }}</p>
                    </article>
                    <article class="db-stat" data-stat="invitations">
                        <span class="db-stat-icon is-gold" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons['mail'] !!}</svg></span>
                        <div class="db-stat-body">
                            <h3 class="db-stat-label">{{ $t('invitations') }}</h3>
                            <p class="db-stat-value">{{ number_format($stats['invitations_sent']) }}</p>
                        </div>
                        <p class="db-stat-desc">{{ $t('invitations_desc') }}</p>
                    </article>
                    <article class="db-stat is-unavailable" data-stat="confirmed">
                        <span class="db-stat-icon is-green" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $icons['check'] !!}</svg></span>
                        <div class="db-stat-body">
                            <h3 class="db-stat-label">{{ $t('confirmed') }}</h3>
                            <p class="db-stat-value is-unavailable">{{ $t('confirmed_unavailable') }}</p>
                        </div>
                        <p class="db-stat-desc">{{ $t('confirmed_desc') }}</p>
                    </article>
                    <article class="db-stat" data-stat="scanner">
                        <span class="db-stat-icon is-lilac" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons['scanner'] !!}</svg></span>
                        <div class="db-stat-body">
                            <h3 class="db-stat-label">{{ $t('scanner') }}</h3>
                            <p class="db-stat-value is-small">{{ number_format($stats['scanners']) }}</p>
                            <p @class(['db-stat-flag', 'is-off' => $stats['scanners'] === 0])>{{ $stats['scanners'] > 0 ? $t('scanner_ready') : $t('scanner_none') }}</p>
                        </div>
                        <p class="db-stat-desc">{{ $stats['scanners'] > 0 ? $t('scanner_desc_ready') : $t('scanner_desc_none') }}</p>
                    </article>
                </section>

                {{-- Admission entries are counted separately from individual guests. --}}
                <section class="db-entries" aria-labelledby="db-entries-title" data-stat="entries">
                    <span class="db-stat-icon is-green" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons['door'] !!}</svg></span>
                    <div class="db-entries-body">
                        <h3 id="db-entries-title" class="db-stat-label">{{ $t('entries') }}</h3>
                        @if ($stats['permitted_entries'] > 0)
                            <p class="db-entries-used">{{ $t('entries_used', ['used' => number_format($stats['used_entries']), 'permitted' => number_format($stats['permitted_entries'])]) }}</p>
                            <div class="db-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $stats['permitted_entries'] }}" aria-valuenow="{{ $stats['used_entries'] }}" aria-labelledby="db-entries-title"><span style="width: {{ $stats['entries_percent'] }}%"></span></div>
                            <p class="db-stat-desc">{{ $choice('entries_detail', $stats['admitted_guests'], ['remaining' => number_format($stats['remaining_entries'])]) }}</p>
                        @else
                            <p class="db-stat-desc">{{ $t('entries_none') }}</p>
                        @endif
                    </div>
                </section>

                {{-- One entry point for the whole invitation process: the server picks the next step. --}}
                @if ($invitation !== null)
                    <section class="db-next db-invite" aria-labelledby="db-invite-title" data-invitation-next="{{ $invitation['next'] }}">
                        <span class="db-next-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span>
                        <div class="db-next-body">
                            <p class="db-next-label">{{ __('invitation_studio.steps.label', ['step' => $invitation['step'], 'name' => __('invitation_studio.steps.names.'.$invitation['stage'], [], $language)], $language) }}</p>
                            <h2 id="db-invite-title" class="db-next-title">{{ __('invitation_studio.continue.title', [], $language) }}</h2>
                            <p class="db-next-desc">{{ __('invitation_studio.continue.next.'.$invitation['next'], ['count' => $invitation['counts']['to_create']], $language) }}</p>
                            @if ($next === 'invitations')
                                <p class="db-next-desc">{{ $choice('next.invitations.body', max(1, $stats['invitations_remaining'])) }}</p>
                            @endif
                        </div>
                        <a class="db-continue" href="{{ route('customer.events.invitation.continue', ['event' => $event->id]) }}" data-db-invitation>
                            <span>{{ __('invitation_studio.continue.'.($invitation['next'] === 'manage' ? 'manage' : 'button'), [], $language) }}</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg>
                        </a>
                        @if ($next === 'invitations')
                            <a class="db-setup-link" href="{{ $setupUrl }}">{{ $t('setup_link', ['done' => $progress['completed'], 'total' => $progress['total'], 'percent' => $progress['percent']]) }}</a>
                        @endif
                    </section>
                @endif

                {{-- When invitations are the next step, the invitation card above is the single primary action. --}}
                @if (! ($next === 'invitations' && $invitation !== null))
                <section class="db-next" aria-labelledby="db-next-title" data-next="{{ $next }}">
                    <span class="db-next-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons['plane'] !!}</svg></span>
                    <div class="db-next-body">
                        <p class="db-next-label">{{ $t('next_label') }}</p>
                        <h2 id="db-next-title" class="db-next-title">{{ $t('next.'.$next.'.title') }}</h2>
                        <p class="db-next-desc">
                            @if ($next === 'invitations')
                                {{ $choice('next.invitations.body', max(1, $stats['invitations_remaining'])) }}
                            @else
                                {{ $t('next.'.$next.'.body') }}
                            @endif
                        </p>
                    </div>
                    @if ($next === 'invitations')
                        {{-- Working Flow 14: the invitation step starts with choosing the card design. --}}
                        <a class="db-continue" href="{{ route('customer.events.template', ['event' => $event->id]) }}" data-db-template><span>{{ $t('continue') }}</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg></a>
                    @elseif ($next === 'done')
                        <a class="db-continue" href="{{ $setupUrl }}"><span>{{ $t('continue') }}</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg></a>
                    @else
                        <button class="db-continue" type="button" aria-describedby="db-note" data-db-pending="{{ $t('pending', ['step' => $t('next.'.$next.'.title')]) }}"><span>{{ $t('continue') }}</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg></button>
                    @endif
                    <p id="db-note" class="db-alert db-alert-info" role="status" hidden></p>
                    <a class="db-setup-link" href="{{ $setupUrl }}">{{ $t('setup_link', ['done' => $progress['completed'], 'total' => $progress['total'], 'percent' => $progress['percent']]) }}</a>
                </section>
                @endif
            @endif
        </main>

        <nav class="db-tabbar" aria-label="{{ $t('nav_label') }}">
            <p id="db-nav-note" class="db-tab-note" role="status" hidden>{{ $t('nav_pending') }}</p>
            <ul>
                <li><a class="db-tab" href="{{ $dashboardUrl }}" aria-current="page" aria-label="{{ $t('nav.home') }} — {{ $t('nav_home_current', ['name' => $event->event_name]) }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $navIcons['home'] !!}</svg><span>{{ $t('nav.home') }}</span></a></li>
                <li><a class="db-tab is-active" href="{{ route('customer.home') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $navIcons['events'] !!}</svg><span>{{ $t('nav.events') }}</span></a></li>
                @foreach (['guests', 'reports'] as $item)
                    <li><button class="db-tab" type="button" aria-disabled="true" aria-describedby="db-nav-note" data-db-nav-pending><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $navIcons[$item] !!}</svg><span>{{ $t('nav.'.$item) }}</span></button></li>
                @endforeach
            </ul>
        </nav>
    </div>
</body>
</html>
