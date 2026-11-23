@php
    // Follow the existing customer views' explicit translation locale.
    $requestedLanguage = request()->query('lang');
    if (in_array($requestedLanguage, ['sw', 'en'], true)) {
        session(['login_language' => $requestedLanguage]);
    }
    $language = session('login_language', 'sw');
    $language = in_array($language, ['sw', 'en'], true) ? $language : 'sw';
    $registrationAvailable = \Illuminate\Support\Facades\Route::has('register');
    $googleAvailable = \Illuminate\Support\Facades\Route::has('auth.google.redirect');
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fdfbf4">
    <title>{{ __('login.title', [], $language) }} · PangaEvents — {{ $loginTitle }}</title>
    @vite(['resources/css/app.css', 'resources/css/login.css', 'resources/js/login.js'])
</head>
<body class="panga-login">
    <div class="login-shell">
        <header class="login-header">
            <a class="login-back" href="{{ url('/') }}" aria-label="{{ __('login.back', [], $language) }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 4-8 8 8 8M4 12h18"/></svg>
            </a>
            <a class="login-brand" href="{{ url('/') }}" aria-label="PangaEvents">
                <img src="{{ asset('assets/pangaevents-mark.svg') }}" width="34" height="37" alt="">
                <span>PangaEvents</span>
            </a>
            <nav class="login-languages" aria-label="{{ __('login.language', [], $language) }}">
                @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $label)
                    <a href="{{ request()->fullUrlWithQuery(['lang' => $locale]) }}" lang="{{ $locale }}" hreflang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </header>
        <main aria-labelledby="login-heading">
            <h1 id="login-heading">{{ __('login.title', [], $language) }}</h1>
            <p class="login-intro">{{ __('login.description', [], $language) }}</p>
            @if (session('error'))
                <div class="login-alert login-alert-error" role="alert">{{ session('error') }}</div>
            @endif
            @if (session('status'))
                <div class="login-alert" role="status">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div id="login-errors" class="login-alert login-alert-error" role="alert" tabindex="-1">
                    @php
                        $error = match ($errors->first()) {
                            'The provided credentials do not match our records.' => __('login.invalid_credentials', [], $language),
                            'The identifier field is required.', 'The email field is required.' => __('login.identifier_required', [], $language),
                            'The password field is required.' => __('login.password_required', [], $language),
                            default => $errors->first(),
                        };
                    @endphp
                    {{ $error }}
                </div>
            @endif
            <form class="login-form" method="POST" action="{{ route('login.attempt') }}">
                @csrf
                <label class="login-field" for="identifier">
                    <svg viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="14" cy="8" r="4.7"/><path d="M4.5 25v-2.5c0-4 4-6.1 9.5-6.1s9.5 2.1 9.5 6.1V25z"/></svg>
                    <span class="sr-only">{{ __('login.identifier', [], $language) }}</span>
                    <input id="identifier" name="identifier" type="text" value="{{ old('identifier', old('email')) }}" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="255" placeholder="{{ __('login.identifier', [], $language) }}" required @if ($errors->has('identifier') || $errors->has('email')) aria-invalid="true" aria-describedby="login-errors" @endif>
                </label>
                <label class="login-field login-password" for="password">
                    <svg viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4.5" y="11" width="19" height="15" rx="2.3"/><path d="M9 11V8a5 5 0 0 1 10 0v3"/><circle cx="14" cy="18" r="1"/><path d="M14 19v2"/></svg>
                    <span class="sr-only">{{ __('login.password', [], $language) }}</span>
                    <input id="password" name="password" type="password" autocomplete="current-password" placeholder="{{ __('login.password', [], $language) }}" required @if ($errors->has('password')) aria-invalid="true" aria-describedby="login-errors" @endif>
                    <button class="login-password-toggle" type="button" aria-label="{{ __('login.show_password', [], $language) }}" aria-controls="password" aria-pressed="false" data-password-toggle data-show-label="{{ __('login.show_password', [], $language) }}" data-hide-label="{{ __('login.hide_password', [], $language) }}">
                        <svg viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 14s4-7.5 12-7.5S26 14 26 14s-4 7.5-12 7.5S2 14 2 14Z"/><circle cx="14" cy="14" r="3.6"/><path class="login-eye-slash" d="m4 3 20 22"/></svg>
                    </button>
                </label>
                <div class="login-options">
                    <label class="login-remember"><input type="checkbox" name="remember" value="1" @checked(session()->hasOldInput() ? old('remember', false) : true)><span>{{ __('login.remember', [], $language) }}</span></label>
                    <a href="{{ route('password.request') }}">{{ __('login.forgot_password', [], $language) }}</a>
                </div>
                <button class="login-submit" type="submit">{{ __('login.sign_in', [], $language) }}</button>
            </form>
            <div class="login-divider"><span>{{ __('login.or', [], $language) }}</span></div>
            <a class="login-google" href="{{ $googleAvailable ? route('auth.google.redirect') : '#google-availability' }}" @unless ($googleAvailable) data-availability="google-availability" @endunless>
                <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#4285f4" d="M44.5 24.5c0-1.5-.1-3-.4-4.5H24v8.5h11.5a9.8 9.8 0 0 1-4.3 6.4v5.4h7c4.1-3.8 6.3-9.3 6.3-15.8Z"/><path fill="#34a853" d="M24 45c5.7 0 10.5-1.9 14.1-5.2l-7-5.4c-1.9 1.3-4.3 2.1-7.1 2.1-5.5 0-10.2-3.7-11.8-8.7H5v5.6A21.3 21.3 0 0 0 24 45Z"/><path fill="#fbbc05" d="M12.2 27.8a12.8 12.8 0 0 1 0-7.6v-5.6H5a21 21 0 0 0 0 18.8Z"/><path fill="#ea4335" d="M24 11.5c3.1 0 5.9 1.1 8.1 3.2l6.1-6.1A20.5 20.5 0 0 0 24 3 21.3 21.3 0 0 0 5 14.6l7.2 5.6c1.6-5 6.3-8.7 11.8-8.7Z"/></svg>
                <span>{{ __('login.google', [], $language) }}</span>
            </a>
            <p class="login-register">{{ __('login.no_account', [], $language) }} <a href="{{ $registrationAvailable ? route('register') : '#registration-availability' }}" @unless ($registrationAvailable) data-availability="registration-availability" @endunless>{{ __('login.register', [], $language) }}</a></p>
            @unless ($googleAvailable)
                <div id="google-availability" class="login-availability" role="status" tabindex="-1">{{ __('login.google_unavailable', [], $language) }}</div>
            @endunless
            @unless ($registrationAvailable)
                <div id="registration-availability" class="login-availability" role="status" tabindex="-1">{{ __('login.registration_unavailable', [], $language) }}</div>
            @endunless
        </main>
    </div>
</body>
</html>
