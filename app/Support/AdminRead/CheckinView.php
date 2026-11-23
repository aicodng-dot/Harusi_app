<?php

namespace App\Support\AdminRead;

use App\Models\Checkin;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Immutable history row: the attempt's own stored scalars plus current decoration only
 * when it passed the selected-Event lineage rules. No model and no lazy SQL.
 */
final readonly class CheckinView
{
    public function __construct(
        public ?CarbonImmutable $checked_in_at,
        public ?CarbonImmutable $created_at,
        public ?string $scan_result,
        public ?string $gate_name,
        public ?int $entries_added,
        public ?int $used_entries_after_scan,
        public ?int $remaining_entries_after_scan,
        public ?string $ip_address,
        public ?HistoryGuest $guest,
        public ?HistoryUser $user,
    ) {}

    public static function fromModel(Checkin $checkin, ?HistoryGuest $guest = null, ?HistoryUser $user = null): self
    {
        return new self(
            self::instant($checkin->checked_in_at),
            self::instant($checkin->created_at),
            $checkin->getRawOriginal('scan_result'),
            $checkin->getRawOriginal('gate_name'),
            self::integer($checkin->getRawOriginal('entries_added')),
            self::integer($checkin->getRawOriginal('used_entries_after_scan')),
            self::integer($checkin->getRawOriginal('remaining_entries_after_scan')),
            $checkin->getRawOriginal('ip_address'),
            $guest,
            $user,
        );
    }

    private static function instant(mixed $value): ?CarbonImmutable
    {
        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : null;
    }

    private static function integer(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
