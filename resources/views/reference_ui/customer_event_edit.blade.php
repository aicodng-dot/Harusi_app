@php
    $t = fn (string $key, array $replace = []) => __('customer_manage.event.'.$key, $replace, $language);
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('page_title') }} · {{ $event->event_name }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_manage.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page">
    <header class="is-header">
        <a class="is-back" href="{{ route('customer.events.dashboard', ['event' => $event->id]) }}" aria-label="{{ __('customer_manage.back', [], $language) }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
        </a>
        <span class="is-brand">PangaEvents</span>
        <nav class="is-languages" aria-label="{{ __('customer_manage.language', [], $language) }}">
            @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                <a @class(['is-language', 'is-active' => $language === $locale]) href="{{ route('customer.events.edit', ['event' => $event->id, 'lang' => $locale]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $code }}</a>
            @endforeach
        </nav>
    </header>

    <main class="is-main" aria-labelledby="ev-heading">
        <h1 id="ev-heading" class="is-heading">{{ $t('title') }}</h1>
        <p class="is-intro">{{ $t('intro') }}</p>

        @if ($readOnly)
            <p class="is-alert is-alert-info" role="status">{{ $t('read_only') }}</p>
        @endif
        @isset($formErrors['delete'])<p class="is-alert is-alert-error" role="alert">{{ $formErrors['delete'] }}</p>@endisset
        @isset($formErrors['confirm_name'])<p class="is-alert is-alert-error" role="alert">{{ $formErrors['confirm_name'] }}</p>@endisset

        <form class="cm-stack" method="POST" action="{{ route('customer.events.update', ['event' => $event->id]) }}" novalidate>
            @csrf
            @method('PUT')
            <fieldset class="ag-fieldset cm-stack" @disabled($readOnly)>
                <div>
                    <label class="ag-label" for="ev-name">{{ $t('name') }}</label>
                    <div @class(['ag-input', 'is-invalid' => isset($formErrors['event_name'])])>
                        <input id="ev-name" name="event_name" value="{{ $values['event_name'] }}" maxlength="160" required @if (isset($formErrors['event_name'])) aria-invalid="true" aria-describedby="ev-name-error" @endif>
                    </div>
                    @isset($formErrors['event_name'])<p class="ag-error" id="ev-name-error">{{ $formErrors['event_name'] }}</p>@endisset
                </div>
                <div>
                    <label class="ag-label" for="ev-date">{{ $t('datetime') }}</label>
                    <div @class(['ag-input', 'is-invalid' => isset($formErrors['event_datetime'])])>
                        <input id="ev-date" type="datetime-local" name="event_datetime" value="{{ $values['event_datetime'] }}" required @if (isset($formErrors['event_datetime'])) aria-invalid="true" aria-describedby="ev-date-error" @endif>
                    </div>
                    @isset($formErrors['event_datetime'])<p class="ag-error" id="ev-date-error">{{ $formErrors['event_datetime'] }}</p>@endisset
                </div>
                <div>
                    <label class="ag-label" for="ev-venue">{{ $t('venue') }}</label>
                    <div @class(['ag-input', 'is-invalid' => isset($formErrors['venue_name'])])>
                        <input id="ev-venue" name="venue_name" value="{{ $values['venue_name'] }}" maxlength="160" required @if (isset($formErrors['venue_name'])) aria-invalid="true" aria-describedby="ev-venue-error" @endif>
                    </div>
                    @isset($formErrors['venue_name'])<p class="ag-error" id="ev-venue-error">{{ $formErrors['venue_name'] }}</p>@endisset
                </div>
                <div class="is-actions">
                    <button type="submit" class="is-primary">{{ $t('save') }}</button>
                    <a class="is-secondary" href="{{ route('customer.events.dashboard', ['event' => $event->id]) }}">{{ __('customer_manage.cancel', [], $language) }}</a>
                </div>
            </fieldset>
        </form>

        <section class="cm-section cm-danger" aria-labelledby="ev-delete">
            <h2 id="ev-delete">{{ $t('delete_title') }}</h2>
            @if (! $canDelete)
                <p>{{ $t('owner_only') }}</p>
            @elseif ($deleteBlocker !== null)
                <p>{{ $t('blocked.'.$deleteBlocker) }}</p>
            @else
                <p>{{ $t('delete_intro') }}</p>
                <form method="POST" action="{{ route('customer.events.destroy', ['event' => $event->id]) }}" class="cm-stack"
                      onsubmit="return window.confirm(this.dataset.message)" data-message="{{ $t('delete_intro') }}">
                    @csrf
                    @method('DELETE')
                    <div>
                        <label class="ag-label" for="ev-confirm">{{ $t('confirm_label') }}: <strong>{{ $event->event_name }}</strong></label>
                        <input id="ev-confirm" name="confirm_name" class="cm-plain" autocomplete="off" required maxlength="160">
                    </div>
                    <button type="submit" class="cm-danger-button">{{ $t('delete_button') }}</button>
                </form>
            @endif
        </section>
    </main>
</div>
</body>
</html>
