<?php

namespace App\Support\AdminLayout;

/** Immutable selected-Event or switcher entry for the admin layout; no model and no lazy SQL. */
final readonly class LayoutEvent
{
    public function __construct(
        public int $id,
        public string $event_name,
        public ?int $workspace_id = null,
    ) {}
}
