<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#03241a">
    <title>{{ __('01_splash_screen.page_title', [], $language) }}</title>
    @vite('resources/css/reference_ui/01_splash_screen.css')
</head>
<body class="splash-body">
    <main class="splash-screen">
        <a
            class="splash-action"
            href="{{ route('reference_ui.10_welcome_how_it_works.show', ['lang' => $language]) }}"
            aria-describedby="splash-continue"
        >
            <div class="splash-canvas">
                <img class="splash-art splash-art-top" src="{{ asset('assets/reference_ui/01_Splash_Screen/01_Splash_Screen_Leaves_Top.webp') }}" width="253" height="340" alt="" aria-hidden="true">
                <img class="splash-art splash-art-left" src="{{ asset('assets/reference_ui/01_Splash_Screen/01_Splash_Screen_Leaves_Left.webp') }}" width="300" height="390" alt="" aria-hidden="true">
                <img class="splash-art splash-art-right" src="{{ asset('assets/reference_ui/01_Splash_Screen/01_Splash_Screen_Leaves_Right.webp') }}" width="173" height="830" alt="" aria-hidden="true">
                <img class="splash-art splash-art-bottom" src="{{ asset('assets/reference_ui/01_Splash_Screen/01_Splash_Screen_Leaves_Bottom.webp') }}" width="240" height="320" alt="" aria-hidden="true">

                <div class="splash-brand">
                    <img class="splash-mark" src="{{ asset('assets/reference_ui/01_Splash_Screen/01_Splash_Screen_PangaEvents_Mark.png') }}" width="206" height="296" alt="">
                    <h1 id="splash-brand" class="splash-wordmark">PangaEvents</h1>
                </div>

                <div id="splash-tagline" class="splash-tagline">
                    <span>{{ __('01_splash_screen.tagline_line_one', [], $language) }}</span>
                    <span>{{ __('01_splash_screen.tagline_line_two', [], $language) }}</span>
                </div>

                <div class="splash-features">
                    @foreach (__('01_splash_screen.features', [], $language) as $feature)
                        <span>{{ $feature }}</span>
                    @endforeach
                </div>

                <div class="splash-progress" role="img" aria-label="{{ __('01_splash_screen.progress', [], $language) }}">
                    <span class="splash-dot is-active"></span>
                    <span class="splash-dot"></span>
                    <span class="splash-dot"></span>
                    <span class="splash-dot"></span>
                    <span class="splash-dot"></span>
                </div>

                <span id="splash-continue" class="splash-continue">{{ __('01_splash_screen.continue', [], $language) }}</span>
            </div>
        </a>
    </main>
    <script>
        (() => {
            const action = document.querySelector('.splash-action');
            if (!action) return;
            // One navigation per tap; the plain link still works without JavaScript.
            action.addEventListener('click', (event) => {
                if (action.getAttribute('aria-busy') === 'true') {
                    event.preventDefault();
                    return;
                }
                action.setAttribute('aria-busy', 'true');
            });
            // Re-enable when the page is restored from the back/forward cache.
            window.addEventListener('pageshow', () => action.removeAttribute('aria-busy'));
        })();
    </script>
</body>
</html>
