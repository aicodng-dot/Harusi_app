@php
    use App\Services\Sharing\ShareMessage;
    use Illuminate\Support\Carbon;

    $t = fn (string $key, array $replace = []) => __('customer_share.'.$key, $replace, $language);
    $ev = ['event' => $event->id];
    $canShare = ! $readOnly && $link['url'] !== null;
    $templateKey = 'default_whatsapp';
    $message = ShareMessage::render($templates[$templateKey]['body'], $values);
    $cardUrl = route('customer.events.invitation.card', $ev + ['guest' => $guest->id, 'format' => 'png']);
    $config = [
        'guest' => (int) $guest->id,
        'name' => (string) $guest->name,
        'phone' => $phone,
        'url' => $link['url'],
        'card' => $cardUrl,
        'templates' => $templates,
        'values' => $values,
        'placeholders' => ShareMessage::PLACEHOLDERS,
        'max' => ShareMessage::MAX_LENGTH,
        'csrf' => csrf_token(),
        'routes' => ['record' => route('customer.events.share.record', $ev)],
        'i18n' => [
            'did_send' => $t('session.did_send', ['name' => '__NAME__']),
            'copied' => $t('js.copied'),
            'copy_failed' => $t('js.copy_failed'),
            'share_unsupported' => $t('js.share_unsupported'),
            'image_unsupported' => $t('js.image_unsupported'),
            'save_failed' => $t('js.save_failed'),
            'chars' => $t('js.chars', ['count' => '__COUNT__']),
            'sms_parts' => $t('js.sms_parts', ['count' => '__COUNT__']),
            'state' => __('customer_share.statuses', [], $language),
            'problems' => __('customer_share.problems', [], $language),
        ],
    ];
    $stateTone = ['confirmed' => 'ok', 'initiated' => 'warn', 'skipped' => 'muted'];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('guest.title') }} · {{ $guest->name }}</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js', 'resources/js/reference_ui/customer_share.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page sh-page">
    <main class="is-main" id="cs-content" aria-labelledby="sh-heading" @if ($canShare) data-sh-guest @endif>
        <div class="sh-who sh-who-page">
            <span class="rc-avatar t{{ $guest->id % 4 }}" aria-hidden="true">{{ str_replace(' & ', '', \App\Services\Invitations\InvitationDesign::initials((string) $guest->name)) }}</span>
            <div>
                <h1 id="sh-heading" class="sh-name">{{ $guest->name }}</h1>
                <p class="sh-meta">
                    {{ __('invitation_studio.guests.passes.'.$guest->pass_type, ['count' => (int) $guest->allowed_entries], $language) }}
                    · {{ $phone !== '' ? '+'.$phone : __('customer_guest_list.no_phone', [], $language) }}
                </p>
            </div>
            <span class="is-pill {{ $stateTone[$overall['status'] ?? ''] ?? 'muted' }}" data-sh-state>{{ $t('statuses.'.($overall['status'] ?? 'not_shared')) }}</span>
        </div>

        @if ($phoneInvalid)
            <p class="sh-note is-warn">{{ $t('guest.invalid_phone') }} <a href="{{ route('customer.events.guests.edit', $ev + ['guest' => $guest->id]) }}">{{ $t('guest.edit_guest') }}</a></p>
        @endif

        @if ($link['reason'] !== 'ok')
            <section class="sh-empty">
                <p>{{ $t('guest.no_link.'.$link['reason']) }}</p>
                <a class="cu-button cu-button-primary" href="{{ route('customer.events.invitation.guest', $ev + ['guest' => $guest->id]) }}">{{ $t('guest.open_card') }}</a>
            </section>
        @else
            <div class="sh-layout">
                @if ($canShare)
                    <div class="sh-actions">
                        <div class="sh-send sh-send-2">
                            <a class="cu-button cu-button-primary sh-big sh-wa" target="_blank" rel="noopener noreferrer" href="{{ ShareMessage::whatsappUrl($phone, $message) }}" data-sh-action="whatsapp">{{ $t('session.open_whatsapp') }}</a>
                            <a class="cu-button cu-button-ghost sh-big" href="{{ ShareMessage::smsUrl($phone, $message) }}" data-sh-action="sms">{{ $t('session.open_sms') }}</a>
                        </div>
                        <div class="sh-confirm" data-sh-confirm hidden role="group" aria-labelledby="sh-confirm-q">
                            <p id="sh-confirm-q"><strong data-sh-confirm-q></strong><small>{{ $t('session.did_send_text') }}</small></p>
                            <div>
                                <button type="button" class="cu-button cu-button-primary" data-sh-sent>{{ $t('session.sent') }}</button>
                                <button type="button" class="cu-button cu-button-ghost" data-sh-notyet>{{ $t('session.not_sent') }}</button>
                            </div>
                        </div>
                    </div>
                @endif
                <figure class="sh-card">
                    <img src="{{ $cardUrl }}" alt="{{ $t('public.card_alt', ['name' => $guest->name]) }}" decoding="async">
                    @if ($link['url'])<figcaption><a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer">{{ $t('session.preview_link') }}</a></figcaption>@endif
                </figure>
                @if ($canShare)
                    <div class="sh-message">
                        @include('reference_ui.partials.share_message_editor', ['templateKey' => $templateKey, 'message' => $message, 'name' => 'message', 'previewName' => $guest->name, 'hidePreview' => true, 'hideInsert' => true, 'rendered' => true, 'label' => $t('guest.message')])
                    </div>
                    <div class="sh-tools">
                        <button type="button" class="ed-tool" data-sh-action="share">{{ $t('session.share') }}</button>
                        <button type="button" class="ed-tool" data-sh-share-image>{{ $t('session.share_card') }}</button>
                        <button type="button" class="ed-tool" data-sh-copy="message">{{ $t('session.copy_message') }}</button>
                        <button type="button" class="ed-tool" data-sh-copy="link">{{ $t('session.copy_link') }}</button>
                        <a class="ed-tool" href="{{ $cardUrl }}?download=1" download>{{ $t('session.download') }}</a>
                    </div>
                    <p class="sh-toast" data-sh-toast role="status" aria-live="polite" hidden></p>
                @else
                    <div class="sh-tools"><a class="ed-tool" href="{{ $cardUrl }}?download=1" download>{{ $t('session.download') }}</a></div>
                @endif
            </div>
        @endif

        <section class="cu-panel" aria-labelledby="sh-hist">
            <h2 id="sh-hist" class="cu-panel-title">{{ $t('guest.history') }}</h2>
            @if ($history->isEmpty())
                <p class="cu-help">{{ $t('guest.history_empty') }}</p>
            @else
                <ul class="sh-list">
                    @foreach ($history as $item)
                        <li>
                            <span><strong>{{ $t('channels.'.$item->channel) }}</strong>
                                <small>{{ Carbon::parse($item->updated_at)->locale($language)->isoFormat('D MMM YYYY, HH:mm') }}@if ($item->attempts > 0) · {{ $t('guest.attempts', ['count' => $item->attempts]) }}@endif</small></span>
                            <span class="is-pill {{ $stateTone[$item->status] ?? 'muted' }}">{{ $t('statuses.'.$item->status) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="sh-note">{{ $t('honesty') }}</p>
        </section>
    </main>
</div>
<script type="application/json" id="sh-config">@json($config)</script>
</body>
</html>
