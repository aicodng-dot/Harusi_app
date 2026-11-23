<?php

namespace App\Policies;

use App\Models\OfflineScannerImportConflict;
use App\Models\OfflineScannerSession;
use App\Models\User;

class OfflineScannerSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, OfflineScannerSession $session): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, OfflineScannerSession $session): bool
    {
        return $user->isAdmin();
    }

    public function generatePackage(User $user, OfflineScannerSession|string|null $session = null): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        if (! $session instanceof OfflineScannerSession) {
            return true;
        }

        return $session->package_export_id !== null;
    }

    public function download(User $user, OfflineScannerSession $session): bool
    {
        return $user->isAdmin() && $session->package_export_id !== null;
    }

    public function cancel(User $user, OfflineScannerSession $session): bool
    {
        return $user->isAdmin()
            && $session->isBlockingAdmissionChanges()
            && $session->results_imported_at === null;
    }

    public function close(User $user, OfflineScannerSession $session): bool
    {
        return $user->isAdmin()
            && $session->status !== OfflineScannerSession::STATUS_CLOSED
            && $session->status !== OfflineScannerSession::STATUS_CANCELLED
            && ($session->results_imported_at !== null || $session->status === OfflineScannerSession::STATUS_RESULTS_IMPORTED)
            && ! $session->importConflicts()
                ->where('resolution_status', OfflineScannerImportConflict::RESOLUTION_OPEN)
                ->exists();
    }

    public function delete(User $user, OfflineScannerSession $session): bool
    {
        return false;
    }
}