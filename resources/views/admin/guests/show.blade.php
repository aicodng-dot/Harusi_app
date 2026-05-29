@extends('layouts.admin', ['title' => 'Guest Details', 'headerTitle' => 'Guest Details'])

@section('content')
    @php
        $qrStatus = ! $guest->qrCode
            ? 'missing'
            : ($guest->qrCode->is_active && ! $guest->qrCode->revoked_at ? 'active' : 'revoked');

        $statusBadge = fn (string $status) => match ($status) {
            'active' => 'bg-emerald-100 text-emerald-800',
            'partially_used' => 'bg-amber-100 text-amber-800',
            'fully_used' => 'bg-zinc-200 text-zinc-800',
            'cancelled', 'revoked' => 'bg-rose-100 text-rose-800',
            default => 'bg-zinc-100 text-zinc-700',
        };
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="section-kicker">Guest management</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">{{ $guest->name }}</h2>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.guests.edit', $guest) }}" class="primary-button">Edit</a>
                <a href="{{ route('admin.guests.index') }}" class="secondary-button">Back</a>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid gap-6 xl:grid-cols-[1fr_360px]">
            <article class="app-card overflow-hidden">
                <div class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <p class="text-xs font-bold uppercase text-zinc-500">Phone number</p>
                        <p class="mt-2 font-semibold">{{ $guest->phone_number }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase text-zinc-500">Pass type</p>
                        <p class="mt-2 font-semibold">{{ $guest->passTypeLabel() }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase text-zinc-500">Status</p>
                        <p class="mt-2"><span class="status-badge {{ $statusBadge($guest->status) }}">{{ str_replace('_', ' ', $guest->status) }}</span></p>
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase text-zinc-500">Allowed entries</p>
                        <p class="mt-2 text-2xl font-semibold">{{ $guest->allowed_entries }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase text-zinc-500">Used entries</p>
                        <p class="mt-2 text-2xl font-semibold">{{ $guest->used_entries }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase text-zinc-500">Remaining entries</p>
                        <p class="mt-2 text-2xl font-semibold">{{ $guest->remainingEntries() }}</p>
                    </div>
                </div>
            </article>

            <article class="app-card p-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="section-kicker">QR code</p>
                        <p class="mt-2"><span class="status-badge {{ $statusBadge($qrStatus) }}">{{ $qrStatus }}</span></p>
                    </div>
                    <form method="POST" action="{{ route('admin.guests.qr.generate', $guest) }}" @if ($guest->qrCode) data-confirm data-confirm-title="Regenerate QR code" data-confirm-message="The current QR token will stop working immediately." @endif>
                        @csrf
                        <button type="submit" class="secondary-button">{{ $guest->qrCode ? 'Regenerate QR' : 'Generate QR' }}</button>
                    </form>
                </div>

                @if ($guest->qrCode)
                    <div class="mt-5 grid place-items-center rounded-lg border border-stone-200 bg-white p-4">
                        <img src="{{ route('admin.guests.qr', $guest) }}" alt="QR code for {{ $guest->name }}" class="size-56">
                    </div>
                    <a href="{{ route('admin.guests.qr.download', $guest) }}" class="primary-button mt-4 w-full">Download QR</a>
                @else
                    <div class="mt-5 rounded-lg border border-dashed border-stone-300 p-6 text-center text-sm font-semibold text-zinc-500">
                        No QR code has been generated yet.
                    </div>
                @endif
            </article>
        </div>

        <article class="app-card overflow-hidden">
            <div class="border-b border-stone-200 px-5 py-4">
                <h3 class="text-lg font-semibold">Recent activity</h3>
            </div>
            <div class="divide-y divide-stone-100">
                @forelse ($guest->checkins->sortByDesc('checked_in_at')->take(8) as $checkin)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div>
                            <p class="font-semibold">{{ str_replace('_', ' ', $checkin->scan_result) }}</p>
                            <p class="mt-1 text-sm text-zinc-500">{{ $checkin->gate_name ?? 'No gate' }} · {{ $checkin->checked_in_at?->diffForHumans() ?? 'Not timed' }}</p>
                        </div>
                        <p class="text-sm font-semibold">{{ $checkin->entries_added }} added</p>
                    </div>
                @empty
                    <div class="px-5 py-10 text-center text-sm font-semibold text-zinc-500">No activity recorded for this guest yet.</div>
                @endforelse
            </div>
        </article>
    </section>
@endsection
