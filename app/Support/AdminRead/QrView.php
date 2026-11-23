<?php

namespace App\Support\AdminRead;

use App\Models\QrCode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Immutable current QR state for one exact Guest. The credential itself never reaches
 * a view: only its display tail, which is all the QR list has ever shown.
 */
final readonly class QrView
{
    public function __construct(
        public bool $is_active,
        public ?CarbonImmutable $generated_at,
        public ?CarbonImmutable $revoked_at,
        public string $qr_token_tail,
    ) {}

    public static function fromModel(QrCode $qr): self
    {
        return new self(
            in_array($qr->getRawOriginal('is_active'), [true, 1, '1'], true),
            self::instant($qr->generated_at),
            self::instant($qr->revoked_at),
            substr((string) $qr->getRawOriginal('qr_token'), -10),
        );
    }

    private static function instant(mixed $value): ?CarbonImmutable
    {
        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : null;
    }
}
