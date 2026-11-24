@php
    $t = fn (string $key, array $replace = []) => __('customer_help.'.$key, $replace, $language);
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('page_title') }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page">
    <header class="is-header">
        <a class="is-back" href="{{ route('customer.home') }}" aria-label="{{ __('customer_shell.nav.events', [], $language) }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
        </a>
        <span class="is-brand">PangaEvents</span>
        <nav class="is-languages" aria-label="{{ __('customer_shell.language', [], $language) }}">
            @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                <a @class(['is-language', 'is-active' => $language === $locale]) href="{{ route('customer.help', ['lang' => $locale]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $code }}</a>
            @endforeach
        </nav>
    </header>
    <main class="is-main" aria-labelledby="hp-heading">
        <h1 id="hp-heading" class="is-heading">{{ $t('title') }}</h1>
        <p class="is-intro">{{ $t('intro') }}</p>
        <section class="cu-panel" aria-labelledby="hp-steps">
            <h2 id="hp-steps" class="cu-panel-title">{{ $t('steps_title') }}</h2>
            <ol class="cu-steps">
                @foreach (__('customer_help.steps', [], $language) as [$title, $text])
                    <li><strong>{{ $title }}</strong><span>{{ $text }}</span></li>
                @endforeach
            </ol>
        </section>
        <section class="cu-panel" aria-labelledby="hp-faq">
            <h2 id="hp-faq" class="cu-panel-title">{{ $t('faq_title') }}</h2>
            @foreach (__('customer_help.faq', [], $language) as [$question, $answer])
                <details class="cu-details"><summary>{{ $question }}</summary><p class="cu-details-body">{{ $answer }}</p></details>
            @endforeach
        </section>
        <section class="cu-panel" aria-labelledby="hp-contact">
            <h2 id="hp-contact" class="cu-panel-title">{{ $t('contact_title') }}</h2>
            <p class="cu-help">{{ $t('contact_text') }}</p>
        </section>
    </main>
</div>
</body>
</html>
