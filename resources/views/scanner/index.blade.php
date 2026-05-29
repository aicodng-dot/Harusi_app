@extends('layouts.scanner', ['title' => 'Scan QR'])

@section('content')
    <main
        class="scanner-shell"
        data-scanner
        data-initial-token="{{ $initialToken }}"
        data-verify-url="{{ route('scanner.validate') }}"
        data-admit-url="{{ route('scanner.admit') }}"
        data-csrf="{{ csrf_token() }}"
    >
        <header class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase text-emerald-300">Gate scanner</p>
                <h1 class="mt-2 truncate text-2xl font-semibold">Scan QR</h1>
            </div>
            <a href="{{ route('scanner.dashboard') }}" class="scanner-quiet-button">Dashboard</a>
        </header>

        <section class="mt-5">
            <div class="scanner-camera">
                <div id="qr-camera-reader" class="absolute inset-0" data-qr-reader></div>
                <div class="pointer-events-none absolute inset-0 grid place-items-center">
                    <div class="size-56 rounded-xl border-2 border-emerald-300 shadow-[0_0_0_999px_rgb(0_0_0_/_0.45)]"></div>
                </div>
                <div class="absolute inset-x-3 top-3 rounded-lg bg-zinc-950/80 px-3 py-2 text-center text-sm font-bold text-emerald-200">
                    Place QR inside the frame
                </div>
                <div class="absolute inset-x-3 bottom-3 rounded-lg bg-zinc-950/85 px-3 py-2 text-center text-sm font-semibold text-white" data-camera-status>
                    Camera idle
                </div>
            </div>

            <div class="mt-4 grid grid-cols-[1fr_96px] gap-3">
                <button type="button" class="scanner-primary-action min-h-14" data-start-camera>Start Camera</button>
                <button type="button" class="scanner-secondary-action min-h-14" data-stop-camera>Stop</button>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3">
                <button type="button" class="scanner-secondary-action w-full" data-scan-another hidden>Scan Next</button>
                <a href="{{ route('scanner.manual-search') }}" class="scanner-secondary-action w-full">Manual Search</a>
            </div>
        </section>

        <section id="manual" class="scanner-card mt-5">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold">Manual code</h2>
                <span class="rounded-md bg-white/10 px-2 py-1 text-xs font-bold text-zinc-300">Backup</span>
            </div>
            <label class="mt-3 grid gap-2 text-sm font-semibold text-zinc-300">
                Token or verification URL
                <input class="scanner-input" data-manual-token value="{{ $initialToken }}" autocomplete="off" inputmode="text" placeholder="Paste or type code">
            </label>
            <button type="button" class="scanner-primary-action mt-3 w-full" data-verify-token>Verify Pass</button>
        </section>

        <section class="scanner-card mt-5">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold">Recent result</h2>
                <span class="rounded-md bg-white/10 px-2 py-1 text-xs font-bold text-zinc-300">Live</span>
            </div>

            <div class="mt-3 rounded-lg border border-dashed border-white/10 p-4 text-sm font-semibold text-zinc-400" data-idle-result>
                Scan or verify a code to show the latest result.
            </div>

            <div class="scanner-result-panel scanner-result-warning" data-result-panel hidden>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase text-zinc-500" data-result-label>Waiting</p>
                        <h2 class="mt-1 truncate text-2xl font-semibold" data-result-title>Scan a pass</h2>
                    </div>
                    <span class="shrink-0 rounded-md px-2.5 py-1 text-xs font-black" data-result-badge>Idle</span>
                </div>

                <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-md bg-zinc-100 p-3">
                        <p class="text-xs font-semibold text-zinc-500">Allowed</p>
                        <p class="mt-1 text-xl font-semibold" data-allowed-count>0</p>
                    </div>
                    <div class="rounded-md bg-zinc-100 p-3">
                        <p class="text-xs font-semibold text-zinc-500">Used</p>
                        <p class="mt-1 text-xl font-semibold" data-used-count>0</p>
                    </div>
                    <div class="rounded-md bg-zinc-100 p-3">
                        <p class="text-xs font-semibold text-zinc-500">Left</p>
                        <p class="mt-1 text-xl font-semibold" data-remaining-count>0</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-2 rounded-md bg-zinc-100 p-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-semibold text-zinc-500">Phone</span>
                        <span class="truncate font-bold text-zinc-800" data-phone-number>Unavailable</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-semibold text-zinc-500">Status</span>
                        <span class="font-bold text-zinc-800" data-system-status>Unknown</span>
                    </div>
                </div>

                <p class="mt-4 rounded-md bg-zinc-100 px-3 py-2 text-sm font-semibold text-zinc-700" data-result-message></p>

                <div class="mt-4" data-admit-controls hidden>
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-semibold text-zinc-700">Admit count</p>
                        <div class="flex items-center rounded-md border border-zinc-300">
                            <button type="button" class="quantity-button" data-quantity-minus>-</button>
                            <input type="number" class="w-16 border-x border-zinc-300 py-2 text-center text-lg font-semibold outline-none" min="1" max="10" value="1" data-admit-quantity>
                            <button type="button" class="quantity-button" data-quantity-plus>+</button>
                        </div>
                    </div>

                    <label class="mt-3 grid gap-2 text-sm font-semibold text-zinc-700">
                        Gate officer
                        <input class="input-control" data-admitted-by placeholder="Gate team">
                    </label>

                    <button type="button" class="mt-4 flex min-h-14 w-full items-center justify-center rounded-lg bg-emerald-600 px-5 text-base font-bold text-white transition hover:bg-emerald-700" data-admit-button>
                        Confirm Admission
                    </button>
                </div>
            </div>
        </section>

        @include('scanner._bottom-nav')
    </main>
@endsection
