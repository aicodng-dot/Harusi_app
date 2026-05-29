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
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="section-kicker">Guest management</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Guest passes</h2>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.guests.import') }}" class="secondary-button">Import CSV</a>
                <a href="{{ route('admin.guests.create') }}" class="primary-button">Create guest</a>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        <form method="GET" action="{{ route('admin.guests.index') }}" class="app-card p-4">
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
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Guest name</th>
                            <th class="px-5 py-3">Phone number</th>
                            <th class="px-5 py-3">Pass type</th>
                            <th class="px-5 py-3">Allowed entries</th>
                            <th class="px-5 py-3">Used entries</th>
                            <th class="px-5 py-3">Remaining entries</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">QR status</th>
                            <th class="w-px px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($guests as $guest)
                            @php($currentQrStatus = $qrStatus($guest))
                            <tr class="transition hover:bg-stone-50/70">
                                <td class="px-5 py-3 font-semibold text-zinc-950">{{ $guest->name }}</td>
                                <td class="whitespace-nowrap px-5 py-3">{{ $guest->phone_number }}</td>
                                <td class="whitespace-nowrap px-5 py-3">{{ $guest->passTypeLabel() }}</td>
                                <td class="whitespace-nowrap px-5 py-3 font-semibold tabular-nums">{{ $guest->allowed_entries }}</td>
                                <td class="whitespace-nowrap px-5 py-3 font-semibold tabular-nums">{{ $guest->used_entries }}</td>
                                <td class="whitespace-nowrap px-5 py-3 font-semibold tabular-nums">{{ $guest->remainingEntries() }}</td>
                                <td class="whitespace-nowrap px-5 py-3">
                                    <span class="status-badge {{ $statusBadge($guest->status) }}">{{ str_replace('_', ' ', $guest->status) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3">
                                    <span class="status-badge {{ $qrBadge($currentQrStatus) }}">{{ $currentQrStatus }}</span>
                                </td>
                                <td class="w-px px-4 py-3">
                                    <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
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
                                <td colspan="9" class="px-5 py-12 text-center text-sm font-semibold text-zinc-500">No guests match this view.</td>
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
