<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesOfflineScannerAdmin;
use App\Http\Controllers\Controller;
use App\Models\OfflineScannerResultImport;
use App\Services\OfflineScannerResults\OfflineScannerResultsImportService;
use App\Services\Tenancy\LegacyOfflineFileArchives;
use App\Services\Tenancy\LegacyOfflineResultConfirmation;
use App\Services\Tenancy\LegacyOfflineSecureDeletion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class OfflineScannerResultImportController extends Controller
{
    use HandlesOfflineScannerAdmin;

    public function preview(Request $request, OfflineScannerResultsImportService $resultImports): RedirectResponse
    {
        Gate::authorize('upload', OfflineScannerResultImport::class);
        $request->validate(['offline_scanner_session_id' => ['required', 'integer']]);
        $session = $this->legacySession($request->input('offline_scanner_session_id'));
        Gate::authorize('view', $session);

        $validated = $request->validate([
            'offline_scanner_session_id' => ['required', 'integer'],
            'results_file' => ['required', 'file', 'max:20480'],
        ], [
            'offline_scanner_session_id.required' => 'Choose the offline scanner session for this results file.',
            'results_file.required' => 'Choose the Android scanner results file.',
            'results_file.file' => 'Choose a valid Android scanner results file.',
            'results_file.max' => 'The Android scanner results file may not be larger than 20 MB.',
        ]);

        $file = $validated['results_file'];

        try {
            $resultImport = $resultImports->verifyUpload(
                $session,
                $request->user(),
                $file->getClientOriginalName(),
                $file->get(),
                (int) $file->getSize(),
                session('selected_event_id'),
            );
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'offline_results' => 'Unable to verify the Android scanner results file.',
            ]);
        }

        return redirect()
            ->route('admin.offline-scanner-results.show', $resultImport)
            ->with('success', 'Android scanner results verified. Review the preview before importing.');
    }

    public function show(
        Request $request,
        OfflineScannerResultImport $resultImport,
        OfflineScannerResultsImportService $resultImports,
    ): View {
        $resultImport = $this->legacyResultImport($resultImport);
        Gate::authorize('view', $resultImport);

        return view('admin.offline-scanner-sessions.results-preview', $this->resultReviewData($request, $resultImport, $resultImports));
    }

    public function logs(
        Request $request,
        OfflineScannerResultImport $resultImport,
        OfflineScannerResultsImportService $resultImports,
    ): View {
        $resultImport = $this->legacyResultImport($resultImport);
        Gate::authorize('view', $resultImport);

        return view('admin.offline-scanner-sessions.results-preview', $this->resultReviewData($request, $resultImport, $resultImports));
    }

    public function conflicts(
        Request $request,
        OfflineScannerResultImport $resultImport,
        OfflineScannerResultsImportService $resultImports,
    ): View {
        $resultImport = $this->legacyResultImport($resultImport);
        Gate::authorize('view', $resultImport);

        return view('admin.offline-scanner-sessions.results-preview', $this->resultReviewData($request, $resultImport, $resultImports));
    }

    public function confirm(
        Request $request,
        OfflineScannerResultImport $resultImport,
        LegacyOfflineResultConfirmation $confirmation,
    ): RedirectResponse {
        $resultImport = $this->legacyResultImport($resultImport);
        Gate::authorize('importResults', $resultImport);
        $session = $this->legacySession($resultImport->getRawOriginal('offline_scanner_session_id'));

        $request->validate([
            'confirm_import' => ['accepted'],
        ], [
            'confirm_import.accepted' => 'Confirm that the verified Android scanner results should be imported.',
        ]);

        try {
            $imported = $confirmation->confirm($resultImport, $request->user(), session('selected_event_id'));
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (PDOException) {
            return back()->withErrors(['offline_results' => 'Unable to import the verified Android scanner results.']);
        } catch (InvalidArgumentException|RuntimeException|LogicException $exception) {
            return back()->withErrors(['offline_results' => $exception->getMessage()]);
        }

        $message = $imported->status === OfflineScannerResultImport::STATUS_COMPLETED_WITH_CONFLICTS
            ? 'Verified Android scanner results imported with conflicts. Resolve all conflicts before the event lock is released.'
            : 'Verified Android scanner results imported and the offline session was closed.';

        return redirect()
            ->route('admin.offline-scanner-sessions.show', $session)
            ->with('success', $message);
    }

    public function archive(
        Request $request,
        OfflineScannerResultImport $resultImport,
        LegacyOfflineFileArchives $archives,
    ): RedirectResponse {
        $resultImport = $this->legacyResultImport($resultImport);
        Gate::authorize('archive', $resultImport);
        $session = $this->legacySession($resultImport->getRawOriginal('offline_scanner_session_id'));

        $validated = $request->validate([
            'archive_reason' => ['required', 'string', 'max:2000'],
            'confirm_archive' => ['accepted'],
        ], [
            'archive_reason.required' => 'Provide an archive reason.',
            'confirm_archive.accepted' => 'Confirm that this offline scanner results file should be archived.',
        ]);

        try {
            $archives->archive($resultImport, $request->user(), session('selected_event_id'), $validated['archive_reason']);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            return back()->withErrors(['offline_results' => 'Unable to archive the offline scanner results file.'])->withInput($request->only('archive_reason'));
        }

        return back()->with('success', 'Offline scanner results file archived according to the retention policy.');
    }

    public function destroy(
        Request $request,
        OfflineScannerResultImport $resultImport,
        LegacyOfflineSecureDeletion $deletions,
    ): RedirectResponse {
        $resultImport = $this->legacyResultImport($resultImport);
        Gate::authorize('secureDelete', $resultImport);
        $session = $this->legacySession($resultImport->getRawOriginal('offline_scanner_session_id'));

        $validated = $request->validate([
            'deletion_reason' => ['required', 'string', 'max:2000'],
            'confirm_secure_delete' => ['accepted'],
        ], [
            'deletion_reason.required' => 'Provide a secure deletion reason.',
            'confirm_secure_delete.accepted' => 'Confirm secure deletion of this offline scanner results file.',
        ]);

        try {
            $deletions->delete($resultImport, $request->user(), session('selected_event_id'), $validated['deletion_reason']);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (RuntimeException) {
            return back()->withErrors(['offline_results' => 'Unable to delete encrypted offline scanner file.']);
        }

        return back()->with('success', 'Offline scanner results file was securely deleted and audited.');
    }
}
