@extends('layouts.admin', ['title' => 'Guests', 'headerTitle' => 'Guests'])

@section('content')
    @php
        $statusBadge = fn (string $status) => match ($status) {
            'active' => 'bg-emerald-100 text-emerald-800',
            'partially_used' => 'bg-amber-100 text-amber-800',
            'fully_used' => 'bg-zinc-200 text-zinc-800',
            'cancelled' => 'bg-rose-100 text-rose-800',
            default => 'bg-zinc-100 text-zinc-700',
        };

        $qrStatus = function ($guest): string {
            if (! $guest->qrCode) {
                return 'missing';
            }

            return $guest->qrCode->is_active && ! $guest->qrCode->revoked_at ? 'active' : 'revoked';
        };

        $qrBadge = fn (string $status) => match ($status) {
            'active' => 'bg-emerald-100 text-emerald-800',
            'revoked' => 'bg-rose-100 text-rose-800',
            default => 'bg-zinc-100 text-zinc-700',
        };

        $guestActionButton = 'inline-grid size-9 place-items-center rounded-md border border-stone-200 bg-white text-zinc-600 shadow-sm transition hover:border-zinc-300 hover:bg-zinc-950 hover:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-70';
        $guestActionWarning = 'inline-grid size-9 place-items-center rounded-md border border-amber-200 bg-amber-50 text-amber-700 shadow-sm transition hover:bg-amber-500 hover:text-white focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-70';
        $guestActionDanger = 'inline-grid size-9 place-items-center rounded-md border border-rose-200 bg-rose-50 text-rose-700 shadow-sm transition hover:bg-rose-600 hover:text-white focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-70';
        $nextNameSort = $filters['name_sort'] === 'asc' ? 'desc' : 'asc';
        $nameSortLabel = match ($filters['name_sort']) {
            'asc' => 'Names are sorted A to Z. Sort names Z to A',
            'desc' => 'Names are sorted Z to A. Sort names A to Z',
            default => 'Sort names A to Z',
        };
        $nameSortQuery = array_filter([
            'search' => $filters['search'],
            'pass_type' => $filters['pass_type'],
            'status' => $filters['status'],
            'name_sort' => $nextNameSort,
        ], fn ($value): bool => $value !== '');
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="section-kicker">Guest management</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Guest passes</h2>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.guests.import') }}" class="secondary-button">Import Excel</a>
                <a href="{{ route('admin.guests.create') }}" class="primary-button">Create guest</a>
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

        <form method="GET" action="{{ route('admin.guests.index') }}" class="app-card p-3">
            <input type="hidden" name="name_sort" value="{{ $filters['name_sort'] }}">
            <div class="grid gap-3 lg:grid-cols-[minmax(220px,1fr)_180px_200px_auto]">
                <label class="field-label">
                    Search
                    <input name="search" value="{{ $filters['search'] }}" class="input-control" placeholder="Name or phone number" autocomplete="off">
                </label>

                <label class="field-label">
                    Pass type
                    <select name="pass_type" class="input-control">
                        <option value="">All passes</option>
                        <option value="single" @selected($filters['pass_type'] === 'single')>Single</option>
                        <option value="double" @selected($filters['pass_type'] === 'double')>Double</option>
                        <option value="special" @selected($filters['pass_type'] === 'special')>Special / Family</option>
                    </select>
                </label>

                <label class="field-label">
                    Status
                    <select name="status" class="input-control">
                        <option value="">All statuses</option>
                        <option value="unused" @selected($filters['status'] === 'unused')>Unused</option>
                        <option value="partially_used" @selected($filters['status'] === 'partially_used')>Partially used</option>
                        <option value="fully_used" @selected($filters['status'] === 'fully_used')>Fully used</option>
                        <option value="cancelled" @selected($filters['status'] === 'cancelled')>Cancelled</option>
                    </select>
                </label>

                <div class="flex items-end gap-2">
                    <button type="submit" class="primary-button">Apply</button>
                    <a href="{{ route('admin.guests.index') }}" class="secondary-button">Reset</a>
                </div>
            </div>
        </form>

        <div class="app-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-3">
                <p class="text-sm font-semibold text-zinc-500"><span data-guest-selected-count>0</span> selected from this page</p>
                <form id="bulk-delete-guests" method="POST" action="{{ route('admin.guests.bulk-destroy') }}" data-bulk-delete-form>
                    @csrf
                    @method('DELETE')
                    <div data-bulk-delete-inputs></div>
                    <button type="submit" class="danger-button inline-flex items-center gap-2" disabled data-bulk-delete-button>
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M3 6h18" />
                            <path d="M8 6V4h8v2" />
                            <path d="m19 6-1 15H6L5 6" />
                            <path d="M10 11v6" />
                            <path d="M14 11v6" />
                        </svg>
                        <span>Delete selected</span>
                    </button>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-[760px] divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-4 py-2.5">
                                <a href="{{ route('admin.guests.index', $nameSortQuery) }}" class="inline-flex items-center gap-1.5 transition hover:text-zinc-950 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1" title="{{ $nameSortLabel }}" aria-label="{{ $nameSortLabel }}">
                                    <span>Guest name</span>
                                    @if ($filters['name_sort'] === 'asc')
                                        <svg class="size-4 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path d="M8 4v16" />
                                            <path d="m4 8 4-4 4 4" />
                                            <path d="M15 7h5" />
                                            <path d="M15 12h4" />
                                            <path d="M15 17h3" />
                                        </svg>
                                    @elseif ($filters['name_sort'] === 'desc')
                                        <svg class="size-4 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path d="M8 4v16" />
                                            <path d="m4 16 4 4 4-4" />
                                            <path d="M15 7h3" />
                                            <path d="M15 12h4" />
                                            <path d="M15 17h5" />
                                        </svg>
                                    @else
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path d="M8 4v16" />
                                            <path d="m4 8 4-4 4 4" />
                                            <path d="m4 16 4 4 4-4" />
                                            <path d="M15 7h5" />
                                            <path d="M15 12h4" />
                                            <path d="M15 17h3" />
                                        </svg>
                                    @endif
                                </a>
                            </th>
                            <th class="px-4 py-2.5">Pass</th>
                            <th class="px-4 py-2.5">Entries</th>
                            <th class="px-4 py-2.5">Status</th>
                            <th class="w-px px-3 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <label class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md border border-stone-200 bg-white" title="Select all guests shown">
                                        <input type="checkbox" class="size-4 rounded border-stone-300 text-zinc-950 focus:ring-emerald-500" data-guest-select-all aria-label="Select all guests shown">
                                    </label>
                                    <span>Actions</span>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($guests as $guest)
                            @php($currentQrStatus = $qrStatus($guest))
                            <tr class="transition hover:bg-stone-50/70">
                                <td class="px-4 py-2.5">
                                    <div class="font-semibold text-zinc-950">{{ $guest->name }}</div>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-zinc-500">
                                        <span class="font-mono font-semibold text-zinc-700">Code {{ $guest->manual_code }}</span>
                                        <span>{{ $guest->phone_number }}</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5">{{ $guest->passTypeLabel() }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <div class="font-semibold tabular-nums text-zinc-950">{{ $guest->used_entries }} / {{ $guest->allowed_entries }}</div>
                                    <div class="text-xs text-zinc-500">{{ $guest->remainingEntries() }} left</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <div class="flex flex-col items-start gap-1">
                                        <span class="status-badge {{ $statusBadge($guest->status) }}">{{ str_replace('_', ' ', $guest->status) }}</span>
                                        <span class="status-badge {{ $qrBadge($currentQrStatus) }}">QR {{ $currentQrStatus }}</span>
                                    </div>
                                </td>
                                <td class="w-px px-3 py-2.5">
                                    <div class="flex items-center justify-end gap-1 whitespace-nowrap">
                                        <label class="inline-flex size-9 cursor-pointer items-center justify-center rounded-md border border-stone-200 bg-white" title="Select {{ $guest->name }} for bulk deletion">
                                            <input type="checkbox" value="{{ $guest->id }}" class="size-4 rounded border-stone-300 text-zinc-950 focus:ring-emerald-500" data-guest-select aria-label="Select {{ $guest->name }} for bulk deletion">
                                        </label>
                                        <a href="{{ route('admin.guests.show', $guest) }}" class="{{ $guestActionButton }}" title="View guest" aria-label="View guest">
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
                                                <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />
                                            </svg>
                                            <span class="sr-only">View guest</span>
                                        </a>
                                        <a href="{{ route('admin.guests.edit', $guest) }}" class="{{ $guestActionButton }}" title="Edit guest" aria-label="Edit guest">
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path d="m16.5 3.5 4 4L8 20H4v-4L16.5 3.5Z" />
                                                <path d="m14 6 4 4" />
                                            </svg>
                                            <span class="sr-only">Edit guest</span>
                                        </a>

                                        <form method="POST" action="{{ route('admin.guests.qr.generate', $guest) }}" class="inline-flex" @if ($guest->qrCode) data-confirm data-confirm-title="Regenerate QR code" data-confirm-message="The current QR token will stop working immediately." @endif>
                                            @csrf
                                            <button type="submit" class="{{ $guestActionButton }}" title="{{ $guest->qrCode ? 'Regenerate QR' : 'Generate QR' }}" aria-label="{{ $guest->qrCode ? 'Regenerate QR' : 'Generate QR' }}">
                                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path d="M3 3h6v6H3z" />
                                                    <path d="M15 3h6v6h-6z" />
                                                    <path d="M3 15h6v6H3z" />
                                                    <path d="M15 15h2v2h-2z" />
                                                    <path d="M19 15h2v6h-6v-2h4z" />
                                                    <path d="M15 19h2v2h-2z" />
                                                </svg>
                                                <span class="sr-only">{{ $guest->qrCode ? 'Regenerate QR' : 'Generate QR' }}</span>
                                            </button>
                                        </form>

                                        @if ($guest->qrCode)
                                            <a href="{{ route('admin.guests.qr.download', $guest) }}" class="{{ $guestActionButton }}" title="Download QR" aria-label="Download QR">
                                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path d="M12 3v11" />
                                                    <path d="m7 10 5 5 5-5" />
                                                    <path d="M5 21h14" />
                                                </svg>
                                                <span class="sr-only">Download QR</span>
                                            </a>
                                        @endif

                                        @if ($guest->status !== 'cancelled')
                                            <form method="POST" action="{{ route('admin.guests.cancel', $guest) }}" class="inline-flex" data-confirm data-confirm-title="Cancel guest pass" data-confirm-message="This pass will no longer be admitted at the gate.">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="{{ $guestActionWarning }}" title="Cancel pass" aria-label="Cancel pass">
                                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                        <path d="M18 6 6 18" />
                                                        <path d="m6 6 12 12" />
                                                    </svg>
                                                    <span class="sr-only">Cancel pass</span>
                                                </button>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{ route('admin.guests.destroy', $guest) }}" class="inline-flex" data-confirm data-confirm-title="Delete guest pass" data-confirm-message="This will delete the guest and linked QR data. This cannot be undone.">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="{{ $guestActionDanger }}" title="Delete guest" aria-label="Delete guest">
                                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path d="M3 6h18" />
                                                    <path d="M8 6V4h8v2" />
                                                    <path d="m19 6-1 15H6L5 6" />
                                                    <path d="M10 11v6" />
                                                    <path d="M14 11v6" />
                                                </svg>
                                                <span class="sr-only">Delete guest</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-12 text-center text-sm font-semibold text-zinc-500">No guests match this view.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($guests->hasPages())
                <div class="border-t border-stone-200 px-4 py-3">
                    {{ $guests->links() }}
                </div>
            @endif
        </div>
    </section>

    <script>
        const guestSelectAll = document.querySelector('[data-guest-select-all]');
        const guestSelections = Array.from(document.querySelectorAll('[data-guest-select]'));
        const selectedGuestCount = document.querySelector('[data-guest-selected-count]');
        const bulkDeleteButton = document.querySelector('[data-bulk-delete-button]');
        const bulkDeleteForm = document.querySelector('[data-bulk-delete-form]');
        const bulkDeleteInputs = document.querySelector('[data-bulk-delete-inputs]');

        const syncGuestSelection = () => {
            const selectedInputs = guestSelections.filter((input) => input.checked);
            const selected = selectedInputs.length;

            if (selectedGuestCount) selectedGuestCount.textContent = selected;
            if (bulkDeleteButton) bulkDeleteButton.disabled = selected === 0;

            if (bulkDeleteInputs) {
                bulkDeleteInputs.replaceChildren(...selectedInputs.map((input) => {
                    const selectedGuest = document.createElement('input');
                    selectedGuest.type = 'hidden';
                    selectedGuest.name = 'guest_ids[]';
                    selectedGuest.value = input.value;

                    return selectedGuest;
                }));
            }

            if (guestSelectAll) {
                guestSelectAll.checked = selected > 0 && selected === guestSelections.length;
                guestSelectAll.indeterminate = selected > 0 && selected < guestSelections.length;
            }
        };

        guestSelectAll?.addEventListener('change', () => {
            guestSelections.forEach((input) => { input.checked = guestSelectAll.checked; });
            syncGuestSelection();
        });

        guestSelections.forEach((input) => input.addEventListener('change', syncGuestSelection));

        bulkDeleteForm?.addEventListener('submit', (event) => {
            const selected = guestSelections.filter((input) => input.checked).length;
            const label = selected === 1 ? 'guest pass' : 'guest passes';

            if (selected === 0 || ! window.confirm(`Delete ${selected} selected ${label} and their linked QR data? This cannot be undone.`)) {
                event.preventDefault();
            }
        });

        syncGuestSelection();
    </script>
@endsection
