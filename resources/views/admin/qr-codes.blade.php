@extends('layouts.admin', ['title' => 'QR Codes'])

@section('content')
    @php
        $filterItems = [
            '' => ['label' => 'All guests', 'count' => $stats['total_guests']],
            'has_qr' => ['label' => 'Has QR', 'count' => $stats['has_qr']],
            'missing_qr' => ['label' => 'Missing QR', 'count' => $stats['missing_qr']],
            'active_qr' => ['label' => 'Active QR', 'count' => $stats['active']],
            'revoked_qr' => ['label' => 'Revoked QR', 'count' => $stats['revoked']],
            'fully_used' => ['label' => 'Fully used', 'count' => $stats['fully_used']],
            'unused' => ['label' => 'Unused', 'count' => $stats['unused']],
        ];

        $qrStatus = function ($qrCode) {
            if (! $qrCode) {
                return ['label' => 'missing', 'class' => 'bg-zinc-100 text-zinc-700'];
            }

            if ($qrCode->is_active && ! $qrCode->revoked_at) {
                return ['label' => 'active', 'class' => 'bg-emerald-100 text-emerald-800'];
            }

            if ($qrCode->revoked_at) {
                return ['label' => 'revoked', 'class' => 'bg-red-950 text-white'];
            }

            return ['label' => 'inactive', 'class' => 'bg-amber-100 text-amber-800'];
        };

        $guestStatusClass = fn ($status) => match ($status) {
            \App\Models\Guest::STATUS_ACTIVE => 'bg-sky-100 text-sky-800',
            \App\Models\Guest::STATUS_PARTIALLY_USED => 'bg-amber-100 text-amber-800',
            \App\Models\Guest::STATUS_FULLY_USED => 'bg-emerald-100 text-emerald-800',
            \App\Models\Guest::STATUS_CANCELLED => 'bg-zinc-200 text-zinc-700',
            default => 'bg-zinc-100 text-zinc-700',
        };
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-zinc-200 pb-6">
            <div>
                <p class="section-kicker">Admin panel</p>
                <h1 class="mt-2 text-3xl font-semibold">QR Codes</h1>
                <p class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">Generate, preview, download, revoke, and activate guest QR passes.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.qr-codes.generate-missing') }}">
                    @csrf
                    <button type="submit" class="primary-button">Generate missing QR codes</button>
                </form>
                <a href="{{ route('admin.qr-codes.download-all') }}" class="secondary-button">Download all ZIP</a>
                <a href="{{ route('admin.qr-codes.export') }}" class="secondary-button">Export QR list CSV</a>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
            <article class="metric-card">
                <p class="text-sm font-semibold text-zinc-500">Guests</p>
                <p class="mt-2 text-3xl font-semibold">{{ $stats['total_guests'] }}</p>
            </article>
            <article class="metric-card">
                <p class="text-sm font-semibold text-zinc-500">Has QR</p>
                <p class="mt-2 text-3xl font-semibold">{{ $stats['has_qr'] }}</p>
            </article>
            <article class="metric-card">
                <p class="text-sm font-semibold text-zinc-500">Missing QR</p>
                <p class="mt-2 text-3xl font-semibold">{{ $stats['missing_qr'] }}</p>
            </article>
            <article class="metric-card">
                <p class="text-sm font-semibold text-zinc-500">Active QR</p>
                <p class="mt-2 text-3xl font-semibold">{{ $stats['active'] }}</p>
            </article>
            <article class="metric-card">
                <p class="text-sm font-semibold text-zinc-500">Revoked QR</p>
                <p class="mt-2 text-3xl font-semibold">{{ $stats['revoked'] }}</p>
            </article>
            <article class="metric-card">
                <p class="text-sm font-semibold text-zinc-500">Unused</p>
                <p class="mt-2 text-3xl font-semibold">{{ $stats['unused'] }}</p>
            </article>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($filterItems as $key => $item)
                <a href="{{ $key === '' ? route('admin.qr-codes.index') : route('admin.qr-codes.index', ['filter' => $key]) }}"
                    @class(['filter-pill', 'is-active' => $activeFilter === $key])>
                    {{ $item['label'] }}
                    <span class="ml-2 rounded bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-600">{{ $item['count'] }}</span>
                </a>
            @endforeach
        </div>

        <div class="app-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-[1180px] divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Preview</th>
                            <th class="px-5 py-3">Guest</th>
                            <th class="px-5 py-3">Pass</th>
                            <th class="px-5 py-3">Usage</th>
                            <th class="px-5 py-3">QR status</th>
                            <th class="px-5 py-3">Generated</th>
                            <th class="px-5 py-3">Token</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($guests as $guest)
                            @php($status = $qrStatus($guest->qrCode))
                            <tr class="align-top">
                                <td class="px-5 py-4">
                                    @if ($guest->qrCode)
                                        <a href="{{ route('admin.guests.qr', $guest) }}" class="qr-thumb" target="_blank">
                                            <img src="{{ route('admin.guests.qr', $guest) }}" alt="QR code for {{ $guest->name }}" class="h-full w-full object-contain">
                                        </a>
                                    @else
                                        <div class="grid size-20 place-items-center rounded-lg border border-dashed border-zinc-300 bg-zinc-50 text-xs font-bold text-zinc-400">
                                            No QR
                                        </div>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <p class="font-semibold">{{ $guest->name }}</p>
                                    <p class="mt-1 text-xs font-semibold text-zinc-500">{{ $guest->phone_number }}</p>
                                    <p class="mt-2 text-xs font-bold text-zinc-400">ID {{ str_pad((string) $guest->id, 3, '0', STR_PAD_LEFT) }}</p>
                                </td>
                                <td class="px-5 py-4">
                                    <p class="font-semibold">{{ $guest->passTypeLabel() }}</p>
                                    <span class="status-badge mt-2 {{ $guestStatusClass($guest->status) }}">{{ str_replace('_', ' ', $guest->status) }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <p class="font-semibold">{{ $guest->used_entries }} / {{ $guest->allowed_entries }}</p>
                                    <p class="mt-1 text-xs font-semibold text-zinc-500">{{ $guest->remainingEntries() }} remaining</p>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="status-badge {{ $status['class'] }}">{{ $status['label'] }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4">{{ $guest->qrCode?->generated_at?->format('M j, Y H:i') ?? 'Not generated' }}</td>
                                <td class="px-5 py-4 font-mono text-xs text-zinc-500">
                                    @if ($guest->qrCode)
                                        ...{{ substr($guest->qrCode->qr_token, -10) }}
                                    @else
                                        --
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <form method="POST" action="{{ route('admin.guests.qr.generate', $guest) }}" @if ($guest->qrCode) data-confirm data-confirm-title="Regenerate QR code" data-confirm-message="The current QR token will stop working immediately." @endif>
                                            @csrf
                                            <button type="submit" class="table-action">
                                                {{ $guest->qrCode ? 'Regenerate' : 'Generate QR' }}
                                            </button>
                                        </form>

                                        @if ($guest->qrCode)
                                            <a href="{{ route('admin.guests.qr.download', $guest) }}" class="table-action">Download</a>

                                            @if ($guest->qrCode->is_active && ! $guest->qrCode->revoked_at)
                                                <form method="POST" action="{{ route('admin.qr-codes.deactivate', $guest) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="table-action">Deactivate</button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.guests.revoke', $guest) }}" data-confirm data-confirm-title="Revoke QR code" data-confirm-message="This QR code will no longer validate at the scanner.">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="table-action table-action-danger">Revoke</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('admin.qr-codes.activate', $guest) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="table-action">Activate</button>
                                                </form>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-12 text-center text-sm font-semibold text-zinc-500">No guests match this QR filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($guests->hasPages())
                <div class="border-t border-stone-200 px-5 py-4">
                    {{ $guests->links() }}
                </div>
            @endif
        </div>
    </section>
@endsection
