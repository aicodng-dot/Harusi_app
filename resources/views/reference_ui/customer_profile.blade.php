@php
    $t = fn (string $key, array $replace = []) => __('customer_manage.profile.'.$key, $replace, $language);
    $profileErrors = $errors->getBag('profile');
    $passwordErrors = $errors->getBag('password');
    $value = fn (string $field) => $profileErrors->any() ? old($field, $user->{$field}) : $user->{$field};
@endphp
<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fbfbf8">
    <title>{{ $t('page_title') }} · PangaEvents</title>
    @vite(['resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/customer_manage.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js'])
</head>
<body>
@include('reference_ui.partials.customer_shell')
<div class="is-page">
    <header class="is-header">
        <a class="is-back" href="{{ route('customer.home') }}" aria-label="{{ __('customer_manage.back', [], $language) }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>
        </a>
        <span class="is-brand">PangaEvents</span>
        <nav class="is-languages" aria-label="{{ __('customer_manage.language', [], $language) }}">
            @foreach (['sw' => 'SW', 'en' => 'EN'] as $locale => $code)
                <a @class(['is-language', 'is-active' => $language === $locale]) href="{{ route('customer.profile', ['lang' => $locale]) }}" lang="{{ $locale }}" @if ($language === $locale) aria-current="true" @endif>{{ $code }}</a>
            @endforeach
        </nav>
    </header>

    <main class="is-main" aria-labelledby="pr-heading">
        <h1 id="pr-heading" class="is-heading">{{ $t('title') }}</h1>
        <p class="is-intro">{{ $t('intro') }}</p>
        <p class="cm-stat">{{ $t('stats', ['workspaces' => $workspaceCount, 'events' => $eventCount]) }} · <a class="is-small-link" href="{{ route('customer.home') }}">{{ $t('my_events') }} →</a></p>

        @if (session('profile_notice'))
            <p class="is-alert is-alert-ok" role="status">{{ session('profile_notice') }}</p>
        @endif

        <section class="cm-section" aria-labelledby="pr-details">
            <h2 id="pr-details">{{ $t('details') }}</h2>
            @if ($profileErrors->any())
                <div class="is-alert is-alert-error" role="alert"><ul>@foreach ($profileErrors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
            @endif
            <form method="POST" action="{{ route('customer.profile.update') }}" class="cm-stack" novalidate>
                @csrf
                @method('PUT')
                <div>
                    <label class="ag-label" for="pr-name">{{ $t('name') }}</label>
                    <div @class(['ag-input', 'is-invalid' => $profileErrors->has('name')])><input id="pr-name" name="name" value="{{ $value('name') }}" maxlength="120" required autocomplete="name"></div>
                </div>
                <div>
                    <label class="ag-label" for="pr-email">{{ $t('email') }}</label>
                    <div @class(['ag-input', 'is-invalid' => $profileErrors->has('email')])><input id="pr-email" type="email" name="email" value="{{ $value('email') }}" maxlength="160" required autocomplete="email"></div>
                </div>
                <div>
                    <label class="ag-label" for="pr-phone">{{ $t('phone') }}</label>
                    <div @class(['ag-input', 'is-invalid' => $profileErrors->has('phone_number')])><input id="pr-phone" type="tel" name="phone_number" value="{{ $value('phone_number') }}" maxlength="40" required inputmode="tel" autocomplete="tel"></div>
                </div>
                <div>
                    <label class="ag-label" for="pr-language">{{ $t('preferred_language') }}</label>
                    <select id="pr-language" name="preferred_language" class="cm-select">
                        @foreach (['sw', 'en'] as $locale)
                            <option value="{{ $locale }}" @selected($value('preferred_language') === $locale)>{{ $t('languages.'.$locale) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ag-label" for="pr-current">{{ $t('current_password') }}</label>
                    <input id="pr-current" type="password" name="current_password" class="cm-plain" autocomplete="current-password" maxlength="255">
                    <p class="cm-hint">{{ $t('current_hint') }}</p>
                </div>
                <button type="submit" class="is-primary">{{ $t('save') }}</button>
            </form>
        </section>

        <section class="cm-section" aria-labelledby="pr-password">
            <h2 id="pr-password">{{ $t('password_title') }}</h2>
            @if ($passwordErrors->any())
                <div class="is-alert is-alert-error" role="alert"><ul>@foreach ($passwordErrors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
            @endif
            <form method="POST" action="{{ route('customer.profile.password') }}" class="cm-stack" novalidate>
                @csrf
                @method('PUT')
                <div>
                    <label class="ag-label" for="pw-current">{{ $t('current_password') }}</label>
                    <input id="pw-current" type="password" name="current_password" class="cm-plain" autocomplete="current-password" required maxlength="255">
                </div>
                <div>
                    <label class="ag-label" for="pw-new">{{ $t('new_password') }}</label>
                    <input id="pw-new" type="password" name="password" class="cm-plain" autocomplete="new-password" required minlength="8" maxlength="255">
                </div>
                <div>
                    <label class="ag-label" for="pw-confirm">{{ $t('confirm_password') }}</label>
                    <input id="pw-confirm" type="password" name="password_confirmation" class="cm-plain" autocomplete="new-password" required minlength="8" maxlength="255">
                </div>
                <button type="submit" class="is-secondary">{{ $t('change_password') }}</button>
            </form>
        </section>
    </main>
</div>
</body>
</html>
