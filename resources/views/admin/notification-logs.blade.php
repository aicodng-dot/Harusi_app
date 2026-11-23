@extends('layouts.admin', ['title' => 'Notification Logs', 'headerTitle' => 'Notification Logs'])

@section('content')
    @php
        $statusBadge = fn (?string $status) => match ($status) {
            \App\Models\NotificationLog::STATUS_SENT => 'bg-emerald-100 text-emerald-800',
            \App\Models\NotificationLog::STATUS_PENDING => 'bg-sky-100 text-sky-800',
            \App\Models\NotificationLog::STATUS_FAILED => 'bg-rose-100 text-rose-800',
            \App\Models\NotificationLog::STATUS_SKIPPED => 'bg-amber-100 text-amber-800',
            \App\Models\NotificationLog::STATUS_SIMULATED => 'bg-violet-100 text-violet-800',
            default => 'bg-zinc-100 text-zinc-700',
        };
        $label = fn (?string $value) => $value ? str_replace('_', ' ', $value) : '--';
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-zinc-200 pb-6">
            <div>
                <p class="section-kicker">Notifications</p>
                <h1 class="mt-2 text-3xl font-semibold">Notification Logs</h1>
            </div>
            <a href="{{ route('admin.settings.index') }}" class="secondary-button">Settings</a>
        </div>

        <div class="app-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-[1180px] divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Time</th>
                            <th class="px-5 py-3">Guest</th>
                            <th class="px-5 py-3">Channel</th>
                            <th class="px-5 py-3">Message type</th>
                            <th class="px-5 py-3">Template</th>
                            <th class="px-5 py-3">Recipient</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Provider</th>
                            <th class="px-5 py-3">Provider message ID</th>
                            <th class="px-5 py-3">Error</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @forelse ($logs as $log)
                            <tr class="align-top">
                                <td class="whitespace-nowrap px-5 py-4">
                                    <p class="font-semibold">{{ $log->created_at->format('M j, Y') }}</p>
                                    <p class="mt-1 text-xs font-semibold text-zinc-500">{{ $log->created_at->format('H:i:s') }}</p>
                                </td>
                                <td class="px-5 py-4 font-semibold">{{ $log->guest?->name ?? 'No guest' }}</td>
                                <td class="whitespace-nowrap px-5 py-4">{{ $label($log->channel) }}</td>
                                <td class="whitespace-nowrap px-5 py-4">{{ $label($log->message_type) }}</td>
                                <td class="whitespace-nowrap px-5 py-4">{{ $log->template_name ?? '--' }}</td>
                                <td class="whitespace-nowrap px-5 py-4 font-mono text-xs">{{ $log->recipient_phone }}</td>
                                <td class="whitespace-nowrap px-5 py-4">
                                    <span class="status-badge {{ $statusBadge($log->status) }}">{{ $label($log->status) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4">{{ $label($log->provider) }}</td>
                                <td class="max-w-64 truncate px-5 py-4 font-mono text-xs">{{ $log->provider_message_id ?? '--' }}</td>
                                <td class="max-w-80 px-5 py-4 text-xs font-semibold text-zinc-600">{{ $log->error_message ?? '--' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-5 py-12 text-center text-sm font-semibold text-zinc-500">No notification logs have been recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($logs->hasPages())
                <div class="border-t border-zinc-200 px-5 py-4">
                    {{ $logs->links() }}
                </div>
            @endif
        </div>
    </section>
@endsection
