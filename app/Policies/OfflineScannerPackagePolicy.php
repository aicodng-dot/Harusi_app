<?php

namespace App\Policies;

use App\Models\OfflineScannerPackage;
use App\Models\User;

class OfflineScannerPackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, OfflineScannerPackage $package): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, OfflineScannerPackage $package): bool
    {
        return $user->isAdmin();
    }

    public function download(User $user, OfflineScannerPackage $package): bool
    {
        return $user->isAdmin()
            && $package->revoked_at === null
            && $package->secure_deleted_at === null
            && $package->file_path !== null;
    }

    public function archive(User $user, OfflineScannerPackage $package): bool
    {
        return $user->isAdmin()
            && $package->secure_deleted_at === null
            && $package->archived_at === null;
    }

    public function secureDelete(User $user, OfflineScannerPackage $package): bool
    {
        return $user->isAdmin()
            && (bool) config('offline_scanner.retention.allow_secure_deletion', false)
            && $package->secure_deleted_at === null;
    }

    public function delete(User $user, OfflineScannerPackage $package): bool
    {
        return $this->secureDelete($user, $package);
    }
}