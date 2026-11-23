<?php

namespace App\Policies;

use App\Models\OfflineScannerImportConflict;
use App\Models\User;

class OfflineScannerImportConflictPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, OfflineScannerImportConflict $conflict): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, OfflineScannerImportConflict $conflict): bool
    {
        return $user->isAdmin();
    }

    public function resolve(User $user, OfflineScannerImportConflict $conflict): bool
    {
        return $user->isAdmin()
            && $conflict->resolution_status === OfflineScannerImportConflict::RESOLUTION_OPEN;
    }

    public function delete(User $user, OfflineScannerImportConflict $conflict): bool
    {
        return false;
    }
}
