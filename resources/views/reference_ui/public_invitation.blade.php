@php
    $t = fn (string $key, array $replace = []) => __('customer_share.public.'.$key, $replace, $language);
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $found ? $t('title').' · '.$event->event_name : $t('not_found_title') }}</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_shell.css'])
</head>
<body class="pi-body">
<main class="pi-page" aria-labelledby="pi-heading">
    @if (! $found)
        <div class="pi-missing">
            <h1 id="pi-heading">{{ $t('not_found_title') }}</h1>
            <p>{{ $t('not_found_text') }}</p>
        </div>
    @else
        <p class="pi-dear">{{ $t('dear', ['name' => $guestName]) }}</p>
        <p class="pi-invited">{{ $t('invited') }}</p>
        <h1 id="pi-heading" class="pi-event">{{ $event->event_name }}</h1>
        <figure class="pi-card">
            <img src="{{ route('invitations.public.card', ['key' => $key]) }}" alt="{{ $t('card_alt', ['name' => $guestName]) }}" width="750" height="1000">
        </figure>
        <dl class="pi-facts">
            @if ($values['event_date'] !== '')<div><dt>{{ $t('date') }}</dt><dd>{{ $values['event_date'] }}</dd></div>@endif
            @if ($values['event_time'] !== '')<div><dt>{{ $t('time') }}</dt><dd>{{ $values['event_time'] }}</dd></div>@endif
            @if ($values['venue'] !== '')<div><dt>{{ $t('venue') }}</dt><dd>{{ $values['venue'] }}</dd></div>@endif
            <div><dt>{{ $t('pass') }}</dt><dd>{{ $passLine }}</dd></div>
        </dl>
        <p class="pi-note">{{ $t('show_qr') }}</p>
        <a class="cu-button cu-button-primary pi-download" href="{{ route('invitations.public.card', ['key' => $key, 'download' => 1]) }}" download="{{ $fileName }}">{{ $t('download') }}</a>
        <nav class="pi-lang" aria-label="Language">
            @foreach (['sw' => 'Kiswahili', 'en' => 'English'] as $locale => $name)
                <a href="{{ route('invitations.public', ['key' => $key, 'lang' => $locale]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $name }}</a>
            @endforeach
        </nav>
    @endif
</main>
</body>
</html>
