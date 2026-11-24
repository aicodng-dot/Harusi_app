@php
    $t = fn (string $key, array $replace = []) => __('customer_share.'.$key, $replace, $language);
    $ev = ['event' => $event->id];
    $statTone = ['confirmed' => 'is-ok', 'initiated' => 'is-warn', 'awaiting' => '', 'not_shared' => ''];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('title') }} · {{ $event->event_name }}</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page sh-page">
    <main class="is-main" id="cs-content" aria-labelledby="sh-heading">
        <h1 id="sh-heading" class="is-heading">{{ $t('title') }}</h1>
        <p class="is-intro">{{ $t('hub.intro') }}</p>

        @if ($readOnly)
            <p class="sh-note is-warn" role="status">{{ $t('hub.read_only') }}</p>
        @endif

        <dl class="sh-stats" aria-label="{{ $t('title') }}">
            @foreach (['guests', 'with_phone', 'cards', 'not_shared', 'initiated', 'confirmed', 'awaiting'] as $key)
                <div class="sh-stat {{ $statTone[$key] ?? '' }}"><dt>{{ $t('hub.stats.'.$key) }}</dt><dd>{{ number_format($stats[$key]) }}</dd></div>
            @endforeach
        </dl>

        @if ($stats['guests'] === 0)
            <section class="sh-empty">
                <h2>{{ $t('hub.no_guests_title') }}</h2>
                <p>{{ $t('hub.no_guests_text') }}</p>
                <a class="cu-button cu-button-primary" href="{{ route('customer.events.guests.create', $ev) }}">{{ $t('hub.no_guests_action') }}</a>
            </section>
        @elseif ($stats['cards'] === 0)
            <section class="sh-empty">
                <h2>{{ $t('hub.no_cards_title') }}</h2>
                <p>{{ $t('hub.no_cards_text') }}</p>
                <a class="cu-button cu-button-primary" href="{{ route('customer.events.invitation.guests', $ev + ['invite' => 'not_generated']) }}">{{ $t('hub.no_cards_action') }}</a>
            </section>
        @endif

        @if ($sessions !== [])
            <section class="cu-panel" aria-labelledby="sh-continue">
                <h2 id="sh-continue" class="cu-panel-title">{{ $t('hub.continue_title') }}</h2>
                <ul class="sh-runs">
                    @foreach ($sessions as $run)
                        <li>
                            <span class="sh-run-text">
                                <strong>{{ $t('hub.session_line', ['channel' => $t('channels.'.$run->channel), 'done' => $run->confirmed + $run->skipped, 'total' => $run->total]) }}</strong>
                                <small>@if ($run->status === 'paused'){{ $t('hub.paused') }} · @endif{{ \Illuminate\Support\Carbon::parse($run->at)->locale($language)->diffForHumans() }}</small>
                                <progress max="{{ max(1, $run->total) }}" value="{{ $run->confirmed + $run->skipped }}"></progress>
                            </span>
                            @unless ($readOnly)
                                <a class="cu-button cu-button-primary" href="{{ route('customer.events.share.sessions.show', $ev + ['session' => $run->id]) }}">{{ $t('hub.continue') }}</a>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @unless ($readOnly)
            <section class="sh-start" aria-labelledby="sh-start">
                <div>
                    <h2 id="sh-start">{{ $t('hub.start') }}</h2>
                    <p>{{ $t('hub.start_text') }}</p>
                </div>
                <div class="sh-start-actions">
                    <a class="cu-button cu-button-primary sh-wa" href="{{ route('customer.events.share.create', $ev + ['channel' => 'whatsapp']) }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2.2a9.7 9.7 0 0 0-8.3 14.8L2.3 21.8l4.9-1.3A9.7 9.7 0 1 0 12 2.2m0 17.7a8 8 0 0 1-4.1-1.1l-.3-.2-2.9.8.8-2.8-.2-.3A8 8 0 1 1 12 19.9m4.4-6c-.2-.1-1.4-.7-1.7-.8s-.4-.1-.5.1l-.8 1c-.1.2-.3.2-.5.1a6.6 6.6 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.5-.4h-.5a.9.9 0 0 0-.7.3 2.8 2.8 0 0 0-.9 2.1 4.9 4.9 0 0 0 1 2.6 11.2 11.2 0 0 0 4.3 3.8c1.6.7 2.2.7 3 .6a2.6 2.6 0 0 0 1.7-1.2 2.1 2.1 0 0 0 .2-1.2c-.1-.1-.2-.2-.5-.3"/></svg>
                        {{ $t('channels.whatsapp') }}
                    </a>
                    <a class="cu-button cu-button-ghost" href="{{ route('customer.events.share.create', $ev + ['channel' => 'sms']) }}">{{ $t('channels.sms') }}</a>
                </div>
            </section>
        @endunless

        <nav class="sh-links" aria-label="{{ $t('title') }}">
            <a href="{{ route('customer.events.invitation.guests', $ev) }}"><strong>{{ $t('hub.guests') }}</strong><small>{{ $t('hub.guests_text') }}</small></a>
            <a href="{{ route('customer.events.share.history', $ev) }}"><strong>{{ $t('hub.history') }}</strong><small>{{ $t('history.intro') }}</small></a>
            <a href="{{ route('customer.events.share.templates', $ev) }}"><strong>{{ $t('hub.templates') }}</strong><small>{{ $t('templates.intro') }}</small></a>
        </nav>

        <section class="cu-panel" aria-labelledby="sh-how">
            <h2 id="sh-how" class="cu-panel-title">{{ $t('hub.how_title') }}</h2>
            <ol class="cu-steps">
                @foreach (__('customer_share.hub.how', [], $language) as [$title, $text])
                    <li><strong>{{ $title }}</strong><span>{{ $text }}</span></li>
                @endforeach
            </ol>
            <p class="sh-note">{{ $t('honesty') }}</p>
        </section>
    </main>
</div>
</body>
</html>
