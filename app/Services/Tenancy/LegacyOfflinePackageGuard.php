<?php

namespace App\Services\Tenancy;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Used only within the package builder's existing Event/User transaction. */
class LegacyOfflinePackageGuard
{
    public function lock(Event $event, User $actor, array $input): array
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Offline package checks require a transaction.');
        }
        $eventId = $this->id($event->getRawOriginal($event->getKeyName()));
        abort_unless($eventId !== null, 404);
        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = $this->id($actor->getAttributes()[$actor->getKeyName()] ?? null);
        abort_unless($actorId !== null && $actorId === $this->id($actor->getRawOriginal($actor->getKeyName())), 403);
        $masterId = $this->id($input['master_scanner_user_id'] ?? null);
        if ($masterId === null) {
            $this->scannerUnavailable();
        }
        $clients = $input['clients'] ?? [];
        if (! is_array($clients)) {
            throw new InvalidArgumentException('Client scanner users must be unique and cannot include the Master scanner.');
        }
        $parsed = [];
        $seen = [$masterId => true];
        foreach ($clients as $client) {
            $id = is_array($client) ? $this->id($client['scanner_user_id'] ?? null) : null;
            if ($id === null || isset($seen[$id])) {
                throw new InvalidArgumentException('Client scanner users must be unique and cannot include the Master scanner.');
            }
            $gate = $client['assigned_gate'] ?? null;
            if (! is_string($gate) || trim($gate) === '') {
                throw new InvalidArgumentException('Assign a gate for every Client scanner user.');
            }
            $parsed[] = ['id' => $id, 'assigned_gate' => trim($gate)];
            $seen[$id] = true;
        }

        // Every generation follows Event, sorted Users, then memberships. This
        // follows staff writers and avoids acquiring another Event after a User.
        $lockedEvent = Event::query()->useWritePdo()->whereKey($eventId)->lockForUpdate()->first();
        abort_unless($lockedEvent instanceof Event && $this->id($lockedEvent->getRawOriginal($lockedEvent->getKeyName())) === $eventId
            && $lockedEvent->getRawOriginal('workspace_id') === null, 404);
        $userIds = array_values(array_unique([$actorId, $masterId, ...array_column($parsed, 'id')]));
        usort($userIds, static fn (int $left, int $right): int => $left <=> $right);
        $users = [];
        foreach ($userIds as $id) {
            $users[$id] = User::query()->useWritePdo()->whereKey($id)->lockForUpdate()->first();
        }
        $lockedActor = $users[$actorId];
        abort_unless($lockedActor instanceof User && $this->id($lockedActor->getRawOriginal($lockedActor->getKeyName())) === $actorId
            && $lockedActor->getRawOriginal('role') === User::ROLE_ADMIN && $this->isTrue($lockedActor->getRawOriginal('is_active')), 403);
        foreach (array_keys($seen) as $id) {
            $scanner = $users[$id];
            if (! $scanner instanceof User || $this->id($scanner->getRawOriginal($scanner->getKeyName())) !== $id
                || $scanner->getRawOriginal('role') !== User::ROLE_SCANNER || ! $this->isTrue($scanner->getRawOriginal('is_active'))
                || ! in_array($scanner->getRawOriginal('is_platform_admin'), [false, 0, '0'], true)
                || $this->id($scanner->getRawOriginal('event_id')) !== $eventId) {
                $this->scannerUnavailable();
            }
            // A current locking read, not a repeatable-read snapshot exists().
            if ($connection->table('workspace_users')->useWritePdo()->where('user_id', $id)->lockForUpdate()->get(['id'])->isNotEmpty()) {
                $this->scannerUnavailable();
            }
        }

        return ['event' => $lockedEvent, 'actor' => $lockedActor, 'master' => $users[$masterId],
            'clients' => array_map(static fn (array $client): array => [
                'user' => $users[$client['id']], 'assigned_gate' => $client['assigned_gate'],
            ], $parsed)];
    }

    private function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id === false ? null : $id;
    }

    private function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    private function scannerUnavailable(): never
    {
        throw new InvalidArgumentException('Choose a valid scanner user assigned to this event.');
    }
}
