@extends('layouts.admin', ['title' => 'Events', 'headerTitle' => 'Events'])

@section('content')
    @php
        $statusBadge = fn (string $status) => match ($status) {
            \App\Models\Event::STATUS_ACTIVE => 'bg-emerald-100 text-emerald-800',
            \App\Models\Event::STATUS_ARCHIVED => 'bg-zinc-200 text-zinc-800',
            \App\Models\Event::STATUS_CANCELLED => 'bg-rose-100 text-rose-800',
            default => 'bg-zinc-100 text-zinc-700',
        };
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="section-kicker">Event management</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Wedding and event profiles</h2>
            </div>
            <a href="{{ route('admin.events.create') }}" class="primary-button">Create event</a>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('info'))
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                {{ session('info') }}
            </div>
        @endif

        <div class="app-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-[1180px] divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Event name</th>
                            <th class="px-5 py-3">Bride</th>
                            <th class="px-5 py-3">Groom</th>
                            <th class="px-5 py-3">Venue</th>
                            <th class="px-5 py-3">Event date</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Total invites</th>
                            <th class="px-5 py-3">Expected admissions</th>
                            <th class="px-5 py-3">Checked in</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($events as $event)
                            @php($stats = $eventStats[$event->id] ?? ['total_invites' => 0, 'expected_admissions' => 0, 'checked_in_count' => 0])
                            <tr>
                                <td class="px-5 py-4 font-semibold text-zinc-950">{{ $event->event_name }}</td>
                                <td class="px-5 py-4">{{ $event->bride_name ?: '--' }}</td>
                                <td class="px-5 py-4">{{ $event->groom_name ?: '--' }}</td>
                                <td class="px-5 py-4">{{ $event->venue_name ?: '--' }}</td>
                                <td class="whitespace-nowrap px-5 py-4">{{ $event->event_date?->format('M j, Y') ?? '--' }}</td>
                                <td class="px-5 py-4">
                                    <span class="status-badge {{ $statusBadge($event->status) }}">{{ $event->status }}</span>
                                </td>
                                <td class="px-5 py-4 font-semibold">{{ number_format($stats['total_invites']) }}</td>
                                <td class="px-5 py-4 font-semibold">{{ number_format($stats['expected_admissions']) }}</td>
                                <td class="px-5 py-4 font-semibold">{{ number_format($stats['checked_in_count']) }}</td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('admin.events.select', $event) }}" class="table-action">Manage Event</a>
                                        <a href="{{ route('admin.events.edit', $event) }}" class="table-action">Edit</a>

                                        @if ($event->status !== \App\Models\Event::STATUS_ARCHIVED)
                                            <form method="POST" action="{{ route('admin.events.archive', $event) }}" data-confirm data-confirm-title="Archive event" data-confirm-message="Archived events are removed from the quick switcher.">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="table-action">Archive</button>
                                            </form>
                                        @endif

                                        @if ($event->status !== \App\Models\Event::STATUS_CANCELLED)
                                            <form method="POST" action="{{ route('admin.events.cancel', $event) }}" data-confirm data-confirm-title="Cancel event" data-confirm-message="Cancelled events are removed from the quick switcher.">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="table-action table-action-danger">Cancel</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-5 py-12 text-center text-sm font-semibold text-zinc-500">No events have been created yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endsection
