<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fcf9f3">
    <title>{{ __('register.title', [], $language) }} · PangaEvents</title>
    @vite(['resources/css/app.css', 'resources/css/login.css', 'resources/css/register.css', 'resources/js/register.js'])
</head>
<body class="panga-login panga-register">
    <div class="login-shell register-shell">
        <header class="login-header">
            <a class="login-back" href="{{ route('login') }}" aria-label="{{ __('login.back', [], $language) }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 4-8 8 8 8M4 12h18"/></svg>
            </a>
            <a class="login-brand" href="{{ route('login') }}" aria-label="PangaEvents"><img src="{{ asset('assets/pangaevents-mark.svg') }}" width="34" height="37" alt=""><span>PangaEvents</span></a>
            <nav class="login-languages" aria-label="{{ __('login.language', [], $language) }}">
                @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $label)
                    <a href="{{ route('register', ['lang' => $locale]) }}" lang="{{ $locale }}" hreflang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </header>
        <main aria-labelledby="register-heading">
            <h1 id="register-heading">{{ __('register.title', [], $language) }}</h1>
            <p class="register-intro">{{ __('register.description', [], $language) }}</p>
            @if ($errors->any())
                <div class="login-alert login-alert-error" role="alert">{{ __('register.check_fields', [], $language) }}</div>
            @endif
            <form method="POST" action="{{ route('register.store') }}" class="register-form">
                @csrf
                <div class="register-fields">
                    <div>
                        <label class="register-field" for="register-name">
                            <x-auth-icon name="user"/>
                            <span class="sr-only">{{ __('register.name', [], $language) }}</span>
                            <input id="register-name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" maxlength="120" placeholder="{{ __('register.name', [], $language) }}" required @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                        </label>
                        @error('name') <p id="name-error" class="register-field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <div class="register-field register-phone">
                            <div class="register-country">
                                <select aria-label="{{ __('register.country', [], $language) }}"><option value="TZ">{{ __('register.tanzania', [], $language) }} +255</option></select>
                                <span class="register-country-art" aria-hidden="true">
                                    <svg viewBox="0 0 30 23"><defs><clipPath id="tz-clip"><rect width="30" height="23" rx="3"/></clipPath></defs><g clip-path="url(#tz-clip)"><path fill="#009b39" d="M0 0h30v23H0z"/><path fill="#0095cc" d="M0 23 30 0v23Z"/><path stroke="#f7d633" stroke-width="9" d="m-3 25 36-27"/><path stroke="#07171c" stroke-width="6" d="m-3 25 36-27"/></g></svg>
                                    <x-auth-icon name="chevron"/>
                                </span>
                            </div>
                            <label class="register-phone-input" for="register-phone">
                                <span>{{ __('register.phone', [], $language) }}</span>
                                <input id="register-phone" name="phone_number" type="tel" inputmode="tel" value="{{ old('phone_number') }}" autocomplete="tel" maxlength="40" placeholder="+255 712 345 678" required @error('phone_number') aria-invalid="true" aria-describedby="phone-error" @enderror>
                            </label>
                        </div>
                        @error('phone_number') <p id="phone-error" class="register-field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="register-field" for="register-email">
                            <x-auth-icon name="mail"/>
                            <span class="sr-only">{{ __('register.email', [], $language) }}</span>
                            <input id="register-email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="160" placeholder="{{ __('register.email', [], $language) }}" required @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                        </label>
                        @error('email') <p id="email-error" class="register-field-error">{{ $message }}</p> @enderror
                    </div>
                    @foreach (['password' => 'password', 'password_confirmation' => 'confirm_password'] as $field => $translation)
                        <div>
                            <label class="register-field" for="register-{{ $field }}">
                                <x-auth-icon name="lock"/>
                                <span class="sr-only">{{ __('register.'.$translation, [], $language) }}</span>
                                <input id="register-{{ $field }}" name="{{ $field }}" type="password" autocomplete="new-password" minlength="8" maxlength="255" placeholder="{{ __('register.'.$translation, [], $language) }}" required @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
                                <button type="button" class="register-password-toggle" data-register-toggle aria-controls="register-{{ $field }}" aria-pressed="false" aria-label="{{ __('register.show_'.$translation, [], $language) }}" data-show-label="{{ __('register.show_'.$translation, [], $language) }}" data-hide-label="{{ __('register.hide_'.$translation, [], $language) }}"><x-auth-icon name="eye"/></button>
                            </label>
                            @if ($field === 'password_confirmation')
                                @error('password') <p id="password-error" class="register-field-error">{{ $message }}</p> @enderror
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="register-language">
                    <label for="preferred-language">{{ __('register.preferred_language', [], $language) }}</label>
                    <div class="register-select-wrap">
                        <select id="preferred-language" name="preferred_language" required @error('preferred_language') aria-invalid="true" aria-describedby="language-error" @enderror>
                            <option value="sw" @selected(old('preferred_language', $language) === 'sw')>Kiswahili</option>
                            <option value="en" @selected(old('preferred_language', $language) === 'en')>English</option>
                        </select>
                        <x-auth-icon name="chevron"/>
                    </div>
                    @error('preferred_language') <p id="language-error" class="register-field-error">{{ $message }}</p> @enderror
                </div>
                <div class="register-terms-group">
                    <div class="register-terms">
                        <input id="register-terms" name="terms" type="checkbox" value="1" required @checked(old('terms')) @error('terms') aria-invalid="true" aria-describedby="terms-error" @enderror>
                        <div><label for="register-terms">{{ __('register.agree', [], $language) }}</label> <a href="{{ route('terms', ['lang' => $language]) }}">{{ __('register.terms', [], $language) }}</a></div>
                    </div>
                    @error('terms') <p id="terms-error" class="register-field-error">{{ $message }}</p> @enderror
                </div>
                <button class="register-submit" type="submit">{{ __('register.submit', [], $language) }}</button>
            </form>
            <p class="register-login">{{ __('register.have_account', [], $language) }} <a href="{{ route('login') }}">{{ __('register.login', [], $language) }}</a></p>
        </main>
    </div>
</body>
</html>
