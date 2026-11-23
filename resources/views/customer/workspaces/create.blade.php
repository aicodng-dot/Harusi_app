<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('customer.create_title', [], $language) }} · {{ config('app.name', 'Kadify') }}</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #192435; background: #f5f7fb; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        header, main { width: min(100% - 2rem, 42rem); margin-inline: auto; }
        header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; padding-block: 1.5rem; border-bottom: 1px solid #dce2ed; }
        .brand { font-weight: 750; overflow-wrap: anywhere; }
        .account { margin: .35rem 0 0; color: #526174; overflow-wrap: anywhere; }
        main { padding-block: 2rem; }
        h1 { font-size: clamp(1.65rem, 4vw, 2.1rem); margin: 0 0 .75rem; }
        .intro, .hint { color: #526174; line-height: 1.5; }
        .panel { background: white; border: 1px solid #dce2ed; border-radius: .85rem; padding: clamp(1rem, 4vw, 1.75rem); margin-block: 1.5rem; }
        label { display: block; font-weight: 650; margin-bottom: .5rem; }
        input { width: 100%; border: 1px solid #8798af; border-radius: .5rem; padding: .8rem; font: inherit; }
        .hint { font-size: .9rem; margin: .6rem 0 1.25rem; }
        button { border: 1px solid #c4cede; border-radius: .6rem; background: white; color: #283c5d; padding: .7rem 1rem; font: inherit; cursor: pointer; }
        .submit { background: #314e94; color: white; width: 100%; }
        button:hover { filter: brightness(.95); }
        a { color: #314e94; }
        button:focus-visible, a:focus-visible, input:focus-visible { outline: 3px solid #426bd7; outline-offset: 3px; }
        .error { color: #9e2333; overflow-wrap: anywhere; }
        .form-error { background: #fff2f3; border: 1px solid #dca1a8; border-radius: .6rem; padding: 1rem; }
    </style>
</head>
<body>
<header>
    <div>
        <div class="brand">{{ config('app.name', 'Kadify') }}</div>
        <p class="account">{{ __('customer.account', ['name' => $accountName], $language) }}</p>
    </div>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">{{ __('customer.logout', [], $language) }}</button>
    </form>
</header>
<main>
    <h1>{{ __('customer.create_title', [], $language) }}</h1>
    <p class="intro">{{ __('customer.create_intro', [], $language) }}</p>
    @if ($formError)
        <p class="error form-error" role="alert">{{ __('customer.'.$formError, [], $language) }}</p>
    @endif
    <form class="panel" method="POST" action="{{ route('customer.workspaces.store') }}">
        @csrf
        <input type="hidden" name="request_key" value="{{ $requestKey }}">
        <label for="workspace-name">{{ __('customer.name', [], $language) }}</label>
        <input id="workspace-name" name="name" type="text" value="{{ $name }}" required maxlength="255" autocomplete="organization" aria-describedby="name-hint{{ $errors->has('name') ? ' name-error' : '' }}" @if ($errors->has('name')) aria-invalid="true" @endif>
        <p class="hint" id="name-hint">{{ __('customer.name_hint', [], $language) }}</p>
        @if ($errors->has('name'))
            <p class="error" id="name-error" role="alert">{{ $errors->first('name') }}</p>
        @endif
        @if ($errors->has('request_key'))
            <p class="error" role="alert">{{ $errors->first('request_key') }}</p>
        @endif
        <button class="submit" type="submit">{{ __('customer.submit', [], $language) }}</button>
    </form>
    <a href="{{ route('customer.home') }}">{{ __('customer.back', [], $language) }}</a>
</main>
</body>
</html>
