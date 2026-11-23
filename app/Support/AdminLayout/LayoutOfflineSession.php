<?php

namespace App\Support\AdminLayout;

use Carbon\CarbonImmutable;

/**
 * Immutable offline-session banner for the admin layout: only the fields the banner
 * shows, with frozen package and creator labels. No model, secret column or lazy SQL.
 */
final readonly class LayoutOfflineSession
{
    public function __construct(
        public int $id,
        public string $session_uuid,
        public string $status,
        public ?CarbonImmutable $package_created_at,
        public ?CarbonImmutable $package_expires_at,
        public ?LayoutPackage $packageExport,
        public ?LayoutCreator $createdBy,
    ) {}
}
