{{--
    Customer navigation shell (shared by every customer page).

    Phones / tablets (< 1024px), following the common app pattern (TikTok, Instagram, Uber):
      - one slim app bar: back to the parent screen, the event as context, "more" (⋯);
      - a bottom tab bar on top-level screens, with a raised centre "+" for the main action;
      - "more" opens a bottom sheet with everything else (switch event, settings, language, sign out);
      - focused task screens (create event, choose design, review / edit forms) hide the tabs.
    Desktop (>= 1024px): the same destinations as a sidebar with a "New event" button.

    Destinations and the active item come from the current route; only routes the signed-in
    customer can already open are linked.
--}}
@php
    $csLang = in_array($language ?? null, ['sw', 'en'], true) ? $language : 'sw';
    $cs = fn (string $key, array $replace = []) => __('customer_shell.'.$key, $replace, $csLang);
    $csRoute = request()->route();
    $csName = (string) ($csRoute?->getName() ?? '');
    $csEventParam = $csRoute?->parameter('event');
    $csEventId = is_string($csEventParam) || is_int($csEventParam) ? (int) $csEventParam : 0;
    $csEventName = $csEventId > 0 && isset($event) && is_object($event) && (int) ($event->id ?? 0) === $csEventId ? (string) ($event->event_name ?? '') : '';
    $csIn = fn (array $names) => in_array($csName, $names, true);
    $csStarts = fn (string $prefix) => str_starts_with($csName, $prefix);

    // Which tab / sidebar item is current.
    $csActive = match (true) {
        $csIn(['customer.events.dashboard', 'customer.events.setup']) => 'home',
        $csIn(['customer.events.guests.create', 'customer.events.guests.store', 'customer.events.guests.review', 'customer.events.guests.bulk'])
            || $csStarts('customer.events.guests.import') => 'add',
        $csIn(['customer.events.invitation.guests', 'customer.events.invitation.guest']) || $csStarts('customer.events.guests.') => 'guests',
        $csStarts('customer.events.template') || $csIn(['customer.events.invitation', 'customer.events.invitation.save']) => 'card',
        $csName === 'customer.events.share.history' => 'history',
        $csStarts('customer.events.rsvp') => 'rsvp',
        $csName === 'customer.events.invitation.status' || $csStarts('customer.events.share') => 'invites',
        $csIn(['customer.events.edit', 'customer.events.update', 'customer.events.destroy']) => 'details',
        $csStarts('customer.profile') => 'account',
        $csName === 'customer.help' => 'help',
        $csIn(['customer.events.create', 'customer.events.type', 'customer.events.details', 'customer.events.store', 'customer.workspaces.create', 'customer.workspaces.store']) => 'new',
        default => 'events',
    };
    // Focused task screens hide the tab bar (they have their own primary buttons).
    $csTask = $csStarts('customer.events.template') || $csActive === 'new' || $csActive === 'details'
        || $csIn(['customer.events.guests.review', 'customer.events.guests.bulk', 'customer.events.guests.edit', 'customer.events.guests.update', 'customer.events.invitation.guest',
            'customer.events.share.create', 'customer.events.share.sessions.show', 'customer.events.share.guest'])
        || $csStarts('customer.events.guests.import');

    // Back goes to the parent screen.
    $csBack = match (true) {
        $csEventId === 0 && $csActive === 'events' => null,
        $csEventId === 0 => route('customer.home'),
        $csActive === 'home' && $csName === 'customer.events.dashboard' => route('customer.home'),
        $csIn(['customer.events.guests.review', 'customer.events.guests.bulk']) || $csStarts('customer.events.guests.import') => route('customer.events.guests.create', ['event' => $csEventId]),
        $csIn(['customer.events.invitation.guest', 'customer.events.guests.edit', 'customer.events.guests.update', 'customer.events.share.guest']) => route('customer.events.invitation.guests', ['event' => $csEventId]),
        $csStarts('customer.events.share.') => route('customer.events.share', ['event' => $csEventId]),
        $csIn(['customer.events.invitation', 'customer.events.invitation.save']) => route('customer.events.template', ['event' => $csEventId]),
        default => route('customer.events.dashboard', ['event' => $csEventId]),
    };
    $csTitle = $csEventId > 0 ? ($csEventName !== '' ? $csEventName : $cs('event.label')) : 'PangaEvents';

    // Desktop breadcrumbs.
    $csCrumbs = [['label' => $cs('crumb.events'), 'url' => route('customer.home')]];
    if ($csEventId > 0) {
        $csCrumbs[] = ['label' => \Illuminate\Support\Str::limit($csTitle, 28), 'url' => route('customer.events.dashboard', ['event' => $csEventId])];
        if ($csName !== 'customer.events.dashboard') {
            $csCrumbs[] = ['label' => $cs('crumbs.'.$csActive), 'url' => null];
        }
    } elseif ($csActive !== 'events') {
        $csCrumbs[] = ['label' => $cs('crumbs.'.$csActive), 'url' => null];
    }

    // Like My Events: a customer without an active workspace starts by creating one.
    $csUser = auth()->user();
    $csHasWorkspace = $csUser !== null && \Illuminate\Support\Facades\DB::table('workspace_users')->useWritePdo()
        ->where('user_id', $csUser->getKey())->where('is_active', true)->whereIn('role', ['owner', 'manager'])->exists();
    $csNewUrl = $csHasWorkspace ? route('customer.events.create') : route('customer.workspaces.create');

    $csIcons = [
        'events' => '<rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'home' => '<path d="M4 11 12 4l8 7v8a1 1 0 0 1-1 1h-4.5v-6h-5v6H5a1 1 0 0 1-1-1z"/>',
        'guests' => '<circle cx="9" cy="8" r="3.2"/><path d="M2.8 20a6.2 6.2 0 0 1 12.4 0M15.5 5a3.2 3.2 0 0 1 0 6.2M21.2 20a6.2 6.2 0 0 0-3.9-5.7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'card' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M10 16h4"/>',
        'invites' => '<path d="m3 11 18-7-7 18-2-8z"/><path d="m12 14 9-10"/>',
        'account' => '<circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/>',
        'help' => '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.4 2.3c-.7.3-1 .8-1 1.5v.4M12 16.8v.2"/>',
        'details' => '<path d="M5 4h10l4 4v12H5z"/><path d="M9 12h6M9 16h4"/>',
        'setup' => '<path d="m4 12 3 3 6-6M4 19h16M14 7h6M14 12h6"/>',
        'design' => '<path d="M12 3a9 9 0 1 0 0 18c1 0 1.5-.8 1.5-1.6 0-1.3-1-1.4-1-2.6 0-1 .8-1.8 1.8-1.8H17a4 4 0 0 0 4-4C21 6.5 17 3 12 3"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="15" cy="7.5" r="1"/>',
        'switch' => '<path d="M7 7h11l-3-3M17 17H6l3 3"/>',
        'signout' => '<path d="M14 4h5v16h-5M10 16l-4-4 4-4M6 12h10"/>',
        'back' => '<path d="m15 5-7 7 7 7"/>',
        'more' => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
        'globe' => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.6 2.5 14.4 0 17M12 3.5c-2.5 2.6-2.5 14.4 0 17"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'history' => '<path d="M4 12a8 8 0 1 0 2.4-5.7L4 8.5"/><path d="M4 4v4.5h4.5M12 8v4l3 2"/>',
        'template' => '<path d="M5 5h14v10H9l-4 4z"/><path d="M9 9h6M9 12h4"/>',
        'rsvp' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
    ];
    $csIcon = fn (string $name, float $w = 1.9) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="'.$w.'" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$csIcons[$name].'</svg>';
    $ev = fn (string $route) => route($route, ['event' => $csEventId]);

    // Bottom tabs (phones) — 3 outside an event, 5 inside with a raised centre "+".
    $csTabs = $csEventId > 0 ? [
        ['key' => 'home', 'icon' => 'home', 'url' => $ev('customer.events.dashboard')],
        ['key' => 'guests', 'icon' => 'guests', 'url' => $ev('customer.events.invitation.guests')],
        ['key' => 'add', 'icon' => 'plus', 'url' => $ev('customer.events.guests.create'), 'primary' => true],
        ['key' => 'card', 'icon' => 'card', 'url' => $ev('customer.events.invitation')],
        ['key' => 'invites', 'icon' => 'invites', 'url' => $ev('customer.events.share')],
    ] : [
        ['key' => 'events', 'icon' => 'events', 'url' => route('customer.home')],
        ['key' => 'new', 'icon' => 'plus', 'url' => $csNewUrl, 'primary' => true],
        ['key' => 'account', 'icon' => 'account', 'url' => route('customer.profile')],
    ];
    // Desktop sidebar (same names, same order).
    $csSide = $csEventId > 0 ? [
        ['key' => 'home', 'icon' => 'home', 'url' => $ev('customer.events.dashboard')],
        ['key' => 'guests', 'icon' => 'guests', 'url' => $ev('customer.events.invitation.guests')],
        ['key' => 'add', 'icon' => 'plus', 'url' => $ev('customer.events.guests.create')],
        ['key' => 'card', 'icon' => 'card', 'url' => $ev('customer.events.invitation')],
        ['key' => 'invites', 'icon' => 'invites', 'url' => $ev('customer.events.share')],
        ['key' => 'history', 'icon' => 'history', 'url' => $ev('customer.events.share.history')],
        ['key' => 'rsvp', 'icon' => 'rsvp', 'url' => $ev('customer.events.rsvp')],
        ['key' => 'details', 'icon' => 'details', 'url' => $ev('customer.events.edit')],
        ['key' => 'setup', 'icon' => 'setup', 'url' => $ev('customer.events.setup')],
    ] : [];
    $csLangUrl = fn (string $locale) => request()->isMethod('GET') ? request()->fullUrlWithQuery(['lang' => $locale]) : null;
