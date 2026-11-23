@php
    $t = fn (string $key, array $replace = []) => __('password_reset.'.$key, $replace, $language);
    $throttleMessage ??= null;

    $passwordError = $errors->first('password');
    $mismatch = $passwordError === $t('password_mismatch');
    $fieldErrors = [
        'password' => $mismatch ? '' : $passwordError,
        'password_confirmation' => $mismatch ? $passwordError : '',
    ];
    $alert = $throttleMessage ?? ($errors->first('form') ?: null);
    $clientMessages = [
        'password' => $t('password_required'),
        'password_min' => $t('password_min'),
        'password_confirmation' => $t('confirm_required'),
        'mismatch' => $t('password_mismatch'),
        'check_fields' => $t('check_fields'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#f9f8f5">
    <title>{{ $linkValid ? $t('page_title') : $t('invalid_heading') }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/11_forgot_password.css', 'resources/css/reference_ui/reset_password.css', 'resources/js/reference_ui/reset_password.js'])
</head>
<body class="fp-body rs-body">
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
                        href="{{ route('password.reset', ['token' => $token, 'email' => $email, 'lang' => $locale]) }}"
                        lang="{{ $locale }}"
                        hreflang="{{ $locale }}"
                        @if ($language === $locale) aria-current="true" @endif
                    >{{ $label }}</a>
                @endforeach
            </nav>
        </header>

        <main class="fp-main rs-main" aria-labelledby="rs-heading">
            <div @class(['rs-badge', 'is-invalid' => ! $linkValid]) aria-hidden="true">
                @if ($linkValid)
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><rect x="11" y="21" width="26" height="20" rx="3.5"/><path d="M16 21v-5a8 8 0 0 1 16 0v5"/><circle cx="24" cy="30" r="1.6" fill="currentColor"/><path d="M24 31.6V35"/></svg>
                @else
                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 27.5a7 7 0 0 0 10 0l6-6a7 7 0 0 0-10-10l-1.5 1.5"/><path d="M27.5 20.5a7 7 0 0 0-10 0l-6 6a7 7 0 0 0 10 10l1.5-1.5"/><path d="m9 9 30 30"/></svg>
                @endif
            </div>

            @if ($linkValid)
                <h1 id="rs-heading" class="fp-heading rs-heading">{{ $t('heading') }}</h1>
                <p class="fp-intro rs-intro">{{ $t('intro') }}</p>

                <div id="rs-alert" class="fp-notice fp-notice-error" role="alert" @if (! $alert && ! $errors->has('password')) hidden @endif>{{ $alert ?? $t('check_fields') }}</div>

                <form class="fp-form rs-form" method="POST" action="{{ route('password.update') }}" novalidate data-rs-form>
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    {{-- Required by the existing broker contract; bound to the token, not shown. --}}
                    <input type="hidden" name="email" value="{{ $email }}">

                    @foreach (['password' => ['password', 'password_placeholder', 'show_password', 'hide_password'], 'password_confirmation' => ['confirm', 'confirm_placeholder', 'show_confirm', 'hide_confirm']] as $field => [$label, $placeholder, $show, $hide])
                        <div class="rs-group">
                            <label class="fp-label" for="rs-{{ $field }}">{{ $t($label) }}</label>
                            <div @class(['fp-field', 'rs-field', 'has-error' => $fieldErrors[$field] !== ''])>
                                <x-auth-icon name="lock" class="fp-icon"/>
                                <input id="rs-{{ $field }}" name="{{ $field }}" type="password" autocomplete="new-password" minlength="8" maxlength="255" placeholder="{{ $t($placeholder) }}" required
                                    @if ($fieldErrors[$field] !== '') aria-invalid="true" @endif
                                    aria-describedby="{{ trim(($field === 'password' ? 'rs-password-hint ' : '').($fieldErrors[$field] !== '' ? 'rs-error-'.$field : '')) }}">
                                <button type="button" class="rs-toggle" data-rs-toggle aria-controls="rs-{{ $field }}" aria-pressed="false" aria-label="{{ $t($show) }}" data-show-label="{{ $t($show) }}" data-hide-label="{{ $t($hide) }}"><x-auth-icon name="eye"/></button>
                            </div>
                            @if ($field === 'password')
                                <p id="rs-password-hint" class="fp-hint">{{ $t('password_hint') }}</p>
                            @endif
                            <p id="rs-error-{{ $field }}" class="fp-error" @if ($fieldErrors[$field] === '') hidden @endif>{{ $fieldErrors[$field] }}</p>
                        </div>
                    @endforeach

                    <button class="fp-submit rs-submit" type="submit" data-rs-submit data-submitting-label="{{ $t('submitting') }}">
                        <span class="fp-spinner" aria-hidden="true"></span>
                        <span class="rs-submit-label">{{ $t('submit') }}</span>
                    </button>
                </form>
            @else
                <h1 id="rs-heading" class="fp-heading rs-heading">{{ $t('invalid_heading') }}</h1>
                <p class="fp-intro rs-intro">{{ $t('invalid_body', ['minutes' => $expireMinutes]) }}</p>
                @if ($alert)
                    <div class="fp-notice fp-notice-error" role="alert">{{ $alert }}</div>
                @endif
                <a class="fp-submit rs-submit rs-request-new" href="{{ route('password.request', ['lang' => $language]) }}">
                    <span>{{ $t('request_new') }}</span>
                    <svg class="fp-submit-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h15M13 5.5 19.5 12 13 18.5"/></svg>
                </a>
            @endif

            <p class="fp-login rs-login">
                <a href="{{ route('login', ['lang' => $language]) }}"><strong>{{ $t('back_link') }}</strong></a>
            </p>
        </main>
    </div>
    @if ($linkValid)
        <script type="application/json" id="rs-messages">@json($clientMessages)</script>
    @endif
</body>
</html>
