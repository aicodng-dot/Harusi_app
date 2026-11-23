@php
    $t = fn (string $key) => __('10_welcome_how_it_works.'.$key, [], $language);
    $assets = 'assets/reference_ui/10_Welcome_How_It_Works/';
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#faf8f4">
    <title>{{ $t('page_title') }}</title>
    @vite('resources/css/reference_ui/10_welcome_how_it_works.css')
</head>
<body class="welcome-body">
    <div class="welcome-page">
        <header class="welcome-header">
            <nav class="welcome-languages" aria-label="{{ $t('language') }}">
                @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $label)
                    <a
                        @class(['welcome-language', 'is-active' => $language === $locale])
                        href="{{ route('reference_ui.10_welcome_how_it_works.show', ['lang' => $locale]) }}"
                        lang="{{ $locale }}"
                        hreflang="{{ $locale }}"
                        data-single-nav
                        @if ($language === $locale) aria-current="true" @endif
                    >{{ $label }}</a>
                @endforeach
            </nav>

            <div class="welcome-brand">
                <img class="welcome-mark" src="{{ asset('assets/reference_ui/01_Splash_Screen/01_Splash_Screen_PangaEvents_Mark.png') }}" width="206" height="296" alt="">
                <p class="welcome-wordmark">{{ $t('brand') }}</p>
            </div>
        </header>

        <main class="welcome-main">
            <h1 class="welcome-heading">{{ $t('heading') }}</h1>
            <p class="welcome-intro">
                <span>{{ $t('intro_line_one') }}</span>
                <span lang="en">{{ $t('intro_line_two') }}</span>
            </p>

            <ol class="welcome-steps" aria-label="{{ $t('steps_label') }}">
                @foreach (__('10_welcome_how_it_works.steps', [], $language) as $index => $step)
                    <li class="welcome-card">
                        <img
                            class="welcome-card-art"
                            src="{{ asset($assets.'10_Welcome_How_It_Works_Card_'.($index + 1).'.webp') }}"
                            width="289"
                            height="244"
                            alt=""
                            @if ($index > 0) loading="lazy" @endif
                            decoding="async"
                        >
                        <div class="welcome-card-copy">
                            <span class="welcome-card-number" aria-hidden="true">{{ $index + 1 }}</span>
                            <h2 class="welcome-card-title">{{ $step['title'] }}</h2>
                            <p class="welcome-card-body">{{ $step['body'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </main>

        <footer class="welcome-footer">
            <img class="welcome-botanicals" src="{{ asset($assets.'10_Welcome_How_It_Works_Footer_Botanicals.webp') }}" width="863" height="322" alt="" aria-hidden="true" decoding="async">

            <div class="welcome-dots" aria-hidden="true">
                <span class="welcome-dot is-active"></span>
                <span class="welcome-dot"></span>
                <span class="welcome-dot"></span>
            </div>

            <a class="welcome-start" href="{{ route('register', ['lang' => $language]) }}" data-single-nav>
                <span>{{ $t('start') }}</span>
                <svg class="welcome-start-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M4 12h15M13 5.5 19.5 12 13 18.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>

            <p class="welcome-login">
                {{ $t('have_account') }}
                <a href="{{ route('login', ['lang' => $language]) }}" data-single-nav>{{ $t('log_in') }}</a>
            </p>
        </footer>
    </div>

    <script>
        (() => {
            // One navigation per tap across every onboarding link; plain links still work without JavaScript.
            let navigating = false;
            document.querySelectorAll('[data-single-nav]').forEach((link) => {
                link.addEventListener('click', (event) => {
                    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                    if (navigating) {
                        event.preventDefault();
                        return;
                    }
                    navigating = true;
                    link.setAttribute('aria-busy', 'true');
                });
            });
            // Re-enable when the page is restored from the back/forward cache.
            window.addEventListener('pageshow', () => {
                navigating = false;
                document.querySelectorAll('[data-single-nav][aria-busy]').forEach((link) => link.removeAttribute('aria-busy'));
            });
        })();
    </script>
</body>
</html>
