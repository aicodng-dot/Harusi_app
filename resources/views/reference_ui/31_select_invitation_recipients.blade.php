@php
    use App\Services\Invitations\InvitationWording;
    use Illuminate\Support\Carbon;

    $t = fn (string $key, array $replace = []) => __('invitation_studio.'.$key, $replace, $language);
    $date = $event->event_date ? Carbon::parse($event->event_date) : null;
    $dateText = $date ? InvitationWording::dateParts($date, $language)['date_short'] : null;
    $timeText = is_string($event->event_time) ? substr($event->event_time, 0, 5) : null;
    $g = fn (string $key) => __('customer_guests.'.$key, [], $language);
    $plus = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
    $upload = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V5m-4 4 4-4 4 4M5 20h14"/></svg>';
    $messageTone = ['pending' => 'warn', 'sent' => 'info', 'delivered' => 'ok', 'read' => 'ok', 'failed' => 'bad', 'skipped' => 'muted'];
    $cardTone = ['none' => 'muted', 'current' => 'ok', 'stale' => 'warn'];
    $config = [
        'routes' => [
            'generate' => route('customer.events.invitation.generate', ['event' => $event->id]),
            'send' => route('customer.events.invitation.send', ['event' => $event->id]),
            'delete' => route('customer.events.guests.bulk_destroy', ['event' => $event->id]),
        ],
        'batch' => \App\Http\Controllers\CustomerInvitationController::GENERATE_BATCH,
        'whatsapp' => $whatsapp,
        'messages' => [
            'selectFirst' => $t('guests.select_first'), 'generating' => $t('guests.generating'), 'sending' => $t('guests.sending'),
            'generated' => $t('guests.generated'), 'problems' => $t('guests.problems'), 'sendResult' => $t('guests.send_result'),
            'network' => $t('network'), 'notConfigured' => $t('whatsapp_not_configured'), 'refresh' => $t('guests.needs_refresh'),
            'deleteResult' => __('customer_guest_list.delete_result', [], $language), 'deleteLocked' => __('customer_guest_list.delete_locked', [], $language),
            'deleteTooMany' => __('customer_guest_list.delete_too_many', [], $language), 'loading' => __('customer_guest_list.loading', [], $language),
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
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/invitation_steps.css', 'resources/css/reference_ui/customer_manage.css', 'resources/js/reference_ui/31_select_invitation_recipients.js', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page cu-wide gm-page has-bar">
    <header class="is-header">
        <a class="is-back" href="{{ route('customer.events.invitation', ['event' => $event->id]) }}" aria-label="{{ $t('guests.edit_design') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
        </a>
        <span class="is-brand">PangaEvents</span>
        <nav class="is-languages" aria-label="{{ $t('language') }}">
            @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                <a @class(['is-language', 'is-active' => $language === $locale]) href="{{ request()->fullUrlWithQuery(['lang' => $locale]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $code }}</a>
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
        @else
            @include('reference_ui.partials.guest_list_toolbar')
            @include('reference_ui.partials.guest_list_results')
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
            <button type="button" class="ed-tool gm-share-bulk" id="rc-share">{{ __('customer_guest_list.share_selected', [], $language) }}</button>
            <button type="button" class="ed-tool" data-rc-download="png">{{ $t('guests.download_png') }}</button>
            <button type="button" class="ed-tool" data-rc-download="pdf">{{ $t('guests.download_pdf') }}</button>
            <button type="button" class="ed-tool gm-danger" id="rc-delete" data-confirm="{{ __('customer_guest_list.delete_confirm', [], $language) }}">{{ __('customer_guest_list.delete', [], $language) }}</button>
            <button type="button" class="ed-tool" id="rc-send" @disabled(! $whatsapp) @unless ($whatsapp) title="{{ $t('whatsapp_not_configured') }}" @endunless>{{ $t('guests.send') }}</button>
        </div>
    </div>
    <form id="rc-share-form" method="POST" action="{{ route('customer.events.share.select', ['event' => $event->id]) }}" hidden>
        @csrf
    </form>
    <form id="rc-download-form" method="POST" action="{{ route('customer.events.invitation.download', ['event' => $event->id]) }}" hidden>
        @csrf
        <input type="hidden" name="format" value="png">
    </form>
@endif
<script type="application/json" id="rc-config">@json($config)</script>
</body>
</html>
