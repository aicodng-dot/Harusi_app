<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerImportedLog;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerResultImport;
use App\Models\OfflineScannerSession;
use App\Models\User;
use App\Services\OfflineEventLockService;
use App\Services\OfflineScannerResults\OfflineScannerResultSchema;
use App\Services\OfflineScannerResults\OfflineScannerResultsImportService;
use App\Services\Tenancy\LegacyEventResolver;
use App\Services\Tenancy\LegacyOfflineGuestReview;
use App\Services\Tenancy\LegacyOfflineResourceResolver;
use App\Services\Tenancy\LegacyOfflineScannerReview;
use App\Support\PositiveIntegerId;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

trait HandlesOfflineScannerAdmin
{
    protected function selectEvent(Event $event): Event
    {
        $event = app(LegacyEventResolver::class)->find($event->getRawOriginal($event->getKeyName()));
        abort_unless($event instanceof Event, 404);
        if (! in_array(session('selected_event_id'), [$event->id, (string) $event->id], true)) {
            session()->forget('guest_import');
        }
        session(['selected_event_id' => $event->id]);

        return $event;
    }

    protected function legacySession(mixed $session): OfflineScannerSession
    {
        $id = $session instanceof OfflineScannerSession ? $session->getRawOriginal($session->getKeyName()) : $session;
        $fresh = app(LegacyOfflineResourceResolver::class)->session($id, session('selected_event_id'));
        abort_unless($fresh instanceof OfflineScannerSession, 404);

        return $fresh;
    }

    protected function legacyPackage(mixed $package): OfflineScannerPackage
    {
        $id = $package instanceof OfflineScannerPackage ? $package->getRawOriginal($package->getKeyName()) : $package;
        $fresh = app(LegacyOfflineResourceResolver::class)->package($id, session('selected_event_id'));
        abort_unless($fresh instanceof OfflineScannerPackage, 404);

        return $fresh;
    }

    protected function legacyResultImport(OfflineScannerResultImport $resultImport): OfflineScannerResultImport
    {
        $fresh = app(LegacyOfflineResourceResolver::class)->resultImport($resultImport->getRawOriginal($resultImport->getKeyName()), session('selected_event_id'));
        abort_unless($fresh instanceof OfflineScannerResultImport, 404);

        return $fresh;
    }

    protected function legacyConflict(OfflineScannerImportConflict $conflict): OfflineScannerImportConflict
    {
        $fresh = app(LegacyOfflineResourceResolver::class)->conflict($conflict->getRawOriginal($conflict->getKeyName()), session('selected_event_id'));
        abort_unless($fresh instanceof OfflineScannerImportConflict, 404);

        return $fresh;
    }

    protected function ensureSelectedEvent(OfflineScannerSession $session): void
    {
        $this->legacySession($session);
    }

    protected function validatePackageRequest(Request $request, Event $event, OfflineEventLockService $locks): array
    {
        $scannerUserRule = Rule::exists('users', 'id')->where(function ($query) use ($event): void {
            $this->constrainLegacyScanners($query, $event);
        });

        $validator = Validator::make($request->all(), [
            'master_scanner_user_id' => ['required', 'integer', $scannerUserRule],
            'master_gate_name' => ['required', 'string', 'max:120'],
            'client_scanner_user_ids' => ['nullable', 'array'],
            'client_scanner_user_ids.*' => ['integer', 'distinct', $scannerUserRule],
            'client_gate_names' => ['nullable', 'array'],
            'client_gate_names.*' => ['nullable', 'string', 'max:120'],
            'package_expires_at' => ['required', 'date', 'after:now'],
            'package_password' => ['required', 'string', 'min:16', 'confirmed'],
            'session_notes' => ['nullable', 'string', 'max:2000'],
            'confirm_generation' => ['accepted'],
        ], [
            'master_scanner_user_id.required' => 'Choose the Master scanner user.',
            'master_gate_name.required' => 'Assign the Master gate.',
            'package_expires_at.after' => 'The package expiration must be in the future.',
            'package_password.confirmed' => 'The package password confirmation does not match.',
            'confirm_generation.accepted' => 'Confirm that package generation will lock admission-sensitive guest data.',
        ]);

        $validator->after(function ($validator) use ($request, $event, $locks): void {
            $password = (string) $request->input('package_password', '');
            $masterId = (int) $request->input('master_scanner_user_id');
            $clientIds = collect($request->input('client_scanner_user_ids', []))->map(fn ($id): int => (int) $id);

            if ($locks->getActiveSessionForEvent($event)) {
                $validator->errors()->add('offline_session', 'This event already has an offline scanner session that blocks package generation.');
            }

            if ($password !== '' && ! preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{16,}$/', $password)) {
                $validator->errors()->add('package_password', 'Use at least 16 characters with uppercase, lowercase, number, and symbol.');
            }

            if ($masterId > 0 && $clientIds->contains($masterId)) {
                $validator->errors()->add('client_scanner_user_ids', 'The Master scanner cannot also be selected as a Client scanner.');
            }

            foreach ($clientIds as $clientId) {
                $gate = trim((string) $request->input('client_gate_names.'.$clientId, ''));
                if ($gate === '') {
                    $validator->errors()->add('client_gate_names.'.$clientId, 'Assign a gate for every Client scanner user.');
                }
            }
        });

        return $validator->validate();
    }

