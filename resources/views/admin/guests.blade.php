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
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="section-kicker">Guest management</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Guest passes</h2>
            </div>
            <a href="{{ route('admin.guests.create') }}" class="primary-button">Create guest</a>
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
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($guests as $guest)
                            @php($currentQrStatus = $qrStatus($guest))
                            <tr>
                                <td class="px-5 py-4 font-semibold text-zinc-950">{{ $guest->name }}</td>
                                <td class="px-5 py-4">{{ $guest->phone_number }}</td>
                                <td class="px-5 py-4">{{ $guest->passTypeLabel() }}</td>
                                <td class="px-5 py-4 font-semibold">{{ $guest->allowed_entries }}</td>
                                <td class="px-5 py-4 font-semibold">{{ $guest->used_entries }}</td>
                                <td class="px-5 py-4 font-semibold">{{ $guest->remainingEntries() }}</td>
                                <td class="px-5 py-4">
                                    <span class="status-badge {{ $statusBadge($guest->status) }}">{{ str_replace('_', ' ', $guest->status) }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="status-badge {{ $qrBadge($currentQrStatus) }}">{{ $currentQrStatus }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('admin.guests.show', $guest) }}" class="table-action">View</a>
                                        <a href="{{ route('admin.guests.edit', $guest) }}" class="table-action">Edit</a>

                                        <form method="POST" action="{{ route('admin.guests.qr.generate', $guest) }}" @if ($guest->qrCode) onsubmit="return confirm('Regenerate this QR code? The old token will stop working.');" @endif>
                                            @csrf
                                            <button type="submit" class="table-action">{{ $guest->qrCode ? 'Regenerate QR' : 'Generate QR' }}</button>
                                        </form>

                                        @if ($guest->qrCode)
                                            <a href="{{ route('admin.guests.qr.download', $guest) }}" class="table-action">Download QR</a>
                                        @endif

                                        @if ($guest->status !== 'cancelled')
                                            <form method="POST" action="{{ route('admin.guests.cancel', $guest) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="table-action table-action-danger">Cancel</button>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{ route('admin.guests.destroy', $guest) }}" onsubmit="return confirm('Delete this guest pass? This cannot be undone.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="table-action table-action-danger">Delete</button>
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
