<?php

namespace App\Support\AdminLayout;

use Carbon\CarbonImmutable;

/** Immutable package label for the offline banner: its generation time only. */
final readonly class LayoutPackage
{
    public function __construct(public ?CarbonImmutable $generated_at) {}
}
