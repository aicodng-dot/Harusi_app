@extends('layouts.admin', ['title' => 'Import Guests', 'headerTitle' => 'Import Guests'])

@section('content')
    @php
        $statusBadge = fn (bool $isValid) => $isValid
            ? 'bg-emerald-100 text-emerald-800'
            : 'bg-rose-100 text-rose-800';
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="section-kicker">Guest management</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Import guest CSV</h2>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.guests.import.sample') }}" class="secondary-button">Download sample CSV</a>
                <a href="{{ route('admin.guests.index') }}" class="secondary-button">Back to guests</a>
            </div>
        </div>

        @if ($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800">
                {{ $errors->first() }}
            </div>
        @endif

        @foreach ($headerErrors as $headerError)
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800">
                {{ $headerError }}
            </div>
        @endforeach

        <form method="POST" action="{{ route('admin.guests.import.process') }}" enctype="multipart/form-data" class="app-card p-5">
            @csrf

            <div class="grid gap-5 lg:grid-cols-[minmax(280px,1fr)_240px]">
                <label class="field-label">
                    CSV file
                    <input type="file" name="csv_file" accept=".csv,text/csv" class="input-control" required>
                    <span class="text-xs font-semibold text-zinc-500">Required columns: name, phone_number, pass_type, allowed_entries.</span>
                    @error('csv_file')
                        <span class="text-xs font-bold text-rose-700">{{ $message }}</span>
                    @enderror
                </label>

                <label class="field-label justify-end">
                    QR codes
                    <span class="flex min-h-11 items-center gap-3 rounded-lg border border-stone-200 bg-white px-3">
                        <input type="checkbox" name="generate_qr" value="1" class="size-4 rounded border-stone-300 text-zinc-950" @checked($generateQr)>
                        <span class="text-sm font-semibold text-zinc-700">Generate after import</span>
                    </span>
                </label>
            </div>

            <div class="mt-6 flex flex-wrap gap-2">
                <button type="submit" class="primary-button">Preview CSV</button>
                <a href="{{ route('admin.guests.import.sample') }}" class="secondary-button">Sample template</a>
            </div>
        </form>

        @if ($summary)
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="app-card p-5">
                    <p class="section-kicker">Rows found</p>
                    <p class="mt-2 text-3xl font-bold">{{ $summary['total'] }}</p>
                </div>
                <div class="app-card p-5">
                    <p class="section-kicker">Ready to import</p>
                    <p class="mt-2 text-3xl font-bold text-emerald-700">{{ $summary['valid'] }}</p>
                </div>
                <div class="app-card p-5">
                    <p class="section-kicker">Rows with errors</p>
                    <p class="mt-2 text-3xl font-bold text-rose-700">{{ $summary['invalid'] }}</p>
                </div>
            </div>

            <div class="app-card overflow-hidden">
                <div class="border-b border-stone-200 bg-white px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-base font-bold text-zinc-950">Preview</h3>
                            <p class="mt-1 text-sm font-semibold text-zinc-500">Invalid rows are shown here and will be skipped.</p>
                        </div>

                        @if ($summary['valid'] > 0)
                            <form method="POST" action="{{ route('admin.guests.import.process') }}" class="flex flex-wrap items-center gap-3">
                                @csrf
                                <input type="hidden" name="import_action" value="confirm">
                                <label class="flex items-center gap-2 text-sm font-semibold text-zinc-700">
                                    <input type="checkbox" name="generate_qr" value="1" class="size-4 rounded border-stone-300 text-zinc-950" @checked($generateQr)>
                                    Generate QR
                                </label>
                                <button type="submit" class="primary-button">Import valid rows</button>
                            </form>
                        @endif
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm">
                        <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                            <tr>
                                <th class="px-5 py-3">Row</th>
                                <th class="px-5 py-3">Guest name</th>
                                <th class="px-5 py-3">Phone number</th>
                                <th class="px-5 py-3">Pass type</th>
                                <th class="px-5 py-3">Allowed entries</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3">Validation</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($rows as $row)
                                <tr @class(['bg-rose-50/40' => ! $row['is_valid']])>
                                    <td class="px-5 py-4 font-semibold text-zinc-600">{{ $row['row_number'] }}</td>
                                    <td class="px-5 py-4 font-semibold text-zinc-950">{{ $row['data']['name'] ?: 'Missing' }}</td>
                                    <td class="px-5 py-4">{{ $row['data']['phone_number'] ?: 'Missing' }}</td>
                                    <td class="px-5 py-4">{{ $row['data']['pass_type'] ?: 'Missing' }}</td>
                                    <td class="px-5 py-4 font-semibold">{{ $row['data']['allowed_entries'] ?: 'Missing' }}</td>
                                    <td class="px-5 py-4">
                                        <span class="status-badge {{ $statusBadge($row['is_valid']) }}">
                                            {{ $row['is_valid'] ? 'valid' : 'invalid' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        @if ($row['is_valid'])
                                            <span class="text-sm font-semibold text-emerald-700">Ready</span>
                                        @else
                                            <div class="space-y-1">
                                                @foreach ($row['errors'] as $rowError)
                                                    <p class="text-xs font-bold text-rose-700">{{ $rowError }}</p>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-12 text-center text-sm font-semibold text-zinc-500">No import rows were found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>
@endsection
