<?php

namespace App\Services\Tenancy;

use App\Models\Event;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Working Flow 10: creates exactly one customer Event inside an authorized workspace.
 *
 * Follows the WorkspaceProvisioner conventions: the account row is locked first,
 * then the workspace and membership rows; authority is decided from those locked
 * rows inside the same transaction as the insert. A one-time request key, stored
 * only as a SHA-256 hash in a unique column, turns retries and concurrent repeats
 * into the same event. Raw database errors are never surfaced.
 */
class CustomerEventCreator
{
    public const TYPES = ['wedding', 'send_off', 'kitchen_party', 'other'];

    private const KEY_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /**
     * @param  array{event_type: string, event_name: string, event_date: string, event_time: string, venue_name: string}  $details
     * @return array{event_id: int, created: bool}
     */
    public function create(Connection $connection, mixed $userId, mixed $workspaceId, mixed $requestKey, array $details): array
    {
        if (! is_int($userId) || $userId < 1 || ! is_int($workspaceId) || $workspaceId < 1
            || ! is_string($requestKey) || preg_match(self::KEY_PATTERN, $requestKey) !== 1 || ! $this->validDetails($details)) {
            throw CustomerEventCreationException::because('invalid_input');
        }
        $keyHash = hash('sha256', "harusi.customer-event.request.v1\0".$userId."\0".$requestKey);

        try {
            return $this->attempt($connection, $userId, $workspaceId, $keyHash, $details);
        } catch (CustomerEventCreationException $exception) {
            throw $exception;
        } catch (UniqueConstraintViolationException) {
            // A concurrent twin committed first: resolve to its event.
            try {
                return $this->attempt($connection, $userId, $workspaceId, $keyHash, $details);
            } catch (CustomerEventCreationException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw CustomerEventCreationException::because('database_unavailable');
            }
        } catch (Throwable) {
            // Do not retain SQL, bindings, names or connection details.
            throw CustomerEventCreationException::because('database_unavailable');
        }
    }

    private function attempt(Connection $connection, int $userId, int $workspaceId, string $keyHash, array $details): array
    {
        return $connection->transaction(function () use ($connection, $userId, $workspaceId, $keyHash, $details): array {
            $accounts = $connection->table('users')->useWritePdo()->select(['id', 'role', 'is_active'])
                ->where('id', $userId)->orderBy('id')->limit(2)->lockForUpdate()->get();
            if ($accounts->count() !== 1 || $this->storedId($accounts->first()->id) !== $userId
                || $accounts->first()->role !== 'customer' || ! $this->isTrue($accounts->first()->is_active)) {
                throw CustomerEventCreationException::because('creator_unavailable');
            }

            $workspaces = $connection->table('workspaces')->useWritePdo()->select('id')
                ->where('id', $workspaceId)->orderBy('id')->limit(2)->lockForUpdate()->get();
            $members = $connection->table('workspace_users')->useWritePdo()->select(['id', 'workspace_id', 'user_id', 'role', 'is_active'])
                ->where('workspace_id', $workspaceId)->where('user_id', $userId)->orderBy('id')->limit(2)->lockForUpdate()->get();
            $member = $members->first();
            if ($workspaces->count() !== 1 || $this->storedId($workspaces->first()->id) !== $workspaceId
                || $members->count() !== 1 || $this->storedId($member->workspace_id) !== $workspaceId
                || $this->storedId($member->user_id) !== $userId || ! in_array($member->role, ['owner', 'manager'], true)
                || ! $this->isTrue($member->is_active)) {
                throw CustomerEventCreationException::because('workspace_unavailable');
            }

            $existing = $connection->table('events')->useWritePdo()->select(['id', 'workspace_id'])
                ->where('creation_key', $keyHash)->orderBy('id')->limit(2)->lockForUpdate()->get();
            if ($existing->count() > 1) {
                throw CustomerEventCreationException::because('request_conflict');
            }
            if ($existing->count() === 1) {
                $eventId = $this->storedId($existing->first()->id);
                if ($eventId === null || $this->storedId($existing->first()->workspace_id) !== $workspaceId) {
                    throw CustomerEventCreationException::because('request_conflict');
                }

                return ['event_id' => $eventId, 'created' => false];
            }

            $timestamp = now();
            $eventId = $connection->table('events')->insertGetId([
                'event_name' => $details['event_name'],
                'bride_name' => null,
                'groom_name' => null,
                'venue_name' => $details['venue_name'],
                'event_date' => $details['event_date'],
                'event_time' => $details['event_time'].':00',
                'event_type' => $details['event_type'],
                'status' => Event::STATUS_ACTIVE,
                'workspace_id' => $workspaceId,
                'creation_key' => $keyHash,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            if ($this->storedId($eventId) === null) {
                throw CustomerEventCreationException::because('database_unavailable');
            }

            return ['event_id' => (int) $eventId, 'created' => true];
        });
    }

    private function validDetails(array $details): bool
    {
        foreach (['event_type', 'event_name', 'event_date', 'event_time', 'venue_name'] as $key) {
            if (! isset($details[$key]) || ! is_string($details[$key]) || $details[$key] === '') {
                return false;
            }
        }

        return in_array($details['event_type'], self::TYPES, true)
            && mb_strlen($details['event_name']) <= 160 && mb_strlen($details['venue_name']) <= 160
            && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $details['event_date']) === 1
            && preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $details['event_time']) === 1;
    }

    private function storedId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1 ? (int) $value : null;
    }

    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
