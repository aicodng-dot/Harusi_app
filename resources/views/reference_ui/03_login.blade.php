@php
    $t = fn (string $key) => __('login.'.$key, [], $language);
    $throttleMessage ??= null;
    $retainedIdentifier ??= null;

    // The controller keeps its existing (English, JSON-contract) messages; translate them here.
    $rawIdentifierError = $errors->first('identifier') ?: $errors->first('email');
    $credentialsFailed = $rawIdentifierError === 'The provided credentials do not match our records.';
    $identifierError = match (true) {
        $rawIdentifierError === '' || $credentialsFailed => '',
        str_contains($rawIdentifierError, 'required') => $t('identifier_required'),
        default => $t('invalid_credentials'),
    };
    $passwordError = $errors->first('password') !== '' ? $t('password_required') : '';

    $sessionError = session('error');
    if ($sessionError === 'Your session expired. Refresh the page and try that action again.') {
        $sessionError = $t('session_expired');
    }

    $alert = $throttleMessage
        ?? ($errors->first('form') ?: null)
        ?? ($credentialsFailed ? $t('invalid_credentials') : null);
    $identifierInvalid = $credentialsFailed || $identifierError !== '';
    $passwordInvalid = $credentialsFailed || $passwordError !== '';
    $identifierValue = $retainedIdentifier ?? old('identifier', old('email'));
    $clientMessages = ['identifier' => $t('identifier_required'), 'password' => $t('password_required')];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fcfaf3">
    <title>{{ $t('title') }} · PangaEvents — {{ $loginTitle }}</title>
    @vite(['resources/css/reference_ui/03_login.css', 'resources/js/reference_ui/03_login.js'])
