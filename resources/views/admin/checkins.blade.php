@extends('layouts.admin', ['title' => 'Check-ins'])

@section('content')
    @php
        $resultBadge = fn (?string $result) => match ($result) {
            \App\Models\Checkin::RESULT_ADMITTED => 'bg-emerald-100 text-emerald-800',
            \App\Models\Checkin::RESULT_VALID => 'bg-sky-100 text-sky-800',
            \App\Models\Checkin::RESULT_INVALID, \App\Models\Checkin::RESULT_ERROR => 'bg-rose-100 text-rose-800',
            \App\Models\Checkin::RESULT_ALREADY_USED => 'bg-orange-100 text-orange-800',
            \App\Models\Checkin::RESULT_CANCELLED => 'bg-zinc-200 text-zinc-700',
            \App\Models\Checkin::RESULT_REVOKED => 'bg-red-950 text-white',
            default => 'bg-zinc-100 text-zinc-700',
        };

        $resultLabel = fn (?string $result) => $result ? str_replace('_', ' ', $result) : 'unknown';
        $exportQuery = array_filter($filters, fn ($value) => filled($value));
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-zinc-200 pb-6">
            <div>
                <p class="section-kicker">Admin panel</p>
                <h1 class="mt-2 text-3xl font-semibold">Check-in History</h1>
                <p class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">Review every QR validation, admission, failed attempt, gate, and scanner user.</p>
            </div>
            <a href="{{ route('admin.checkins.export', $exportQuery) }}" class="primary-button">Export CSV</a>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <article class="metric-card border-l-4 border-l-zinc-950">
                <p class="text-sm font-semibold text-zinc-500">Total scans today</p>
                <p class="mt-2 text-3xl font-semibold">{{ $summary['total_scans_today'] }}</p>
            </article>
            <article class="metric-card border-l-4 border-l-emerald-500">
                <p class="text-sm font-semibold text-zinc-500">Successful admissions today</p>
                <p class="mt-2 text-3xl font-semibold">{{ $summary['successful_admissions_today'] }}</p>
            </article>
            <article class="metric-card border-l-4 border-l-rose-500">
                <p class="text-sm font-semibold text-zinc-500">Invalid attempts today</p>
                <p class="mt-2 text-3xl font-semibold">{{ $summary['invalid_attempts_today'] }}</p>
            </article>
            <article class="metric-card border-l-4 border-l-orange-500">
                <p class="text-sm font-semibold text-zinc-500">Already used attempts today</p>
                <p class="mt-2 text-3xl font-semibold">{{ $summary['already_used_attempts_today'] }}</p>
            </article>
            <article class="metric-card border-l-4 border-l-red-950">
                <p class="text-sm font-semibold text-zinc-500">Cancelled/revoked today</p>
                <p class="mt-2 text-3xl font-semibold">{{ $summary['cancelled_revoked_attempts_today'] }}</p>
            </article>
        </div>

        <form method="GET" action="{{ route('admin.checkins.index') }}" class="app-card p-4">
            <div class="grid gap-4 lg:grid-cols-[minmax(240px,1fr)_190px_190px_160px_auto] lg:items-end">
                <label class="field-label">
                    Search
                    <input class="input-control" name="search" value="{{ $filters['search'] }}" placeholder="Guest name or phone">
                </label>

                <label class="field-label">
                    Result
                    <select class="input-control" name="scan_result">
                        <option value="">All results</option>
                        @foreach ($scanResults as $scanResult)
                            <option value="{{ $scanResult }}" @selected($filters['scan_result'] === $scanResult)>{{ ucfirst($resultLabel($scanResult)) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="field-label">
                    Gate
                    <select class="input-control" name="gate_name">
                        <option value="">All gates</option>
                        @foreach ($gateNames as $gateName)
                            <option value="{{ $gateName }}" @selected($filters['gate_name'] === $gateName)>{{ $gateName }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="field-label">
                    Date
                    <input class="input-control" type="date" name="date" value="{{ $filters['date'] }}">
                </label>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="primary-button">Apply</button>
                    <a href="{{ route('admin.checkins.index') }}" class="secondary-button">Reset</a>
                </div>
            </div>
        </form>

        <div class="app-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-[1320px] divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Time</th>
                            <th class="px-5 py-3">Guest name</th>
                            <th class="px-5 py-3">Phone number</th>
                            <th class="px-5 py-3">Pass type</th>
                            <th class="px-5 py-3">Entries added</th>
                            <th class="px-5 py-3">Used after</th>
                            <th class="px-5 py-3">Remaining after</th>
                            <th class="px-5 py-3">Scan result</th>
                            <th class="px-5 py-3">Gate name</th>
                            <th class="px-5 py-3">Scanner user</th>
                            <th class="px-5 py-3">IP address</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse ($checkins as $checkin)
                            <tr class="align-top">
                                <td class="whitespace-nowrap px-5 py-4">
                                    <p class="font-semibold">{{ $checkin->checked_in_at?->format('M j, Y') ?? $checkin->created_at->format('M j, Y') }}</p>
                                    <p class="mt-1 text-xs font-semibold text-zinc-500">{{ $checkin->checked_in_at?->format('H:i:s') ?? $checkin->created_at->format('H:i:s') }}</p>
                                </td>
                                <td class="px-5 py-4 font-semibold">{{ $checkin->guest?->name ?? 'Unknown QR' }}</td>
                                <td class="whitespace-nowrap px-5 py-4">{{ $checkin->guest?->phone_number ?? '--' }}</td>
                                <td class="whitespace-nowrap px-5 py-4">{{ $checkin->guest?->passTypeLabel() ?? '--' }}</td>
                                <td class="px-5 py-4 font-semibold">{{ $checkin->entries_added }}</td>
                                <td class="px-5 py-4 font-semibold">{{ $checkin->used_entries_after_scan }}</td>
                                <td class="px-5 py-4 font-semibold">{{ $checkin->remaining_entries_after_scan }}</td>
                                <td class="px-5 py-4">
                                    <span class="status-badge {{ $resultBadge($checkin->scan_result) }}">{{ $resultLabel($checkin->scan_result) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4">{{ $checkin->gate_name ?? 'No gate' }}</td>
                                <td class="whitespace-nowrap px-5 py-4">{{ $checkin->user?->name ?? 'Unknown' }}</td>
                                <td class="whitespace-nowrap px-5 py-4 font-mono text-xs text-zinc-600">{{ $checkin->ip_address ?? '--' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-5 py-12 text-center text-sm font-semibold text-zinc-500">No check-ins match the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($checkins->hasPages())
                <div class="border-t border-zinc-200 px-5 py-4">
                    {{ $checkins->links() }}
                </div>
            @endif
        </div>
    </section>
@endsection
