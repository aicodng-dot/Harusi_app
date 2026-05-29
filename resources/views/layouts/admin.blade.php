<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-stone-50 text-zinc-950 antialiased">
    @php
        $navItems = [
            ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'active' => 'admin.dashboard', 'marker' => 'bg-emerald-500', 'icon' => 'DB'],
            ['label' => 'Guests', 'route' => 'admin.guests.index', 'active' => 'admin.guests.*', 'marker' => 'bg-sky-500', 'icon' => 'GS'],
            ['label' => 'QR Codes', 'route' => 'admin.qr-codes.index', 'active' => 'admin.qr-codes.*', 'marker' => 'bg-violet-500', 'icon' => 'QR'],
            ['label' => 'Check-ins', 'route' => 'admin.checkins.index', 'active' => 'admin.checkins.*', 'marker' => 'bg-amber-500', 'icon' => 'IN'],
            ['label' => 'Reports', 'route' => 'admin.reports.index', 'active' => 'admin.reports.*', 'marker' => 'bg-cyan-500', 'icon' => 'RP'],
            ['label' => 'Scanner Users', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'marker' => 'bg-rose-500', 'icon' => 'SU'],
            ['label' => 'Settings', 'route' => 'admin.settings.index', 'active' => 'admin.settings.*', 'marker' => 'bg-zinc-500', 'icon' => 'ST'],
        ];
    @endphp

    <div class="min-h-screen md:grid md:grid-cols-[280px_minmax(0,1fr)]">
        <aside class="hidden border-r border-stone-200 bg-white md:sticky md:top-0 md:flex md:h-screen md:flex-col">
            <div class="border-b border-stone-200 px-5 py-5">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3">
                    <span class="grid size-12 place-items-center rounded-lg bg-zinc-950 text-sm font-black text-white">QR</span>
                    <span class="min-w-0">
                        <span class="block truncate text-lg font-bold">Wedding QR</span>
                        <span class="block truncate text-xs font-semibold text-zinc-500">Admission System</span>
                    </span>
                </a>
            </div>

            <nav class="flex-1 space-y-1 px-3 py-5">
                @foreach ($navItems as $item)
                    <a href="{{ route($item['route']) }}" @class(['admin-nav-link', 'admin-nav-link-active' => request()->routeIs($item['active'])])>
                        <span @class(['grid size-8 shrink-0 place-items-center rounded-md text-[10px] font-black', 'bg-white/15 text-white' => request()->routeIs($item['active']), 'bg-stone-100 text-zinc-500' => ! request()->routeIs($item['active'])])>{{ $item['icon'] }}</span>
                        <span class="truncate">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            <div class="border-t border-stone-200 p-4">
                <div class="rounded-lg border border-stone-200 bg-stone-50 p-4">
                    <p class="truncate text-sm font-semibold">{{ auth()->user()?->name ?? 'Admin' }}</p>
                    <p class="mt-1 text-xs font-bold uppercase text-zinc-500">{{ auth()->user()?->role ?? 'admin' }}</p>
                    <form method="POST" action="{{ route('logout') }}" class="mt-4">
                        @csrf
                        <button type="submit" class="admin-nav-link w-full justify-start border border-stone-200 bg-white">
                            <span class="admin-nav-marker bg-zinc-950"></span>
                            <span>Logout</span>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <div class="min-w-0">
            <header class="sticky top-0 z-30 border-b border-stone-200 bg-white/95 px-4 py-3 backdrop-blur sm:px-6 lg:px-8">
                <div class="flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <p class="hidden text-xs font-bold uppercase text-emerald-700 md:block">Wedding event management</p>
                        <h1 class="truncate text-lg font-semibold sm:text-xl">{{ $headerTitle ?? $title ?? 'Admin Panel' }}</h1>
                    </div>

                    <div class="flex items-center gap-3">
                        <a href="{{ route('admin.guests.create') }}" class="hidden secondary-button sm:inline-flex">New invite</a>
                        <div class="hidden rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-right md:block">
                            <p class="text-sm font-semibold">{{ auth()->user()?->name ?? 'Admin' }}</p>
                            <p class="mt-0.5 text-xs font-semibold text-zinc-500">{{ now()->format('M j, Y') }}</p>
                        </div>
                        <form method="POST" action="{{ route('logout') }}" class="md:hidden">
                            @csrf
                            <button type="submit" class="secondary-button">Logout</button>
                        </form>
                    </div>
                </div>

                <nav class="mt-3 flex gap-2 overflow-x-auto pb-1 md:hidden">
                    @foreach ($navItems as $item)
                        <a href="{{ route($item['route']) }}" @class(['filter-pill shrink-0', 'is-active' => request()->routeIs($item['active'])])>{{ $item['label'] }}</a>
                    @endforeach
                </nav>
            </header>

            <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                @yield('content')
            </main>
        </div>
    </div>

    <dialog class="confirm-dialog" data-confirm-dialog>
        <form method="dialog" class="p-5">
            <div class="flex items-start gap-4">
                <span class="admin-icon bg-rose-600">!</span>
                <div class="min-w-0">
                    <h2 class="text-lg font-bold" data-confirm-title>Confirm action</h2>
                    <p class="mt-2 text-sm leading-6 text-zinc-600" data-confirm-message>This action cannot be undone.</p>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="secondary-button" data-confirm-cancel>Cancel</button>
                <button type="button" class="danger-button" data-confirm-accept>Confirm</button>
            </div>
        </form>
    </dialog>
</body>
</html>
