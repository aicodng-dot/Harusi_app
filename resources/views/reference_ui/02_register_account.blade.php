@php
    $t = fn (string $key) => __('register.'.$key, [], $language);
    $passwordError = $errors->first('password');
    $mismatch = $passwordError === $t('password_mismatch');
    $fieldErrors = [
        'name' => $errors->first('name'),
        'phone_number' => $errors->first('phone_number'),
        'email' => $errors->first('email'),
        'password' => $mismatch ? '' : $passwordError,
        'password_confirmation' => $mismatch ? $passwordError : '',
        'preferred_language' => $errors->first('preferred_language'),
        'terms' => $errors->first('terms'),
    ];
    $describedBy = fn (string $field, string $extra = '') => trim($extra.' '.($fieldErrors[$field] !== '' ? 'reg-error-'.$field : ''));
    $clientMessages = [
        'name' => $t('name_error'),
        'phone_number' => $t('phone_required'),
        'email' => $t('email_required'),
        'email_format' => $t('email_error'),
        'password' => $t('password_required'),
        'password_confirmation' => $t('confirm_required'),
        'mismatch' => $t('password_mismatch'),
        'terms' => $t('terms_required'),
        'check_fields' => $t('check_fields'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fcfaf3">
    <title>{{ $t('title') }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/02_register_account.css', 'resources/js/reference_ui/02_register_account.js'])
</head>
<body class="reg-body">
    <div class="reg-page">
        <header class="reg-header">
            <a class="reg-back" href="{{ route('reference_ui.10_welcome_how_it_works.show', ['lang' => $language]) }}" aria-label="{{ $t('back') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12H4m7-7-7 7 7 7"/></svg>
            </a>
            <div class="reg-brand">
                <img src="{{ asset('assets/pangaevents-mark.svg') }}" width="68" height="76" alt="">
                <span>PangaEvents</span>
            </div>
            <nav class="reg-languages" aria-label="{{ $t('language') }}">
                @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $label)
                    <a
                        @class(['reg-language', 'is-active' => $language === $locale])
                        href="{{ route('register', ['lang' => $locale]) }}"
                        lang="{{ $locale }}"
                        hreflang="{{ $locale }}"
                        data-reg-language
                        @if ($language === $locale) aria-current="true" @endif
                    >{{ $label }}</a>
                @endforeach
            </nav>
        </header>

        <main class="reg-main" aria-labelledby="register-heading">
            <h1 id="register-heading" class="reg-heading">{{ $t('title') }}</h1>
            <p class="reg-intro">{{ $t('description') }}</p>

            <div id="reg-alert" class="reg-alert" role="alert" @if (! $errors->any()) hidden @endif>
                {{ $errors->first('form') ?: $t('check_fields') }}
            </div>

            <form method="POST" action="{{ route('register.store') }}" class="reg-form" novalidate data-reg-form>
                @csrf

                <div class="reg-group">
                    <label @class(['reg-field', 'has-error' => $fieldErrors['name'] !== '']) for="register-name">
                        <x-auth-icon name="user" class="reg-icon"/>
                        <span class="sr-only">{{ $t('name') }}</span>
                        <input id="register-name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" autocapitalize="words" maxlength="120" placeholder="{{ $t('name') }}" required data-reg-draft @if ($fieldErrors['name'] !== '') aria-invalid="true" @endif @if ($describedBy('name') !== '') aria-describedby="{{ $describedBy('name') }}" @endif>
                    </label>
                    <p id="reg-error-name" class="reg-error" @if ($fieldErrors['name'] === '') hidden @endif>{{ $fieldErrors['name'] }}</p>
                </div>

                <div class="reg-group">
                    <div @class(['reg-field', 'reg-phone', 'has-error' => $fieldErrors['phone_number'] !== ''])>
                        <div class="reg-country">
                            <select aria-label="{{ $t('country') }}"><option value="TZ">{{ $t('tanzania') }} +255</option></select>
                            <span class="reg-country-art" aria-hidden="true">
                                <svg class="reg-flag" viewBox="0 0 30 23"><defs><clipPath id="tz-clip"><rect width="30" height="23" rx="3"/></clipPath></defs><g clip-path="url(#tz-clip)"><path fill="#1eb53a" d="M0 0h30v23H0z"/><path fill="#00a3dd" d="M0 23 30 0v23Z"/><path stroke="#fcd116" stroke-width="9" d="m-3 25 36-27"/><path stroke="#000" stroke-width="6" d="m-3 25 36-27"/></g></svg>
                                <x-auth-icon name="chevron" class="reg-country-chevron"/>
                            </span>
                        </div>
                        <label class="reg-phone-input" for="register-phone">
                            <span class="reg-phone-label">{{ $t('phone') }}</span>
                            <input id="register-phone" name="phone_number" type="tel" inputmode="tel" value="{{ old('phone_number') }}" autocomplete="tel" maxlength="40" placeholder="+255 712 345 678" required data-reg-draft @if ($fieldErrors['phone_number'] !== '') aria-invalid="true" @endif @if ($describedBy('phone_number') !== '') aria-describedby="{{ $describedBy('phone_number') }}" @endif>
                        </label>
                    </div>
                    <p id="reg-error-phone_number" class="reg-error" @if ($fieldErrors['phone_number'] === '') hidden @endif>{{ $fieldErrors['phone_number'] }}</p>
                </div>

                <div class="reg-group">
                    <label @class(['reg-field', 'has-error' => $fieldErrors['email'] !== '']) for="register-email">
                        <x-auth-icon name="mail" class="reg-icon"/>
                        <span class="sr-only">{{ $t('email') }}</span>
                        <input id="register-email" name="email" type="email" inputmode="email" value="{{ old('email') }}" autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="160" placeholder="{{ $t('email') }}" required data-reg-draft @if ($fieldErrors['email'] !== '') aria-invalid="true" @endif @if ($describedBy('email') !== '') aria-describedby="{{ $describedBy('email') }}" @endif>
                    </label>
                    <p id="reg-error-email" class="reg-error" @if ($fieldErrors['email'] === '') hidden @endif>{{ $fieldErrors['email'] }}</p>
                </div>

                @foreach (['password' => 'password', 'password_confirmation' => 'confirm_password'] as $field => $translation)
                    <div class="reg-group">
                        <label @class(['reg-field', 'has-error' => $fieldErrors[$field] !== '']) for="register-{{ $field }}">
                            <x-auth-icon name="lock" class="reg-icon"/>
                            <span class="sr-only">{{ $t($translation) }}</span>
                            <input id="register-{{ $field }}" name="{{ $field }}" type="password" autocomplete="new-password" minlength="8" maxlength="255" placeholder="{{ $t($translation) }}" required @if ($fieldErrors[$field] !== '') aria-invalid="true" @endif aria-describedby="{{ $describedBy($field, $field === 'password' ? 'reg-password-hint' : '') }}">
                            <button type="button" class="reg-toggle" data-register-toggle aria-controls="register-{{ $field }}" aria-pressed="false" aria-label="{{ $t('show_'.$translation) }}" data-show-label="{{ $t('show_'.$translation) }}" data-hide-label="{{ $t('hide_'.$translation) }}"><x-auth-icon name="eye"/></button>
                        </label>
                        @if ($field === 'password')
                            <p id="reg-password-hint" class="reg-hint">{{ $t('password_hint') }}</p>
                        @endif
                        <p id="reg-error-{{ $field }}" class="reg-error" @if ($fieldErrors[$field] === '') hidden @endif>{{ $fieldErrors[$field] }}</p>
                    </div>
                @endforeach

                <div class="reg-group reg-language-group">
                    <label class="reg-label" for="preferred-language">{{ $t('preferred_language') }}</label>
                    <div @class(['reg-select', 'has-error' => $fieldErrors['preferred_language'] !== ''])>
                        <select id="preferred-language" name="preferred_language" required @if ($fieldErrors['preferred_language'] !== '') aria-invalid="true" aria-describedby="reg-error-preferred_language" @endif>
                            <option value="sw" lang="sw" @selected(old('preferred_language', $language) === 'sw')>Kiswahili</option>
                            <option value="en" lang="en" @selected(old('preferred_language', $language) === 'en')>English</option>
                        </select>
                        <x-auth-icon name="chevron" class="reg-select-chevron"/>
                    </div>
                    <p id="reg-error-preferred_language" class="reg-error" @if ($fieldErrors['preferred_language'] === '') hidden @endif>{{ $fieldErrors['preferred_language'] }}</p>
                </div>

                <div class="reg-group reg-terms-group">
                    <div @class(['reg-terms', 'has-error' => $fieldErrors['terms'] !== ''])>
                        <input id="register-terms" name="terms" type="checkbox" value="1" required @checked(old('terms')) @if ($fieldErrors['terms'] !== '') aria-invalid="true" aria-describedby="reg-error-terms" @endif>
                        <p class="reg-terms-text">
                            <label for="register-terms">{{ $t('agree') }}</label>
                            <a href="{{ route('terms', ['lang' => $language]) }}" target="_blank" rel="noopener">{{ $t('terms') }}<span class="sr-only"> {{ $t('terms_new_tab') }}</span></a>
                        </p>
                    </div>
                    <p id="reg-error-terms" class="reg-error" @if ($fieldErrors['terms'] === '') hidden @endif>{{ $fieldErrors['terms'] }}</p>
                </div>

                <button class="reg-submit" type="submit" data-reg-submit data-submitting-label="{{ $t('submitting') }}">
                    <span class="reg-spinner" aria-hidden="true"></span>
                    <span class="reg-submit-label">{{ $t('submit') }}</span>
                </button>
            </form>

            <p class="reg-login">
                <span>{{ $t('have_account') }}</span>
                <a href="{{ route('login', ['lang' => $language]) }}">{{ $t('login') }}</a>
            </p>
        </main>
    </div>
    <script type="application/json" id="reg-messages">@json($clientMessages)</script>
</body>
</html>
