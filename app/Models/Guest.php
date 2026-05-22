<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Guest extends Model
{
    use HasFactory;

    public const PASS_SINGLE = 'single';
    public const PASS_DOUBLE = 'double';
    public const PASS_SPECIAL = 'special';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PARTIALLY_USED = 'partially_used';
    public const STATUS_FULLY_USED = 'fully_used';
    public const STATUS_CANCELLED = 'cancelled';

    // Backward-compatible aliases from the earlier prototype.
    public const PASS_FAMILY = self::PASS_SPECIAL;
    public const STATUS_REVOKED = self::STATUS_CANCELLED;

    protected $fillable = [
        'name',
        'phone_number',
        'pass_type',
        'allowed_entries',
        'used_entries',
        'status',
    ];

    protected static function booted(): void
    {
        static::saving(function (Guest $guest): void {
            $guest->applyPassRules();
            $guest->applyUsageStatus();
        });

        static::saved(function (Guest $guest): void {
            if ($guest->relationLoaded('qrCode') && $guest->qrCode && ! $guest->qrCode->exists) {
                $guest->qrCode()->save($guest->qrCode);
            }
        });
    }

    public static function makeSecureToken(): string
    {
        return Str::random(64);
    }

    public static function allowedEntriesForPassType(string $passType, int $requestedEntries): int
    {
        return match ($passType) {
            self::PASS_SINGLE => 1,
            self::PASS_DOUBLE => 2,
            self::PASS_SPECIAL => min(10, max(3, $requestedEntries)),
            default => throw new InvalidArgumentException('Invalid pass type.'),
        };
    }

    public function qrCode(): HasOne
    {
        return $this->hasOne(QrCode::class);
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

    public function remainingEntries(): int
    {
        return max(0, $this->allowed_entries - $this->used_entries);
    }

    public function canAdmit(int $entries): bool
    {
        return $this->status !== self::STATUS_CANCELLED
            && $entries >= 1
            && $entries <= 10
            && $entries <= $this->remainingEntries();
    }

    public function passTypeLabel(): string
    {
        return match ($this->pass_type) {
            self::PASS_SINGLE => 'Single pass',
            self::PASS_DOUBLE => 'Double pass',
            self::PASS_SPECIAL => 'Special pass',
            default => ucfirst($this->pass_type),
        };
    }

    public function usageStatus(): string
    {
        return $this->status;
    }

    public function setQrToken(string $token): void
    {
        $this->setRelation('qrCode', new QrCode([
            'qr_token' => $token,
            'is_active' => true,
            'generated_at' => now(),
        ]));
    }

    public function revealQrToken(): string
    {
        return (string) $this->qrCode?->qr_token;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getGuestNameAttribute(): ?string
    {
        return $this->name;
    }

    public function setGuestNameAttribute(string $value): void
    {
        $this->attributes['name'] = $value;
    }

    public function getAllowedAdmissionsAttribute(): int
    {
        return (int) $this->allowed_entries;
    }

    public function setAllowedAdmissionsAttribute(int $value): void
    {
        $this->attributes['allowed_entries'] = $value;
    }

    public function getAdmittedCountAttribute(): int
    {
        return (int) $this->used_entries;
    }

    public function setAdmittedCountAttribute(int $value): void
    {
        $this->attributes['used_entries'] = $value;
    }

    public function remainingAdmissions(): int
    {
        return $this->remainingEntries();
    }

    private function applyPassRules(): void
    {
        $requestedEntries = (int) ($this->allowed_entries ?: 0);
        $this->allowed_entries = self::allowedEntriesForPassType($this->pass_type, $requestedEntries);
        $this->used_entries = min((int) $this->used_entries, $this->allowed_entries);

        if ($this->allowed_entries > 10) {
            throw new InvalidArgumentException('Allowed entries may not exceed 10.');
        }

        if ($this->used_entries > $this->allowed_entries) {
            throw new InvalidArgumentException('Used entries may not exceed allowed entries.');
        }
    }

    private function applyUsageStatus(): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        if ((int) $this->used_entries <= 0) {
            $this->status = self::STATUS_ACTIVE;
            return;
        }

        $this->status = $this->used_entries >= $this->allowed_entries
            ? self::STATUS_FULLY_USED
            : self::STATUS_PARTIALLY_USED;
    }
}
