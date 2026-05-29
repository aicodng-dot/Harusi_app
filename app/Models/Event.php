<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Event extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'event_name',
        'bride_name',
        'groom_name',
        'venue_name',
        'event_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
        ];
    }

    public static function defaultEvent(): self
    {
        return self::query()->firstOrCreate(
            ['event_name' => 'Default Wedding Event'],
            ['status' => self::STATUS_ACTIVE]
        );
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_ARCHIVED,
            self::STATUS_CANCELLED,
        ];
    }

    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(Checkin::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function scanLogs(): HasMany
    {
        return $this->hasMany(ScanLog::class);
    }

    public function scannerUsers(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function displayCouple(): string
    {
        $names = array_values(array_filter([$this->bride_name, $this->groom_name]));

        return $names === [] ? '' : implode(' & ', $names);
    }

    public function safeSlug(): string
    {
        $slug = (string) Str::of($this->event_name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(60, '');

        return $slug !== '' ? $slug : 'event_'.$this->id;
    }
}
