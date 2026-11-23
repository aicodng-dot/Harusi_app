@extends('layouts.admin', ['title' => 'Customers', 'headerTitle' => 'Customers'])

@section('content')
    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="section-kicker">Accounts</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Customer management</h2>
                <p class="mt-1 text-sm font-semibold text-zinc-500">{{ $totals['all'] }} customers · {{ $totals['suspended'] }} suspended</p>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="GET" action="{{ route('admin.customers.index') }}" class="app-card grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto]" role="search">
            <label class="field-label">
                <span class="sr-only">Search customers</span>
                <input type="search" name="q" value="{{ $search }}" maxlength="100" class="input-control" placeholder="Search name, email or phone">
            </label>
            <label class="field-label">
                <span class="sr-only">Status</span>
                <select name="status" class="input-control">
                    <option value="all" @selected($status === 'all')>All statuses</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="suspended" @selected($status === 'suspended')>Suspended</option>
                </select>
            </label>
            <label class="field-label">
                <span class="sr-only">Events</span>
                <select name="events" class="input-control">
                    <option value="all" @selected($eventsFilter === 'all')>Any events</option>
                    <option value="with" @selected($eventsFilter === 'with')>With events</option>
                    <option value="without" @selected($eventsFilter === 'without')>Without events</option>
                </select>
            </label>
            <div class="flex gap-2">
                <button type="submit" class="primary-button">Filter</button>
                @if ($search !== '' || $status !== 'all' || $eventsFilter !== 'all')
                    <a href="{{ route('admin.customers.index') }}" class="secondary-button">Clear</a>
                @endif
            </div>
        </form>

        <div class="app-card overflow-hidden">
            <div class="border-b border-stone-200 bg-white px-5 py-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-base font-bold text-zinc-950">Customer accounts</h3>
                    <span class="status-badge bg-stone-100 text-zinc-700">{{ $customers->total() }} {{ \Illuminate\Support\Str::plural('result', $customers->total()) }}</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Customer</th>
                            <th class="px-5 py-3">Phone</th>
                            <th class="px-5 py-3 text-right">Workspaces</th>
                            <th class="px-5 py-3 text-right">Events</th>
                            <th class="px-5 py-3 text-right">Guests</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Joined</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($customers as $row)
                            <tr>
                                <td class="px-5 py-4">
                                    <a href="{{ route('admin.customers.show', $row->id) }}" class="font-semibold text-zinc-950 hover:underline">{{ $row->name }}</a>
                                    <span class="block text-xs font-semibold text-zinc-500">{{ $row->email }}</span>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">{{ $row->phone_number ?: '--' }}</td>
                                <td class="px-5 py-4 text-right font-semibold">{{ (int) $row->workspaces_count }}</td>
                                <td class="px-5 py-4 text-right font-semibold">{{ (int) $row->events_count }}</td>
                                <td class="px-5 py-4 text-right font-semibold">{{ (int) $row->guests_count }}</td>
                                <td class="px-5 py-4">
                                    <span class="status-badge {{ $row->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">{{ $row->is_active ? 'Active' : 'Suspended' }}</span>
                                </td>
                                <td class="px-5 py-4 font-semibold text-zinc-600 whitespace-nowrap">{{ $row->created_at?->format('M j, Y') }}</td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('admin.customers.show', $row->id) }}" class="table-action">View</a>
                                        <a href="{{ route('admin.customers.edit', $row->id) }}" class="table-action">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-12 text-center text-sm font-semibold text-zinc-500">
                                    {{ $search !== '' || $status !== 'all' || $eventsFilter !== 'all' ? 'No customers match these filters.' : 'No customers have registered yet.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($customers->hasPages())
                <div class="border-t border-stone-200 px-5 py-4">{{ $customers->links() }}</div>
            @endif
        </div>
    </section>
@endsection
