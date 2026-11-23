@extends('layouts.admin', ['title' => 'Settings'])

@section('content')
    @php
        $statusBadge = fn (bool $configured) => $configured
            ? 'bg-emerald-100 text-emerald-800'
            : 'bg-amber-100 text-amber-800';
        $statusText = fn (bool $configured) => $configured ? 'Configured' : 'Not configured';
    @endphp

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
                <p class="mt-2 text-xl font-semibold">{{ \App\Models\Guest::MAX_ALLOWED_ENTRIES }}</p>
            </article>
        </div>

        <div class="app-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-stone-200 pb-4">
                <div>
                    <p class="section-kicker">Notification Settings</p>
                    <h3 class="mt-2 text-xl font-semibold">WhatsApp readiness</h3>
                </div>
                <a href="{{ route('admin.notification-logs.index') }}" class="secondary-button">Notification logs</a>
            </div>

            <dl class="mt-5 grid gap-x-8 gap-y-0 md:grid-cols-2 xl:grid-cols-3">
                <div class="border-b border-stone-200 py-4">
                    <dt class="text-xs font-black uppercase text-zinc-500">WhatsApp provider</dt>
                    <dd class="mt-2 text-sm font-bold text-zinc-950">{{ $whatsappReadiness['provider_label'] }}</dd>
                </div>
                <div class="border-b border-stone-200 py-4">
                    <dt class="text-xs font-black uppercase text-zinc-500">WhatsApp credentials status</dt>
                    <dd class="mt-2">
                        <span class="status-badge {{ $statusBadge($whatsappReadiness['credentials_configured']) }}">{{ $statusText($whatsappReadiness['credentials_configured']) }}</span>
                    </dd>
                </div>
                <div class="border-b border-stone-200 py-4">
                    <dt class="text-xs font-black uppercase text-zinc-500">Phone Number ID status</dt>
                    <dd class="mt-2">
                        <span class="status-badge {{ $statusBadge($whatsappReadiness['phone_number_id_configured']) }}">{{ $statusText($whatsappReadiness['phone_number_id_configured']) }}</span>
                    </dd>
                </div>
                <div class="border-b border-stone-200 py-4">
                    <dt class="text-xs font-black uppercase text-zinc-500">Business Account ID status</dt>
                    <dd class="mt-2">
                        <span class="status-badge {{ $statusBadge($whatsappReadiness['business_account_id_configured']) }}">{{ $statusText($whatsappReadiness['business_account_id_configured']) }}</span>
                    </dd>
                </div>
                <div class="border-b border-stone-200 py-4">
                    <dt class="text-xs font-black uppercase text-zinc-500">Webhook status</dt>
                    <dd class="mt-2 text-sm font-semibold text-zinc-700">{{ $whatsappReadiness['webhook_status'] }}</dd>
                </div>
                <div class="border-b border-stone-200 py-4">
                    <dt class="text-xs font-black uppercase text-zinc-500">Template approval status</dt>
                    <dd class="mt-2 text-sm font-semibold text-zinc-700">{{ $whatsappReadiness['template_status'] }}</dd>
                </div>
            </dl>
        </div>
    </section>
@endsection
