@extends('layouts.scanner', ['title' => 'Recent Scans'])

@section('content')
    @php
        $resultBadge = fn (?string $result) => match ($result) {
            'valid', 'admitted' => 'bg-emerald-400 text-zinc-950',
            'already_used' => 'bg-amber-300 text-zinc-950',
            'cancelled', 'revoked', 'invalid', 'error' => 'bg-rose-400 text-white',
            default => 'bg-white/10 text-white',
        };
    @endphp

    <main class="scanner-shell">
        <header class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase text-emerald-300">Scanner app</p>
                <h1 class="mt-2 truncate text-2xl font-semibold">Recent Scans</h1>
                <p class="mt-1 truncate text-sm font-semibold text-zinc-400">{{ auth()->user()?->gate_name ?? 'Gate activity' }}</p>
            </div>
            <a href="{{ route('scanner.scan') }}" class="scanner-quiet-button">Scan</a>
        </header>

        <section class="mt-6 space-y-3">
            @forelse ($checkins as $checkin)
                <article class="scanner-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-base font-semibold">{{ $checkin->guest?->name ?? 'Unknown QR' }}</p>
                            <p class="mt-1 text-sm font-semibold text-zinc-400">{{ $checkin->checked_in_at?->format('M j, H:i') ?? $checkin->created_at->format('M j, H:i') }}</p>
                        </div>
                        <span class="shrink-0 rounded-md px-2 py-1 text-xs font-bold {{ $resultBadge($checkin->scan_result) }}">{{ str_replace('_', ' ', $checkin->scan_result) }}</span>
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-lg bg-zinc-950/70 p-3">
                            <p class="text-xs font-bold uppercase text-zinc-500">Added</p>
                            <p class="mt-1 text-2xl font-semibold">{{ $checkin->entries_added }}</p>
                        </div>
                        <div class="rounded-lg bg-zinc-950/70 p-3">
                            <p class="text-xs font-bold uppercase text-zinc-500">Used</p>
                            <p class="mt-1 text-2xl font-semibold">{{ $checkin->used_entries_after_scan }}</p>
                        </div>
                        <div class="rounded-lg bg-zinc-950/70 p-3">
                            <p class="text-xs font-bold uppercase text-zinc-500">Left</p>
                            <p class="mt-1 text-2xl font-semibold">{{ $checkin->remaining_entries_after_scan }}</p>
                        </div>
                    </div>
                </article>
            @empty
                <section class="scanner-card">
                    <p class="text-base font-semibold text-zinc-200">No scans yet</p>
                    <p class="mt-2 text-sm leading-6 text-zinc-400">Your scans will appear here after you verify a QR code.</p>
                    <a href="{{ route('scanner.scan') }}" class="scanner-primary-action mt-4 w-full">Start Scanning</a>
                </section>
            @endforelse
        </section>

        @if ($checkins->hasPages())
            <div class="mt-4 rounded-lg bg-white p-3 text-zinc-950">
                {{ $checkins->links() }}
            </div>
        @endif

        @include('scanner._bottom-nav')
    </main>
@endsection
