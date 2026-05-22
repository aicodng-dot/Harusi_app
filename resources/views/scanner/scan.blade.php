@extends('layouts.scanner', ['title' => 'Scan QR'])

@section('content')
    <main class="mx-auto flex min-h-screen w-full max-w-sm flex-col px-4 py-5">
        <header class="flex items-start justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-emerald-300">Scanner app</p>
                <h1 class="mt-1 text-2xl font-semibold">Scan QR</h1>
                <p class="mt-2 text-sm leading-6 text-zinc-400">Mobile-first placeholder for camera scanning and token validation.</p>
            </div>
            <a href="{{ route('scanner.dashboard') }}" class="rounded-md bg-white/10 px-3 py-2 text-sm font-semibold">Back</a>
        </header>

        <section class="scanner-camera mt-6">
            <div class="absolute inset-0 grid place-items-center">
                <div class="size-56 rounded-lg border-2 border-white/80"></div>
            </div>
            <div class="absolute inset-x-3 bottom-3 rounded-lg bg-zinc-950/80 px-3 py-2 text-sm font-semibold">Camera placeholder</div>
        </section>

        <button class="scanner-button scanner-button-primary mt-4">Start scan</button>
    </main>
@endsection
