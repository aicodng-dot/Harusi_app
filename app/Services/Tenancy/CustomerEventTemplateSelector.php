<?php

namespace App\Services\Tenancy;

use App\Models\Event;
use App\Services\Events\InvitationTemplates;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Working Flow 14: stores the chosen invitation template on one authorized event.
 *
 * Follows the CustomerEventCreator conventions: the account row is locked first, then
 * the workspace, membership and event rows; authority, ownership and lifecycle are
 * decided from those locked rows inside the same transaction as the single update.
 * Re-submitting the same choice is harmless (idempotent). Raw errors never surface.
 */
class CustomerEventTemplateSelector
{
    public function select(Connection $connection, mixed $userId, mixed $eventId, mixed $template): void
    {
        if (! is_int($userId) || $userId < 1 || ! is_int($eventId) || $eventId < 1 || ! InvitationTemplates::isValid($template)) {
            throw CustomerEventCreationException::because('invalid_input');
        }

        try {
            $connection->transaction(function () use ($connection, $userId, $eventId, $template): void {
                $accounts = $connection->table('users')->useWritePdo()->select(['id', 'role', 'is_active'])
                    ->where('id', $userId)->orderBy('id')->limit(2)->lockForUpdate()->get();
                if ($accounts->count() !== 1 || (int) $accounts->first()->id !== $userId
                    || $accounts->first()->role !== 'customer' || ! $this->isTrue($accounts->first()->is_active)) {
                    throw CustomerEventCreationException::because('creator_unavailable');
                }

                $events = $connection->table('events')->useWritePdo()->select(['id', 'workspace_id', 'status'])
                    ->where('id', $eventId)->orderBy('id')->limit(2)->lockForUpdate()->get();
                $event = $events->first();
                if ($events->count() !== 1 || $event->workspace_id === null) {
                    throw CustomerEventCreationException::because('workspace_unavailable');
                }
                $workspaceId = (int) $event->workspace_id;

                $workspaces = $connection->table('workspaces')->useWritePdo()->select('id')
                    ->where('id', $workspaceId)->orderBy('id')->limit(2)->lockForUpdate()->get();
                $members = $connection->table('workspace_users')->useWritePdo()->select(['id', 'workspace_id', 'user_id', 'role', 'is_active'])
                    ->where('workspace_id', $workspaceId)->where('user_id', $userId)->orderBy('id')->limit(2)->lockForUpdate()->get();
                $member = $members->first();
                if ($workspaces->count() !== 1 || $members->count() !== 1 || (int) $member->user_id !== $userId
                    || (int) $member->workspace_id !== $workspaceId || ! in_array($member->role, ['owner', 'manager'], true)
                    || ! $this->isTrue($member->is_active)) {
                    throw CustomerEventCreationException::because('workspace_unavailable');
                }
                // Archived and cancelled events stay read-only.
                if ($event->status !== Event::STATUS_ACTIVE) {
                    throw CustomerEventCreationException::because('event_locked');
                }

                $connection->table('events')->where('id', $eventId)->where('workspace_id', $workspaceId)
                    ->update(['invitation_template' => $template, 'updated_at' => now()]);
            });
        } catch (CustomerEventCreationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CustomerEventCreationException::because('database_unavailable');
        }
    }

    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
