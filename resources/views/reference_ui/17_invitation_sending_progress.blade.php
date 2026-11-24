@php
    use App\Services\Invitations\InvitationWording;
    use Illuminate\Support\Carbon;

    $t = fn (string $key, array $replace = []) => __('invitation_studio.'.$key, $replace, $language);
    $sent = $counts['accepted'] + $counts['delivered'];
    $percent = $counts['guests'] > 0 ? (int) round(100 * $sent / $counts['guests']) : 0;
    $circumference = 2 * M_PI * 44;
    $date = $event->event_date ? Carbon::parse($event->event_date) : null;
    $tone = ['pending' => 'warn', 'sent' => 'info', 'delivered' => 'ok', 'read' => 'ok', 'failed' => 'bad', 'skipped' => 'muted'];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('status.page_title') }} · {{ $event->event_name }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/invitation_steps.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page">
    <header class="is-header">
        <a class="is-back" href="{{ route('customer.events.invitation.guests', ['event' => $event->id]) }}" aria-label="{{ $t('back') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
        </a>
        <span class="is-brand">PangaEvents</span>
        <nav class="is-languages" aria-label="{{ $t('language') }}">
            @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                <a @class(['is-language', 'is-active' => $language === $locale]) href="{{ route('customer.events.invitation.status', ['event' => $event->id, 'lang' => $locale]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $code }}</a>
            @endforeach
        </nav>
    </header>

    <main class="is-main" aria-labelledby="st-heading">
        <h1 id="st-heading" class="is-heading">{{ $t('status.title') }}</h1>
        <p class="is-intro">{{ $t('status.intro') }}</p>
        @include('reference_ui.partials.invitation_steps', ['stage' => 'share'])

        <section class="st-event">
            <img src="{{ route('customer.events.invitation.thumb', ['event' => $event->id, 'template' => $event->invitation_template ?? \App\Services\Invitations\InvitationDesign::defaultTemplate($event->event_type)]) }}" alt="" width="330" height="440">
            <div>
                <h2>{{ $event->event_name }}</h2>
                <p>{{ $date ? InvitationWording::dateParts($date, $language)['date_short'] : '' }}</p>
                <p>{{ $whatsapp ? $t('status.channel_whatsapp') : $t('status.channel_download') }}</p>
            </div>
        </section>
        @unless ($whatsapp)
            <p class="is-alert is-alert-info">{{ $t('whatsapp_not_configured') }}</p>
        @endunless

        <section class="st-panel" aria-label="{{ $t('status.sent_of') }}">
            <div class="st-top">
                <div class="st-ring">
                    <svg viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="44" fill="none" stroke="#e3ebe7" stroke-width="9"/><circle cx="50" cy="50" r="44" fill="none" stroke="#17463a" stroke-width="9" stroke-linecap="round" stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $circumference * (1 - $percent / 100) }}"/></svg>
                    <span><strong>{{ $percent }}%</strong><small>{{ $t('status.complete') }}</small></span>
                </div>
                <div>
                    <div class="st-big">{{ $sent }} <span>/ {{ $counts['guests'] }}</span></div>
                    <div style="color:var(--is-muted)">{{ $t('status.sent_of') }}</div>
                    <div class="st-bar"><i style="width: {{ $percent }}%"></i></div>
                </div>
            </div>
            <div class="st-grid">
                <div class="st-tile ok"><small>{{ $t('status.delivered') }}</small><strong>{{ $counts['delivered'] }}</strong></div>
                <div class="st-tile info"><small>{{ $t('status.accepted') }}</small><strong>{{ $counts['accepted'] }}</strong></div>
                <div class="st-tile warn"><small>{{ $t('status.pending') }}</small><strong>{{ $counts['pending'] }}</strong></div>
                <div class="st-tile bad"><small>{{ $t('status.failed') }}</small><strong>{{ $counts['failed'] }}</strong></div>
                <div class="st-tile muted"><small>{{ $t('status.not_sent') }}</small><strong>{{ $counts['not_sent'] }}</strong></div>
                <div class="st-tile muted"><small>{{ $t('status.cards') }}</small><strong>{{ $counts['cards'] }}@if ($counts['stale']) <span style="font-size:13px">(+{{ $counts['stale'] }} {{ $t('status.stale') }})</span>@endif</strong></div>
            </div>
        </section>

        <h2 class="st-subhead">{{ $t('status.recent') }}</h2>
        @if ($recent->isEmpty())
            <p class="rc-empty">{{ $t('status.no_recent') }}</p>
        @else
            <ul class="rc-list">
                @foreach ($recent as $row)
                    <li class="rc-item" style="grid-template-columns: calc(78 * var(--rp)) minmax(0,1fr) auto 18px">
                        <span class="rc-avatar t{{ $row->id % 4 }}" aria-hidden="true">{{ str_replace(' & ', '', $row->initials) }}</span>
                        <span style="min-width:0"><a class="rc-name" href="{{ route('customer.events.invitation.guest', ['event' => $event->id, 'guest' => $row->id]) }}">{{ $row->name }}</a><span class="rc-sub">{{ $row->phone }}</span></span>
                        <span class="is-pill {{ $tone[$row->status] ?? 'muted' }}">{{ $t('guests.message_states.'.$row->status) }}</span>
                        <svg class="rc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="is-alert is-alert-info">{{ $t('status.note') }}</p>
        <div class="is-actions">
            <a class="is-primary" href="{{ route('customer.events.invitation.guests', ['event' => $event->id]) }}">{{ $t('status.manage') }}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg></a>
        </div>
    </main>
</div>
</body>
</html>
