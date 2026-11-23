<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('customer.title', [], $language) }} · {{ config('app.name', 'Kadify') }}</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #192435; background: #f5f7fb; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        header, main { width: min(100% - 2rem, 58rem); margin-inline: auto; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding-block: 1.5rem; border-bottom: 1px solid #dce2ed; }
        .brand { font-size: 1.15rem; font-weight: 750; overflow-wrap: anywhere; }
        .account { margin: .35rem 0 0; color: #526174; font-size: .9rem; overflow-wrap: anywhere; }
        button, .page-link, .create-link { display: inline-block; border: 1px solid #c4cede; border-radius: .6rem; background: white; color: #283c5d; padding: .65rem 1rem; font: inherit; text-decoration: none; cursor: pointer; }
        button:hover, .page-link:hover, .create-link:hover { background: #eaf0fa; }
        button:focus-visible, a:focus-visible { outline: 3px solid #426bd7; outline-offset: 3px; }
        main { padding-block: 2.5rem; }
        h1 { font-size: clamp(1.65rem, 4vw, 2.1rem); margin: 0 0 .5rem; }
        .intro { color: #526174; margin: 0 0 1.75rem; }
        .create-link { margin-bottom: 1.5rem; background: #edf2ff; }
        .status { padding: 1rem; border: 1px solid #a3cfb7; border-radius: .6rem; background: #f0fbf4; }
        .workspaces { list-style: none; padding: 0; margin: 0; display: grid; gap: 1rem; }
        .workspace, .empty { background: white; border: 1px solid #dce2ed; border-radius: .85rem; padding: 1.35rem; }
        h2 { font-size: 1.1rem; margin: 0; overflow-wrap: anywhere; }
        .role { display: inline-block; margin: .8rem 0 0; border-radius: 2rem; padding: .3rem .65rem; background: #edf2ff; color: #314e94; font-size: .85rem; }
        .empty p { margin-bottom: 0; color: #526174; }
        nav { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .75rem; margin-top: 1.5rem; }
        .page-status { color: #526174; font-size: .9rem; }
        @media (max-width: 35rem) { header { align-items: flex-start; } main { padding-block: 1.75rem; } button { padding: .6rem .8rem; white-space: nowrap; } }
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
    <h1>{{ __('customer.title', [], $language) }}</h1>
    <p class="intro">{{ __('customer.intro', [], $language) }}</p>
    @if (in_array(session('customer_workspace_status'), ['created', 'existing'], true))
        <p class="status" role="status">{{ __('customer.'.session('customer_workspace_status'), [], $language) }}</p>
    @endif
    <a class="create-link" href="{{ route('customer.workspaces.create') }}">{{ __('customer.create', [], $language) }}</a>
    @if ($workspaces->isEmpty())
        <section class="empty" aria-labelledby="empty-title">
            <h2 id="empty-title">{{ __('customer.empty', [], $language) }}</h2>
            <p>{{ __('customer.empty_description', [], $language) }}</p>
        </section>
    @else
        <ul class="workspaces">
            @foreach ($workspaces as $workspace)
                <li class="workspace">
                    <h2>{{ $workspace->name }}</h2>
                    <p class="role">{{ __('customer.'.$workspace->membership_role, [], $language) }}</p>
                </li>
            @endforeach
        </ul>
        @if ($workspaces->hasPages())
            <nav aria-label="{{ __('customer.page', ['current' => $workspaces->currentPage(), 'last' => $workspaces->lastPage()], $language) }}">
                @if ($workspaces->previousPageUrl())
                    <a class="page-link" rel="prev" href="{{ $workspaces->previousPageUrl() }}">{{ __('customer.previous', [], $language) }}</a>
                @endif
                <span class="page-status">{{ __('customer.page', ['current' => $workspaces->currentPage(), 'last' => $workspaces->lastPage()], $language) }}</span>
                @if ($workspaces->nextPageUrl())
                    <a class="page-link" rel="next" href="{{ $workspaces->nextPageUrl() }}">{{ __('customer.next', [], $language) }}</a>
                @endif
            </nav>
        @endif
    @endif
</main>
</body>
</html>
