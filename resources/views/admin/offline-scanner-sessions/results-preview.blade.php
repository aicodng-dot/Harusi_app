@extends('layouts.admin', ['title' => 'Android Results Review', 'headerTitle' => 'Android Results Review'])

@section('content')
    @php
        $formatStatus = fn (?string $status) => $status ? str_replace('_', ' ', ucwords($status, '_')) : 'None';
        $statusBadge = fn (?string $status) => match ($status) {
            \App\Models\OfflineScannerResultImport::STATUS_PREVIEW_READY => 'bg-emerald-100 text-emerald-800',
            \App\Models\OfflineScannerResultImport::STATUS_COMPLETED => 'bg-emerald-100 text-emerald-800',
            \App\Models\OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS => 'bg-amber-100 text-amber-800',
            \App\Models\OfflineScannerResultImport::STATUS_FAILED,
            \App\Models\OfflineScannerResultImport::STATUS_VALIDATION_FAILED => 'bg-rose-100 text-rose-800',
            default => 'bg-sky-100 text-sky-800',
        };
        $logStatusBadge = fn (?string $status) => match ($status) {
            \App\Models\OfflineScannerImportedLog::STATUS_IMPORTED => 'bg-emerald-100 text-emerald-800',
            \App\Models\OfflineScannerImportedLog::STATUS_DUPLICATE => 'bg-sky-100 text-sky-800',
            \App\Models\OfflineScannerImportedLog::STATUS_CONFLICT,
            \App\Models\OfflineScannerImportedLog::STATUS_UNKNOWN_GUEST => 'bg-amber-100 text-amber-800',
            \App\Models\OfflineScannerImportedLog::STATUS_REJECTED => 'bg-zinc-100 text-zinc-700',
            default => 'bg-stone-100 text-stone-700',
        };
        $resultBadge = fn (?string $result) => match ($result) {
            \App\Services\OfflineScannerResults\OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY => 'bg-emerald-100 text-emerald-800',
            \App\Services\OfflineScannerResults\OfflineScannerResultSchema::RESULT_DUPLICATE_REQUEST => 'bg-sky-100 text-sky-800',
            \App\Services\OfflineScannerResults\OfflineScannerResultSchema::RESULT_INVALID_QR,
            \App\Services\OfflineScannerResults\OfflineScannerResultSchema::RESULT_WRONG_EVENT,
            \App\Services\OfflineScannerResults\OfflineScannerResultSchema::RESULT_DISABLED_PASS,
            \App\Services\OfflineScannerResults\OfflineScannerResultSchema::RESULT_REJECTED => 'bg-rose-100 text-rose-800',
            default => 'bg-zinc-100 text-zinc-700',
        };
        $label = fn (?string $value) => $value ? str_replace('_', ' ', ucwords($value, '_')) : 'n/a';
        $dateTime = fn ($value) => $value ? $value->format('M j, Y g:i A') : 'Not recorded';
        $short = fn ($value) => $value ? substr((string) $value, 0, 18).'...' : 'n/a';
        $masterDevice = $resultImport->devices->firstWhere('device_role', 'master');
        $packageFingerprint = $session->package_fingerprint ?: $session->packageExport?->package_fingerprint;
        $openConflictStatus = \App\Models\OfflineScannerImportConflict::RESOLUTION_OPEN;
        $openConflicts = $resultImport->conflicts->where('resolution_status', $openConflictStatus)->count();
        $canImport = $resultImport->status === \App\Models\OfflineScannerResultImport::STATUS_PREVIEW_READY;
        $isCompletedCleanly = $resultImport->status === \App\Models\OfflineScannerResultImport::STATUS_COMPLETED
            && $session->status === \App\Models\OfflineScannerSession::STATUS_CLOSED
            && $openConflicts === 0;
        $summaryCards = [
            ['label' => 'Total scan attempts', 'value' => $summary['total_logs']],
            ['label' => 'Successful admissions', 'value' => $summary['successful_admissions']],
            ['label' => 'Total people admitted', 'value' => $summary['total_people_admitted']],
            ['label' => 'QR admissions', 'value' => $summary['qr_admissions']],
            ['label' => 'Manual admissions', 'value' => $summary['manual_admissions']],
            ['label' => 'Invalid scans', 'value' => $summary['invalid_scans']],
            ['label' => 'Duplicate requests', 'value' => $summary['duplicate_requests']],
            ['label' => 'Conflicts', 'value' => $summary['potential_conflicts']],
        ];
        $actionLabels = \App\Models\OfflineScannerImportConflict::resolutionActionLabels();
    @endphp

    <div class="mx-auto max-w-7xl space-y-6">
        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->has('offline_results'))
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800">
                @foreach ($errors->get('offline_results') as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        @if ($isCompletedCleanly)
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-900">
                <p class="font-bold">Import completed and session closed.</p>
                <p class="mt-1">The session was marked results imported, the event admission lock was released, normal Laravel admissions resumed, and the offline session is closed.</p>
            </div>
        @elseif ($openConflicts > 0 || $resultImport->status === \App\Models\OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
                <p class="font-bold">{{ number_format($openConflicts) }} unresolved conflict{{ $openConflicts === 1 ? '' : 's' }} remain.</p>
                <p class="mt-1">The event admission lock stays active until critical conflicts are resolved.</p>
            </div>
        @endif

        <section class="border-b border-stone-200 pb-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="section-kicker">Import Android Scanner Results</p>
                    <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Android Results Review</h2>
                    <p class="mt-2 text-sm font-semibold text-zinc-600">{{ $session->event->event_name }}</p>
                </div>
                <span class="status-badge {{ $statusBadge($resultImport->status) }}">{{ $formatStatus($resultImport->status) }}</span>
            </div>
        </section>

        <section class="app-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-stone-200 pb-4">
                <div>
                    <p class="section-kicker">Result import page</p>
                    <h3 class="mt-1 text-lg font-semibold">Verified results file</h3>
                </div>
                <span class="status-badge bg-zinc-100 text-zinc-700">{{ $resultImport->file_name }}</span>
            </div>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Event</dt>
                    <dd class="mt-1 font-semibold">{{ $session->event->event_name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Offline session UUID</dt>
                    <dd class="mt-1 break-all font-mono text-sm font-semibold">{{ $session->session_uuid }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Results export ID</dt>
                    <dd class="mt-1 break-all font-mono text-sm font-semibold">{{ $resultImport->results_export_id }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Master scanner</dt>
                    <dd class="mt-1 font-semibold">{{ $masterDevice?->device_name ?? $resultImport->source_master_device_id }}</dd>
                    <dd class="text-xs font-semibold text-zinc-500">{{ $masterDevice?->scanner_user_name ?? $session->masterScannerUser?->name ?? 'Unknown scanner user' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Session start</dt>
                    <dd class="mt-1 font-semibold">{{ $dateTime($resultImport->result_session_started_at) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Session end</dt>
                    <dd class="mt-1 font-semibold">{{ $dateTime($resultImport->result_session_closed_at) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Number of connected devices</dt>
                    <dd class="mt-1 font-semibold">{{ number_format($resultImport->devices->count()) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Package fingerprint</dt>
                    <dd class="mt-1 break-all font-mono text-xs font-semibold">{{ $packageFingerprint ?: 'n/a' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">File verification status</dt>
                    <dd class="mt-1"><span class="status-badge {{ $statusBadge($resultImport->status) }}">{{ $formatStatus($resultImport->status) }}</span></dd>
                </div>
            </dl>
        </section>

        <section class="app-card overflow-hidden">
            <div class="border-b border-stone-200 px-5 py-4">
                <h3 class="text-lg font-semibold">Summary</h3>
            </div>
            <div class="grid gap-px bg-stone-200 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($summaryCards as $card)
                    <div class="bg-white p-4">
                        <p class="text-xs font-bold uppercase text-zinc-500">{{ $card['label'] }}</p>
                        <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format($card['value']) }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="app-card overflow-hidden">
            <div class="border-b border-stone-200 px-5 py-4">
                <h3 class="text-lg font-semibold">Device summary</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Device name</th>
                            <th class="px-5 py-3">Device role</th>
                            <th class="px-5 py-3">Scanner user</th>
                            <th class="px-5 py-3">Gate</th>
                            <th class="px-5 py-3 text-right">Admissions performed</th>
                            <th class="px-5 py-3">Last seen</th>
                            <th class="px-5 py-3">Connection period</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($resultImport->devices->sortBy('device_name') as $device)
                            <tr>
                                <td class="px-5 py-4 font-semibold">{{ $device->device_name }}</td>
                                <td class="px-5 py-4">{{ $label($device->device_role) }}</td>
                                <td class="px-5 py-4">{{ $device->scanner_user_name ?: $device->scannerUser?->name ?: 'Unknown' }}</td>
                                <td class="px-5 py-4">{{ $device->gate_name ?: 'n/a' }}</td>
                                <td class="px-5 py-4 text-right font-semibold">{{ number_format((int) $device->admissions_count) }}</td>
                                <td class="px-5 py-4">{{ $dateTime($device->last_seen_at) }}</td>
                                <td class="px-5 py-4">{{ $dateTime($device->paired_at) }} - {{ $dateTime($device->last_seen_at) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-8 text-sm font-semibold text-zinc-500">No scanner devices were reported in this results file.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <form method="GET" action="{{ route('admin.offline-scanner-results.show', $resultImport) }}" class="app-card p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="text-sm font-semibold text-zinc-700">
                    <span>Scanner device</span>
                    <select name="device_id" class="input-control mt-1">
                        <option value="">All devices</option>
                        @foreach ($filterOptions['devices'] as $device)
                            <option value="{{ $device->device_id }}" @selected($filters['device_id'] === $device->device_id)>{{ $device->device_name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-semibold text-zinc-700">
                    <span>Scanner user</span>
                    <select name="scanner_user_id" class="input-control mt-1">
                        <option value="">All users</option>
                        @foreach ($filterOptions['scannerUsers'] as $device)
                            <option value="{{ $device->scanner_user_id }}" @selected($filters['scanner_user_id'] === (string) $device->scanner_user_id)>{{ $device->scanner_user_name ?: $device->scannerUser?->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-semibold text-zinc-700">
                    <span>Gate</span>
                    <select name="gate" class="input-control mt-1">
                        <option value="">All gates</option>
                        @foreach ($filterOptions['gates'] as $gateName)
                            <option value="{{ $gateName }}" @selected($filters['gate'] === $gateName)>{{ $gateName }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-semibold text-zinc-700">
                    <span>Method</span>
                    <select name="method" class="input-control mt-1">
                        <option value="">QR and manual</option>
                        <option value="qr" @selected($filters['method'] === 'qr')>QR</option>
                        <option value="manual" @selected($filters['method'] === 'manual')>Manual</option>
                    </select>
                </label>
                <label class="text-sm font-semibold text-zinc-700">
                    <span>Result</span>
                    <select name="result" class="input-control mt-1">
                        <option value="">All results</option>
                        <option value="successful" @selected($filters['result'] === 'successful')>Successful</option>
                        <option value="rejected" @selected($filters['result'] === 'rejected')>Rejected</option>
                        <option value="conflict" @selected($filters['result'] === 'conflict')>Conflict</option>
                        <option value="duplicate" @selected($filters['result'] === 'duplicate')>Duplicate</option>
                    </select>
                </label>
                <label class="text-sm font-semibold text-zinc-700">
                    <span>Guest</span>
                    <input name="guest" value="{{ $filters['guest'] }}" class="input-control mt-1" placeholder="Name, code, or ID" autocomplete="off">
                </label>
                <label class="text-sm font-semibold text-zinc-700">
                    <span>Time from</span>
                    <input type="datetime-local" name="time_from" value="{{ $filters['time_from'] }}" class="input-control mt-1">
                </label>
                <label class="text-sm font-semibold text-zinc-700">
                    <span>Time to</span>
                    <input type="datetime-local" name="time_to" value="{{ $filters['time_to'] }}" class="input-control mt-1">
                </label>
            </div>
            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <a href="{{ route('admin.offline-scanner-results.show', $resultImport) }}" class="secondary-button">Reset</a>
                <button type="submit" class="primary-button">Apply filters</button>
            </div>
        </form>

        <section class="app-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-5 py-4">
                <h3 class="text-lg font-semibold">Admission log table</h3>
                <span class="text-sm font-semibold text-zinc-500">{{ number_format($admissionLogs->total()) }} matching logs</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Sequence</th>
                            <th class="px-5 py-3">Guest</th>
                            <th class="px-5 py-3">Manual code</th>
                            <th class="px-5 py-3">Method</th>
                            <th class="px-5 py-3 text-right">Added</th>
                            <th class="px-5 py-3 text-right">Used before</th>
                            <th class="px-5 py-3 text-right">Used after</th>
                            <th class="px-5 py-3 text-right">Remaining after</th>
                            <th class="px-5 py-3">Scanner</th>
                            <th class="px-5 py-3">Gate</th>
                            <th class="px-5 py-3">Result</th>
                            <th class="px-5 py-3">Time</th>
                            <th class="px-5 py-3">Import status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 align-top">
                        @forelse ($admissionLogs as $log)
                            @php
                                $guest = $logGuests->get($log->external_guest_id);
                                $conflict = $log->conflict;
                            @endphp
                            <tr>
                                <td class="px-5 py-4 font-mono text-xs font-semibold">{{ $log->master_sequence_number }}</td>
                                <td class="px-5 py-4">
                                    <p class="font-semibold">{{ $guest?->name ?? 'Unknown guest' }}</p>
                                    <p class="font-mono text-xs text-zinc-500">External {{ $log->external_guest_id }}</p>
                                </td>
                                <td class="px-5 py-4 font-mono text-xs font-semibold">{{ $guest?->manual_code ?? 'n/a' }}</td>
                                <td class="px-5 py-4">{{ $label($log->admission_method) }}</td>
                                <td class="px-5 py-4 text-right font-semibold">{{ number_format((int) $log->added_entries) }}</td>
                                <td class="px-5 py-4 text-right">{{ number_format((int) $log->used_before) }}</td>
                                <td class="px-5 py-4 text-right">{{ number_format((int) $log->used_after) }}</td>
                                <td class="px-5 py-4 text-right">{{ number_format((int) $log->remaining_after) }}</td>
                                <td class="px-5 py-4">{{ $log->scannerUser?->name ?? $log->scanner_user_id ?? 'Unknown' }}</td>
                                <td class="px-5 py-4">{{ $log->source_gate_name ?: 'n/a' }}</td>
                                <td class="px-5 py-4"><span class="status-badge {{ $resultBadge($log->result_code) }}">{{ $label($log->result_code) }}</span></td>
                                <td class="px-5 py-4">{{ $dateTime($log->master_timestamp) }}</td>
                                <td class="px-5 py-4">
                                    <span class="status-badge {{ $logStatusBadge($log->import_status) }}">{{ $label($log->import_status) }}</span>
                                    @if ($conflict)
                                        <a href="{{ route('admin.offline-scanner-conflicts.show', $conflict) }}" class="mt-2 inline-flex text-xs font-bold text-amber-700 hover:text-amber-900">Review conflict</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="px-5 py-10 text-center text-sm font-semibold text-zinc-500">No admission logs match the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($admissionLogs->hasPages())
                <div class="border-t border-stone-200 px-5 py-4">
                    {{ $admissionLogs->links() }}
                </div>
            @endif
        </section>

        <section class="app-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-stone-200 px-5 py-4">
                <div>
                    <p class="section-kicker">Review before import</p>
                    <h3 class="mt-1 text-lg font-semibold">Detected conflicts</h3>
                </div>
                <span class="status-badge {{ $openConflicts === 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ number_format($openConflicts) }} open</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Conflict</th>
                            <th class="px-5 py-3">Server state</th>
                            <th class="px-5 py-3">Exported snapshot</th>
                            <th class="px-5 py-3">Offline request</th>
                            <th class="px-5 py-3">Expected final</th>
                            <th class="px-5 py-3">Resolution</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 align-top">
                        @forelse ($resultImport->conflicts->sortBy('id') as $conflict)
                            @php
                                $server = $conflict->server_state ?: [];
                                $snapshot = $conflict->snapshot_state ?: [];
                                $offline = $conflict->offline_request ?: [];
                                $expected = $conflict->expected_final_state ?: [];
                                $actions = collect($conflict->allowedResolutionActions())->map(fn ($action) => $actionLabels[$action] ?? $label($action))->implode(', ');
                            @endphp
                            <tr>
                                <td class="px-5 py-4">
                                    <p class="font-semibold">{{ $label($conflict->conflict_type) }}</p>
                                    <p class="mt-1 text-xs text-zinc-600">{{ $conflict->conflict_reason ?: $conflict->recommended_action }}</p>
                                </td>
                                <td class="px-5 py-4 text-zinc-700">Allowed {{ $server['allowed_entries'] ?? 'n/a' }}; Used {{ $server['used_entries'] ?? $conflict->server_used ?? 'n/a' }}; Remaining {{ $server['remaining_entries'] ?? $conflict->server_remaining ?? 'n/a' }}</td>
                                <td class="px-5 py-4 text-zinc-700">Allowed {{ $snapshot['allowed_entries'] ?? 'n/a' }}; Used {{ $snapshot['used_entries'] ?? 'n/a' }}; Remaining {{ $snapshot['remaining_entries'] ?? 'n/a' }}</td>
                                <td class="px-5 py-4 text-zinc-700">Seq {{ $offline['master_sequence_number'] ?? $conflict->importedLog?->master_sequence_number ?? 'n/a' }}; Added {{ $offline['added_entries'] ?? $conflict->offline_added ?? 'n/a' }}; Gate {{ $offline['source_gate_name'] ?? $conflict->importedLog?->source_gate_name ?? 'n/a' }}</td>
                                <td class="px-5 py-4 text-zinc-700">Used {{ $expected['final_used'] ?? $conflict->offline_used_after ?? 'n/a' }}; Remaining {{ $expected['final_remaining'] ?? $conflict->offline_remaining_after ?? 'n/a' }}</td>
                                <td class="px-5 py-4">
                                    <span class="status-badge {{ $conflict->resolution_status === $openConflictStatus ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">{{ $label($conflict->resolution_status) }}</span>
                                    @if ($conflict->resolution_status === $openConflictStatus)
                                        <p class="mt-2 text-xs text-zinc-600">{{ $actions }}</p>
                                    @elseif ($conflict->resolution_action)
                                        <p class="mt-2 text-xs text-zinc-600">{{ $actionLabels[$conflict->resolution_action] ?? $label($conflict->resolution_action) }}</p>
                                    @endif
                                    <a href="{{ route('admin.offline-scanner-conflicts.show', $conflict) }}" class="secondary-button mt-3">Review</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-8 text-sm font-semibold text-zinc-500">No conflicts were detected in this verified results file.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-200 pt-5">
            <a href="{{ route('admin.offline-scanner-sessions.show', $session) }}" class="secondary-button">Cancel</a>
            @can('confirmImport', $resultImport)
                <form method="POST" action="{{ route('admin.offline-scanner-results.confirm', $resultImport) }}" class="flex flex-wrap items-center justify-end gap-3" data-confirm data-confirm-title="Import verified Android results" data-confirm-message="This will update guest admission counts from the verified Android Master results export.">
                    @csrf
                    <label class="flex items-start gap-2 text-sm font-semibold text-zinc-700">
                        <input type="checkbox" name="confirm_import" value="1" class="mt-1 size-4" required @disabled(! $canImport)>
                        <span>I confirm these verified Android Master results should be imported.</span>
                    </label>
                    <button type="submit" class="primary-button" data-submitting-text="Importing..." @disabled(! $canImport)>Import Verified Results</button>
                </form>
            @endcan
        </section>
    </div>
@endsection
