<?php

namespace App\Support\AdminLayout;

/** Immutable creator label for the offline banner: the display name only. */
final readonly class LayoutCreator
{
    public function __construct(public string $name) {}
}
