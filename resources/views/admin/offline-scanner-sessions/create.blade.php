@extends('layouts.admin', ['title' => 'Offline Android Scanner', 'headerTitle' => 'Offline Android Scanner'])

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
        $packageExportedAt = $latestSession?->package_created_at ?? $latestSession?->packageExport?->generated_at;
        $packageGenerated = $latestSession && ($latestSession->packageExport || $latestSession->package_fingerprint);
        $resultsImportCount = $latestSession?->resultImports?->count() ?? 0;
        $resultsStatus = $latestSession?->results_imported_at
            ? 'Imported '.$latestSession->results_imported_at->format('M j, Y g:i A')
            : ($resultsImportCount > 0 ? $resultsImportCount.' import file'.($resultsImportCount === 1 ? '' : 's').' uploaded' : 'No results imported');
        $generationDisabled = (bool) $activeSession || $scannerUsers->isEmpty() || $snapshotSummary['has_critical_errors'];
        $snapshotCards = [
            ['label' => 'Total guests', 'value' => $snapshotSummary['total_guests'], 'tone' => 'text-zinc-950'],
            ['label' => 'Active passes', 'value' => $snapshotSummary['active_passes'], 'tone' => 'text-emerald-700'],
            ['label' => 'Fully used passes', 'value' => $snapshotSummary['fully_used_passes'], 'tone' => 'text-zinc-800'],
            ['label' => 'Disabled passes', 'value' => $snapshotSummary['disabled_passes'], 'tone' => 'text-amber-700'],
            ['label' => 'Guests missing QR tokens', 'value' => $snapshotSummary['guests_missing_qr_tokens'], 'tone' => $snapshotSummary['guests_missing_qr_tokens'] > 0 ? 'text-rose-700' : 'text-emerald-700'],
            ['label' => 'Guests missing manual codes', 'value' => $snapshotSummary['guests_missing_manual_codes'], 'tone' => $snapshotSummary['guests_missing_manual_codes'] > 0 ? 'text-rose-700' : 'text-emerald-700'],
            ['label' => 'Duplicate manual codes', 'value' => $snapshotSummary['duplicate_manual_codes'], 'tone' => $snapshotSummary['duplicate_manual_codes'] > 0 ? 'text-rose-700' : 'text-emerald-700'],
            ['label' => 'Duplicate QR-token hashes', 'value' => $snapshotSummary['duplicate_qr_token_hashes'], 'tone' => $snapshotSummary['duplicate_qr_token_hashes'] > 0 ? 'text-rose-700' : 'text-emerald-700'],
        ];
        $oldClientIds = collect(old('client_scanner_user_ids', []))->map(fn ($id) => (int) $id)->all();
    @endphp

    <div class="mx-auto max-w-6xl space-y-6">
        @if ($errors->has('offline_session'))
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800">
                {{ $errors->first('offline_session') }}
            </div>
        @endif

        @if ($errors->has('offline_package'))
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800">
                {{ $errors->first('offline_package') }}
            </div>
        @endif

        <section class="border-b border-stone-200 pb-5">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="section-kicker">{{ $event->event_name }}</p>
                    <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Offline Android Scanner</h2>
                </div>
                @if ($latestSession)
                    <a href="{{ route('admin.offline-scanner-sessions.show', $latestSession) }}" class="secondary-button">View session</a>
                @endif
            </div>
        </section>

        @if ($activeSession)
            <section class="border border-amber-300 bg-amber-50 p-5 text-amber-950" role="alert">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="font-bold">Offline package already active</p>
                        <p class="mt-1 break-all font-mono text-sm font-semibold">{{ $activeSession->session_uuid }}</p>
                    </div>
                    <a href="{{ route('admin.offline-scanner-sessions.show', $activeSession) }}" class="secondary-button">View session</a>
                </div>
            </section>
        @endif

        <section class="app-card p-5">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-stone-200 pb-4">
                <div>
                    <h3 class="text-lg font-semibold">Event session status</h3>
                </div>
                <span class="status-badge {{ $statusBadge($latestSession?->status) }}">{{ $formatStatus($latestSession?->status) }}</span>
            </div>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Event name</dt>
                    <dd class="mt-1 font-semibold">{{ $event->event_name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Event date</dt>
                    <dd class="mt-1 font-semibold">{{ $event->event_date?->format('M j, Y') ?? 'Not set' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Venue</dt>
                    <dd class="mt-1 font-semibold">{{ $event->venue_name ?: 'Not set' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Existing offline session status</dt>
                    <dd class="mt-1 font-semibold">{{ $formatStatus($latestSession?->status) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Package generation status</dt>
                    <dd class="mt-1 font-semibold">{{ $packageGenerated ? 'Generated' : 'Not generated' }}</dd>
                    @if ($packageExportedAt)
                        <dd class="mt-1 text-xs font-semibold text-zinc-500">{{ $packageExportedAt->format('M j, Y g:i A') }}</dd>
                    @endif
                </div>
                <div>
                    <dt class="text-xs font-bold uppercase text-zinc-500">Package expiration</dt>
                    <dd class="mt-1 font-semibold">{{ $latestSession?->package_expires_at?->format('M j, Y g:i A') ?? 'Not set' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-bold uppercase text-zinc-500">Results-import status</dt>
                    <dd class="mt-1 font-semibold">{{ $resultsStatus }}</dd>
                </div>
            </dl>
        </section>

        <section class="app-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-stone-200 px-5 py-4">
                <div>
                    <p class="section-kicker">Preflight</p>
                    <h3 class="mt-1 text-lg font-semibold">Guest snapshot summary</h3>
                </div>
                @if ($snapshotSummary['has_critical_errors'])
                    <span class="status-badge bg-rose-100 text-rose-800">{{ $snapshotSummary['critical_error_count'] }} critical</span>
                @else
                    <span class="status-badge bg-emerald-100 text-emerald-800">Ready</span>
                @endif
            </div>
            <div class="grid gap-px bg-stone-200 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($snapshotCards as $card)
                    <div class="bg-white p-4">
                        <p class="text-xs font-bold uppercase text-zinc-500">{{ $card['label'] }}</p>
                        <p class="mt-2 text-2xl font-semibold {{ $card['tone'] }}">{{ number_format($card['value']) }}</p>
                    </div>
                @endforeach
            </div>
            @if ($snapshotSummary['has_critical_errors'])
                <div class="border-t border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800">
                    Package generation is disabled until critical guest snapshot issues are resolved.
                </div>
            @endif
        </section>

        @can('create', \App\Models\OfflineScannerSession::class)
            <form method="POST" action="{{ route('admin.offline-scanner-sessions.store', $event) }}" class="space-y-6" data-disable-on-submit data-confirm data-confirm-title="Generate offline scanner package" data-confirm-message="Generating this package will lock admission-sensitive guest data for this event until offline scanner results are imported or the session is cancelled.">
                @csrf

                <section class="app-card p-5">
                    <h3 class="text-lg font-semibold">Master scanner</h3>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <label class="field-label">
                            Master scanner user
                            <select name="master_scanner_user_id" class="input-control" required @disabled($generationDisabled)>
                                <option value="">Choose scanner user</option>
                                @foreach ($scannerUsers as $scannerUser)
                                    <option value="{{ $scannerUser->id }}" @selected((int) old('master_scanner_user_id') === $scannerUser->id)>{{ $scannerUser->name }} - {{ $scannerUser->gate_name ?: 'No gate' }}</option>
                                @endforeach
                            </select>
                            @error('master_scanner_user_id')<span class="text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                        </label>
                        <label class="field-label">
                            Master gate
                            <input type="text" name="master_gate_name" value="{{ old('master_gate_name') }}" class="input-control" maxlength="120" required @disabled($generationDisabled)>
                            @error('master_gate_name')<span class="text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                        </label>
                    </div>
                </section>

                <section class="app-card overflow-hidden">
                    <div class="border-b border-stone-200 px-5 py-4">
                        <h3 class="text-lg font-semibold">Authorized Client scanner users</h3>
                    </div>
                    <div class="divide-y divide-stone-100">
                        @forelse ($scannerUsers as $scannerUser)
                            <div class="grid gap-3 px-5 py-4 md:grid-cols-[minmax(0,1fr)_260px]">
                                <label class="flex items-center gap-3 text-sm font-semibold text-zinc-800">
                                    <input type="checkbox" name="client_scanner_user_ids[]" value="{{ $scannerUser->id }}" class="size-4" @checked(in_array($scannerUser->id, $oldClientIds)) @disabled($generationDisabled)>
                                    <span>{{ $scannerUser->name }}</span>
                                </label>
                                <label class="field-label">
                                    Gate
                                    <input type="text" name="client_gate_names[{{ $scannerUser->id }}]" value="{{ old('client_gate_names.'.$scannerUser->id, $scannerUser->gate_name) }}" class="input-control" maxlength="120" placeholder="Client gate" @disabled($generationDisabled)>
                                    @error('client_gate_names.'.$scannerUser->id)<span class="text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                                </label>
                            </div>
                        @empty
                            <p class="px-5 py-8 text-sm font-semibold text-zinc-500">No scanner users are assigned to this event.</p>
                        @endforelse
                    </div>
                    @error('client_scanner_user_ids')<p class="px-5 py-3 text-xs font-bold text-rose-700">{{ $message }}</p>@enderror
                </section>

                <section class="app-card p-5">
                    <h3 class="text-lg font-semibold">Package security</h3>
                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <label class="field-label">
                            Package expiration
                            <input type="datetime-local" name="package_expires_at" value="{{ old('package_expires_at', $defaultExpiration) }}" class="input-control" required @disabled($generationDisabled)>
                            @error('package_expires_at')<span class="text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                        </label>
                        <label class="field-label">
                            One-time package password
                            <input type="password" name="package_password" class="input-control" required @disabled($generationDisabled)>
                            @error('package_password')<span class="text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                        </label>
                        <label class="field-label">
                            Confirm package password
                            <input type="password" name="package_password_confirmation" class="input-control" required @disabled($generationDisabled)>
                        </label>
                    </div>
                    <label class="field-label mt-4">
                        Optional session notes
                        <textarea name="session_notes" rows="3" class="input-control" maxlength="2000" @disabled($generationDisabled)>{{ old('session_notes') }}</textarea>
                        @error('session_notes')<span class="text-xs font-bold text-rose-700">{{ $message }}</span>@enderror
                    </label>
                </section>

                <section class="border border-amber-300 bg-amber-50 p-5 text-amber-950">
                    <label class="flex items-start gap-3 text-sm font-semibold">
                        <input type="checkbox" name="confirm_generation" value="1" class="mt-1 size-4" required @checked(old('confirm_generation')) @disabled($generationDisabled)>
                        <span>Generating this package will lock admission-sensitive guest data for this event until offline scanner results are imported or the session is cancelled.</span>
                    </label>
                    @error('confirm_generation')<p class="mt-3 text-xs font-bold text-rose-700">{{ $message }}</p>@enderror
                </section>

                <div class="flex flex-wrap justify-end gap-2">
                    <a href="{{ route('admin.dashboard') }}" class="secondary-button">Cancel</a>
                    <button type="submit" class="primary-button" data-submitting-text="Generating..." @disabled($generationDisabled)>Generate .wqrpkg</button>
                </div>
            </form>
        @endcan
    </div>
@endsection