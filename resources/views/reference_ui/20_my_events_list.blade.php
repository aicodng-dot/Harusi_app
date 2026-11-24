@php
    use App\Http\Controllers\CustomerHomeController;
    use Illuminate\Support\Carbon;

    $t = fn (string $key, array $replace = []) => __('my_events.'.$key, $replace, $language);
    $choice = fn (string $key, int $count) => trans_choice('my_events.'.$key, $count, ['count' => number_format($count)], $language);
    $hasWorkspace = $workspaces->isNotEmpty();
    $multipleWorkspaces = $workspaces->count() > 1;
    $filtering = $filter !== 'all';
    $searching = $search !== '';
    $total = $counts['all'];
    $workspaceStatus = session('customer_workspace_status');
    $listUrl = fn (array $query) => route('customer.home', array_filter($query + ['q' => $search ?: null, 'status' => $filtering ? $filter : null], fn ($value) => $value !== null && $value !== ''));
    $formatDate = fn ($value, $time = null) => $value === null ? null
        : Carbon::parse($value)->locale($language)->isoFormat('D MMMM YYYY').(is_string($time) && preg_match('/\A\d{2}:\d{2}/', $time) ? ', '.substr($time, 0, 5) : '');
    $eventCreated = session('customer_event_created');
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fcfaf3">
    <title>{{ $t('page_title') }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/20_my_events_list.css', 'resources/js/reference_ui/20_my_events_list.js', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body class="ev-body">
@include('reference_ui.partials.customer_shell')
    <div class="ev-page">
        <header class="ev-header">
            <a class="ev-brand" href="{{ route('customer.home') }}">
                <img src="{{ asset('assets/pangaevents-mark.svg') }}" width="68" height="76" alt="">
                <span>PangaEvents</span>
            </a>
            <nav class="ev-languages" aria-label="{{ $t('language') }}">
                @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $label)
                    <a
                        @class(['ev-language', 'is-active' => $language === $locale])
                        href="{{ $listUrl(['lang' => $locale, 'page' => request()->integer('page') > 1 ? request()->integer('page') : null]) }}"
                        lang="{{ $locale }}"
                        hreflang="{{ $locale }}"
                        @if ($language === $locale) aria-current="true" @endif
                    >{{ $label }}</a>
                @endforeach
            </nav>
        </header>

        <main class="ev-main" id="ev-main" aria-labelledby="ev-heading">
            <h1 id="ev-heading" class="ev-heading">{{ $t('heading') }}</h1>
            <p class="ev-intro">{{ $t('intro') }}</p>

            @if (is_string($eventCreated) && $eventCreated !== '')
                <p class="ev-alert ev-alert-status" role="status" data-ev-created>{{ $t('event_created', ['name' => $eventCreated]) }}</p>
            @endif
            @if (is_string(session('customer_event_deleted')))
                <p class="ev-alert ev-alert-status" role="status">{{ session('customer_event_deleted') }}</p>
            @endif
            @if (in_array($workspaceStatus, ['created', 'existing'], true))
                <p class="ev-alert ev-alert-status" role="status">{{ __('customer.'.$workspaceStatus, [], $language) }}</p>
            @endif

            @if (! $loadError && is_array($overview ?? null) && $overview['events'] > 0)
                @include('reference_ui.partials.customer_overview', ['overview' => $overview, 'focusEvent' => $events?->first()])
            @endif

            @if ($loadError)
                <section class="ev-state ev-state-error" role="alert" aria-labelledby="ev-error-title">
                    <span class="ev-state-icon is-error"><x-events-icon name="alert"/></span>
                    <h2 id="ev-error-title" class="ev-state-title">{{ $t('error_title') }}</h2>
                    <p class="ev-state-body">{{ $t('error_body') }}</p>
                    <a class="ev-primary ev-state-action" href="{{ $listUrl([]) }}">{{ $t('retry') }}</a>
                </section>
            @elseif (! $hasWorkspace)
                {{-- New customer: registration never creates a workspace, so creation starts with one. --}}
                <section class="ev-state" aria-labelledby="ev-empty-title" data-ev-state="no-workspace">
                    <span class="ev-state-icon"><x-events-icon name="events"/></span>
                    <h2 id="ev-empty-title" class="ev-state-title">{{ $t('no_workspace_title') }}</h2>
                    <p class="ev-state-body">{{ $t('no_workspace_body') }}</p>
                    <a class="ev-primary ev-state-action" href="{{ route('customer.workspaces.create') }}" data-ev-create>
                        <x-events-icon name="plus"/><span>{{ $t('create_short') }}</span>
                    </a>
                    <p class="ev-state-hint">{{ $t('no_workspace_hint') }}</p>
                </section>
            @else
                @if ($total > 0 || $searching || $filtering)
                    <div class="ev-tools">
                        <form class="ev-search" method="GET" action="{{ route('customer.home') }}" role="search" data-ev-search>
                            <label class="sr-only" for="ev-search-input">{{ $t('search_label') }}</label>
                            <x-events-icon name="search" class="ev-search-icon"/>
                            <input id="ev-search-input" name="q" type="search" value="{{ $search }}" maxlength="100" autocomplete="off" enterkeyhint="search" placeholder="{{ $t('search_placeholder') }}">
                            @if ($filtering)
                                <input type="hidden" name="status" value="{{ $filter }}">
                            @endif
                            @if ($searching)
                                <a class="ev-search-clear" href="{{ route('customer.home', $filtering ? ['status' => $filter] : []) }}" aria-label="{{ $t('clear_search') }}"><x-events-icon name="close"/></a>
                            @endif
                            <button class="sr-only" type="submit">{{ $t('search_submit') }}</button>
                        </form>
                        <button class="ev-filter-toggle @if ($filtering) is-active @endif" type="button" aria-expanded="{{ $filtering ? 'true' : 'false' }}" aria-controls="ev-filters" aria-label="{{ $t('filter_toggle') }}" data-ev-filter-toggle>
                            <x-events-icon name="filter"/>
                        </button>
                    </div>
                    <nav id="ev-filters" class="ev-filters" aria-label="{{ $t('filter_heading') }}" @unless ($filtering) hidden @endunless data-ev-filters>
                        @foreach (CustomerHomeController::FILTERS as $option)
                            <a @class(['ev-chip', 'is-active' => $filter === $option])
                               href="{{ route('customer.home', array_filter(['q' => $search ?: null, 'status' => $option === 'all' ? null : $option])) }}"
                               @if ($filter === $option) aria-current="true" @endif
                               data-ev-filter="{{ $option }}"
                            ><span>{{ $t('filters.'.$option) }}</span> <span class="ev-chip-count">{{ number_format($counts[$option]) }}</span></a>
                        @endforeach
                    </nav>
                    <noscript><style>.ev-filters[hidden] { display: flex; }</style></noscript>
                @endif

                <a class="ev-primary ev-create" href="{{ route('customer.events.create') }}" data-ev-create>
                    <x-events-icon name="plus"/><span>{{ $t('create') }}</span>
                </a>

                @if ($events !== null && $events->isNotEmpty())
                    @if ($searching || $filtering)
                        <p class="ev-results" role="status">{{ $choice('results', $events->total()) }}</p>
                    @endif
                    <ul class="ev-list" aria-label="{{ $t('list_label') }}" data-ev-list>
                        @foreach ($events as $event)
                            @php
                                $state = CustomerHomeController::displayStatus($event, $today);
                                $date = $formatDate($event->event_date, $event->event_time ?? null);
                                $typeLabel = in_array($event->event_type ?? null, ['wedding', 'send_off', 'kitchen_party', 'other'], true)
                                    ? __('event_type.types.'.$event->event_type, [], $language) : null;
                            @endphp
                            <li class="ev-card" data-event-id="{{ $event->id }}" data-status="{{ $state }}">
                                <div class="ev-cover" aria-hidden="true">
                                    <img src="{{ asset('assets/pangaevents-mark.svg') }}" width="68" height="76" alt="" loading="lazy">
                                    @if ($typeLabel)
                                        <span class="ev-cover-type">{{ $typeLabel }}</span>
                                    @endif
                                </div>
                                @if ($typeLabel)
                                    <span class="sr-only">{{ $typeLabel }}</span>
                                @endif
                                <div class="ev-card-body">
                                    <div class="ev-card-top">
                                        <h2 class="ev-name"><a class="ev-card-link" href="{{ route('customer.events.dashboard', ['event' => $event->id]) }}">{{ $event->event_name }}</a></h2>
                                        <span class="ev-pill is-{{ $state }}">{{ $t('statuses.'.$state) }}</span>
                                    </div>
                                    <ul class="ev-meta">
                                        <li @class(['is-missing' => $date === null])><x-events-icon name="calendar"/><span>{{ $date ?? $t('date_missing') }}</span></li>
                                        <li @class(['is-missing' => blank($event->venue_name)])><x-events-icon name="pin"/><span>{{ filled($event->venue_name) ? $event->venue_name : $t('venue_missing') }}</span></li>
                                        <li><x-events-icon name="guests"/><span>{{ $choice('guests', (int) $event->guests_count) }}</span></li>
                                    </ul>
                                    @if ($multipleWorkspaces && filled($event->workspace_name))
                                        <p class="ev-workspace">{{ $t('workspace', ['name' => $event->workspace_name]) }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @if ($events->hasPages())
                        <nav class="ev-pagination" aria-label="{{ $t('pagination') }}">
                            @if ($events->onFirstPage())
                                <span class="ev-page-link is-disabled" aria-disabled="true">{{ $t('previous') }}</span>
                            @else
                                <a class="ev-page-link" rel="prev" href="{{ $events->previousPageUrl() }}">{{ $t('previous') }}</a>
                            @endif
                            <span class="ev-page-status">{{ $t('page', ['current' => $events->currentPage(), 'last' => $events->lastPage()]) }}</span>
                            @if ($events->hasMorePages())
                                <a class="ev-page-link" rel="next" href="{{ $events->nextPageUrl() }}">{{ $t('next') }}</a>
                            @else
                                <span class="ev-page-link is-disabled" aria-disabled="true">{{ $t('next') }}</span>
                            @endif
                        </nav>
                    @endif
                @elseif ($searching || $filtering)
                    <section class="ev-state" aria-labelledby="ev-none-title" data-ev-state="no-results">
                        <span class="ev-state-icon"><x-events-icon name="search"/></span>
                        <h2 id="ev-none-title" class="ev-state-title">{{ $t('no_results_title') }}</h2>
                        <p class="ev-state-body">{{ $searching ? $t('no_results_body', ['search' => $search]) : $t('no_filter_results_body') }}</p>
                        <a class="ev-secondary ev-state-action" href="{{ route('customer.home') }}">{{ $t('show_all') }}</a>
                    </section>
                @else
                    <section class="ev-state" aria-labelledby="ev-empty-title" data-ev-state="empty">
                        <span class="ev-state-icon"><x-events-icon name="events"/></span>
                        <h2 id="ev-empty-title" class="ev-state-title">{{ $t('empty_title') }}</h2>
                        <p class="ev-state-body">{{ $t('empty_body') }}</p>
                        @if ($workspaces->count() === 1)
                            <p class="ev-state-hint">{{ $t('empty_workspace', ['name' => $workspaces->first()->name]) }}</p>
                        @else
                            <ul class="ev-state-workspaces">
                                @foreach ($workspaces as $workspace)
                                    <li>{{ $t('workspace', ['name' => $workspace->name]) }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif
            @endif

            <div class="ev-account">
                <p class="ev-account-name">{{ $t('signed_in', ['name' => $accountName]) }}</p>
                <a class="ev-logout" href="{{ route('customer.profile') }}"><span>{{ __('customer_manage.profile.title', [], $language) }}</span></a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="ev-logout" type="submit"><x-events-icon name="logout"/><span>{{ $t('logout') }}</span></button>
                </form>
            </div>
        </main>

        <nav class="ev-tabbar" aria-label="{{ $t('nav_label') }}">
            <p id="ev-nav-note" class="ev-tab-note" role="status" hidden>{{ $t('nav_pending') }}</p>
            <ul>
                @foreach (['home' => 'home', 'events' => 'events', 'guests' => 'guests', 'reports' => 'reports'] as $item => $icon)
                    <li>
                        @if ($item === 'events')
                            <a class="ev-tab is-active" href="{{ route('customer.home') }}" aria-current="page"><x-events-icon :name="$icon"/><span>{{ $t('nav.'.$item) }}</span></a>
                        @else
                            {{-- Destinations arrive in later working flows; never a fake page. --}}
                            <button class="ev-tab" type="button" aria-disabled="true" aria-describedby="ev-nav-note" data-ev-pending="ev-nav-note"><x-events-icon :name="$icon"/><span>{{ $t('nav.'.$item) }}</span></button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>
</body>
</html>
