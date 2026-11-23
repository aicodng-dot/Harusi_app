@extends('layouts.admin', ['title' => 'Offline Scanner Session', 'headerTitle' => 'Offline Scanner Session'])

@section('content')
    @php
        $formatStatus = fn (?string $status) => $status ? str_replace('_', ' ', ucwords($status, '_')) : 'None';
        $statusBadge = fn (?string $status) => match ($status) {
            \App\Models\OfflineScannerSession::STATUS_ACTIVE,
            \App\Models\OfflineScannerSession::STATUS_RESULTS_IMPORTED,
            \App\Models\OfflineScannerSession::STATUS_CLOSED => 'bg-emerald-100 text-emerald-800',
            \App\Models\OfflineScannerSession::STATUS_PACKAGE_GENERATED,
            \App\Models\OfflineScannerSession::STATUS_AWAITING_RESULTS,
            \App\Models\OfflineScannerSession::STATUS_RESULTS_UPLOADED,
            \App\Models\OfflineScannerSession::STATUS_CONFLICT => 'bg-amber-100 text-amber-800',
            \App\Models\OfflineScannerSession::STATUS_CANCELLED,
            \App\Models\OfflineScannerSession::STATUS_EXPIRED => 'bg-rose-100 text-rose-800',
            default => 'bg-zinc-100 text-zinc-700',
        };
        $package = $session->packageExport;
        $fingerprint = $session->package_fingerprint ?? $package?->package_fingerprint;
        $packageExportedAt = $session->package_created_at ?? $package?->generated_at;
        $clientCount = $session->sessionUsers->where('device_role', \App\Models\OfflineScannerSessionUser::DEVICE_ROLE_CLIENT)->count();
        $resultsImportCount = $session->resultImports->count();
        $resultsStatus = $session->results_imported_at
            ? 'Imported '.$session->results_imported_at->format('M j, Y g:i A')
            : ($resultsImportCount > 0 ? $resultsImportCount.' import file'.($resultsImportCount === 1 ? '' : 's').' uploaded' : 'No results imported');
        $canUploadResults = in_array($session->status, [
            \App\Models\OfflineScannerSession::STATUS_ACTIVE,
            \App\Models\OfflineScannerSession::STATUS_AWAITING_RESULTS,
            \App\Models\OfflineScannerSession::STATUS_RESULTS_UPLOADED,
        ], true) && ! $session->results_imported_at;
    @endphp

    <div class="mx-auto max-w-6xl space-y-6">
        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->has('offline_session'))
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800">
                {{ $errors->first('offline_session') }}
            </div>
        @endif

        @if ($errors->has('offline_results'))
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800">
                @foreach ($errors->get('offline_results') as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        <section class="border-b border-stone-200 pb-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="section-kicker">Offline Android scanner</p>
                    <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">{{ $session->event->event_name }}</h2>
                    <p class="mt-2 break-all font-mono text-sm font-semibold text-zinc-600">{{ $session->session_uuid }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @can('download', $session)
                        @if ($package)
                            <a href="{{ route('admin.offline-scanner-packages.download', $package) }}" class="primary-button">Download package</a>
                        @endif
                    @endcan
                    <span class="status-badge {{ $statusBadge($session->status) }}">{{ $formatStatus($session->status) }}</span>
                </div>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-[minmax(0,1.3fr)_minmax(320px,0.7fr)]">
            <article class="app-card p-5">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-stone-200 pb-4">
                    <div>
                        <h3 class="text-lg font-semibold">Session overview</h3>
                    </div>
                    <span class="status-badge {{ $statusBadge($session->status) }}">{{ $formatStatus($session->status) }}</span>
                </div>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-bold uppercase text-zinc-500">Event name</dt>
                        <dd class="mt-1 font-semibold">{{ $session->event->event_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase text-zinc-500">Event date</dt>
                        <dd class="mt-1 font-semibold">{{ $session->event->event_date?->format('M j, Y') ?? 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase text-zinc-500">Venue</dt>
                        <dd class="mt-1 font-semibold">{{ $session->event->venue_name ?: 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase text-zinc-500">Existing offline session status</dt>
                        <dd class="mt-1 font-semibold">{{ $formatStatus($session->status) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase text-zinc-500">Package generation status</dt>
                        <dd class="mt-1 font-semibold">{{ $package ? 'Generated' : 'Not generated' }}</dd>
                        @if ($packageExportedAt)
                            <dd class="mt-1 text-xs font-semibold text-zinc-500">{{ $packageExportedAt->format('M j, Y g:i A') }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase text-zinc-500">Package expiration</dt>
                        <dd class="mt-1 font-semibold">{{ $session->package_expires_at?->format('M j, Y g:i A') ?? 'Not set' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-bold uppercase text-zinc-500">Results-import status</dt>
                        <dd class="mt-1 font-semibold">{{ $resultsStatus }}</dd>
                    </div>
                    @if ($session->notes)
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-bold uppercase text-zinc-500">Session notes</dt>
                            <dd class="mt-1 whitespace-pre-line text-sm font-semibold text-zinc-700">{{ $session->notes }}</dd>
                        </div>
                    @endif
                </dl>
            </article>

            <article class="app-card p-5">
                <h3 class="text-lg font-semibold">Package details</h3>
                <dl class="mt-5 space-y-4">
                    <div>
                        <dt class="text-xs font-bold uppercase text-zinc-500">Session UUID</dt>
                        <dd class="mt-1 break-all font-mono text-sm font-semibold">{{ $session->session_uuid }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase text-zinc-500">Package export ID</dt>
                        <dd class="mt-1 break-all font-mono text-sm font-semibold">{{ $package?->package_uuid ?? 'Not generated' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase text-zinc-500">Package fingerprint</dt>
                        <dd class="mt-1 break-all font-mono text-xs font-semibold">{{ $fingerprint ?? 'Not generated' }}</dd>
                        @if ($fingerprint)
                            <button type="button" class="secondary-button mt-3" data-copy-text="{{ $fingerprint }}" data-copied-text="Fingerprint copied">Copy fingerprint</button>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase text-zinc-500">Master scanner</dt>
                        <dd class="mt-1 font-semibold">{{ $session->masterScannerUser?->name ?? 'Not assigned' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase text-zinc-500">Master gate</dt>
                        <dd class="mt-1 font-semibold">{{ $session->master_gate_name ?: 'No gate' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase text-zinc-500">Authorized Client scanners</dt>
                        <dd class="mt-1 font-semibold">{{ number_format($clientCount) }}</dd>
                    </div>
                </dl>
            </article>
        </section>

        <section class="app-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-stone-200 px-5 py-4">
                <div>
                    <p class="section-kicker">Results import</p>
                    <h3 class="mt-1 text-lg font-semibold">Import Android Scanner Results</h3>
                </div>
                <span class="status-badge {{ $canUploadResults ? 'bg-emerald-100 text-emerald-800' : 'bg-zinc-100 text-zinc-700' }}">{{ $canUploadResults ? 'Ready' : 'Unavailable' }}</span>
            </div>
            <div class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.8fr)]">
                <div>
                    @can('create', \App\Models\OfflineScannerResultImport::class)
                        <form method="POST" action="{{ route('admin.offline-scanner-results.preview') }}" enctype="multipart/form-data" class="space-y-4" data-disable-on-submit>
                            @csrf
                            <input type="hidden" name="offline_scanner_session_id" value="{{ $session->id }}">
                            <label class="field-label">
                                Results file
                                <input type="file" name="results_file" accept=".wqrresults" class="input-control" required @disabled(! $canUploadResults)>
                                @error('results_file')<span class="text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                            </label>
                            <button type="submit" class="primary-button" data-submitting-text="Verifying..." @disabled(! $canUploadResults)>Verify results file</button>
                        </form>
                    @else
                        <p class="text-sm font-semibold text-zinc-500">You are not authorized to import scanner results.</p>
                    @endcan
                </div>
                <div class="border border-stone-200 bg-stone-50 p-4">
                    <p class="text-xs font-bold uppercase text-zinc-500">Existing result imports</p>
                    <div class="mt-3 space-y-3">
                        @forelse ($session->resultImports->sortByDesc('created_at') as $resultImport)
                            <div class="flex items-start justify-between gap-3 text-sm">
                                <div class="min-w-0">
                                    <p class="break-all font-mono text-xs font-semibold">{{ $resultImport->results_export_id ?? $resultImport->import_uuid }}</p>
                                    <p class="mt-1 font-semibold text-zinc-600">{{ str_replace('_', ' ', ucwords($resultImport->status, '_')) }} - {{ $resultImport->created_at?->format('M j, Y g:i A') }}</p>
                                </div>
                                @can('view', $resultImport)
                                    <a href="{{ route('admin.offline-scanner-results.show', $resultImport) }}" class="secondary-button shrink-0">Preview</a>
                                @endcan
                            </div>
                        @empty
                            <p class="text-sm font-semibold text-zinc-500">No Android results files have been uploaded.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>
        <section class="app-card overflow-hidden">
            <div class="border-b border-stone-200 px-5 py-4">
                <h3 class="text-lg font-semibold">Authorized scanner users</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Scanner</th>
                            <th class="px-5 py-3">Role</th>
                            <th class="px-5 py-3">Gate</th>
                            <th class="px-5 py-3">Offline PIN algorithm</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($session->sessionUsers as $sessionUser)
                            <tr>
                                <td class="px-5 py-4 font-semibold">{{ $sessionUser->scannerUser?->name ?? 'Unknown scanner' }}</td>
                                <td class="px-5 py-4"><span class="status-badge bg-zinc-100 text-zinc-700">{{ ucfirst($sessionUser->device_role) }}</span></td>
                                <td class="px-5 py-4">{{ $sessionUser->assigned_gate }}</td>
                                <td class="px-5 py-4 font-mono text-xs">{{ $sessionUser->offline_pin_algorithm }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-8 text-sm font-semibold text-zinc-500">No scanner users are attached to this session.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="app-card overflow-hidden">
            <div class="border-b border-stone-200 px-5 py-4">
                <h3 class="text-lg font-semibold">Session audit history</h3>
            </div>
            <div class="divide-y divide-stone-100 px-5">
                @forelse ($session->audits->sortByDesc('occurred_at') as $audit)
                    <div class="py-4 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="font-semibold text-zinc-950">{{ str_replace('_', ' ', ucwords($audit->action, '_')) }}</p>
                            <p class="text-xs font-bold uppercase text-zinc-500">{{ $audit->occurred_at?->format('M j, Y g:i A') ?? 'Not timed' }}</p>
                        </div>
                        <p class="mt-1 text-zinc-600">{{ $audit->actor?->name ?? 'System' }}</p>
                        @if ($audit->reason)
                            <p class="mt-2 text-zinc-700">{{ $audit->reason }}</p>
                        @endif
                    </div>
                @empty
                    <p class="py-8 text-sm font-semibold text-zinc-500">No session audit entries yet.</p>
                @endforelse
            </div>
        </section>

        @can('cancel', $session)
            <section class="border border-rose-200 bg-rose-50 p-5">
                <h3 class="text-lg font-semibold text-rose-950">Cancel session</h3>
                <form method="POST" action="{{ route('admin.offline-scanner-sessions.cancel', $session) }}" class="mt-4 space-y-4" data-confirm data-confirm-title="Cancel offline scanner session" data-confirm-message="Only cancel when no scanner results were used or you accept the admission reconciliation risk.">
                    @csrf
                    <label class="flex items-start gap-3 text-sm font-semibold text-rose-950">
                        <input type="checkbox" name="confirm_unused" value="1" class="mt-1 size-4" required>
                        <span>I confirm that the Android scanner session was not used, or I accept the risk of cancelling it.</span>
                    </label>
                    <label class="field-label text-rose-950">
                        Cancellation reason
                        <textarea name="cancellation_reason" rows="3" class="input-control" required></textarea>
                    </label>
                    <button type="submit" class="danger-button">Cancel session</button>
                </form>
            </section>
        @endcan
    </div>
@endsection