@php
    $t = fn (string $key, array $replace = []) => __('customer_share.'.$key, $replace, $language);
    $ev = ['event' => $event->id];
    $config = [
        'session' => (int) $run->id,
        'channel' => $run->channel,
        'message' => $run->message,
        'position' => $position,
        'recipients' => $recipients,
        'values' => $values,
        'placeholders' => \App\Services\Sharing\ShareMessage::PLACEHOLDERS,
        'max' => \App\Services\Sharing\ShareMessage::MAX_LENGTH,
        'csrf' => csrf_token(),
        'routes' => [
            'progress' => route('customer.events.share.sessions.progress', $ev + ['session' => $run->id]),
            'hub' => route('customer.events.share', $ev),
        ],
        'i18n' => [
            'of' => $t('session.of', ['n' => '__N__', 'total' => '__TOTAL__']),
            'did_send' => $t('session.did_send', ['name' => '__NAME__']),
            'already' => $t('session.already', ['channel' => '__CHANNEL__', 'date' => '__DATE__']),
            'state' => __('customer_share.session.state', [], $language),
            'channels' => __('customer_share.channels', [], $language),
            'no_phone' => $t('session.no_phone'),
            'no_phone_sms' => $t('session.no_phone_sms'),
            'copied' => $t('js.copied'),
            'copy_failed' => $t('js.copy_failed'),
            'share_unsupported' => $t('js.share_unsupported'),
            'image_unsupported' => $t('js.image_unsupported'),
            'save_failed' => $t('js.save_failed'),
            'saved_all' => $t('session.saved_all'),
            'chars' => $t('js.chars', ['count' => '__COUNT__']),
            'sms_parts' => $t('js.sms_parts', ['count' => '__COUNT__']),
            'problems' => __('customer_share.problems', [], $language),
            'locale' => $language === 'sw' ? 'sw-TZ' : 'en-GB',
        ],
    ];
    $primary = ['whatsapp' => 'open_whatsapp', 'sms' => 'open_sms', 'share' => 'share'][$run->channel];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('session.title') }} · {{ $event->event_name }}</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js', 'resources/js/reference_ui/customer_share.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page sh-page sh-run-page">
    <main class="is-main" id="cs-content" aria-labelledby="sh-heading" data-sh-session>
        <div class="sh-run-head">
            <h1 id="sh-heading" class="sh-run-title">{{ $t('session.title') }} · {{ $t('channels.'.$run->channel) }}</h1>
            <button type="button" class="cu-button cu-button-ghost sh-pause" data-sh-pause>{{ $t('session.pause') }}</button>
        </div>

        <div class="sh-progress" aria-live="polite">
            <progress max="1" value="0" data-sh-bar></progress>
            <dl>
                @foreach (['total', 'initiated', 'confirmed', 'skipped', 'remaining'] as $key)
                    <div class="sh-p-{{ $key }}"><dt>{{ $t('session.progress.'.$key) }}</dt><dd data-sh-count="{{ $key }}">0</dd></div>
                @endforeach
            </dl>
        </div>

        <noscript><p class="sh-note is-warn">{{ $t('wizard.no_js') }}</p></noscript>

        <section class="sh-recipient" data-sh-recipient aria-labelledby="sh-name" hidden>
            <p class="sh-of" data-sh-of></p>
            <div class="sh-who">
                <span class="rc-avatar" data-sh-initials aria-hidden="true"></span>
                <div>
                    <h2 id="sh-name" class="sh-name" data-sh-name></h2>
                    <p class="sh-meta"><span data-sh-pass></span> · <span data-sh-phone></span></p>
                    <span class="sh-state" data-sh-state hidden></span>
                </div>
            </div>
            <p class="sh-note is-warn" data-sh-already hidden></p>
            <p class="sh-note is-bad" data-sh-unavailable hidden>{{ $t('session.unavailable') }}</p>
            <p class="sh-note" data-sh-nophone hidden></p>

            <div class="sh-layout">
                <div class="sh-actions">
                    <div class="sh-send">
                        <a class="cu-button cu-button-primary sh-big sh-wa" href="#" target="_blank" rel="noopener noreferrer" data-sh-action="whatsapp" @if ($run->channel !== 'whatsapp') hidden @endif>{{ $t('session.open_whatsapp') }}</a>
                        <a class="cu-button cu-button-primary sh-big" href="#" data-sh-action="sms" @if ($run->channel !== 'sms') hidden @endif>{{ $t('session.open_sms') }}</a>
                        <button type="button" class="cu-button cu-button-primary sh-big" data-sh-action="share" @if ($run->channel !== 'share') hidden @endif>{{ $t('session.share') }}</button>
                    </div>
                    <div class="sh-confirm" data-sh-confirm hidden role="group" aria-labelledby="sh-confirm-q">
                        <p id="sh-confirm-q"><strong data-sh-confirm-q></strong><small>{{ $t('session.did_send_text') }}</small></p>
                        <div>
                            <button type="button" class="cu-button cu-button-primary" data-sh-sent>{{ $t('session.sent') }}</button>
                            <button type="button" class="cu-button cu-button-ghost" data-sh-notyet>{{ $t('session.not_sent') }}</button>
                        </div>
                    </div>
                    <button type="button" class="sh-undo" data-sh-undo hidden>{{ $t('session.undo') }}</button>
                </div>
                <figure class="sh-card">
                    <img alt="" data-sh-card decoding="async">
                    <figcaption><a href="#" target="_blank" rel="noopener noreferrer" data-sh-open-link>{{ $t('session.preview_link') }}</a></figcaption>
                </figure>
                <label class="cu-field sh-message">
                    <span>{{ $t('session.edit') }} <span class="cu-counter" data-sh-counter></span></span>
                    <textarea rows="9" data-sh-text></textarea>
                </label>
                <div class="sh-tools">
                    <button type="button" class="ed-tool" data-sh-copy="message">{{ $t('session.copy_message') }}</button>
                    <button type="button" class="ed-tool" data-sh-copy="link">{{ $t('session.copy_link') }}</button>
                    <button type="button" class="ed-tool" data-sh-share-image>{{ $t('session.share_card') }}</button>
                    <a class="ed-tool" href="#" download data-sh-download>{{ $t('session.download') }}</a>
                    @foreach (['whatsapp' => 'open_whatsapp', 'sms' => 'open_sms', 'share' => 'share'] as $other => $label)
                        @continue($other === $run->channel)
                        @if ($other === 'share')
                            <button type="button" class="ed-tool" data-sh-action="share" data-secondary>{{ $t('session.'.$label) }}</button>
                        @else
                            <a class="ed-tool" href="#" @if ($other === 'whatsapp') target="_blank" rel="noopener noreferrer" @endif data-sh-action="{{ $other }}" data-secondary>{{ $t('session.'.$label) }}</a>
                        @endif
                    @endforeach
                </div>
            </div>
            <p class="sh-toast" data-sh-toast role="status" aria-live="polite" hidden></p>
        </section>

        <section class="cu-panel sh-done" data-sh-done hidden aria-labelledby="sh-done-title">
            <h2 id="sh-done-title" class="cu-panel-title">{{ $t('session.done_title') }}</h2>
            <p class="cu-help">{{ $t('session.done_text') }}</p>
            <div data-sh-left hidden>
                <h3 class="cu-label">{{ $t('session.remaining_title') }}</h3>
                <ul class="sh-list" data-sh-left-list></ul>
            </div>
            <p class="sh-note">{{ $t('honesty') }}</p>
            <div class="cu-actions">
                <button type="button" class="cu-button cu-button-ghost" data-sh-review>{{ $t('session.review') }}</button>
                <button type="button" class="cu-button cu-button-primary" data-sh-finish>{{ $t('session.finish') }}</button>
            </div>
        </section>

        <details class="cu-details sh-edit-all">
            <summary>{{ $t('session.edit_all') }}</summary>
            <div class="cu-details-body">
                <p class="cu-help">{{ $t('session.edit_all_text') }}</p>
                @include('reference_ui.partials.share_message_editor', ['templates' => [], 'templateKey' => '', 'message' => $run->message, 'name' => 'message', 'editorId' => 'sh-all', 'hidePreview' => true])
                <button type="button" class="cu-button cu-button-ghost" data-sh-save-all>{{ $t('session.save_all') }}</button>
            </div>
        </details>

        <details class="cu-details">
            <summary>{{ $t('session.list') }} ({{ count($recipients) }})</summary>
            <ul class="sh-list" data-sh-list></ul>
        </details>

        <nav class="sh-nav" aria-label="{{ $t('session.title') }}" data-sh-nav>
            <button type="button" class="cu-button cu-button-ghost" data-sh-prev>← <span class="sh-nav-label">{{ $t('session.previous') }}</span></button>
            <button type="button" class="cu-button cu-button-ghost" data-sh-skip>{{ $t('session.skip') }}</button>
            <button type="button" class="cu-button cu-button-primary" data-sh-next-guest>{{ $t('session.next') }} →</button>
        </nav>
    </main>
</div>
<script type="application/json" id="sh-config">@json($config)</script>
</body>
</html>