@endphp
<a class="cs-skip" href="#cs-content">{{ $cs('skip') }}</a>

{{-- App bar (phones: back · context · more; desktop: breadcrumbs · language). --}}
<header class="cs-bar" data-cs-bar>
    @if ($csBack)
        <a class="cs-icon-btn cs-back" href="{{ $csBack }}" aria-label="{{ $cs('back') }}">{!! $csIcon('back', 2.2) !!}</a>
    @else
        <a class="cs-brand" href="{{ route('customer.home') }}" aria-label="PangaEvents"><span class="cs-logo-mark" aria-hidden="true">P</span></a>
    @endif
    <p class="cs-title">{{ $csTitle }}</p>
    <nav class="cs-crumbs" aria-label="{{ $cs('breadcrumb') }}">
        <ol>
            @foreach ($csCrumbs as $crumb)
                <li>@if ($crumb['url'] && ! $loop->last)<a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>@else<span aria-current="page">{{ $crumb['label'] }}</span>@endif</li>
            @endforeach
        </ol>
    </nav>
    @if ($csLangUrl('sw') !== null)
        <nav class="cs-lang" aria-label="{{ $cs('language') }}">
            @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                <a href="{{ $csLangUrl($locale) }}" lang="{{ $locale }}" @if ($csLang === $locale) aria-current="true" @endif>{{ $code }}</a>
            @endforeach
        </nav>
    @endif
    <button class="cs-icon-btn cs-more" type="button" aria-haspopup="dialog" aria-controls="cs-sheet" aria-expanded="false" aria-label="{{ $cs('more') }}" data-cs-open>{!! $csIcon('more', 2.4) !!}</button>
