<?php

namespace App\Support\AdminRead;

use App\Models\Event;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Immutable selected-Event summary for the dashboard; no model and no lazy SQL. */
final readonly class EventSummary
{
    public function __construct(
        public int $id,
        public ?string $event_name,
        public ?string $venue_name,
        public ?CarbonImmutable $event_date,
        public ?string $status,
        private string $couple,
    ) {}

    public static function fromModel(Event $event, int $id): self
    {
        return new self(
            $id,
            $event->getRawOriginal('event_name'),
            $event->getRawOriginal('venue_name'),
            $event->event_date instanceof DateTimeInterface ? CarbonImmutable::instance($event->event_date) : null,
            $event->getRawOriginal('status'),
            $event->displayCouple(),
        );
    }

    public function displayCouple(): string
    {
        return $this->couple;
    }
}
