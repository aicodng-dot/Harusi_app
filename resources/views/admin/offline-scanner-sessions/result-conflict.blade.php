@extends('layouts.admin', ['title' => 'Offline Result Conflict', 'headerTitle' => 'Offline Result Conflict'])

@section('content')
    @php
        $label = fn (?string $value) => $value ? str_replace('_', ' ', ucwords($value, '_')) : 'n/a';
        $dateTime = fn ($value) => $value ? $value->format('M j, Y g:i A') : 'Not recorded';
        $short = fn ($value) => $value ? substr((string) $value, 0, 18).'...' : 'n/a';
        $server = $conflict->server_state ?: [];
        $snapshot = $conflict->snapshot_state ?: [];
        $offline = $conflict->offline_request ?: [];
        $expected = $conflict->expected_final_state ?: [];
        $log = $conflict->importedLog;
        $actionLabels = \App\Models\OfflineScannerImportConflict::resolutionActionLabels();
        $openStatus = \App\Models\OfflineScannerImportConflict::RESOLUTION_OPEN;
        $isOpen = $conflict->resolution_status === $openStatus;
        $resultStatusClass = $isOpen ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800';
        $offlineDevice = $resultImport->devices->firstWhere('device_id', $offline['source_device_id'] ?? $log?->source_device_id);
    @endphp

    <div class="mx-auto max-w-7xl space-y-6">
        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800">
                @foreach (['conflict_resolution', 'resolution_action', 'resolution_notes', 'replacement_guest_id', 'manual_used_entries', 'confirm_resolution'] as $field)
                    @foreach ($errors->get($field) as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                @endforeach
            </div>
        @endif

        <section class="border-b border-stone-200 pb-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="section-kicker">Android scanner result conflict</p>
                    <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">{{ $label($conflict->conflict_type) }}</h2>
                    <p class="mt-2 text-sm font-semibold text-zinc-600">{{ $session->event->event_name }} - {{ $resultImport->results_export_id }}</p>
                </div>
                <span class="status-badge {{ $resultStatusClass }}">{{ $label($conflict->resolution_status) }}</span>
            </div>
        </section>

        <section class="app-card p-5">
            <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Offline session UUID</dt>
                    <dd class="mt-1 break-all font-mono text-sm font-semibold">{{ $session->session_uuid }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Results export ID</dt>
                    <dd class="mt-1 break-all font-mono text-sm font-semibold">{{ $resultImport->results_export_id }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Log UUID</dt>
                    <dd class="mt-1 break-all font-mono text-sm font-semibold">{{ $log?->log_uuid ?? 'n/a' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Request UUID</dt>
                    <dd class="mt-1 break-all font-mono text-sm font-semibold">{{ $log?->request_uuid ?? 'n/a' }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-sm font-semibold text-zinc-700">{{ $conflict->conflict_reason ?: $conflict->recommended_action }}</p>
        </section>

        <div class="grid gap-5 xl:grid-cols-3">
            <section class="app-card overflow-hidden">
                <div class="border-b border-stone-200 px-5 py-4">
                    <h3 class="text-lg font-semibold">Server state</h3>
                </div>
                <dl class="divide-y divide-stone-100 text-sm">
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Allowed</dt><dd class="font-semibold">{{ $server['allowed_entries'] ?? 'n/a' }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Used</dt><dd class="font-semibold">{{ $server['used_entries'] ?? $conflict->server_used ?? 'n/a' }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Remaining</dt><dd class="font-semibold">{{ $server['remaining_entries'] ?? $conflict->server_remaining ?? 'n/a' }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Status</dt><dd class="font-semibold">{{ $label($server['status'] ?? null) }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Record version</dt><dd class="font-mono text-xs font-semibold">{{ $short($server['record_version'] ?? null) }}</dd></div>
                </dl>
            </section>

            <section class="app-card overflow-hidden">
                <div class="border-b border-stone-200 px-5 py-4">
                    <h3 class="text-lg font-semibold">Offline snapshot</h3>
                </div>
                <dl class="divide-y divide-stone-100 text-sm">
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Allowed</dt><dd class="font-semibold">{{ $snapshot['allowed_entries'] ?? 'n/a' }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Used</dt><dd class="font-semibold">{{ $snapshot['used_entries'] ?? 'n/a' }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Remaining</dt><dd class="font-semibold">{{ $snapshot['remaining_entries'] ?? 'n/a' }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Record version</dt><dd class="font-mono text-xs font-semibold">{{ $short($snapshot['record_version'] ?? $log?->base_guest_record_version) }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">External guest</dt><dd class="font-mono text-xs font-semibold">{{ $snapshot['external_guest_id'] ?? $log?->external_guest_id ?? 'n/a' }}</dd></div>
                </dl>
            </section>

            <section class="app-card overflow-hidden">
                <div class="border-b border-stone-200 px-5 py-4">
                    <h3 class="text-lg font-semibold">Offline result</h3>
                </div>
                <dl class="divide-y divide-stone-100 text-sm">
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Added</dt><dd class="font-semibold">{{ $offline['added_entries'] ?? $conflict->offline_added ?? 'n/a' }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Used after</dt><dd class="font-semibold">{{ $offline['used_after'] ?? $conflict->offline_used_after ?? $expected['final_used'] ?? 'n/a' }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Remaining after</dt><dd class="font-semibold">{{ $offline['remaining_after'] ?? $conflict->offline_remaining_after ?? $expected['final_remaining'] ?? 'n/a' }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Device</dt><dd class="font-semibold">{{ $offlineDevice?->device_name ?? $offline['source_device_id'] ?? $log?->source_device_id ?? 'n/a' }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Gate</dt><dd class="font-semibold">{{ $offline['source_gate_name'] ?? $log?->source_gate_name ?? 'n/a' }}</dd></div>
                    <div class="flex items-center justify-between gap-4 px-5 py-3"><dt class="font-bold text-zinc-500">Time</dt><dd class="font-semibold">{{ $dateTime($log?->master_timestamp) }}</dd></div>
                </dl>
            </section>
        </div>

        <section class="app-card overflow-hidden">
            <div class="border-b border-stone-200 px-5 py-4">
                <h3 class="text-lg font-semibold">Resolution</h3>
            </div>

            @if (! $isOpen)
                <div class="px-5 py-5 text-sm text-zinc-700">
                    <p class="font-semibold">{{ $actionLabels[$conflict->resolution_action] ?? $label($conflict->resolution_action) }}</p>
                    <p class="mt-1">Resolved by {{ $conflict->resolvedBy?->name ?? 'Unknown admin' }} at {{ $dateTime($conflict->resolved_at) }}</p>
                    @if ($conflict->replacementGuest)
                        <p class="mt-1">Replacement guest: {{ $conflict->replacementGuest->name }} (#{{ $conflict->replacementGuest->id }})</p>
                    @endif
                    @if ($conflict->manual_used_entries !== null)
                        <p class="mt-1">Manual used count: {{ $conflict->manual_used_entries }}</p>
                    @endif
                    <p class="mt-3 font-semibold text-zinc-900">{{ $conflict->resolution_notes }}</p>
                </div>
            @else
                @can('resolve', $conflict)
                    <form method="POST" action="{{ route('admin.offline-scanner-conflicts.resolve', $conflict) }}" class="space-y-5 px-5 py-5" data-confirm data-confirm-title="Resolve offline result conflict" data-confirm-message="This records an audited resolution and may update guest admission counts depending on the selected action.">
                        @csrf
                        <div class="grid gap-4 lg:grid-cols-3">
                            <label class="text-sm font-semibold text-zinc-700 lg:col-span-3">
                                <span>Resolution notes</span>
                                <textarea name="resolution_notes" rows="3" class="input-control mt-1" required>{{ old('resolution_notes') }}</textarea>
                            </label>
                            <label class="text-sm font-semibold text-zinc-700">
                                <span>Replacement guest</span>
                                <select name="replacement_guest_id" class="input-control mt-1">
                                    <option value="">No replacement guest</option>
                                    @foreach ($eventGuests as $eventGuest)
                                        <option value="{{ $eventGuest->id }}" @selected(old('replacement_guest_id') == $eventGuest->id)>{{ $eventGuest->name }} (#{{ $eventGuest->id }})</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-sm font-semibold text-zinc-700">
                                <span>Manual used count</span>
                                <input type="number" name="manual_used_entries" min="0" max="{{ \App\Models\Guest::MAX_ALLOWED_ENTRIES }}" value="{{ old('manual_used_entries') }}" class="input-control mt-1">
                            </label>
                            <label class="flex items-start gap-2 text-sm font-semibold text-zinc-700 lg:pt-7">
                                <input type="checkbox" name="confirm_resolution" value="1" class="mt-1 size-4" required>
                                <span>I confirm this conflict resolution.</span>
                            </label>
                        </div>

                        <div class="flex flex-wrap gap-3 border-t border-stone-200 pt-5">
                            @foreach ($conflict->allowedResolutionActions() as $action)
                                <button type="submit" name="resolution_action" value="{{ $action }}" class="{{ $action === \App\Models\OfflineScannerImportConflict::ACTION_ACCEPT_OFFLINE_ADMISSION ? 'primary-button' : 'secondary-button' }}" data-submitting-text="Saving...">{{ $actionLabels[$action] ?? $label($action) }}</button>
                            @endforeach
                        </div>
                    </form>
                @else
                    <div class="px-5 py-5 text-sm font-semibold text-zinc-500">Resolution is not available for your account.</div>
                @endcan
            @endif
        </section>

        <section class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-200 pt-5">
            <a href="{{ route('admin.offline-scanner-results.show', $resultImport) }}" class="secondary-button">Back to results review</a>
            <a href="{{ route('admin.offline-scanner-sessions.show', $session) }}" class="secondary-button">Session page</a>
        </section>
    </div>
@endsection