</header>

{{-- Desktop sidebar. --}}
<aside class="cs-side" aria-label="{{ $cs('main_nav') }}">
    <a class="cs-logo" href="{{ route('customer.home') }}"><span class="cs-logo-mark" aria-hidden="true">P</span><span>PangaEvents</span></a>
    <a class="cs-new" href="{{ $csNewUrl }}" @if ($csActive === 'new') aria-current="page" @endif>{!! $csIcon('plus', 2.4) !!}<span>{{ $cs('nav.new_event') }}</span></a>
    <nav class="cs-nav" aria-label="{{ $cs('main_nav') }}">
        <a href="{{ route('customer.home') }}" @if ($csEventId === 0 && $csActive === 'events') aria-current="page" @endif>{!! $csIcon('events') !!}<span>{{ $cs('nav.events') }}</span></a>
    </nav>
    @if ($csSide !== [])
        <nav class="cs-nav cs-nav-event" aria-label="{{ $cs('event_nav') }}">
            <a class="cs-event-card" href="{{ $ev('customer.events.dashboard') }}">
                <small>{{ $cs('event.label') }}</small>
                <strong>{{ $csTitle }}</strong>
            </a>
            @foreach ($csSide as $item)
                <a href="{{ $item['url'] }}" @if ($csActive === $item['key']) aria-current="page" @endif>{!! $csIcon($item['icon']) !!}<span>{{ $cs('side.'.$item['key']) }}</span></a>
            @endforeach
        </nav>
    @endif
    <nav class="cs-nav cs-nav-foot" aria-label="{{ $cs('account') }}">
        <a href="{{ route('customer.help') }}" @if ($csActive === 'help') aria-current="page" @endif>{!! $csIcon('help') !!}<span>{{ $cs('nav.help') }}</span></a>
        <a href="{{ route('customer.profile') }}" @if ($csActive === 'account') aria-current="page" @endif>{!! $csIcon('account') !!}<span>{{ $cs('nav.account') }}</span></a>
    </nav>
