<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Shared by Choose Event Type (Working Flow 09) and Event Details (Working Flow 10):
 * the eligible workspaces and the `customer_event_setup` session state, which only
 * counts for this account, a supported type and a still-authorized workspace.
 */
trait ResolvesCustomerEventSetup
{
    protected static string $setupKey = 'customer_event_setup';

    /** @var list<string> */
    protected static array $eventTypes = ['wedding', 'send_off', 'kitchen_party', 'other'];

    protected function eligibleWorkspaces(Connection $connection, User $user, WorkspaceAccess $access): Collection
    {
        $rows = $connection->table('workspaces as w')->useWritePdo()
            ->join('workspace_users as m', 'm.workspace_id', '=', 'w.id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('u.id', $user->getKey())->where('u.role', User::ROLE_CUSTOMER)->where('u.is_active', true)
            ->where('m.user_id', $user->getKey())->where('m.is_active', true)->whereIn('m.role', ['owner', 'manager'])
            ->orderBy('w.name')->orderBy('w.id')
            ->limit(50)
            ->get(['w.id', 'w.name']);

        return $rows->map(fn (object $row) => (object) ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->filter(fn (object $row) => $access->allows($connection, (int) $user->getKey(), $row->id, 'event.create'))
            ->values();
    }

    /** A valid setup for this account and an eligible workspace, or [] (stale state is discarded). */
    protected function setupState(Request $request, User $user, Collection $workspaces): array
    {
        $setup = $request->session()->get(self::$setupKey);
        if (! is_array($setup) || ($setup['user_id'] ?? null) !== (int) $user->getKey()
            || $this->validType($setup['event_type'] ?? null) === null
            || $this->validWorkspace($setup['workspace_id'] ?? null, $workspaces) === null) {
            if ($setup !== null) {
                $request->session()->forget(self::$setupKey);
            }

            return [];
        }

        return $setup;
    }

    protected function validType(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::$eventTypes, true) ? $value : null;
    }

    protected function validWorkspace(mixed $value, Collection $workspaces): ?int
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1) {
            $id = (int) $value;
        } else {
            return null;
        }

        return $workspaces->contains('id', $id) ? $id : null;
    }

    protected function customer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasSafeIdentityId() && $user->isCustomer() && $user->canAuthenticate(), 403);

        return $user;
    }

    protected function privateHeaders(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer'];
    }
}
