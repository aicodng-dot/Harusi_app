<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;

/**
 * Shared by Event Setup Progress (Working Flow 11) and Event Dashboard (Working Flow 12).
 *
 * The URL event id is never trusted: the event must belong to a workspace where the
 * current account still holds an active owner/manager membership, decided by the
 * approved WorkspaceAccess `event.view` permission. Unknown and foreign events get
 * the same 404, so existence is never revealed.
 */
trait AuthorizesCustomerEvent
{
    protected function authorizedCustomer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasSafeIdentityId() && $user->isCustomer() && $user->canAuthenticate(), 403);

        return $user;
    }

    protected function authorizedEvent(Connection $connection, User $user, string $event, WorkspaceAccess $access): object
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,18}\z/', $event) === 1, 404);

        $row = $connection->table('events')->useWritePdo()
            ->select(['id', 'event_name', 'venue_name', 'event_date', 'event_time', 'event_type', 'invitation_template', 'status', 'workspace_id'])
            ->where('id', (int) $event)->first();
        abort_if($row === null || $row->workspace_id === null, 404);
        abort_unless($access->allows($connection, (int) $user->getKey(), (int) $row->workspace_id, 'event.view', (int) $row->id), 404);

        return $row;
    }

    protected function privateEventHeaders(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer'];
    }
}
