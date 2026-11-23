<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesOfflineScannerAdmin;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\OfflineScannerSession;
use App\Services\GuestSnapshotService;
use App\Services\OfflineEventLockService;
use App\Services\OfflineScannerPackageBuilder;
use App\Services\Tenancy\LegacyOfflinePackageDelivery;
use App\Services\Tenancy\LegacyOfflineScannerReview;
use App\Services\Tenancy\LegacyOfflineSessionActions;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class OfflineScannerSessionController extends Controller
{
    use HandlesOfflineScannerAdmin;

    public function create(Event $event, OfflineEventLockService $locks, GuestSnapshotService $snapshots): View
    {
        Gate::authorize('viewAny', OfflineScannerSession::class);
        Gate::authorize('create', OfflineScannerSession::class);
        $event = $this->selectEvent($event);

        $activeSession = $locks->getActiveSessionForEvent($event);
        $latestSession = $this->latestSessionForEvent($event);
        foreach ([$activeSession, $latestSession] as $session) {
            if ($session !== null) {
                app(LegacyOfflineScannerReview::class)->projectSession($session, $event->getRawOriginal($event->getKeyName()));
            }
        }

        return view('admin.offline-scanner-sessions.create', [
            'event' => $event,
            'scannerUsers' => $this->scannerUsersForEvent($event),
            'activeSession' => $activeSession,
            'latestSession' => $latestSession,
            'snapshotSummary' => $snapshots->summary($event),
            'defaultExpiration' => now()->addHours(24)->format('Y-m-d\TH:i'),
        ]);
    }

    public function store(
        Request $request,
        Event $event,
        OfflineScannerPackageBuilder $packages,
        OfflineEventLockService $locks,
        LegacyOfflinePackageDelivery $delivery,
    ): Response|RedirectResponse {
        Gate::authorize('create', OfflineScannerSession::class);
        Gate::authorize('generatePackage', OfflineScannerSession::class);
        $event = $this->selectEvent($event);

        $validated = $this->validatePackageRequest($request, $event, $locks);

        try {
            $export = $packages->generate($event, $request->user(), [
                'master_scanner_user_id' => (int) $validated['master_scanner_user_id'],
                'master_gate_name' => $validated['master_gate_name'],
                'clients' => $this->clientInputs($validated),
                'package_expires_at' => $validated['package_expires_at'],
                'package_password' => $validated['package_password'],
                'session_notes' => $validated['session_notes'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (QueryException) {
            return back()->withErrors(['offline_session' => 'Unable to generate the encrypted offline scanner package.'])
                ->withInput($request->except(['package_password', 'package_password_confirmation']));
        } catch (InvalidArgumentException|RuntimeException|LogicException $exception) {
            return back()
                ->withErrors(['offline_session' => $exception->getMessage()])
                ->withInput($request->except(['package_password', 'package_password_confirmation']));
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors(['offline_session' => 'Unable to generate the encrypted offline scanner package.'])
                ->withInput($request->except(['package_password', 'package_password_confirmation']));
        }

        return $delivery->download($export->package, $request->user(), session('selected_event_id'), $export->session);
    }

    public function show(OfflineScannerSession $offlineScannerSession): View
    {
        $offlineScannerSession = $this->legacySession($offlineScannerSession);
        Gate::authorize('view', $offlineScannerSession);

        $offlineScannerSession->load([
            'createdBy',
            'event',
            'packageExport',
            'sessionUsers',
            'resultImports.uploadedBy',
            'audits.actor',
        ]);
        app(LegacyOfflineScannerReview::class)->projectSession($offlineScannerSession, session('selected_event_id'), true);

        return view('admin.offline-scanner-sessions.show', [
            'session' => $offlineScannerSession,
        ]);
    }

    public function cancel(
        Request $request,
        OfflineScannerSession $offlineScannerSession,
        LegacyOfflineSessionActions $actions,
    ): RedirectResponse {
        $offlineScannerSession = $this->legacySession($offlineScannerSession);
        Gate::authorize('cancel', $offlineScannerSession);

        $validated = $request->validate([
            'confirm_unused' => ['accepted'],
            'cancellation_reason' => ['required', 'string', 'max:2000'],
        ], [
            'confirm_unused.accepted' => 'Confirm that the Android scanner session was not used before cancelling it.',
            'cancellation_reason.required' => 'Provide a cancellation reason.',
        ]);

        try {
            $actions->cancel(
                $offlineScannerSession,
                $request->user(),
                session('selected_event_id'),
                true,
                $validated['cancellation_reason'],
            );
        } catch (QueryException|DeadlockException) {
            return back()->withErrors(['offline_session' => 'Unable to cancel the offline scanner session.']);
        } catch (InvalidArgumentException|LogicException $exception) {
            return back()->withErrors(['offline_session' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.offline-scanner-sessions.show', $offlineScannerSession)
            ->with('success', 'Offline scanner session cancelled and event lock released.');
    }

    public function close(
        Request $request,
        OfflineScannerSession $offlineScannerSession,
        LegacyOfflineSessionActions $actions,
    ): RedirectResponse {
        $offlineScannerSession = $this->legacySession($offlineScannerSession);
        Gate::authorize('close', $offlineScannerSession);

        $request->validate([
            'confirm_close' => ['accepted'],
        ], [
            'confirm_close.accepted' => 'Confirm that the offline scanner session should be closed.',
        ]);

        try {
            $actions->close($offlineScannerSession, $request->user(), session('selected_event_id'));
        } catch (QueryException|DeadlockException) {
            return back()->withErrors(['offline_session' => 'Unable to close the offline scanner session.']);
        } catch (LogicException|InvalidArgumentException $exception) {
            return back()->withErrors(['offline_session' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.offline-scanner-sessions.show', $offlineScannerSession)
            ->with('success', 'Offline scanner session closed and normal Laravel admissions resumed.');
    }
}
