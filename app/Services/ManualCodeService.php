<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

class ManualCodeService
{
    private const MAX_CODES = 10000;

    public function generate(?int $eventId = null): string
    {
        if (! $this->hasAvailableCode($eventId)) {
            throw new RuntimeException('No available manual codes remain for this event.');
        }

        do {
            $code = $this->format(random_int(0, 9999));
        } while ($this->exists($code, $eventId));

        return $code;
    }

    public function format(int $number): string
    {
        if ($number < 0 || $number > 9999) {
            throw new InvalidArgumentException('Manual code number must be between 0 and 9999.');
        }

        return str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public function exists(string $code, ?int $eventId = null): bool
    {
        $query = DB::table('guests')->where('manual_code', $code);

        if (Schema::hasColumn('guests', 'event_id')) {
            $eventId === null
                ? $query->whereNull('event_id')
                : $query->where('event_id', $eventId);
        }

        return $query->exists();
    }

    private function hasAvailableCode(?int $eventId): bool
    {
        $query = DB::table('guests')
            ->whereNotNull('manual_code')
            ->where('manual_code', '<>', '');

        if (Schema::hasColumn('guests', 'event_id')) {
            $eventId === null
                ? $query->whereNull('event_id')
                : $query->where('event_id', $eventId);
        }

        return (clone $query)->distinct()->count('manual_code') < self::MAX_CODES;
    }
}
