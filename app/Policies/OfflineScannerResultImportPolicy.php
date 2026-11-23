<?php

namespace App\Policies;

use App\Models\OfflineScannerResultImport;
use App\Models\User;

class OfflineScannerResultImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, OfflineScannerResultImport $resultImport): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function upload(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, OfflineScannerResultImport $resultImport): bool
    {
        return $user->isAdmin();
    }

    public function confirmImport(User $user, OfflineScannerResultImport $resultImport): bool
    {
        return $this->importResults($user, $resultImport);
    }

    public function importResults(User $user, OfflineScannerResultImport $resultImport): bool
    {
        return $user->isAdmin()
            && $resultImport->status === OfflineScannerResultImport::STATUS_PREVIEW_READY;
    }

    public function archive(User $user, OfflineScannerResultImport $resultImport): bool
    {
        return $user->isAdmin()
            && $resultImport->secure_deleted_at === null
            && $resultImport->archived_at === null;
    }

    public function secureDelete(User $user, OfflineScannerResultImport $resultImport): bool
    {
        return $user->isAdmin()
            && (bool) config('offline_scanner.retention.allow_secure_deletion', false)
            && $resultImport->secure_deleted_at === null;
    }

    public function delete(User $user, OfflineScannerResultImport $resultImport): bool
    {
        return $this->secureDelete($user, $resultImport);
    }
}