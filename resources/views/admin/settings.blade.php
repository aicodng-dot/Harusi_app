@extends('layouts.admin', ['title' => 'Settings'])

@section('content')
    <section class="space-y-6">
        <div>
            <p class="section-kicker">Admin panel</p>
            <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Settings</h2>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <article class="metric-card">
                <p class="text-sm font-semibold text-zinc-500">Application</p>
                <p class="mt-2 text-xl font-semibold">{{ config('app.name') }}</p>
            </article>
            <article class="metric-card">
                <p class="text-sm font-semibold text-zinc-500">Environment</p>
                <p class="mt-2 text-xl font-semibold">{{ app()->environment() }}</p>
            </article>
            <article class="metric-card">
                <p class="text-sm font-semibold text-zinc-500">Maximum entries per QR</p>
                <p class="mt-2 text-xl font-semibold">10</p>
            </article>
        </div>
    </section>
@endsection
