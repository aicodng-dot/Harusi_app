@php
    use App\Services\Invitations\InvitationWording;
    use Illuminate\Support\Carbon;

    $t = fn (string $key, array $replace = []) => __('invitation_studio.'.$key, $replace, $language);
    $date = $event->event_date ? Carbon::parse($event->event_date) : null;
    $dateText = $date ? InvitationWording::dateParts($date, $language)['date_short'] : null;
    $timeText = is_string($event->event_time) ? substr($event->event_time, 0, 5) : null;
    $url = fn (array $query) => route('customer.events.invitation.guests', array_filter(['event' => $event->id] + $query, fn ($v) => $v !== null && $v !== '' && $v !== 'all'));
    $g = fn (string $key) => __('customer_guests.'.$key, [], $language);
    $plus = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
    $upload = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V5m-4 4 4-4 4 4M5 20h14"/></svg>';
    $messageTone = ['pending' => 'warn', 'sent' => 'info', 'delivered' => 'ok', 'read' => 'ok', 'failed' => 'bad', 'skipped' => 'muted'];
    $cardTone = ['none' => 'muted', 'current' => 'ok', 'stale' => 'warn'];
    $config = [
        'routes' => [
            'generate' => route('customer.events.invitation.generate', ['event' => $event->id]),
            'send' => route('customer.events.invitation.send', ['event' => $event->id]),
        ],
        'batch' => \App\Http\Controllers\CustomerInvitationController::GENERATE_BATCH,
        'whatsapp' => $whatsapp,
        'messages' => [
            'selectFirst' => $t('guests.select_first'), 'generating' => $t('guests.generating'), 'sending' => $t('guests.sending'),
            'generated' => $t('guests.generated'), 'problems' => $t('guests.problems'), 'sendResult' => $t('guests.send_result'),
            'network' => $t('network'), 'notConfigured' => $t('whatsapp_not_configured'), 'refresh' => $t('guests.needs_refresh'),
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $t('guests.page_title') }} · {{ $event->event_name }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/invitation_steps.css', 'resources/css/reference_ui/customer_manage.css', 'resources/js/reference_ui/31_select_invitation_recipients.js'])
</head>
<body>
<div class="is-page has-bar">
    <header class="is-header">
        <a class="is-back" href="{{ route('customer.events.invitation', ['event' => $event->id]) }}" aria-label="{{ $t('guests.edit_design') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
        </a>
        <span class="is-brand">PangaEvents</span>
        <nav class="is-languages" aria-label="{{ $t('language') }}">
            @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                <a @class(['is-language', 'is-active' => $language === $locale]) href="{{ $url(['q' => $search, 'filter' => $filter, 'lang' => $locale]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $code }}</a>
            @endforeach
        </nav>
    </header>

    <main class="is-main" aria-labelledby="rc-heading">
        <h1 id="rc-heading" class="is-heading">{{ $t('guests.title') }}</h1>
        <p class="is-intro">{{ $t('guests.intro') }}</p>
        @include('reference_ui.partials.invitation_steps', ['stage' => $invited === 0 ? 'guests' : 'cards'])
        @if (session('guest_notice'))
            <p class="is-alert is-alert-ok" role="status">{{ session('guest_notice') }}</p>
        @endif

        <a class="rc-event" href="{{ route('customer.events.dashboard', ['event' => $event->id]) }}">
            <span class="rc-event-ring" aria-hidden="true">💍</span>
            <span>
                <p class="rc-event-name">{{ $event->event_name }}</p>
                <p class="rc-event-meta">{{ implode(' · ', array_filter([$dateText, $timeText])) }}<br>{{ $event->venue_name }}</p>
            </span>
            <svg class="rc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
        </a>

        @if ($readOnly)
            <p class="is-alert is-alert-info" role="status">{{ $t('read_only') }}</p>
        @endif
        @if (! $designSaved)
            <p class="is-alert is-alert-info">{{ $t('guests.design_default') }} <a href="{{ route('customer.events.invitation', ['event' => $event->id]) }}">{{ $t('guests.edit_design') }}</a></p>
        @endif
        @if ($warnings !== [])
            <div class="is-alert is-alert-warn" role="status"><ul>@foreach ($warnings as $warning)<li>{{ $t('warnings.'.$warning) }}</li>@endforeach</ul></div>
        @endif
        @unless ($whatsapp)
            <p class="is-alert is-alert-info">{{ $t('whatsapp_not_configured') }}</p>
        @endunless
        <p id="rc-alert" class="is-alert" role="status" hidden></p>

        @if ($total > 0)
        <form class="rc-search" method="GET" action="{{ route('customer.events.invitation.guests', ['event' => $event->id]) }}" role="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <label class="sr-only" for="rc-q">{{ $t('guests.search') }}</label>
            <input id="rc-q" type="search" name="q" value="{{ $search }}" placeholder="{{ $t('guests.search') }}" maxlength="60" autocomplete="off">
            @if ($filter !== 'all')<input type="hidden" name="filter" value="{{ $filter }}">@endif
            <button class="sr-only" type="submit">{{ $t('guests.search_button') }}</button>
        </form>

        <nav class="rc-chips" aria-label="{{ $t('guests.title') }}">
            @foreach (['all', 'no_card', 'ready', 'sent', 'failed'] as $option)
                <a @class(['rc-chip', 'is-active' => $filter === $option]) href="{{ $url(['q' => $search, 'filter' => $option]) }}" @if ($filter === $option) aria-current="true" @endif>{{ $t('guests.filters.'.$option) }}</a>
            @endforeach
        </nav>

        @endif
        @if ($total > 0 && ! $readOnly)
            <nav class="rc-add" aria-label="{{ $g('add_guest') }}">
                <a href="{{ route('customer.events.guests.create', ['event' => $event->id]) }}">{!! $plus !!}{{ $g('add_guest') }}</a>
                <a href="{{ route('customer.events.guests.import', ['event' => $event->id]) }}">{!! $upload !!}{{ $g('import_guests') }}</a>
            </nav>
        @endif

        @if ($total === 0)
            <section class="rc-start">
                <h2>{{ $g('empty_title') }}</h2>
                <p>{{ $g('empty_text') }}</p>
                @unless ($readOnly)
                    <a class="is-primary" href="{{ route('customer.events.guests.create', ['event' => $event->id]) }}" data-rc-add-first>{!! $plus !!}{{ $g('add_guest') }}</a>
                    <a class="is-secondary" href="{{ route('customer.events.guests.import', ['event' => $event->id]) }}">{!! $upload !!}{{ $g('import_guests') }}</a>
                @endunless
            </section>
        @elseif ($guests->isEmpty())
            <p class="rc-empty">{{ $t('guests.no_results') }}</p>
        @else
            <div class="rc-toolbar">
                <label class="ed-check" style="min-height:44px"><input type="checkbox" id="rc-all" @disabled($readOnly)> {{ $t('guests.select_all') }}</label>
                <span>{{ $t('guests.count', ['shown' => $guests->count(), 'total' => $total]) }}</span>
            </div>
            <ul class="rc-list" id="rc-list">
                @foreach ($guests as $guest)
                    <li class="rc-item">
                        <label class="rc-check"><input type="checkbox" value="{{ $guest->id }}" data-rc-guest @disabled($readOnly || $guest->status === 'cancelled')><span class="sr-only">{{ $guest->name }}</span></label>
                        <span class="rc-avatar t{{ $guest->id % 4 }}" aria-hidden="true">{{ str_replace(' & ', '', $guest->initials) }}</span>
                        <span style="min-width:0">
                            <a class="rc-name" href="{{ route('customer.events.invitation.guest', ['event' => $event->id, 'guest' => $guest->id]) }}">{{ $guest->name }}</a>
                            <span class="rc-sub">{{ $guest->phone }} · {{ $t('guests.passes.'.($guest->pass_type === 'special' ? 'special' : $guest->pass_type), ['count' => $guest->allowed]) }}</span>
                            @unless ($readOnly)<a class="cm-inline-edit" style="min-height:0;padding:0" href="{{ route('customer.events.guests.edit', ['event' => $event->id, 'guest' => $guest->id]) }}" aria-label="{{ __('customer_manage.guest.edit', [], $language) }}: {{ $guest->name }}">{{ __('customer_manage.guest.edit', [], $language) }}</a>@endunless
                        </span>
                        <span class="rc-pills">
                            @if ($guest->status === 'cancelled')
                                <span class="is-pill bad">{{ $t('guests.cancelled') }}</span>
                            @else
                                <span class="is-pill {{ $cardTone[$guest->card] }}">{{ $t('guests.card_states.'.$guest->card) }}</span>
                            @endif
                            @if ($guest->message)
                                <span class="is-pill {{ $messageTone[$guest->message] ?? 'muted' }}">{{ $t('guests.message_states.'.$guest->message) }}</span>
                            @endif
                        </span>
                        <a href="{{ route('customer.events.invitation.guest', ['event' => $event->id, 'guest' => $guest->id]) }}" aria-label="{{ $t('guests.view', ['name' => $guest->name]) }}">
                            <svg class="rc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
        <p style="margin-top:18px"><a class="is-small-link" href="{{ route('customer.events.invitation.status', ['event' => $event->id]) }}">{{ $t('guests.status_link') }} →</a></p>
    </main>
</div>

@if ($total > 0 && ! $readOnly)
    <div class="rc-bar">
        <div class="rc-progress" id="rc-progress" hidden><span id="rc-progress-text"></span><progress id="rc-progress-bar" max="1" value="0"></progress></div>
        <div class="rc-bar-inner">
            <div class="rc-count">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.6a3.5 3.5 0 0 1 0 6.8M21.5 20a6.5 6.5 0 0 0-4-6"/></svg>
                <span><small>{{ $t('guests.selected') }}</small><strong id="rc-selected">0</strong></span>
            </div>
            <button type="button" class="is-primary" id="rc-generate">{{ $t('guests.generate') }}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg>
            </button>
        </div>
        <div class="rc-more" role="group" aria-label="{{ $t('guests.more_actions') }}">
            <button type="button" class="ed-tool" data-rc-download="png">{{ $t('guests.download_png') }}</button>
            <button type="button" class="ed-tool" data-rc-download="pdf">{{ $t('guests.download_pdf') }}</button>
            <button type="button" class="ed-tool" id="rc-send" @disabled(! $whatsapp) @unless ($whatsapp) title="{{ $t('whatsapp_not_configured') }}" @endunless>{{ $t('guests.send') }}</button>
        </div>
    </div>
    <form id="rc-download-form" method="POST" action="{{ route('customer.events.invitation.download', ['event' => $event->id]) }}" hidden>
        @csrf
        <input type="hidden" name="format" value="png">
    </form>
@endif
<script type="application/json" id="rc-config">@json($config)</script>
</body>
</html>
