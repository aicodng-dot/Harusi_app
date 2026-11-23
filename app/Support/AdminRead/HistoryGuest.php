<?php

namespace App\Support\AdminRead;

/** Current Guest decoration on a history row, present only for exact selected-Event lineage. */
final readonly class HistoryGuest
{
    public function __construct(
        public string $name,
        public ?string $phone_number,
        private string $passLabel,
    ) {}

    public function passTypeLabel(): string
    {
        return $this->passLabel;
    }
}