</aside>

{{-- Bottom tab bar (phones / tablets; hidden on focused task screens). --}}
@unless ($csTask)
    <nav class="cs-tabs" aria-label="{{ $cs('main_nav') }}" data-cs-tabs>
        @foreach ($csTabs as $tab)
            <a @class(['cs-tab', 'is-primary' => $tab['primary'] ?? false]) href="{{ $tab['url'] }}" @if ($csActive === $tab['key']) aria-current="page" @endif>
                <span class="cs-tab-icon">{!! $csIcon($tab['icon'], ($tab['primary'] ?? false) ? 2.6 : 1.9) !!}</span>
                <span class="cs-tab-label">{{ $cs('tabs.'.$tab['key']) }}</span>
            </a>
        @endforeach
    </nav>
@endunless

{{-- "More" bottom sheet. --}}
<div class="cs-scrim" data-cs-close hidden></div>
<div class="cs-sheet" id="cs-sheet" role="dialog" aria-modal="true" aria-labelledby="cs-sheet-title" tabindex="-1" data-cs-sheet hidden>
    <span class="cs-sheet-handle" aria-hidden="true"></span>
    <div class="cs-sheet-head">
        <p class="cs-sheet-title" id="cs-sheet-title">{{ $csEventId > 0 ? $csTitle : $cs('more') }}</p>
        <button class="cs-icon-btn" type="button" data-cs-close aria-label="{{ $cs('close') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
    </div>
    @if ($csEventId > 0)
        <ul class="cs-sheet-list">
            <li><a href="{{ $ev('customer.events.rsvp') }}">{!! $csIcon('rsvp') !!}<span>{{ $cs('side.rsvp') }}</span>{!! $csIcon('chevron') !!}</a></li>
            <li><a href="{{ $ev('customer.events.share.history') }}">{!! $csIcon('history') !!}<span>{{ $cs('side.history') }}</span>{!! $csIcon('chevron') !!}</a></li>
            <li><a href="{{ $ev('customer.events.share.templates') }}">{!! $csIcon('template') !!}<span>{{ $cs('side.templates') }}</span>{!! $csIcon('chevron') !!}</a></li>
            <li><a href="{{ $ev('customer.events.edit') }}">{!! $csIcon('details') !!}<span>{{ $cs('side.details') }}</span>{!! $csIcon('chevron') !!}</a></li>
            <li><a href="{{ $ev('customer.events.setup') }}">{!! $csIcon('setup') !!}<span>{{ $cs('side.setup') }}</span>{!! $csIcon('chevron') !!}</a></li>
            <li><a href="{{ $ev('customer.events.template') }}">{!! $csIcon('design') !!}<span>{{ $cs('side.designs') }}</span>{!! $csIcon('chevron') !!}</a></li>
            <li><a href="{{ route('customer.home') }}">{!! $csIcon('switch') !!}<span>{{ $cs('switch_event') }}</span>{!! $csIcon('chevron') !!}</a></li>
        </ul>
    @endif
    <ul class="cs-sheet-list">
        @if ($csEventId > 0)
            <li><a href="{{ $csNewUrl }}">{!! $csIcon('plus') !!}<span>{{ $cs('nav.new_event') }}</span>{!! $csIcon('chevron') !!}</a></li>
        @endif
        <li><a href="{{ route('customer.profile') }}">{!! $csIcon('account') !!}<span>{{ $cs('nav.account') }}</span>{!! $csIcon('chevron') !!}</a></li>
        <li><a href="{{ route('customer.help') }}">{!! $csIcon('help') !!}<span>{{ $cs('nav.help') }}</span>{!! $csIcon('chevron') !!}</a></li>
    </ul>
    @if ($csLangUrl('sw') !== null)
        <div class="cs-sheet-row">
            {!! $csIcon('globe') !!}<span>{{ $cs('language') }}</span>
            <span class="cs-seg" role="group" aria-label="{{ $cs('language') }}">
                @foreach (['sw' => 'Kiswahili', 'en' => 'English'] as $locale => $label)
                    <a href="{{ $csLangUrl($locale) }}" lang="{{ $locale }}" @if ($csLang === $locale) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </span>
        </div>
    @endif
    <form class="cs-sheet-signout" method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">{!! $csIcon('signout') !!}<span>{{ $cs('nav.sign_out') }}</span></button>
    </form>
</div>
<span id="cs-content" tabindex="-1"></span>
