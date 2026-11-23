<?php

namespace App\Services\Invitations;

use App\Models\Guest;
use App\Models\QrCode;
use Illuminate\Support\Facades\DB;

/**
 * Supplies each guest's existing admission credential to the invitation renderer.
 *
 * - A guest that already has a QR row keeps it: the token is never rotated, edited,
 *   re-derived or duplicated by the invitation workflow.
 * - A guest with no QR row gets one, created exactly like the established issuance
 *   (Guest::makeSecureToken(): 64 random characters from a CSPRNG, checked unique and
 *   protected by the qr_codes.qr_token unique index), inside a transaction that locks
 *   the guest row so concurrent generations cannot create two credentials.
 * - Revoked/inactive credentials and cancelled guests are reported, not reissued:
 *   replacing a credential stays a deliberate administrator action.
 *
 * The QR payload is the scanner's own verification URL, so the existing web and
 * Android scanners validate invitation QR codes without any change.
 */
class InvitationCredentials
{
    public const OK = 'ok';

    public const REVOKED = 'revoked';

    public const CANCELLED = 'cancelled';

    /** @return array{status: string, qr: ?QrCode, created: bool} */
    public function ensure(int $eventId, int $guestId): array
    {
        return DB::transaction(function () use ($eventId, $guestId): array {
            $guest = Guest::query()->useWritePdo()->whereKey($guestId)->where('event_id', $eventId)->lockForUpdate()->first();
            abort_if($guest === null, 404);
            if ($guest->status === Guest::STATUS_CANCELLED) {
                return ['status' => self::CANCELLED, 'qr' => null, 'created' => false];
            }
            $qr = QrCode::query()->useWritePdo()->where('guest_id', $guestId)->orderBy('id')->lockForUpdate()->first();
            if ($qr !== null) {
                return ['status' => $qr->is_active && $qr->revoked_at === null ? self::OK : self::REVOKED, 'qr' => $qr, 'created' => false];
            }

            do {
                $token = Guest::makeSecureToken();
            } while (QrCode::query()->useWritePdo()->where('qr_token', $token)->exists());
            $qr = QrCode::query()->create([
                'guest_id' => $guestId,
                'qr_token' => $token,
                'qr_image_path' => null,
                'is_active' => true,
                'generated_at' => now(),
                'revoked_at' => null,
            ]);

            return ['status' => self::OK, 'qr' => $qr, 'created' => true];
        });
    }

    /** The exact payload the scanners already accept for this token. */
    public static function payload(QrCode $qr): string
    {
        return route('scanner.verify-token', ['token' => $qr->getRawOriginal('qr_token')]);
    }

    /** Fingerprint stored with generated cards (never the token itself). */
    public static function fingerprint(QrCode $qr): string
    {
        return hash('sha256', 'invitation-card|'.$qr->getRawOriginal('qr_token'));
    }
}
