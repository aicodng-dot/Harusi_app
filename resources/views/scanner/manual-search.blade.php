@extends('layouts.scanner', ['title' => 'Manual Search'])

@section('content')
    @php
        $statusBadge = fn (string $status) => match ($status) {
            \App\Models\Guest::STATUS_ACTIVE => 'bg-sky-400 text-zinc-950',
            \App\Models\Guest::STATUS_PARTIALLY_USED => 'bg-amber-300 text-zinc-950',
            \App\Models\Guest::STATUS_FULLY_USED => 'bg-emerald-400 text-zinc-950',
            \App\Models\Guest::STATUS_CANCELLED => 'bg-zinc-500 text-white',
            default => 'bg-white/10 text-white',
        };
        $resultStatusLabel = fn (string $status) => match ($status) {
            \App\Models\Guest::STATUS_ACTIVE => 'Active',
            \App\Models\Guest::STATUS_PARTIALLY_USED => 'Partially Used',
            \App\Models\Guest::STATUS_FULLY_USED => 'Fully Used',
            \App\Models\Guest::STATUS_CANCELLED => 'Cancelled',
            default => str_replace('_', ' ', ucwords($status, '_')),
        };
        $manualPassLabel = fn (string $passType) => match ($passType) {
            \App\Models\Guest::PASS_SINGLE => 'Single',
            \App\Models\Guest::PASS_DOUBLE => 'Double',
            \App\Models\Guest::PASS_SPECIAL => 'Special / Family',
            default => ucfirst($passType),
        };
    @endphp

    <main class="scanner-shell">
        <header class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase text-emerald-300">Scanner app</p>
                <h1 class="mt-2 truncate text-2xl font-semibold">Manual Search</h1>
                <p class="mt-1 truncate text-sm font-semibold text-zinc-400">{{ auth()->user()?->gate_name ?? 'Gate admission' }} &middot; {{ $assignedEvent->event_name ?? 'Assigned event' }}</p>
            </div>
            <a href="{{ route('scanner.scan') }}" class="scanner-quiet-button">Scan</a>
        </header>

        @if (session('success'))
            <div class="mt-5 rounded-xl border border-emerald-300/40 bg-emerald-400/15 px-4 py-3 text-sm font-bold text-emerald-100">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-5 rounded-xl border border-rose-300/40 bg-rose-500/15 px-4 py-3 text-sm font-bold text-rose-100">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="GET" action="{{ route('scanner.manual-search') }}" class="scanner-card mt-5">
            <label class="grid gap-2 text-sm font-semibold text-zinc-300">
                Phone number or 4-digit code
                <input class="scanner-input" name="q" value="{{ $search }}" autocomplete="off" inputmode="search" placeholder="Enter phone number or 4-digit code">
            </label>
            <button type="submit" class="scanner-primary-action mt-3 w-full">Search Guest</button>
        </form>

        <section class="mt-5 space-y-3">
            @if ($search === '')
                <div class="scanner-card">
                    <p class="text-base font-semibold text-zinc-200">Search by phone number or 4-digit guest code.</p>
                </div>
            @elseif ($manualSearchError)
                <div class="scanner-card">
                    <p class="text-base font-semibold text-zinc-200">{{ $manualSearchError }}</p>
                </div>
            @elseif ($guests->isEmpty())
                <div class="scanner-card">
                    <p class="text-base font-semibold text-zinc-200">No matching guest found.</p>
                </div>
            @endif
            @if ($guests->count() > 1)
                <div class="scanner-card border border-amber-300/30 bg-amber-400/10">
                    <p class="text-base font-semibold text-amber-100">Multiple guests use this phone number. Select the correct guest.</p>
                </div>
            @endif

            @foreach ($guests as $guest)
                @php
                    $remaining = $guest->remainingEntries();
                    $canAdmit = $guest->status !== \App\Models\Guest::STATUS_CANCELLED
                        && $guest->status !== \App\Models\Guest::STATUS_FULLY_USED
                        && $remaining > 0;
                @endphp

                <article class="scanner-card" data-manual-admit-card>
                    <div class="space-y-2 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <span class="shrink-0 font-black text-zinc-500">GUEST:</span>
                            <span class="min-w-0 text-right font-bold text-white">{{ $guest->name }}</span>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="shrink-0 font-black text-zinc-500">PHONE:</span>
                            <span class="min-w-0 text-right font-bold text-white">{{ $guest->phone_number }}</span>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="shrink-0 font-black text-zinc-500">CODE:</span>
                            <span class="min-w-0 text-right font-mono text-base font-bold text-white">{{ $guest->manual_code }}</span>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="shrink-0 font-black text-zinc-500">PASS:</span>
                            <span class="min-w-0 text-right font-bold text-white">{{ $manualPassLabel($guest->pass_type) }}</span>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="shrink-0 font-black text-zinc-500">ALLOWED:</span>
                            <span class="min-w-0 text-right font-bold text-white">{{ $guest->allowed_entries }}</span>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="shrink-0 font-black text-zinc-500">USED:</span>
                            <span class="min-w-0 text-right font-bold text-white">{{ $guest->used_entries }}</span>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="shrink-0 font-black text-zinc-500">REMAINING:</span>
                            <span class="min-w-0 text-right font-bold text-white">{{ $remaining }}</span>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="shrink-0 font-black text-zinc-500">STATUS:</span>
                            <span class="shrink-0 rounded-md px-2 py-1 text-right text-xs font-black {{ $statusBadge($guest->status) }}">{{ $resultStatusLabel($guest->status) }}</span>
                        </div>
                    </div>

                    @if ($canAdmit)
                        <form method="POST" action="{{ route('scanner.manual-admit') }}" class="mt-4" data-disable-on-submit>
                            @csrf
                            <input type="hidden" name="guest_id" value="{{ $guest->id }}">
                            <input type="hidden" name="search" value="{{ $search }}">

                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-bold text-zinc-300">Guest counter</p>
                                <div class="flex items-center overflow-hidden rounded-lg border border-white/10 bg-white text-zinc-950">
                                    <button type="button" class="quantity-button" data-manual-minus>-</button>
                                    <input type="number" class="w-16 border-x border-zinc-300 py-2 text-center text-lg font-semibold outline-none" name="entries_to_admit" min="1" max="{{ $remaining }}" value="1" data-manual-quantity>
                                    <button type="button" class="quantity-button" data-manual-plus>+</button>
                                </div>
                            </div>

                            <button type="submit" class="scanner-primary-action mt-4 w-full" data-submitting-text="Recording...">Confirm Admission</button>
                        </form>
                    @elseif ($guest->status === \App\Models\Guest::STATUS_CANCELLED)
                        <p class="mt-4 rounded-lg bg-zinc-700 px-3 py-3 text-center text-sm font-bold text-white">Cancelled Pass</p>
                    @else
                        <p class="mt-4 rounded-lg bg-amber-300 px-3 py-3 text-center text-sm font-bold text-zinc-950">Already Fully Used</p>
                    @endif
                </article>
            @endforeach
        </section>

        @include('scanner._bottom-nav')
    </main>
@endsection
