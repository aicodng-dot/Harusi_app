@php
    $t = fn (string $key, array $replace = []) => __('event_details.'.$key, $replace, $language);
    $typeLabel = __('event_type.types.'.$eventType, [], $language);
    $alert = $formErrors['form'] ?? ($formErrors === [] ? null : $t('check_fields'));
    $fieldError = fn (string $field) => $formErrors[$field] ?? '';
    $describedBy = fn (string $field, string $base = '') => trim($base.' '.($fieldError($field) !== '' ? 'ed-error-'.$field : ''));
    $clientMessages = [
        'event_name' => $t('name_required.'.$eventType),
        'event_datetime' => $t('datetime_required'),
        'event_datetime_past' => $t('datetime_past'),
        'venue_name' => $t('venue_required'),
        'check' => $t('check_fields'),
    ];
    $icon = fn (string $name) => match ($name) {
        'person' => '<circle cx="11" cy="8" r="4.2"/><path d="M3.5 21v-1.2c0-3.4 3.4-5.4 7.5-5.4s7.5 2 7.5 5.4V21z"/><circle cx="19.6" cy="5" r="1.4" fill="currentColor" stroke="none"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.6"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M8.5 14h.01M12 14h.01M8.5 17h.01M12 17h.01" stroke-width="2.6"/>',
        'pin' => '<path d="M12 21.5s-7-6-7-12a7 7 0 0 1 14 0c0 6-7 12-7 12z"/><circle cx="12" cy="9.5" r="2.6"/>',
        'pin-solid' => '<path d="M12 22s-7-6.1-7-12.1a7 7 0 0 1 14 0C19 15.9 12 22 12 22z" fill="currentColor" stroke="none"/><circle cx="12" cy="9.8" r="2.6" fill="#fff" stroke="none"/>',
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fcfaf3">
    <title>{{ $t('page_title') }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/05_event_details.css', 'resources/js/reference_ui/05_event_details.js'])
</head>
<body class="ed-body">
    <div class="ed-art" aria-hidden="true">
        <img src="{{ asset('assets/reference_ui/05_Event_Details/05_Event_Details_Footer_Botanicals.webp') }}" alt="" width="838" height="300" decoding="async">
    </div>
    <div class="ed-page">
        <header class="ed-header">
            <a class="ed-back" href="{{ route('customer.events.create') }}" aria-label="{{ $t('back') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12H4m7-7-7 7 7 7"/></svg>
            </a>
            <div class="ed-brand">
                <img src="{{ asset('assets/pangaevents-mark.svg') }}" width="68" height="76" alt="">
                <span>PangaEvents</span>
            </div>
            <span class="ed-header-spacer" aria-hidden="true"></span>
        </header>

        <main class="ed-main" aria-labelledby="ed-heading">
            <h1 id="ed-heading" class="ed-heading">{{ $t('heading') }}</h1>
            <p class="ed-intro">{{ $t('intro') }}</p>
            <p class="ed-type">
                <span class="ed-type-label">{{ $t('type') }}:</span>
                <strong data-event-type="{{ $eventType }}">{{ $typeLabel }}</strong>
                <a href="{{ route('customer.events.create') }}">{{ $t('change_type') }}</a>
                @if ($workspaceName)
                    <span class="ed-type-workspace">{{ $t('workspace', ['name' => $workspaceName]) }}</span>
                @endif
            </p>

            <p id="ed-alert" class="ed-alert" role="alert" @unless ($alert) hidden @endunless>{{ $alert }}</p>

            <form class="ed-form" method="POST" action="{{ route('customer.events.store') }}" novalidate data-ed-form>
                @csrf
                <input type="hidden" name="request_key" value="{{ $requestKey }}">

                <div @class(['ed-field', 'has-error' => $fieldError('event_name') !== ''])>
                    <span class="ed-field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $icon('person') !!}</svg></span>
                    <div class="ed-field-body">
                        <label class="ed-label" for="ed-event-name">{{ $t('name_label.'.$eventType) }}</label>
                        <div class="ed-control">
                            <input id="ed-event-name" name="event_name" type="text" value="{{ $values['event_name'] }}" maxlength="160" autocomplete="off" autocapitalize="words" placeholder="{{ $t('name_placeholder.'.$eventType) }}" required
                                   @if ($fieldError('event_name') !== '') aria-invalid="true" aria-describedby="ed-error-event_name" @endif>
                        </div>
                        <p id="ed-error-event_name" class="ed-error" @if ($fieldError('event_name') === '') hidden @endif>{{ $fieldError('event_name') }}</p>
                    </div>
                </div>

                <div @class(['ed-field', 'has-error' => $fieldError('event_datetime') !== ''])>
                    <span class="ed-field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $icon('calendar') !!}</svg></span>
                    <div class="ed-field-body">
                        <label class="ed-label" for="ed-event-datetime">{{ $t('datetime_label') }}</label>
                        <div class="ed-control ed-control-datetime">
                            <input id="ed-event-datetime" name="event_datetime" type="datetime-local" value="{{ $values['event_datetime'] }}" min="{{ $minDate }}" step="300" required
                                   aria-describedby="{{ $describedBy('event_datetime', 'ed-datetime-hint') }}" @if ($fieldError('event_datetime') !== '') aria-invalid="true" @endif>
                            <svg class="ed-trailing" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icon('calendar') !!}</svg>
                        </div>
                        <p id="ed-datetime-hint" class="sr-only">{{ $t('datetime_hint') }}</p>
                        <p id="ed-error-event_datetime" class="ed-error" @if ($fieldError('event_datetime') === '') hidden @endif>{{ $fieldError('event_datetime') }}</p>
                    </div>
                </div>

                <div @class(['ed-field', 'has-error' => $fieldError('venue_name') !== ''])>
                    <span class="ed-field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $icon('pin') !!}</svg></span>
                    <div class="ed-field-body">
                        <label class="ed-label" for="ed-venue-name">{{ $t('venue_label') }}</label>
                        <div class="ed-control">
                            <input id="ed-venue-name" name="venue_name" type="text" value="{{ $values['venue_name'] }}" maxlength="160" autocomplete="off" placeholder="{{ $t('venue_placeholder') }}" required
                                   @if ($fieldError('venue_name') !== '') aria-invalid="true" aria-describedby="ed-error-venue_name" @endif>
                            <svg class="ed-trailing" viewBox="0 0 24 24" aria-hidden="true">{!! $icon('pin-solid') !!}</svg>
                        </div>
                        <p id="ed-error-venue_name" class="ed-error" @if ($fieldError('venue_name') === '') hidden @endif>{{ $fieldError('venue_name') }}</p>
                    </div>
                </div>

                <button class="ed-submit" type="submit" data-ed-submit data-submitting-label="{{ $t('submitting') }}">
                    <span class="ed-submit-label">{{ $t('submit') }}</span>
                </button>
            </form>
        </main>
    </div>
    <script type="application/json" id="ed-messages">@json($clientMessages)</script>
</body>
</html>