</head>
<body class="login-body">
    <div class="lg-page">
        <header class="lg-header">
            <a class="lg-back" href="{{ route('reference_ui.10_welcome_how_it_works.show', ['lang' => $language]) }}" aria-label="{{ $t('back') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12H4m7-7-7 7 7 7"/></svg>
            </a>
            <div class="lg-brand">
                <img src="{{ asset('assets/pangaevents-mark.svg') }}" width="68" height="76" alt="">
                <span>PangaEvents</span>
            </div>
            <nav class="lg-languages" aria-label="{{ $t('language') }}">
                @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $label)
                    <a
                        @class(['lg-language', 'is-active' => $language === $locale])
                        href="{{ request()->routeIs('login.attempt') ? route('login', ['lang' => $locale]) : request()->fullUrlWithQuery(['lang' => $locale]) }}"
                        lang="{{ $locale }}"
                        hreflang="{{ $locale }}"
                        data-lg-language
                        @if ($language === $locale) aria-current="true" @endif
                    >{{ $label }}</a>
                @endforeach
            </nav>
        </header>

        <main class="lg-main" aria-labelledby="login-heading">
            <h1 id="login-heading" class="lg-heading">{{ $t('title') }}</h1>
            <p class="lg-intro">{{ $t('description') }}</p>

            @if ($sessionError)
                <div class="lg-alert lg-alert-error" role="alert">{{ $sessionError }}</div>
            @endif
            @if (session('status'))
                <div class="lg-alert lg-alert-status" role="status">{{ session('status') }}</div>
            @endif
            <div id="lg-alert" class="lg-alert lg-alert-error" role="alert" tabindex="-1" @if (! $alert) hidden @endif>{{ $alert }}</div>

            <form class="lg-form" method="POST" action="{{ route('login.attempt') }}" novalidate data-lg-form>
                @csrf
                <div class="lg-group">
                    <label @class(['lg-field', 'has-error' => $identifierInvalid]) for="identifier">
                        <x-auth-icon name="user" class="lg-icon"/>
                        <span class="sr-only">{{ $t('identifier') }}</span>
                        <input id="identifier" name="identifier" type="text" value="{{ $identifierValue }}" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="255" placeholder="{{ $t('identifier') }}" required data-lg-draft
                            @if ($identifierInvalid) aria-invalid="true" @endif
                            @if ($identifierError !== '') aria-describedby="lg-error-identifier" @elseif ($credentialsFailed) aria-describedby="lg-alert" @endif>
                    </label>
                    <p id="lg-error-identifier" class="lg-error" @if ($identifierError === '') hidden @endif>{{ $identifierError }}</p>
                </div>

                <div class="lg-group">
                    <label @class(['lg-field', 'has-error' => $passwordInvalid]) for="password">
                        <x-auth-icon name="lock" class="lg-icon"/>
                        <span class="sr-only">{{ $t('password') }}</span>
                        <input id="password" name="password" type="password" autocomplete="current-password" maxlength="255" placeholder="{{ $t('password') }}" required
                            @if ($passwordInvalid) aria-invalid="true" @endif
                            @if ($passwordError !== '') aria-describedby="lg-error-password" @elseif ($credentialsFailed) aria-describedby="lg-alert" @endif>
                        <button class="lg-toggle" type="button" aria-label="{{ $t('show_password') }}" aria-controls="password" aria-pressed="false" data-password-toggle data-show-label="{{ $t('show_password') }}" data-hide-label="{{ $t('hide_password') }}"><x-auth-icon name="eye"/></button>
                    </label>
                    <p id="lg-error-password" class="lg-error" @if ($passwordError === '') hidden @endif>{{ $passwordError }}</p>
                </div>

                <div class="lg-options">
                    <label class="lg-remember">
                        <input type="checkbox" name="remember" value="1" @checked(session()->hasOldInput() ? old('remember', false) : true)>
                        <span>{{ $t('remember') }}</span>
                    </label>
                    <a class="lg-forgot" href="{{ route('password.request', ['lang' => $language]) }}">{{ $t('forgot_password') }}</a>
                </div>

                <button class="lg-submit" type="submit" data-lg-submit data-submitting-label="{{ $t('signing_in') }}">
                    <span class="lg-spinner" aria-hidden="true"></span>
                    <span class="lg-submit-label">{{ $t('sign_in') }}</span>
                </button>
            </form>

            {{-- Google sign-in appears only when a real OAuth route exists; none is configured. --}}
            @if (\Illuminate\Support\Facades\Route::has('auth.google.redirect'))
                <div class="lg-divider"><span>{{ $t('or') }}</span></div>
                <a class="lg-google" href="{{ route('auth.google.redirect') }}">
                    <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#4285f4" d="M44.5 24.5c0-1.5-.1-3-.4-4.5H24v8.5h11.5a9.8 9.8 0 0 1-4.3 6.4v5.4h7c4.1-3.8 6.3-9.3 6.3-15.8Z"/><path fill="#34a853" d="M24 45c5.7 0 10.5-1.9 14.1-5.2l-7-5.4c-1.9 1.3-4.3 2.1-7.1 2.1-5.5 0-10.2-3.7-11.8-8.7H5v5.6A21.3 21.3 0 0 0 24 45Z"/><path fill="#fbbc05" d="M12.2 27.8a12.8 12.8 0 0 1 0-7.6v-5.6H5a21 21 0 0 0 0 18.8Z"/><path fill="#ea4335" d="M24 11.5c3.1 0 5.9 1.1 8.1 3.2l6.1-6.1A20.5 20.5 0 0 0 24 3 21.3 21.3 0 0 0 5 14.6l7.2 5.6c1.6-5 6.3-8.7 11.8-8.7Z"/></svg>
                    <span>{{ $t('google') }}</span>
                </a>
            @endif

            <p class="lg-register">
                <span>{{ $t('no_account') }}</span>
                <a href="{{ route('register') }}">{{ $t('register') }}</a>
            </p>
        </main>
    </div>
    <script type="application/json" id="lg-messages">@json($clientMessages)</script>
</body>
</html>
