@extends('layouts.admin', ['title' => 'Admin Dashboard', 'headerTitle' => 'Dashboard'])

@section('content')
    @php
        $statCards = [
            ['label' => 'Total Invites', 'value' => $stats['total_guests'], 'tone' => 'border-l-emerald-500', 'chip' => 'bg-emerald-50 text-emerald-700', 'icon' => 'TI'],
            ['label' => 'Expected Admissions', 'value' => $stats['total_allowed_entries'], 'tone' => 'border-l-sky-500', 'chip' => 'bg-sky-50 text-sky-700', 'icon' => 'EA'],
            ['label' => 'Checked In', 'value' => $stats['admitted_entries'], 'tone' => 'border-l-cyan-500', 'chip' => 'bg-cyan-50 text-cyan-700', 'icon' => 'CI'],
            ['label' => 'Remaining Admissions', 'value' => $stats['remaining_entries'], 'tone' => 'border-l-amber-500', 'chip' => 'bg-amber-50 text-amber-700', 'icon' => 'RA'],
            ['label' => 'Single Passes', 'value' => $stats['single_passes'], 'tone' => 'border-l-zinc-500', 'chip' => 'bg-zinc-100 text-zinc-700', 'icon' => 'S'],
            ['label' => 'Double Passes', 'value' => $stats['double_passes'], 'tone' => 'border-l-indigo-500', 'chip' => 'bg-indigo-50 text-indigo-700', 'icon' => 'D'],
            ['label' => 'Special / Family Passes', 'value' => $stats['special_passes'], 'tone' => 'border-l-violet-500', 'chip' => 'bg-violet-50 text-violet-700', 'icon' => 'F'],
            ['label' => 'Unused Passes', 'value' => $stats['unused_passes'], 'tone' => 'border-l-lime-500', 'chip' => 'bg-lime-50 text-lime-700', 'icon' => 'U'],
            ['label' => 'Partially Used Passes', 'value' => $stats['partially_used'], 'tone' => 'border-l-orange-500', 'chip' => 'bg-orange-50 text-orange-700', 'icon' => 'P'],
            ['label' => 'Fully Used Passes', 'value' => $stats['fully_used'], 'tone' => 'border-l-zinc-800', 'chip' => 'bg-zinc-900 text-white', 'icon' => 'FU'],
            ['label' => 'Cancelled Passes', 'value' => $stats['cancelled'], 'tone' => 'border-l-rose-500', 'chip' => 'bg-rose-50 text-rose-700', 'icon' => 'C'],
        ];

        $resultBadge = fn (?string $result) => match ($result) {
            'valid', 'admitted' => 'bg-emerald-100 text-emerald-800',
            'already_used' => 'bg-amber-100 text-amber-800',
            'cancelled', 'revoked', 'invalid', 'error' => 'bg-rose-100 text-rose-800',
            default => 'bg-zinc-100 text-zinc-700',
        };
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="section-kicker">Admin overview</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Wedding admissions at a glance</h2>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.guests.create') }}" class="primary-button">Add invite</a>
                <a href="{{ route('admin.checkins.index') }}" class="secondary-button">View check-ins</a>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-6">
            @foreach ($statCards as $card)
                <article class="dashboard-stat-card {{ $card['tone'] }}">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-zinc-500">{{ $card['label'] }}</p>
                            <p class="mt-3 text-3xl font-semibold tracking-normal text-zinc-950">{{ number_format($card['value']) }}</p>
                        </div>
                        <span class="grid size-10 shrink-0 place-items-center rounded-lg text-xs font-bold {{ $card['chip'] }}">{{ $card['icon'] }}</span>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(360px,0.8fr)]">
            <article class="app-card overflow-hidden">
                <div class="flex items-center justify-between gap-4 border-b border-stone-200 px-5 py-4">
                    <div>
                        <h3 class="text-lg font-semibold">Latest invites</h3>
                    </div>
                    <a href="{{ route('admin.guests.index') }}" class="secondary-button">Guests</a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm">
                        <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                            <tr>
                                <th class="px-5 py-3">Guest</th>
                                <th class="px-5 py-3">Pass</th>
                                <th class="px-5 py-3">Usage</th>
                                <th class="px-5 py-3">QR</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($recentGuests as $guest)
                                <tr>
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-zinc-950">{{ $guest->name }}</p>
                                        <p class="mt-1 text-xs font-semibold text-zinc-500">{{ $guest->phone_number }}</p>
                                    </td>
                                    <td class="px-5 py-4">{{ $guest->passTypeLabel() }}</td>
                                    <td class="px-5 py-4">
                                        <p class="font-semibold">{{ $guest->used_entries }} / {{ $guest->allowed_entries }}</p>
                                        <p class="mt-1 text-xs font-semibold text-zinc-500">{{ $guest->remainingEntries() }} left</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        @if ($guest->qrCode)
                                            <button type="button" class="secondary-button" data-preview-qr data-guest-name="{{ $guest->name }}" data-qr-url="{{ route('admin.guests.qr', $guest) }}" data-download-url="{{ route('admin.guests.qr.download', $guest) }}">
                                                Preview
                                            </button>
                                        @else
                                            <span class="text-xs font-bold text-zinc-400">Missing</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-10 text-center text-sm font-semibold text-zinc-500">No invites yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="app-card overflow-hidden">
                <div class="flex items-center justify-between gap-4 border-b border-stone-200 px-5 py-4">
                    <h3 class="text-lg font-semibold">Gate activity</h3>
                    <a href="{{ route('admin.reports.index') }}" class="secondary-button">Reports</a>
                </div>

                <div class="divide-y divide-stone-100">
                    @forelse ($recentCheckins as $checkin)
                        <div class="px-5 py-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="min-w-0 truncate font-semibold">{{ $checkin->guest?->name ?? 'Unknown QR' }}</p>
                                <span class="status-badge {{ $resultBadge($checkin->scan_result) }}">{{ str_replace('_', ' ', $checkin->scan_result) }}</span>
                            </div>
                            <p class="mt-2 text-sm text-zinc-500">{{ $checkin->gate_name ?? 'No gate' }} &middot; {{ $checkin->checked_in_at?->diffForHumans() ?? 'Not timed' }}</p>
                        </div>
                    @empty
                        <div class="px-5 py-10 text-center text-sm font-semibold text-zinc-500">No scanner activity yet.</div>
                    @endforelse
                </div>
            </article>
        </div>

        <dialog class="w-full max-w-sm rounded-lg p-0 shadow-xl backdrop:bg-zinc-950/50" data-qr-dialog>
            <div class="p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="section-kicker">QR code</p>
                        <h2 class="mt-1 text-xl font-semibold" data-qr-title>Guest pass</h2>
                    </div>
                    <button type="button" class="secondary-button" data-close-qr>Close</button>
                </div>
                <div class="mt-5 grid place-items-center rounded-lg border border-stone-200 bg-white p-4">
                    <img src="" alt="Guest QR code" class="size-64" data-qr-preview-image>
                </div>
                <a href="#" class="primary-button mt-4 w-full" data-qr-download>Download QR</a>
            </div>
        </dialog>
    </section>
@endsection
