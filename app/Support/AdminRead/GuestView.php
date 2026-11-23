<?php

namespace App\Support\AdminRead;

use App\Models\Guest;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Immutable Guest row for family-3 administrator views: only the fields those views
 * show, with no model and no lazy SQL. It builds route URLs from its frozen ID.
 */
final readonly class GuestView implements UrlRoutable
{
    public bool $exists;

    /** @param Collection<int, CheckinView> $checkins */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $phone_number,
        public ?string $manual_code,
        public string $pass_type,
        public int $allowed_entries,
        public int $used_entries,
        public string $status,
        public ?QrView $qrCode,
        public int $checkins_count,
        public Collection $checkins,
        private string $passLabel,
        private int $remaining,
    ) {
        $this->exists = true;
    }

    /** Values come from a Guest already read inside the current read attempt. */
    public static function fromModel(Guest $guest, int $id, ?QrView $qr, int $checkinsCount = 0, ?Collection $checkins = null): self
    {
        return new self(
            $id,
            (string) $guest->getRawOriginal('name'),
            $guest->getRawOriginal('phone_number'),
            $guest->getRawOriginal('manual_code'),
            (string) $guest->getRawOriginal('pass_type'),
            (int) $guest->getRawOriginal('allowed_entries'),
            (int) $guest->getRawOriginal('used_entries'),
            (string) $guest->getRawOriginal('status'),
            $qr,
            $checkinsCount,
            $checkins ?? new Collection,
            $guest->passTypeLabel(),
            $guest->remainingEntries(),
        );
    }

    public function passTypeLabel(): string
    {
        return $this->passLabel;
    }

    public function remainingEntries(): int
    {
        return $this->remaining;
    }

    public function getRouteKey(): int
    {
        return $this->id;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null): never
    {
        throw new LogicException('A frozen Guest view cannot resolve route bindings.');
    }

    public function resolveChildRouteBinding($childType, $value, $field): never
    {
        throw new LogicException('A frozen Guest view cannot resolve route bindings.');
    }
}
