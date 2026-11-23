@extends('layouts.admin', ['title' => 'Import Guests', 'headerTitle' => 'Import Guests'])

@section('content')
    @php
        $statusBadge = fn (string $status) => match ($status) {
            'new' => 'bg-emerald-100 text-emerald-800',
            'duplicate' => 'bg-amber-100 text-amber-900',
            'invalid' => 'bg-rose-100 text-rose-800',
            default => 'bg-stone-100 text-zinc-700',
        };
        $passLabel = fn (?string $passType) => match ($passType) {
            \App\Models\Guest::PASS_SINGLE => 'Single',
            \App\Models\Guest::PASS_DOUBLE => 'Double',
            \App\Models\Guest::PASS_SPECIAL => 'Special / Family',
            default => ucfirst((string) $passType),
        };
        $importInterrupted = ($importResult['interrupted'] ?? false) === true;
        $resultLanguage = auth()->user()?->preferred_language;
        $resultLanguage = in_array($resultLanguage, \App\Models\User::SUPPORTED_LANGUAGES, true) ? $resultLanguage : 'sw';
        $resultSections = $importResult ? [
            ['title' => 'Successfully imported contacts', 'rows' => $importResult['imported'] ?? [], 'empty' => 'No new contacts were imported.'],
            ['title' => 'Added duplicate contacts', 'rows' => $importResult['added_duplicates'] ?? [], 'empty' => 'No duplicates were added.'],
            ['title' => 'Skipped duplicate contacts', 'rows' => $importResult['skipped_duplicates'] ?? [], 'empty' => 'No duplicates were skipped.'],
            ['title' => 'Invalid contacts', 'rows' => $importResult['invalid'] ?? [], 'empty' => 'No invalid contacts.'],
            ['title' => 'Failed contacts', 'rows' => $importResult['failed'] ?? [], 'empty' => 'No failed contacts.'],
        ] : [];
        if ($importInterrupted) {
            $resultSections = array_filter($resultSections, fn (array $section) => $section['rows'] !== []);
        }
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="section-kicker">Guest management</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Import guests from Excel</h2>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.guests.import.sample') }}" class="secondary-button">Download Excel Template</a>
                <a href="{{ route('admin.guests.index') }}" class="secondary-button">Back to guests</a>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

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

        @if ($importResult)
            <div class="app-card p-5">
                <p class="section-kicker">{{ $importInterrupted ? __('guest_issuance.import_interrupted', [], $resultLanguage) : 'Import completed' }}</p>
                @if ($importInterrupted)
                    <p class="mt-3 text-sm font-semibold text-zinc-700">{{ __('guest_issuance.partial_results', [], $resultLanguage) }}</p>
                @else
                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                    <div><p class="text-xs font-bold uppercase text-zinc-500">Total rows</p><p class="mt-1 text-2xl font-bold">{{ $importResult['summary']['total_rows'] }}</p></div>
                    <div><p class="text-xs font-bold uppercase text-zinc-500">Imported new contacts</p><p class="mt-1 text-2xl font-bold text-emerald-700">{{ $importResult['summary']['imported_new_contacts'] }}</p></div>
                    <div><p class="text-xs font-bold uppercase text-zinc-500">Duplicates added</p><p class="mt-1 text-2xl font-bold text-amber-700">{{ $importResult['summary']['duplicates_added'] }}</p></div>
                    <div><p class="text-xs font-bold uppercase text-zinc-500">Duplicates skipped</p><p class="mt-1 text-2xl font-bold text-zinc-700">{{ $importResult['summary']['duplicates_skipped'] }}</p></div>
                    <div><p class="text-xs font-bold uppercase text-zinc-500">Invalid rows</p><p class="mt-1 text-2xl font-bold text-rose-700">{{ $importResult['summary']['invalid_rows'] }}</p></div>
                    <div><p class="text-xs font-bold uppercase text-zinc-500">Failed rows</p><p class="mt-1 text-2xl font-bold text-rose-700">{{ $importResult['summary']['failed_rows'] }}</p></div>
                </div>
                @endif
            </div>

            @foreach ($resultSections as $section)
                <div class="app-card overflow-hidden">
                    <div class="border-b border-stone-200 bg-white px-5 py-4">
                        <h3 class="text-base font-bold text-zinc-950">{{ $section['title'] }}</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-stone-200 text-sm">
                            <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                                <tr><th class="px-5 py-3">Row</th><th class="px-5 py-3">Guest name</th><th class="px-5 py-3">Phone number</th><th class="px-5 py-3">Pass type</th><th class="px-5 py-3">Manual code</th><th class="px-5 py-3">Result</th></tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @forelse ($section['rows'] as $resultRow)
                                    <tr>
                                        <td class="px-5 py-4 font-semibold text-zinc-600">{{ $resultRow['row_number'] ?: '-' }}</td>
                                        <td class="px-5 py-4 font-semibold text-zinc-950">{{ $resultRow['name'] ?: 'Missing' }}</td>
                                        <td class="px-5 py-4">{{ $resultRow['phone_number'] ?: 'Missing' }}</td>
                                        <td class="px-5 py-4">{{ $passLabel($resultRow['pass_type']) }}</td>
                                        <td class="px-5 py-4 font-mono font-semibold">{{ $resultRow['manual_code'] ?? '-' }}</td>
                                        <td class="px-5 py-4 font-semibold text-zinc-700">{{ $resultRow['reason'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-5 py-6 text-sm font-semibold text-zinc-500">{{ $section['empty'] }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @endif

        <form method="POST" action="{{ route('admin.guests.import.process') }}" enctype="multipart/form-data" class="app-card p-5">
            @csrf
            <div class="grid gap-5 lg:grid-cols-[minmax(280px,1fr)_240px]">
                <label class="field-label">
                    Excel file
                    <input type="file" name="excel_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="input-control" required>
                    <span class="text-xs font-semibold text-zinc-500">Upload an .xlsx workbook with these required columns: name, phone_number, pass_type, allowed_entries.</span>
                    @error('excel_file')<span class="text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
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
                <button type="submit" class="primary-button">Review contacts</button>
                <a href="{{ route('admin.guests.import.sample') }}" class="secondary-button">Download Excel Template</a>
            </div>
        </form>

        @if ($summary)
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="app-card p-5"><p class="section-kicker">Total contacts</p><p class="mt-2 text-3xl font-bold">{{ $summary['total'] }}</p></div>
                <div class="app-card p-5"><p class="section-kicker">New contacts</p><p class="mt-2 text-3xl font-bold text-emerald-700">{{ $summary['new'] }}</p></div>
                <div class="app-card p-5"><p class="section-kicker">Duplicate contacts</p><p class="mt-2 text-3xl font-bold text-amber-700">{{ $summary['duplicates'] }}</p></div>
                <div class="app-card p-5"><p class="section-kicker">Invalid contacts</p><p class="mt-2 text-3xl font-bold text-rose-700">{{ $summary['invalid'] }}</p></div>
            </div>

            @if ($summary['duplicates'] > 0)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-900">
                    Some contacts match an existing guest name or phone number in this event. Review the duplicate contacts and choose whether to add them or skip them.
                </div>
            @endif

            <form method="POST" action="{{ route('admin.guests.import.process') }}" class="app-card overflow-hidden" data-import-confirm-form>
                @csrf
                <input type="hidden" name="import_action" value="confirm">
                <input type="hidden" name="import_token" value="{{ $importToken }}">
                <input type="hidden" name="generate_qr" value="{{ $generateQr ? '1' : '0' }}">

                <div class="border-b border-stone-200 bg-white px-5 py-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-base font-bold text-zinc-950">Preview contacts</h3>
                            <p class="mt-1 text-sm font-semibold text-zinc-500">New contacts are selected automatically. Invalid contacts will not be imported.</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($summary['duplicates'] > 0)
                                <button type="button" class="secondary-button" data-duplicate-bulk="skip">Skip all duplicates</button>
                                <button type="button" class="secondary-button" data-duplicate-bulk="add_anyway">Add all duplicates</button>
                            @endif
                            @if ($summary['valid'] > 0)
                                <button type="submit" class="primary-button" data-submitting-text="Importing...">Confirm import</button>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm">
                        <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                            <tr><th class="px-5 py-3">Row</th><th class="px-5 py-3">Incoming contact</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Decision</th></tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($rows as $row)
                                <tr @class(['bg-rose-50/40' => $row['status'] === 'invalid', 'bg-amber-50/40' => $row['status'] === 'duplicate'])>
                                    <td class="px-5 py-4 font-semibold text-zinc-600">{{ $row['row_number'] }}</td>
                                    <td class="px-5 py-4 align-top">
                                        <div class="space-y-1">
                                            <p class="font-bold text-zinc-950">{{ $row['data']['name'] ?: 'Missing' }}</p>
                                            <p class="font-semibold text-zinc-600">{{ $row['data']['phone_number'] ?: 'Missing' }}</p>
                                            <p class="text-xs font-bold uppercase text-zinc-500">{{ $passLabel($row['data']['pass_type'] ?? '') }} &middot; Allowed: {{ $row['data']['allowed_entries'] ?: 'Missing' }}</p>
                                        </div>
                                        @if ($row['status'] === 'duplicate' && $row['duplicate'])
                                            <div class="mt-4 rounded-lg border border-amber-200 bg-white p-3">
                                                <p class="text-xs font-bold uppercase text-amber-800">Existing contact</p>
                                                @if ($row['duplicate']['match_reasons'])
                                                    <p class="mt-1 text-xs font-bold text-amber-800">Matched by: {{ implode(', ', $row['duplicate']['match_reasons']) }}</p>
                                                @endif
                                                <div class="mt-2 grid gap-2 text-xs font-semibold text-zinc-700 sm:grid-cols-2">
                                                    <p>Name: <span class="font-bold text-zinc-950">{{ $row['duplicate']['name'] }}</span></p>
                                                    <p>Phone: <span class="font-bold text-zinc-950">{{ $row['duplicate']['phone_number'] }}</span></p>
                                                    <p>Code: <span class="font-mono font-bold text-zinc-950">{{ $row['duplicate']['manual_code'] }}</span></p>
                                                    <p>Pass: <span class="font-bold text-zinc-950">{{ $row['duplicate']['pass_type_label'] }}</span></p>
                                                    <p>Allowed: <span class="font-bold text-zinc-950">{{ $row['duplicate']['allowed_entries'] }}</span></p>
                                                    <p>Used: <span class="font-bold text-zinc-950">{{ $row['duplicate']['used_entries'] }}</span></p>
                                                    <p>Remaining: <span class="font-bold text-zinc-950">{{ $row['duplicate']['remaining_entries'] }}</span></p>
                                                    <p>Status: <span class="font-bold text-zinc-950">{{ $row['duplicate']['status_label'] }}</span></p>
                                                </div>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 align-top"><span class="status-badge {{ $statusBadge($row['status']) }}">{{ $row['status_label'] }}</span></td>
                                    <td class="px-5 py-4 align-top">
                                        @if ($row['status'] === 'new')
                                            <span class="text-sm font-bold text-emerald-700">Selected for import</span>
                                        @elseif ($row['status'] === 'duplicate')
                                            <div class="grid gap-2">
                                                <label class="flex items-center gap-2 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-bold text-zinc-700"><input type="radio" name="duplicate_actions[{{ $row['row_number'] }}]" value="skip" class="size-4 border-stone-300 text-zinc-950" checked><span>Skip duplicate</span></label>
                                                <label class="flex items-center gap-2 rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm font-bold text-amber-900"><input type="radio" name="duplicate_actions[{{ $row['row_number'] }}]" value="add_anyway" class="size-4 border-stone-300 text-zinc-950" data-add-anyway-choice><span>Add anyway</span></label>
                                            </div>
                                        @else
                                            <div class="space-y-1">@foreach ($row['errors'] as $rowError)<p class="text-xs font-bold text-rose-700">{{ $rowError }}</p>@endforeach</div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-12 text-center text-sm font-semibold text-zinc-500">No import rows were found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </form>
        @endif
    </section>

    <script>
        document.querySelectorAll('[data-duplicate-bulk]').forEach((button) => {
            button.addEventListener('click', () => {
                const action = button.getAttribute('data-duplicate-bulk');
                document.querySelectorAll(`input[type="radio"][value="${action}"]`).forEach((input) => { input.checked = true; });
            });
        });

        document.querySelectorAll('[data-import-confirm-form]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                const addsDuplicate = form.querySelector('input[value="add_anyway"]:checked') !== null;
                if (addsDuplicate && ! window.confirm('This contact matches an existing guest by name or phone number in the selected event. Adding it again will create another guest record with a separate QR code and manual admission code.')) {
                    event.preventDefault();
                    return;
                }

                const submitButton = form.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = submitButton.dataset.submittingText || 'Importing...';
                }
            });
        });
    </script>
@endsection
