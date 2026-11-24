@php
    use App\Models\Guest;

    $t = fn (string $key, array $replace = []) => __('customer_guests.'.$key, $replace, $language);
    $has = fn (string $field) => in_array($field, $errors, true);
    $icons = [
        'single' => '<circle cx="12" cy="8" r="3.4"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
        'double' => '<circle cx="9" cy="8" r="3.2"/><path d="M2.8 20a6.2 6.2 0 0 1 12.4 0M15.5 5a3.2 3.2 0 0 1 0 6.2M21.2 20a6.2 6.2 0 0 0-3.9-5.7"/>',
        'special' => '<circle cx="12" cy="8.5" r="2.8"/><path d="M6.8 20a5.2 5.2 0 0 1 10.4 0M5.6 6.2a2.3 2.3 0 1 0 1 4.2M2.5 17.5a3.8 3.8 0 0 1 3.4-3.7M18.4 6.2a2.3 2.3 0 1 1-1 4.2M21.5 17.5a3.8 3.8 0 0 0-3.4-3.7"/>',
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('add.page_title') }} · {{ $event->event_name }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/invitation_steps.css', 'resources/js/reference_ui/customer_guests.js', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
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
                <a @class(['is-language', 'is-active' => $language === $locale]) href="{{ route('customer.events.guests.create', ['event' => $event->id, 'lang' => $locale]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $code }}</a>
            @endforeach
        </nav>
    </header>

    <main class="is-main" aria-labelledby="ag-heading">
        <h1 id="ag-heading" class="is-heading">{{ $t('add.title') }}</h1>
        <p class="is-intro">{{ $t('add.intro') }}</p>
        @include('reference_ui.partials.invitation_steps', ['stage' => 'guests'])

        @if (session('guest_notice'))
            <p class="is-alert is-alert-ok" role="status">{{ session('guest_notice') }}</p>
        @endif
        @if ($readOnly)
            <p class="is-alert is-alert-info" role="status">{{ $t('read_only') }}</p>
        @endif
        @if ($has('locked'))
            <p class="is-alert is-alert-error" role="alert">{{ $t('errors.locked') }}</p>
        @endif

        <form class="ag-form" method="POST" action="{{ route('customer.events.guests.store', ['event' => $event->id]) }}" novalidate data-ag-form>
            @csrf
            <fieldset class="ag-fieldset" @disabled($readOnly)>
                @if ($duplicate)
                    <div class="is-alert is-alert-warn" role="alert">
                        <p style="margin:0 0 10px">{{ $t('add.duplicate.'.$duplicate) }}</p>
                        <button type="submit" class="ed-tool" name="allow_duplicate" value="1">{{ $t('add.duplicate.confirm') }}</button>
                    </div>
                @endif

                <div class="ag-field">
                    <label class="ag-label" for="ag-name">{{ $t('add.name') }}</label>
                    <div @class(['ag-input', 'is-invalid' => $has('name')])>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>
                        <input id="ag-name" type="text" name="name" value="{{ $values['name'] }}" maxlength="120" required autocomplete="off" placeholder="{{ $t('add.name_placeholder') }}"
                            @if ($has('name')) aria-invalid="true" aria-describedby="ag-name-error" @endif @if (! $duplicate && $errors === []) autofocus @endif>
                    </div>
                    @if ($has('name'))<p class="ag-error" id="ag-name-error">{{ $t('errors.name') }}</p>@endif
                </div>

                <div class="ag-field">
                    <label class="ag-label" for="ag-phone">{{ $t('add.phone') }}</label>
                    <div @class(['ag-input', 'is-invalid' => $has('phone_number')])>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
                        <input id="ag-phone" type="tel" name="phone_number" value="{{ $values['phone_number'] }}" maxlength="40" required inputmode="tel" autocomplete="off" placeholder="{{ $t('add.phone_placeholder') }}"
                            @if ($has('phone_number')) aria-invalid="true" aria-describedby="ag-phone-error" @endif>
                    </div>
                    @if ($has('phone_number'))<p class="ag-error" id="ag-phone-error">{{ $t('errors.phone_number') }}</p>@endif
                </div>

                <fieldset class="ag-field ag-fieldset">
                    <legend class="ag-label">{{ $t('add.pass') }}</legend>
                    <div class="ag-passes">
                        @foreach ([Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL] as $pass)
                            <label class="ag-pass">
                                <input type="radio" name="pass_type" value="{{ $pass }}" @checked($values['pass_type'] === $pass) data-ag-pass>
                                <span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$pass] !!}</svg>
                                    {{ $t('add.passes.'.$pass) }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @if ($has('pass_type'))<p class="ag-error">{{ $t('errors.pass_type') }}</p>@endif
                </fieldset>

                <div class="ag-field">
                    <label class="ag-label" for="ag-count">{{ $t('add.count') }}</label>
                    <p class="ag-help" id="ag-count-help" data-single="{{ $t('add.count_fixed.single') }}" data-double="{{ $t('add.count_fixed.double') }}" data-special="{{ $t('add.count_special') }}">{{ $t('add.count_help') }}</p>
                    <div @class(['ag-stepper', 'is-invalid' => $has('allowed_entries')])>
                        <button type="button" data-ag-step="-1" aria-label="{{ $t('add.minus') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 12h12"/></svg></button>
                        <input id="ag-count" type="number" name="allowed_entries" value="{{ $values['allowed_entries'] }}" min="1" max="{{ Guest::MAX_ALLOWED_ENTRIES }}" step="1" inputmode="numeric" aria-describedby="ag-count-help"
                            data-ag-count data-min-special="{{ Guest::MIN_SPECIAL_ENTRIES }}" data-max="{{ Guest::MAX_ALLOWED_ENTRIES }}">
                        <button type="button" data-ag-step="1" aria-label="{{ $t('add.plus') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 12h12M12 6v12"/></svg></button>
                    </div>
                    @if ($has('allowed_entries'))<p class="ag-error">{{ $t('errors.allowed_entries') }}</p>@endif
                </div>

                <p class="ag-note">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.500 7-10V6z"/><path d="m9 12 2 2 4-4.500"/></svg>
                    <span>{{ $t('add.note') }}</span>
                </p>

                <div class="is-actions">
                    <button type="submit" class="is-primary">{{ $t('add.save') }}
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-7-7 7 7-7 7"/></svg>
                    </button>
                    <button type="submit" class="is-secondary" name="again" value="1">{{ $t('add.save_again') }}</button>
                </div>
            </fieldset>
        </form>
        <p style="margin-top:14px"><a class="is-small-link" href="{{ route('customer.events.guests.import', ['event' => $event->id]) }}">{{ $t('add.import_link') }} →</a></p>
    </main>
</div>
</body>
</html>
