@php
    $t = fn (string $key, array $replace = []) => __('password_recovery.'.$key, $replace, $language);
    $throttleMessage ??= null;
    $retainedIdentifier ??= null;

    // The controller keeps its existing English status constant; present it localized.
    $accepted = session('status') === \App\Http\Controllers\PasswordRecoveryController::REQUEST_STATUS;
    $otherStatus = ! $accepted && is_string(session('status')) ? session('status') : null;
    $identifierError = $errors->first('identifier');
    $alert = $throttleMessage ?? ($errors->first('form') ?: null);
    $identifierValue = $retainedIdentifier ?? old('identifier');
    $minutes = (int) config('auth.passwords.users.expire', 60);
    $clientMessages = ['identifier' => $t('identifier_required')];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#f9f8f5">
    <title>{{ $t('page_title') }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/11_forgot_password.css', 'resources/js/reference_ui/11_forgot_password.js'])
</head>
<body class="fp-body">
    <div class="fp-page">
        <header class="fp-header">
            <a class="fp-back" href="{{ route('login', ['lang' => $language]) }}" aria-label="{{ $t('back') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
            </a>
            <p class="fp-brand">PangaEvents</p>
            <nav class="fp-languages" aria-label="{{ $t('language') }}">
                @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $label)
                    <a
                        @class(['fp-language', 'is-active' => $language === $locale])
                        href="{{ route('password.request', ['lang' => $locale]) }}"
                        lang="{{ $locale }}"
                        hreflang="{{ $locale }}"
                        data-fp-language
                        @if ($language === $locale) aria-current="true" @endif
                    >{{ $label }}</a>
                @endforeach
            </nav>
        </header>

        <main class="fp-main" aria-labelledby="fp-heading">
            <h1 id="fp-heading" class="fp-heading">{{ $t('heading') }}</h1>
            <p class="fp-intro">{{ $t('intro') }}</p>

            @if ($accepted)
                <div class="fp-notice fp-notice-accepted" role="status">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                    <div>
                        <p class="fp-notice-title">{{ $t('accepted') }}</p>
                        <p class="fp-notice-hint">{{ $t('accepted_hint', ['minutes' => $minutes]) }}</p>
                    </div>
                </div>
            @elseif ($otherStatus)
                <div class="fp-notice" role="status">{{ $otherStatus }}</div>
            @endif
            <div id="fp-alert" class="fp-notice fp-notice-error" role="alert" @if (! $alert) hidden @endif>{{ $alert }}</div>

            <form class="fp-form" method="POST" action="{{ route('password.email') }}" novalidate data-fp-form>
                @csrf
                <label class="fp-label" for="fp-identifier">{{ $t('identifier') }}</label>
                <div @class(['fp-field', 'has-error' => $identifierError !== ''])>
                    <x-auth-icon name="mail" class="fp-icon"/>
                    <input id="fp-identifier" name="identifier" type="text" value="{{ $identifierValue }}" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="255" placeholder="{{ $t('placeholder') }}" required data-fp-draft
                        @if ($identifierError !== '') aria-invalid="true" @endif
                        aria-describedby="{{ trim('fp-delivery-hint '.($identifierError !== '' ? 'fp-error-identifier' : '')) }}">
                </div>
                <p id="fp-error-identifier" class="fp-error" @if ($identifierError === '') hidden @endif>{{ $identifierError }}</p>
                <p id="fp-delivery-hint" class="fp-hint">{{ $t('delivery_hint') }}</p>

                <button class="fp-submit" type="submit" data-fp-submit data-submitting-label="{{ $t('submitting') }}">
                    <span class="fp-spinner" aria-hidden="true"></span>
                    <span class="fp-submit-label">{{ $accepted ? $t('resend') : $t('submit') }}</span>
                    <svg class="fp-submit-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h15M13 5.5 19.5 12 13 18.5"/></svg>
                </button>
            </form>

            <p class="fp-login">
                <a href="{{ route('login', ['lang' => $language]) }}"><span>{{ $t('back_prefix') }}</span> <strong>{{ $t('back_link') }}</strong></a>
            </p>
        </main>
    </div>
    <script type="application/json" id="fp-messages">@json($clientMessages)</script>
</body>
</html>
