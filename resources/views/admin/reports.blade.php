@extends('layouts.admin', ['title' => 'Reports'])

@section('content')
    @php
        $summaryCards = [
            ['label' => 'Total invites', 'value' => $admissionSummary['total_invites'], 'tone' => 'border-l-zinc-950'],
            ['label' => 'Expected admissions', 'value' => $admissionSummary['total_expected_admissions'], 'tone' => 'border-l-sky-500'],
            ['label' => 'Admitted people', 'value' => $admissionSummary['total_admitted_people'], 'tone' => 'border-l-emerald-500'],
            ['label' => 'Remaining admissions', 'value' => $admissionSummary['total_remaining_admissions'], 'tone' => 'border-l-amber-500'],
        ];

        $passRows = [
            \App\Models\Guest::PASS_SINGLE => 'Single passes',
            \App\Models\Guest::PASS_DOUBLE => 'Double passes',
            \App\Models\Guest::PASS_SPECIAL => 'Special / Family passes',
        ];

        $statusRows = [
            'unused' => 'Unused passes',
            \App\Models\Guest::STATUS_PARTIALLY_USED => 'Partially used passes',
            \App\Models\Guest::STATUS_FULLY_USED => 'Fully used passes',
            \App\Models\Guest::STATUS_CANCELLED => 'Cancelled passes',
        ];

        $maxGateValue = collect($gateReport)
            ->flatMap(fn ($row) => [$row['admissions'], $row['invalid_attempts']])
            ->push(1)
            ->max();
        $maxHourValue = collect($timeReport)
            ->pluck('admissions')
            ->push(1)
            ->max();
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-zinc-200 pb-6">
            <div>
                <p class="section-kicker">Admin panel</p>
                <h1 class="mt-2 text-3xl font-semibold">Reports</h1>
                <p class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">Invitation, admission, gate, and scan health totals for the wedding day.</p>
            </div>
            <a href="{{ route('admin.checkins.index') }}" class="secondary-button">Check-in log</a>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($summaryCards as $card)
                <article class="metric-card border-l-4 {{ $card['tone'] }}">
                    <p class="text-sm font-semibold text-zinc-500">{{ $card['label'] }}</p>
                    <p class="mt-2 text-3xl font-semibold">{{ number_format($card['value']) }}</p>
                </article>
            @endforeach
        </div>

        <section class="app-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">CSV Exports</h2>
                    <p class="mt-1 text-sm font-semibold text-zinc-500">Download operational lists for sharing or archiving.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.reports.export.guest-list') }}" class="secondary-button">Export guest list CSV</a>
                    <a href="{{ route('admin.reports.export.checked-in-guests') }}" class="secondary-button">Export checked-in guests CSV</a>
                    <a href="{{ route('admin.reports.export.remaining-guests') }}" class="secondary-button">Export remaining guests CSV</a>
                    <a href="{{ route('admin.reports.export.invalid-scans') }}" class="secondary-button">Export invalid scan attempts CSV</a>
                </div>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <article class="app-card overflow-hidden">
                <div class="border-b border-zinc-200 px-5 py-4">
                    <h2 class="text-lg font-semibold">Pass Type Report</h2>
                </div>
                <div class="divide-y divide-zinc-100">
                    @foreach ($passRows as $key => $label)
                        <div class="flex items-center justify-between gap-4 px-5 py-4">
                            <span class="font-semibold">{{ $label }}</span>
                            <span class="text-2xl font-semibold">{{ number_format($passTypeReport[$key] ?? 0) }}</span>
                        </div>
                    @endforeach
                </div>
            </article>

            <article class="app-card overflow-hidden">
                <div class="border-b border-zinc-200 px-5 py-4">
                    <h2 class="text-lg font-semibold">Status Report</h2>
                </div>
                <div class="divide-y divide-zinc-100">
                    @foreach ($statusRows as $key => $label)
                        <div class="flex items-center justify-between gap-4 px-5 py-4">
                            <span class="font-semibold">{{ $label }}</span>
                            <span class="text-2xl font-semibold">{{ number_format($statusReport[$key] ?? 0) }}</span>
                        </div>
                    @endforeach
                </div>
            </article>
        </div>

        <article class="app-card overflow-hidden">
            <div class="border-b border-zinc-200 px-5 py-4">
                <h2 class="text-lg font-semibold">Gate Report</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Gate</th>
                            <th class="px-5 py-3">Admissions by gate</th>
                            <th class="px-5 py-3">Invalid attempts by gate</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse ($gateReport as $row)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-4 font-semibold">{{ $row['gate_name'] }}</td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="w-12 text-right font-semibold">{{ number_format($row['admissions']) }}</span>
                                        <div class="h-2 flex-1 rounded-full bg-zinc-100">
                                            <div class="h-2 rounded-full bg-emerald-500" style="width: {{ ($row['admissions'] / $maxGateValue) * 100 }}%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="w-12 text-right font-semibold">{{ number_format($row['invalid_attempts']) }}</span>
                                        <div class="h-2 flex-1 rounded-full bg-zinc-100">
                                            <div class="h-2 rounded-full bg-rose-500" style="width: {{ ($row['invalid_attempts'] / $maxGateValue) * 100 }}%"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-5 py-12 text-center text-sm font-semibold text-zinc-500">No gate activity has been recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="app-card overflow-hidden">
            <div class="border-b border-zinc-200 px-5 py-4">
                <h2 class="text-lg font-semibold">Time Report</h2>
            </div>
            <div class="grid gap-3 p-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($timeReport as $row)
                    <div class="grid grid-cols-[64px_1fr_48px] items-center gap-3">
                        <span class="text-sm font-bold text-zinc-500">{{ $row['hour'] }}</span>
                        <div class="h-2 rounded-full bg-zinc-100">
                            <div class="h-2 rounded-full bg-sky-500" style="width: {{ ($row['admissions'] / $maxHourValue) * 100 }}%"></div>
                        </div>
                        <span class="text-right text-sm font-bold">{{ number_format($row['admissions']) }}</span>
                    </div>
                @endforeach
            </div>
        </article>
    </section>
@endsection
