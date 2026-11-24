@php
    use App\Models\Guest;

    $g = fn (string $key, array $replace = []) => __('customer_guests.'.$key, $replace, $language);
    $t = fn (string $key, array $replace = []) => __('customer_manage.guest.'.$key, $replace, $language);
    $has = fn (string $field) => in_array($field, $errors, true);
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('page_title') }} · {{ $event->event_name }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_manage.css', 'resources/js/reference_ui/customer_guests.js', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page">
    <header class="is-header">
        <a class="is-back" href="{{ route('customer.events.invitation.guests', ['event' => $event->id]) }}" aria-label="{{ __('customer_manage.back', [], $language) }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
        </a>
        <span class="is-brand">PangaEvents</span>
        <nav class="is-languages" aria-label="{{ __('customer_manage.language', [], $language) }}">
            @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                <a @class(['is-language', 'is-active' => $language === $locale]) href="{{ route('customer.events.guests.edit', ['event' => $event->id, 'guest' => $guest->id, 'lang' => $locale]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $code }}</a>
            @endforeach
        </nav>
    </header>

    <main class="is-main" aria-labelledby="ge-heading">
        <h1 id="ge-heading" class="is-heading">{{ $t('title') }}</h1>
        <p class="is-intro">{{ $t('intro') }}</p>
        <p class="cm-stat">{{ $t('used_note', ['used' => (int) $guest->used_entries, 'allowed' => (int) $guest->allowed_entries]) }}</p>

        @if ($readOnly)
            <p class="is-alert is-alert-info" role="status">{{ $g('read_only') }}</p>
        @endif
        @if ($has('locked'))<p class="is-alert is-alert-error" role="alert">{{ $g('errors.locked') }}</p>@endif
        @if ($has('allowed_below_used'))<p class="is-alert is-alert-error" role="alert">{{ $t('allowed_below_used') }}</p>@endif
        @if ($has('admitted'))<p class="is-alert is-alert-error" role="alert">{{ $t('admitted') }}</p>@endif
        @if ($has('confirm_delete'))<p class="is-alert is-alert-error" role="alert">{{ $t('confirm_delete') }}</p>@endif

        <form class="ag-form" method="POST" action="{{ route('customer.events.guests.update', ['event' => $event->id, 'guest' => $guest->id]) }}" novalidate data-ag-form>
            @csrf
            @method('PUT')
            <fieldset class="ag-fieldset" @disabled($readOnly)>
                @if ($duplicate)
                    <div class="is-alert is-alert-warn" role="alert">
                        <p style="margin:0 0 10px">{{ $g('add.duplicate.'.$duplicate) }}</p>
                        <button type="submit" class="ed-tool" name="allow_duplicate" value="1">{{ $g('add.duplicate.confirm') }}</button>
                    </div>
                @endif
                <div class="ag-field">
                    <label class="ag-label" for="ge-name">{{ $g('add.name') }}</label>
                    <div @class(['ag-input', 'is-invalid' => $has('name')])>
                        <input id="ge-name" type="text" name="name" value="{{ $values['name'] }}" maxlength="120" required autocomplete="off" @if ($has('name')) aria-invalid="true" @endif>
                    </div>
                    @if ($has('name'))<p class="ag-error">{{ $g('errors.name') }}</p>@endif
                </div>
                <div class="ag-field">
                    <label class="ag-label" for="ge-phone">{{ $g('add.phone') }}</label>
                    <div @class(['ag-input', 'is-invalid' => $has('phone_number')])>
                        <input id="ge-phone" type="tel" name="phone_number" value="{{ $values['phone_number'] }}" maxlength="40" required inputmode="tel" autocomplete="off" @if ($has('phone_number')) aria-invalid="true" @endif>
                    </div>
                    @if ($has('phone_number'))<p class="ag-error">{{ $g('errors.phone_number') }}</p>@endif
                </div>
                <fieldset class="ag-field ag-fieldset">
                    <legend class="ag-label">{{ $g('add.pass') }}</legend>
                    <div class="ag-passes">
                        @foreach ([Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL] as $pass)
                            <label class="ag-pass">
                                <input type="radio" name="pass_type" value="{{ $pass }}" @checked($values['pass_type'] === $pass) data-ag-pass>
                                <span>{{ $g('add.passes.'.$pass) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if ($has('pass_type'))<p class="ag-error">{{ $g('errors.pass_type') }}</p>@endif
                </fieldset>
                <div class="ag-field">
                    <label class="ag-label" for="ag-count">{{ $g('add.count') }}</label>
                    <p class="ag-help" id="ag-count-help" data-single="{{ $g('add.count_fixed.single') }}" data-double="{{ $g('add.count_fixed.double') }}" data-special="{{ $g('add.count_special') }}">{{ $g('add.count_help') }}</p>
                    <div @class(['ag-stepper', 'is-invalid' => $has('allowed_entries')])>
                        <button type="button" data-ag-step="-1" aria-label="{{ $g('add.minus') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 12h12"/></svg></button>
                        <input id="ag-count" type="number" name="allowed_entries" value="{{ $values['allowed_entries'] }}" min="1" max="{{ Guest::MAX_ALLOWED_ENTRIES }}" step="1" inputmode="numeric" aria-describedby="ag-count-help"
                            data-ag-count data-min-special="{{ Guest::MIN_SPECIAL_ENTRIES }}" data-max="{{ Guest::MAX_ALLOWED_ENTRIES }}">
                        <button type="button" data-ag-step="1" aria-label="{{ $g('add.plus') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 12h12M12 6v12"/></svg></button>
                    </div>
                    @if ($has('allowed_entries'))<p class="ag-error">{{ $g('errors.allowed_entries') }}</p>@endif
                </div>
                <div class="is-actions">
                    <button type="submit" class="is-primary">{{ $t('save') }}</button>
                    <a class="is-secondary" href="{{ route('customer.events.invitation.guests', ['event' => $event->id]) }}">{{ __('customer_manage.cancel', [], $language) }}</a>
                </div>
            </fieldset>
        </form>

        @unless ($readOnly)
            <section class="cm-section cm-danger" aria-labelledby="ge-delete">
                <h2 id="ge-delete">{{ $t('delete_title') }}</h2>
                @if ($deletable)
                    <p>{{ $t('delete_intro') }}</p>
                    <form method="POST" action="{{ route('customer.events.guests.destroy', ['event' => $event->id, 'guest' => $guest->id]) }}" class="cm-stack"
                          onsubmit="return window.confirm(this.dataset.message)" data-message="{{ $t('delete_intro') }}">
                        @csrf
                        @method('DELETE')
                        <label class="cm-check"><input type="checkbox" name="confirm" value="1" required> {{ $t('confirm_box') }}</label>
                        <button type="submit" class="cm-danger-button">{{ $t('delete_button') }}</button>
                    </form>
                @else
                    <p>{{ $t('admitted') }}</p>
                @endif
            </section>
        @endunless
    </main>
</div>
</body>
</html>
