@php
    $requestedLanguage = request()->query('lang');
    if (in_array($requestedLanguage, ['sw', 'en'], true)) {
        session(['login_language' => $requestedLanguage]);
    }
    $language = session('login_language', 'sw');
    $language = in_array($language, ['sw', 'en'], true) ? $language : 'sw';
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fdfbf4">
    <title>{{ __('terms.title', [], $language) }} · PangaEvents</title>
    @vite(['resources/css/app.css', 'resources/css/login.css'])
    <style>
        .terms-shell { width: min(88.2%, 48rem); margin-inline: auto; padding-block: max(2rem, env(safe-area-inset-top)) 3rem; }
        .terms-header { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; padding-bottom: 1.75rem; border-bottom: 1px solid #deded6; }
        .terms-header .login-brand { margin-left: 0; }
        .terms-header .login-languages { margin-left: auto; }
        .terms-main { padding-top: 1.75rem; }
        .terms-main h1 { margin: 0 0 1rem; font-size: clamp(1.8rem, 6vw, 2.5rem); line-height: 1.2; letter-spacing: -.025em; overflow-wrap: anywhere; }
        .terms-draft { margin: 0 0 2rem; padding: 1rem 1.15rem; border: 1px solid #dfc88b; border-radius: .8rem; color: #5d471a; background: #fff8e7; line-height: 1.6; }
        .terms-draft strong { display: block; margin-bottom: .3rem; }
        .terms-section { margin-bottom: 1.65rem; }
        .terms-section h2 { margin: 0 0 .5rem; color: #104b3e; font-size: 1.15rem; line-height: 1.4; }
        .terms-section p { margin: 0; color: #475362; font-size: 1rem; line-height: 1.75; }
        .terms-return { display: inline-flex; align-items: center; justify-content: center; min-height: 3rem; margin-top: .5rem; padding: .75rem 1.5rem; border-radius: 999px; background: #1b4e40; color: white !important; font-size: 1rem; font-weight: 700; text-align: center; }
        .terms-return:hover { background: #123e32; }
    </style>
</head>
<body class="panga-login">
    <div class="terms-shell">
        <header class="terms-header">
            <a class="login-brand" href="{{ route('register', ['lang' => $language]) }}" aria-label="PangaEvents">
                <img src="{{ asset('assets/pangaevents-mark.svg') }}" width="34" height="37" alt="">
                <span>PangaEvents</span>
            </a>
            <nav class="login-languages" aria-label="{{ __('login.language', [], $language) }}">
                @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $label)
                    <a href="{{ request()->fullUrlWithQuery(['lang' => $locale]) }}" lang="{{ $locale }}" hreflang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </header>
        <main class="terms-main" aria-labelledby="terms-heading">
            <h1 id="terms-heading">{{ __('terms.title', [], $language) }}</h1>
            <aside class="terms-draft" aria-label="{{ __('terms.draft_title', [], $language) }}">
                <strong>{{ __('terms.draft_title', [], $language) }}</strong>
                {{ __('terms.draft_notice', [], $language) }}
            </aside>
            @foreach (__('terms.sections', [], $language) as $section)
                <section class="terms-section">
                    <h2>{{ $loop->iteration }}. {{ $section['title'] }}</h2>
                    <p>{{ $section['body'] }}</p>
                </section>
            @endforeach
            <a class="terms-return" href="{{ route('register', ['lang' => $language]) }}">{{ __('terms.back', [], $language) }}</a>
        </main>
    </div>
</body>
</html>
