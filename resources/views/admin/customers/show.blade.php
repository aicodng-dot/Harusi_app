@extends('layouts.admin', ['title' => 'Customer · '.$customer->name, 'headerTitle' => 'Customers'])

@section('content')
    @php
        $statusBadge = fn (?string $status) => match ($status) {
            'active' => 'bg-emerald-100 text-emerald-800',
            'fully_used' => 'bg-sky-100 text-sky-800',
            'cancelled', 'archived' => 'bg-zinc-100 text-zinc-700',
            default => 'bg-amber-100 text-amber-800',
        };
        $actionLabels = [
            'customer.updated' => 'Details edited',
            'customer.suspended' => 'Suspended',
            'customer.reactivated' => 'Reactivated',
        ];
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <a href="{{ route('admin.customers.index') }}" class="text-xs font-black uppercase text-emerald-700">&larr; All customers</a>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">{{ $customer->name }}</h2>
                <p class="mt-1 text-sm font-semibold text-zinc-500">{{ $customer->email }}@if ($customer->phone_number) · {{ $customer->phone_number }}@endif</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="status-badge {{ $customer->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">{{ $customer->is_active ? 'Active' : 'Suspended' }}</span>
                <a href="{{ route('admin.customers.edit', $customer->id) }}" class="secondary-button">Edit details</a>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([['Workspaces', $memberships->count()], ['Events', $events->count()], ['Guests', (int) ($guestTotals->total ?? 0)], ['Guests arrived', (int) ($guestTotals->arrived ?? 0)], ['Joined', $customer->created_at?->format('M j, Y') ?? '--']] as [$label, $value])
                <div class="app-card p-4">
                    <p class="text-xs font-bold uppercase text-zinc-500">{{ $label }}</p>
                    <p class="mt-2 text-xl font-semibold">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div class="space-y-6">
                <div class="app-card overflow-hidden">
                    <div class="border-b border-stone-200 px-5 py-4"><h3 class="text-base font-bold">Events</h3></div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-stone-200 text-sm">
                            <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                                <tr><th class="px-5 py-3">Event</th><th class="px-5 py-3">Date</th><th class="px-5 py-3">Status</th><th class="px-5 py-3 text-right">Guests</th><th class="px-5 py-3 text-right">Entries used</th></tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @forelse ($events as $event)
                                    <tr>
                                        <td class="px-5 py-3"><span class="font-semibold">{{ $event->event_name }}</span><span class="block text-xs font-semibold text-zinc-500">{{ $event->workspace_name }} · {{ str_replace('_', ' ', (string) $event->event_type) }}</span></td>
                                        <td class="px-5 py-3 whitespace-nowrap">{{ $event->event_date ? \Illuminate\Support\Carbon::parse($event->event_date)->format('M j, Y') : '--' }}</td>
                                        <td class="px-5 py-3"><span class="status-badge {{ $statusBadge($event->status) }}">{{ $event->status }}</span></td>
                                        <td class="px-5 py-3 text-right font-semibold">{{ (int) $event->guests_count }}</td>
                                        <td class="px-5 py-3 text-right font-semibold">{{ (int) $event->entries_used }} / {{ (int) $event->entries_allowed }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-5 py-8 text-center text-sm font-semibold text-zinc-500">No events yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="app-card overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-200 px-5 py-4">
                        <h3 class="text-base font-bold">Guests</h3>
                        <p class="text-xs font-semibold text-zinc-500">
                            {{ (int) ($guestTotals->active ?? 0) }} active · {{ (int) ($guestTotals->fully_used ?? 0) }} fully used · {{ (int) ($guestTotals->cancelled ?? 0) }} cancelled
                            @if ((int) ($guestTotals->total ?? 0) > $recentGuests->count()) · showing latest {{ $recentGuests->count() }} @endif
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-stone-200 text-sm">
                            <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                                <tr><th class="px-5 py-3">Guest</th><th class="px-5 py-3">Event</th><th class="px-5 py-3">Pass</th><th class="px-5 py-3 text-right">Used</th><th class="px-5 py-3">Status</th></tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @forelse ($recentGuests as $guest)
                                    <tr>
                                        <td class="px-5 py-3"><span class="font-semibold">{{ $guest->name }}</span><span class="block text-xs font-semibold text-zinc-500">{{ $guest->phone_number }}</span></td>
                                        <td class="px-5 py-3">{{ $guest->event_name }}</td>
                                        <td class="px-5 py-3">{{ $guest->pass_type }}</td>
                                        <td class="px-5 py-3 text-right font-semibold">{{ (int) $guest->used_entries }} / {{ (int) $guest->allowed_entries }}</td>
                                        <td class="px-5 py-3"><span class="status-badge {{ $statusBadge($guest->status) }}">{{ str_replace('_', ' ', (string) $guest->status) }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-5 py-8 text-center text-sm font-semibold text-zinc-500">No guests yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="app-card p-5">
                    <h3 class="text-base font-bold">Profile</h3>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="font-semibold text-zinc-500">Language</dt><dd class="font-semibold">{{ $customer->preferred_language === 'en' ? 'English' : 'Kiswahili' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="font-semibold text-zinc-500">Customer ID</dt><dd class="font-semibold">#{{ $customer->id }}</dd></div>
                    </dl>
                    <h4 class="mt-5 text-xs font-bold uppercase text-zinc-500">Workspaces</h4>
                    <ul class="mt-2 space-y-1 text-sm">
                        @forelse ($memberships as $membership)
                            <li class="flex justify-between gap-3"><span class="font-semibold">{{ $membership->name }}</span><span class="text-zinc-500">{{ $membership->role }}{{ $membership->is_active ? '' : ' (inactive)' }}</span></li>
                        @empty
                            <li class="text-zinc-500">No workspace yet.</li>
                        @endforelse
                    </ul>
                </div>

                <div class="app-card p-5">
                    <h3 class="text-base font-bold">Account status</h3>
                    @if ($customer->is_active)
                        <p class="mt-1 text-sm font-semibold text-zinc-500">Suspending signs the customer out everywhere and blocks sign-in. Their events, guests and invitations are kept.</p>
                        <form method="POST" action="{{ route('admin.customers.suspend', $customer->id) }}" class="mt-3 space-y-3" data-confirm data-confirm-title="Suspend customer" data-confirm-message="{{ $customer->email }} will be signed out and unable to sign in until reactivated.">
                            @csrf
                            <input type="hidden" name="confirm" value="1">
                            <label class="field-label">Reason (optional, kept in the audit log)
                                <input name="reason" maxlength="500" class="input-control">
                            </label>
                            <button type="submit" class="table-action table-action-danger">Suspend account</button>
                        </form>
                    @else
                        <p class="mt-1 text-sm font-semibold text-zinc-500">This customer cannot sign in.</p>
                        <form method="POST" action="{{ route('admin.customers.reactivate', $customer->id) }}" class="mt-3" data-confirm data-confirm-title="Reactivate customer" data-confirm-message="{{ $customer->email }} will be able to sign in again.">
                            @csrf
                            <input type="hidden" name="confirm" value="1">
                            <button type="submit" class="primary-button">Reactivate account</button>
                        </form>
                    @endif
                </div>

                <div class="app-card border-rose-200 p-5">
                    <h3 class="text-base font-bold text-rose-800">Delete customer</h3>
                    @if ($deletion['allowed'])
                        <p class="mt-1 text-sm font-semibold text-zinc-500">
                            Permanently deletes this account{{ count($deletion['workspaces_removed']) ? ' and '.count($deletion['workspaces_removed']).' empty workspace(s)' : '' }}. This cannot be undone.
                        </p>
                        <form method="POST" action="{{ route('admin.customers.destroy', $customer->id) }}" class="mt-3 space-y-3" data-confirm data-confirm-title="Delete customer permanently" data-confirm-message="This permanently deletes {{ $customer->email }}. This cannot be undone.">
                            @csrf
                            @method('DELETE')
                            <label class="field-label">Type the customer's email to confirm
                                <input name="confirm_email" autocomplete="off" class="input-control" required>
                            </label>
                            <button type="submit" class="table-action table-action-danger">Delete permanently</button>
                        </form>
                    @else
                        <p class="mt-1 text-sm font-semibold text-zinc-600">{{ \App\Http\Controllers\Admin\CustomerController::refusal($deletion) }}</p>
                    @endif
                </div>

                <div class="app-card p-5">
                    <h3 class="text-base font-bold">Audit history</h3>
                    <ul class="mt-3 space-y-3 text-sm">
                        @forelse ($audits as $audit)
                            <li>
                                <span class="font-semibold">{{ $actionLabels[$audit->action] ?? $audit->action }}</span>
                                <span class="block text-xs font-semibold text-zinc-500">
                                    {{ $audit->created_at?->format('M j, Y H:i') }} · {{ $audit->actor?->name ?? 'removed admin' }}
                                    @if (! empty($audit->details['fields'])) · {{ implode(', ', $audit->details['fields']) }} @endif
                                    @if (! empty($audit->details['reason'])) · “{{ $audit->details['reason'] }}” @endif
                                </span>
                            </li>
                        @empty
                            <li class="text-zinc-500">No administrator actions yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </section>
@endsection
