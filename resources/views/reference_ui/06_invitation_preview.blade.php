@php
    $t = fn (string $key, array $replace = []) => __('invitation_studio.'.$key, $replace, $language);
    $cardUrl = fn (string $format, bool $download = false) => route('customer.events.invitation.card', array_filter(['event' => $event->id, 'guest' => $guest->id, 'format' => $format, 'download' => $download ? 1 : null, 'v' => $card['generated_at'] ? substr(md5((string) $card['generated_at']), 0, 8) : null]));
    $guestUrl = fn (?int $id) => $id ? route('customer.events.invitation.guest', ['event' => $event->id, 'guest' => $id]) : null;
    $cancelled = $guest->status === \App\Models\Guest::STATUS_CANCELLED;
    $messageStatus = $message?->status;
    $config = [
        'routes' => [
            'generate' => route('customer.events.invitation.generate', ['event' => $event->id]),
            'send' => route('customer.events.invitation.send', ['event' => $event->id]),
        ],
        'guest' => $guest->id,
        'messages' => ['working' => $t('preview.working'), 'network' => $t('network'), 'notConfigured' => $t('whatsapp_not_configured'),
            'sentResult' => $t('preview.sent_result'), 'states' => __('invitation_studio.guests.message_states', [], $language)],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $t('preview.page_title') }} · {{ $guest->name }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/js/reference_ui/06_invitation_preview.js', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page">
    <header class="is-header">
        <a class="is-back" href="{{ route('customer.events.invitation.guests', ['event' => $event->id]) }}" aria-label="{{ $t('preview.back_to_list') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6"/></svg>
        </a>
        <span class="is-brand">PangaEvents</span>
        <nav class="is-languages" aria-label="{{ $t('language') }}">
            @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                <a @class(['is-language', 'is-active' => $language === $locale]) href="{{ route('customer.events.invitation.guest', ['event' => $event->id, 'guest' => $guest->id, 'lang' => $locale]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $code }}</a>
            @endforeach
        </nav>
    </header>

    <main class="is-main" aria-labelledby="pv-heading">
        <h1 id="pv-heading" class="is-heading">{{ $t('preview.title') }}</h1>
        <p class="is-intro">{{ $t('preview.intro', ['name' => $guest->name]) }}</p>

        @if ($warnings !== [])
            <div class="is-alert is-alert-warn" role="status"><ul>@foreach ($warnings as $warning)<li>{{ $t('warnings.'.$warning) }}</li>@endforeach</ul></div>
        @endif
        @if ($cancelled)
            <p class="is-alert is-alert-error">{{ $t('preview.cancelled') }}</p>
        @elseif ($credential === 'revoked')
            <p class="is-alert is-alert-error">{{ $t('preview.revoked') }}</p>
        @elseif ($card['state'] === 'stale')
            <p class="is-alert is-alert-warn">{{ $t('preview.stale') }}</p>
        @endif
        <p id="pv-alert" class="is-alert" role="status" hidden></p>

        <div class="pv-card">
            @if ($card['state'] !== 'none')
                <img src="{{ $cardUrl('png') }}" alt="{{ $t('preview.image_alt', ['name' => $guest->name]) }}" width="1500" height="2000">
            @else
                <div class="pv-empty">
                    <p>{{ $t('preview.no_card') }}</p>
                    @unless ($readOnly || $cancelled || $credential === 'revoked')
                        <button type="button" class="is-primary" style="width:auto;padding:0 24px" data-pv-generate>{{ $t('preview.create') }}</button>
                    @endunless
                </div>
            @endif
        </div>

        <nav class="pv-nav" aria-label="{{ $t('preview.position', ['position' => $position, 'count' => $count]) }}">
            @if ($guestUrl($previous))
                <a href="{{ $guestUrl($previous) }}" aria-label="{{ $t('preview.previous') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg></a>
            @else
                <span class="is-disabled" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 5-7 7 7 7"/></svg></span>
            @endif
            <span class="pv-position">{{ $t('preview.position', ['position' => $position, 'count' => $count]) }}</span>
            @if ($guestUrl($next))
                <a href="{{ $guestUrl($next) }}" aria-label="{{ $t('preview.next') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></a>
            @else
                <span class="is-disabled" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 7 7-7 7"/></svg></span>
            @endif
        </nav>

        <div class="pv-facts">
            <div class="pv-fact"><small>{{ $t('preview.pass') }}</small><strong>{{ $passLine }}</strong></div>
            <div class="pv-fact"><small>{{ $t('preview.allowed') }}</small><strong>{{ $guest->allowed_entries }}</strong></div>
            <div class="pv-fact"><small>{{ $t('preview.message') }}</small><strong id="pv-message">{{ $messageStatus ? $t('guests.message_states.'.$messageStatus) : $t('preview.not_sent') }}</strong></div>
        </div>

        @if ($card['state'] !== 'none')
            <div class="pv-downloads" role="group" aria-label="{{ $t('preview.downloads') }}">
                <a class="ed-tool" href="{{ $cardUrl('png', true) }}" download>{{ $t('preview.download_png') }}</a>
                <a class="ed-tool" href="{{ $cardUrl('jpg', true) }}" download>{{ $t('preview.download_jpg') }}</a>
                <a class="ed-tool" href="{{ $cardUrl('pdf', true) }}" download>{{ $t('preview.download_pdf') }}</a>
            </div>
        @endif

        <div class="is-actions">
            <a class="is-secondary" href="{{ route('customer.events.invitation', ['event' => $event->id]) }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="7"/></svg>
                {{ $t('preview.change_design') }}
            </a>
            @if ($card['state'] === 'stale' && ! $readOnly)
                <button type="button" class="is-primary" data-pv-generate>{{ $t('preview.regenerate') }}</button>
            @elseif ($card['state'] === 'current' && ! $readOnly)
                <button type="button" class="is-primary" data-pv-send data-resend="{{ $messageStatus && in_array($messageStatus, ['pending', 'sent', 'delivered', 'read'], true) ? 1 : 0 }}" @disabled(! $whatsapp)>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4Z"/></svg>
                    {{ $messageStatus ? $t('preview.resend') : $t('preview.send') }}
                </button>
                @unless ($whatsapp)
                    <p class="ed-help" style="text-align:center;margin:0">{{ $t('whatsapp_not_configured') }}</p>
                @endunless
            @endif
        </div>
    </main>
</div>
<script type="application/json" id="pv-config">@json($config)</script>
</body>
</html>
