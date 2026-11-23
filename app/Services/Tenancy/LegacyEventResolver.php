<?php

namespace App\Services\Tenancy;

use App\Models\Event;
use Throwable;

class LegacyEventResolver
{
    public function find(mixed $id): ?Event
    {
        $id = $this->canonicalId($id);
        if ($id === null) {
            return null;
        }

        try {
            $event = Event::query()->useWritePdo()->whereNull('workspace_id')->find($id);

            return $this->matches($event, $id) ? $event : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function soleActive(): ?Event
    {
        try {
            $events = Event::query()->useWritePdo()->whereNull('workspace_id')
                ->whereBetween('id', [1, PHP_INT_MAX])->where('status', Event::STATUS_ACTIVE)
                ->orderBy('id')->limit(2)->get();
            if ($events->count() !== 1) {
                return null;
            }
            $event = $events->first();
            $id = $this->canonicalId($event->getRawOriginal($event->getKeyName()));

            return $id !== null && $this->matches($event, $id)
                && $event->getRawOriginal('status') === Event::STATUS_ACTIVE ? $event : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function matches(?Event $event, int $id): bool
    {
        return $event !== null && $event->getRawOriginal('workspace_id') === null
            && $this->canonicalId($event->getRawOriginal($event->getKeyName())) === $id;
    }

    private function canonicalId(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }
        if (! is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1) {
            return null;
        }
        $parsed = filter_var($id, FILTER_VALIDATE_INT);

        return $parsed === false ? null : $parsed;
    }
}
