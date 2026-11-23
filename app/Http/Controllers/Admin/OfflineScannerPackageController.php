<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesOfflineScannerAdmin;
use App\Http\Controllers\Controller;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerSession;
use App\Services\Tenancy\LegacyOfflineFileArchives;
use App\Services\Tenancy\LegacyOfflinePackageDelivery;
use App\Services\Tenancy\LegacyOfflineSecureDeletion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class OfflineScannerPackageController extends Controller
{
    use HandlesOfflineScannerAdmin;

    public function generate(
        Request $request,
        OfflineScannerSession $offlineScannerSession,
        LegacyOfflinePackageDelivery $delivery,
    ): Response|RedirectResponse {
        $offlineScannerSession = $this->legacySession($offlineScannerSession);
        Gate::authorize('generatePackage', $offlineScannerSession);

        $package = $this->legacyPackage($offlineScannerSession->getRawOriginal('package_export_id'));
        abort_unless((string) $package->getRawOriginal('offline_scanner_session_id') === (string) $offlineScannerSession->getRawOriginal($offlineScannerSession->getKeyName()), 404);
        Gate::authorize('download', $package);

        $request->validate([
            'confirm_package_download' => ['nullable', 'accepted'],
        ]);

        return $delivery->download($package, $request->user(), session('selected_event_id'), $offlineScannerSession);
    }

    public function download(
        Request $request,
        OfflineScannerPackage $offlineScannerPackage,
        LegacyOfflinePackageDelivery $delivery,
    ): Response {
        $offlineScannerPackage = $this->legacyPackage($offlineScannerPackage);
        Gate::authorize('download', $offlineScannerPackage);
        $session = $this->legacySession($offlineScannerPackage->getRawOriginal('offline_scanner_session_id'));

        return $delivery->download($offlineScannerPackage, $request->user(), session('selected_event_id'));
    }

    public function archive(
        Request $request,
        OfflineScannerPackage $offlineScannerPackage,
        LegacyOfflineFileArchives $archives,
    ): RedirectResponse {
        $offlineScannerPackage = $this->legacyPackage($offlineScannerPackage);
        Gate::authorize('archive', $offlineScannerPackage);
        $session = $this->legacySession($offlineScannerPackage->getRawOriginal('offline_scanner_session_id'));

        $validated = $request->validate([
            'archive_reason' => ['required', 'string', 'max:2000'],
            'confirm_archive' => ['accepted'],
        ], [
            'archive_reason.required' => 'Provide an archive reason.',
            'confirm_archive.accepted' => 'Confirm that this offline scanner package file should be archived.',
        ]);

        try {
            $archives->archive($offlineScannerPackage, $request->user(), session('selected_event_id'), $validated['archive_reason']);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            return back()->withErrors(['offline_package' => 'Unable to archive the offline scanner package.'])->withInput($request->only('archive_reason'));
        }

        return back()->with('success', 'Offline scanner package file archived according to the retention policy.');
    }

    public function destroy(
        Request $request,
        OfflineScannerPackage $offlineScannerPackage,
        LegacyOfflineSecureDeletion $deletions,
    ): RedirectResponse {
        $offlineScannerPackage = $this->legacyPackage($offlineScannerPackage);
        Gate::authorize('secureDelete', $offlineScannerPackage);
        $session = $this->legacySession($offlineScannerPackage->getRawOriginal('offline_scanner_session_id'));

        $validated = $request->validate([
            'deletion_reason' => ['required', 'string', 'max:2000'],
            'confirm_secure_delete' => ['accepted'],
        ], [
            'deletion_reason.required' => 'Provide a secure deletion reason.',
            'confirm_secure_delete.accepted' => 'Confirm secure deletion of this offline scanner package file.',
        ]);

        try {
            $deletions->delete($offlineScannerPackage, $request->user(), session('selected_event_id'), $validated['deletion_reason']);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (RuntimeException) {
            return back()->withErrors(['offline_package' => 'Unable to delete encrypted offline scanner file.']);
        }

        return back()->with('success', 'Offline scanner package file was securely deleted and audited.');
    }
}
