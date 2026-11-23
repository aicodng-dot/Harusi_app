<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Guest;
use Illuminate\Support\Carbon;

class GuestSnapshotService
{
    public function __construct(
        private readonly QrTokenCanonicalizer $tokens,
        private readonly CanonicalJson $json,
        private readonly PhoneNumberService $phoneNumbers,
    ) {
    }

    public function build(Event $event): array
    {
        return Guest::query()
            ->with('qrCode')
            ->where('event_id', $event->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Guest $guest): array => $this->guestPayload($guest))
            ->all();
    }

    public function summary(Event $event): array
    {
        $guests = $this->build($event);
        $manualCodes = [];
        $qrTokenHashes = [];
        $activePasses = 0;
        $fullyUsedPasses = 0;
        $disabledPasses = 0;
        $guestsMissingQrTokens = 0;
        $guestsMissingManualCodes = 0;

        foreach ($guests as $guest) {
            $status = (string) ($guest['guest_status'] ?? '');
            $disabled = (bool) ($guest['admission_disabled'] ?? false);
            $allowedEntries = (int) ($guest['allowed_entries'] ?? 0);
            $usedEntries = (int) ($guest['used_entries'] ?? 0);
            $manualCode = (string) ($guest['manual_code'] ?? '');
            $qrTokenHash = (string) ($guest['qr_token_hash'] ?? '');

            if ($status === Guest::STATUS_FULLY_USED || ($allowedEntries > 0 && $usedEntries >= $allowedEntries)) {
                $fullyUsedPasses++;
            }

            if ($disabled || $status === Guest::STATUS_CANCELLED) {
                $disabledPasses++;
            }

            if (! $disabled && in_array($status, [Guest::STATUS_ACTIVE, Guest::STATUS_PARTIALLY_USED], true)) {
                $activePasses++;
            }

            if ($qrTokenHash === '' || ! preg_match('/^[a-f0-9]{64}$/', $qrTokenHash)) {
                $guestsMissingQrTokens++;
            } else {
                $qrTokenHashes[] = $qrTokenHash;
            }

            if (! preg_match('/^\d{4}$/', $manualCode)) {
                $guestsMissingManualCodes++;
            } else {
                $manualCodes[] = $manualCode;
            }
        }

        $duplicateManualCodes = $this->duplicateValueCount($manualCodes);
        $duplicateQrTokenHashes = $this->duplicateValueCount($qrTokenHashes);
        $criticalErrorCount = $guestsMissingQrTokens
            + $guestsMissingManualCodes
            + $duplicateManualCodes
            + $duplicateQrTokenHashes;

        return [
            'total_guests' => count($guests),
            'active_passes' => $activePasses,
            'fully_used_passes' => $fullyUsedPasses,
            'disabled_passes' => $disabledPasses,
            'guests_missing_qr_tokens' => $guestsMissingQrTokens,
            'guests_missing_manual_codes' => $guestsMissingManualCodes,
            'duplicate_manual_codes' => $duplicateManualCodes,
            'duplicate_qr_token_hashes' => $duplicateQrTokenHashes,
            'critical_error_count' => $criticalErrorCount,
            'has_critical_errors' => $criticalErrorCount > 0,
        ];
    }

    public function version(array $guests): string
    {
        return hash('sha256', $this->json->encode($guests));
    }

    private function guestPayload(Guest $guest): array
    {
        $allowedEntries = (int) $guest->allowed_entries;
        $usedEntries = (int) $guest->used_entries;
        $remainingEntries = $allowedEntries - $usedEntries;
        $qrToken = (string) $guest->qrCode?->qr_token;
        $updatedAt = $this->dateTime($guest->updated_at);

        $payload = [
            'external_guest_id' => (string) $guest->id,
            'event_id' => (int) $guest->event_id,
            'display_name' => (string) $guest->name,
            'normalized_phone' => $this->phoneNumbers->normalize((string) $guest->phone_number) ?? (string) $guest->phone_number,
            'manual_code' => (string) $guest->manual_code,
            'pass_type' => (string) $guest->pass_type,
            'allowed_entries' => $allowedEntries,
            'used_entries' => $usedEntries,
            'remaining_entries' => $remainingEntries,
            'guest_status' => (string) $guest->status,
            'admission_disabled' => $guest->status === Guest::STATUS_CANCELLED
                || ! $guest->qrCode
                || ! $guest->qrCode->is_active
                || $guest->qrCode->revoked_at !== null,
            'qr_token_hash' => $qrToken === '' ? '' : $this->tokens->hash($qrToken),
            'updated_at' => $updatedAt,
        ];

        $payload['record_version'] = hash('sha256', $this->json->encode($payload));

        return $payload;
    }

    private function duplicateValueCount(array $values): int
    {
        $counts = [];

        foreach ($values as $value) {
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        return array_reduce($counts, fn (int $total, int $count): int => $total + max(0, $count - 1), 0);
    }

    private function dateTime(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toJSON();
        }

        return $value ? Carbon::parse($value)->toJSON() : null;
    }
}