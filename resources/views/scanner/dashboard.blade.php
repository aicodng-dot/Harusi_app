@extends('layouts.scanner', ['title' => 'Scanner Dashboard'])

@section('content')
    @php
        $scannerName = $scannerUser?->name ?? auth()->user()?->name ?? 'Scanner';
        $gateName = $scannerUser?->gate_name ?? auth()->user()?->gate_name ?? 'Gate';
        $resultBadge = fn (?string $result) => match ($result) {
            'valid', 'admitted' => 'bg-emerald-400 text-zinc-950',
            'already_used' => 'bg-amber-300 text-zinc-950',
            'cancelled', 'revoked', 'invalid', 'error' => 'bg-rose-400 text-white',
            default => 'bg-white/10 text-white',
        };
    @endphp

    <main class="scanner-shell">
        <header class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase text-emerald-300">Admission scanner</p>
                <h1 class="mt-2 truncate text-2xl font-semibold">{{ $scannerName }}</h1>
                <p class="mt-1 truncate text-sm font-semibold text-zinc-400">{{ $gateName }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="scanner-quiet-button">Logout</button>
            </form>
        </header>

        <section class="mt-6 grid grid-cols-3 gap-3">
            <article class="scanner-stat-card">
                <p class="text-xs font-bold uppercase text-zinc-400">Scans</p>
                <p class="mt-2 text-3xl font-semibold">{{ $todayScans }}</p>
            </article>
            <article class="scanner-stat-card border-emerald-500/40">
                <p class="text-xs font-bold uppercase text-emerald-300">Admitted</p>
                <p class="mt-2 text-3xl font-semibold">{{ $todayEntries }}</p>
            </article>
            <article class="scanner-stat-card border-rose-500/40">
                <p class="text-xs font-bold uppercase text-rose-300">Invalid</p>
                <p class="mt-2 text-3xl font-semibold">{{ $todayInvalidAttempts }}</p>
            </article>
        </section>

        <section class="mt-6 grid gap-3">
            <a href="{{ route('scanner.scan') }}" class="scanner-primary-action">Start Scanning</a>
            <div class="grid grid-cols-2 gap-3">
                <a href="{{ route('scanner.scan') }}#manual" class="scanner-secondary-action">Manual Search</a>
                <a href="{{ route('scanner.recent-scans') }}" class="scanner-secondary-action">Recent Scans</a>
            </div>
        </section>

        <section class="scanner-card mt-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold">Latest scans</h2>
                <span class="rounded-md bg-white/10 px-2 py-1 text-xs font-bold text-zinc-300">Today</span>
            </div>

            <div class="mt-3 space-y-3">
                @forelse ($recentCheckins as $checkin)
                    <article class="rounded-lg bg-zinc-950/70 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="min-w-0 truncate font-semibold">{{ $checkin->guest?->name ?? 'Unknown QR' }}</p>
                            <span class="shrink-0 rounded-md px-2 py-1 text-xs font-bold {{ $resultBadge($checkin->scan_result) }}">{{ str_replace('_', ' ', $checkin->scan_result) }}</span>
                        </div>
                        <p class="mt-2 text-sm text-zinc-400">{{ $checkin->entries_added }} admitted &middot; {{ $checkin->checked_in_at?->format('H:i') ?? 'Just now' }}</p>
                    </article>
                @empty
                    <div class="rounded-lg border border-dashed border-white/10 p-4 text-sm font-semibold text-zinc-400">
                        No scans yet today.
                    </div>
                @endforelse
            </div>
        </section>

        @include('scanner._bottom-nav')
    </main>
@endsection
