<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesOfflineScannerAdmin;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\OfflineScannerImportConflict;
use App\Services\Tenancy\LegacyOfflineConflictResolution;
use App\Services\Tenancy\LegacyOfflineGuestReview;
use App\Services\Tenancy\LegacyOfflineScannerReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OfflineScannerConflictController extends Controller
{
    use HandlesOfflineScannerAdmin;

    public function show(OfflineScannerImportConflict $conflict): View
    {
        $conflict = $this->legacyConflict($conflict);
        Gate::authorize('view', $conflict);

        $conflict->load([
            'resultImport.session.event',
            'resultImport.session.packageExport',
            'resultImport.devices',
            'resultImport.uploadedBy',
            'importedLog',
            'resolvedBy',
        ]);

        $resultImport = $conflict->resultImport;
        $session = $resultImport?->session;
        abort_unless($resultImport && $session, 404);
        $this->ensureSelectedEvent($session);

        app(LegacyOfflineGuestReview::class)->projectConflict($conflict, $resultImport, $session);
        app(LegacyOfflineScannerReview::class)->projectConflict($conflict, $resultImport, $session);

        return view('admin.offline-scanner-sessions.result-conflict', [
            'session' => $session->load(['event', 'packageExport']),
            'resultImport' => $resultImport,
            'conflict' => $conflict,
            'eventGuests' => Guest::query()
                ->where('event_id', $session->event_id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function resolve(
        Request $request,
        OfflineScannerImportConflict $conflict,
        LegacyOfflineConflictResolution $resolutions,
    ): RedirectResponse {
        $conflict = $this->legacyConflict($conflict);
        Gate::authorize('resolve', $conflict);

        $conflict->load('resultImport.session');
        $resultImport = $conflict->resultImport;
        $session = $resultImport?->session;
        abort_unless($resultImport && $session, 404);
        $this->ensureSelectedEvent($session);

        $validated = $request->validate([
            'resolution_action' => ['required', Rule::in(OfflineScannerImportConflict::resolutionActions())],
            'resolution_notes' => ['required', 'string', 'max:2000'],
            'replacement_guest_id' => [
                'nullable',
                'integer',
                Rule::exists('guests', 'id')->where(fn ($query) => $query->where('event_id', $session->event_id)),
            ],
            'manual_used_entries' => ['nullable', 'integer', 'min:0', 'max:'.Guest::MAX_ALLOWED_ENTRIES],
            'confirm_resolution' => ['accepted'],
        ], [
            'resolution_action.required' => 'Choose a conflict resolution action.',
            'resolution_notes.required' => 'Resolution notes are required.',
            'confirm_resolution.accepted' => 'Confirm this conflict resolution before applying it.',
        ]);

        try {
            $resolutions->resolve($conflict, $request->user(), session('selected_event_id'), $validated);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (PDOException) {
            return back()->withErrors(['conflict_resolution' => 'Unable to resolve the offline result conflict.']);
        } catch (InvalidArgumentException|RuntimeException|LogicException $exception) {
            return back()->withErrors(['conflict_resolution' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.offline-scanner-results.show', $resultImport)
            ->with('success', 'Offline result conflict updated.');
    }
}