    protected function clientInputs(array $validated): array
    {
        $clientIds = collect($validated['client_scanner_user_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $gates = $validated['client_gate_names'] ?? [];

        return $clientIds
            ->map(fn (int $id): array => [
                'scanner_user_id' => $id,
                'assigned_gate' => trim((string) ($gates[$id] ?? $gates[(string) $id] ?? '')),
            ])
            ->all();
    }

    protected function constrainLegacyScanners($query, Event $event): void
    {
        $query->whereBetween('users.id', [1, PHP_INT_MAX])->where('users.role', User::ROLE_SCANNER)
            ->whereRaw('HEX(role) = ?', [strtoupper(bin2hex(User::ROLE_SCANNER))])
            ->where('users.event_id', $event->id)->where('users.is_active', true)->where('users.is_platform_admin', false)
            ->whereNotExists(function ($memberships): void {
                $memberships->selectRaw('1')->from('workspace_users')->whereColumn('workspace_users.user_id', 'users.id');
            });
    }

    protected function scannerUsersForEvent(Event $event)
    {
        $query = User::query()->useWritePdo();
        $this->constrainLegacyScanners($query, $event);

        return $query->orderBy('name')->get();
    }

    protected function latestSessionForEvent(Event $event): ?OfflineScannerSession
    {
        return OfflineScannerSession::query()
            ->with(['createdBy', 'packageExport', 'resultImports'])
            ->where('event_id', $event->id)
            ->latest()
            ->first();
    }

    protected function resultReviewData(
        Request $request,
        OfflineScannerResultImport $resultImport,
        OfflineScannerResultsImportService $resultImports,
    ): array {
        $resultImport = $this->legacyResultImport($resultImport);
        $resultImport->load([
            'session.event',
            'session.packageExport',
            'devices',
            'finalGuestStates',
            'conflicts.importedLog',
            'conflicts.resolvedBy',
            'uploadedBy',
        ]);

        $session = $resultImport->session;
        abort_unless($session instanceof OfflineScannerSession, 404);
        $this->ensureSelectedEvent($session);

        app(LegacyOfflineGuestReview::class)->projectImport($resultImport, $session);

        $filters = $this->resultLogFilters($request);
        $admissionLogs = $this->filteredResultLogs($resultImport, $session, $filters);
        $logGuests = $this->guestLookupForLogs($admissionLogs->getCollection(), $session);
        app(LegacyOfflineScannerReview::class)->projectImport($resultImport, $session, $admissionLogs->getCollection());

        return [
            'session' => $session->load(['event', 'packageExport']),
            'resultImport' => $resultImport,
            'summary' => $resultImports->previewSummary($resultImport),
            'eventGuests' => Guest::query()
                ->where('event_id', $session->event_id)
                ->orderBy('name')
                ->get(),
            'admissionLogs' => $admissionLogs,
            'logGuests' => $logGuests,
            'filters' => $filters,
            'filterOptions' => $this->resultLogFilterOptions($resultImport),
        ];
    }

    protected function resultLogFilters(Request $request): array
    {
        return [
            'device_id' => trim((string) $request->query('device_id', '')),
            'scanner_user_id' => trim((string) $request->query('scanner_user_id', '')),
            'gate' => trim((string) $request->query('gate', '')),
            'method' => trim((string) $request->query('method', '')),
            'result' => trim((string) $request->query('result', '')),
            'guest' => trim((string) $request->query('guest', '')),
            'time_from' => trim((string) $request->query('time_from', '')),
            'time_to' => trim((string) $request->query('time_to', '')),
        ];
    }

    protected function filteredResultLogs(
        OfflineScannerResultImport $resultImport,
        OfflineScannerSession $session,
        array $filters,
    ) {
        $query = $resultImport->importedLogs()
            ->with('conflict')
            ->orderBy('master_sequence_number');

        if ($filters['device_id'] !== '') {
            $query->where('source_device_id', $filters['device_id']);
        }

        if ($filters['scanner_user_id'] !== '') {
            $query->where('scanner_user_id', (int) $filters['scanner_user_id']);
        }

        if ($filters['gate'] !== '') {
            $query->where('source_gate_name', $filters['gate']);
        }

        if (in_array($filters['method'], ['qr', 'manual'], true)) {
            $query->where('admission_method', $filters['method']);
        }

        match ($filters['result']) {
            'successful' => $query->where('result_code', OfflineScannerResultSchema::RESULT_ADMITTED_SUCCESSFULLY),
            'rejected' => $query->where('import_status', OfflineScannerImportedLog::STATUS_REJECTED),
            'conflict' => $query->whereIn('import_status', [
                OfflineScannerImportedLog::STATUS_CONFLICT,
                OfflineScannerImportedLog::STATUS_UNKNOWN_GUEST,
            ]),
            'duplicate' => $query->where('import_status', OfflineScannerImportedLog::STATUS_DUPLICATE),
            default => null,
        };

        if ($filters['guest'] !== '') {
            $guestSearch = $filters['guest'];
            $matchingGuestIds = Guest::query()
                ->where('event_id', $session->event_id)
                ->where(function ($guestQuery) use ($guestSearch): void {
                    $guestQuery->where('name', 'like', '%'.$guestSearch.'%')
                        ->orWhere('manual_code', 'like', '%'.$guestSearch.'%');

                    $guestId = PositiveIntegerId::parse($guestSearch);
                    if ($guestId !== null) {
                        $guestQuery->orWhere('id', $guestId);
                    }
                })
                ->pluck('id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            $query->where(function ($logQuery) use ($guestSearch, $matchingGuestIds): void {
                $logQuery->where('external_guest_id', 'like', '%'.$guestSearch.'%');

                if ($matchingGuestIds !== []) {
                    $logQuery->orWhereIn('external_guest_id', $matchingGuestIds);
                }
            });
        }

        foreach (['time_from' => '>=', 'time_to' => '<='] as $field => $operator) {
            if ($filters[$field] === '') {
                continue;
            }

            try {
                $query->where('master_timestamp', $operator, Carbon::parse($filters[$field]));
            } catch (Throwable) {
                // Keep malformed optional filters from breaking review access.
            }
        }

        return $query->paginate(50)->withQueryString();
    }

    protected function guestLookupForLogs($logs, OfflineScannerSession $session)
    {
        $guestIds = $logs
            ->pluck('external_guest_id')
            ->map(fn ($id): ?int => PositiveIntegerId::parse($id))
            ->filter()
            ->unique()
            ->values();

        if ($guestIds->isEmpty()) {
            return collect();
        }

        return Guest::query()
            ->with('qrCode')
            ->where('event_id', $session->event_id)
            ->whereIn('id', $guestIds)
            ->get()
            ->keyBy('id');
    }

    protected function resultLogFilterOptions(OfflineScannerResultImport $resultImport): array
    {
        $logs = $resultImport->loadMissing('importedLogs')->importedLogs;

        return [
            'devices' => $resultImport->devices->sortBy('device_name')->values(),
            'scannerUsers' => $resultImport->devices
                ->filter(fn ($device): bool => (int) $device->scanner_user_id > 0)
                ->unique('scanner_user_id')
                ->sortBy(fn ($device): string => (string) ($device->scanner_user_name ?: $device->scannerUser?->name))
                ->values(),
            'gates' => $resultImport->devices
                ->pluck('gate_name')
                ->merge($logs->pluck('source_gate_name'))
                ->filter()
                ->unique()
                ->sort()
                ->values(),
        ];
    }
}
