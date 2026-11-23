<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Guest;
use Illuminate\Validation\ValidationException;

class OfflineScannerPackageValidator
{
    public function __construct(private readonly PhoneNumberService $phoneNumbers)
    {
    }

    public function validateGuestSnapshot(Event $event, array $guests): void
    {
        $errors = [];
        $guestIds = [];
        $manualCodes = [];
        $qrHashes = [];

        foreach ($guests as $index => $guest) {
            $label = 'Guest row '.($index + 1);
            $externalGuestId = (string) ($guest['external_guest_id'] ?? '');
            $manualCode = (string) ($guest['manual_code'] ?? '');
            $qrHash = (string) ($guest['qr_token_hash'] ?? '');
            $allowed = $guest['allowed_entries'] ?? null;
            $used = $guest['used_entries'] ?? null;
            $remaining = $guest['remaining_entries'] ?? null;
            $status = (string) ($guest['guest_status'] ?? '');
            $phone = (string) ($guest['normalized_phone'] ?? '');

            if ((int) ($guest['event_id'] ?? 0) !== (int) $event->id) {
                $errors[] = "$label does not belong to the selected event.";
            }

            if ($externalGuestId === '') {
                $errors[] = "$label is missing an external guest ID.";
            } elseif (isset($guestIds[$externalGuestId])) {
                $errors[] = "$label duplicates guest ID $externalGuestId.";
            }
            $guestIds[$externalGuestId] = true;

            if ($qrHash === '' || ! preg_match('/^[a-f0-9]{64}$/', $qrHash)) {
                $errors[] = "$label is missing a secure QR token hash.";
            } elseif (isset($qrHashes[$qrHash])) {
                $errors[] = "$label duplicates a QR token hash.";
            }
            $qrHashes[$qrHash] = true;

            if (! preg_match('/^\d{4}$/', $manualCode)) {
                $errors[] = "$label has an invalid manual code.";
            } elseif (isset($manualCodes[$manualCode])) {
                $errors[] = "$label duplicates manual code $manualCode.";
            }
            $manualCodes[$manualCode] = true;

            if (! is_int($allowed) || $allowed < 0) {
                $errors[] = "$label has invalid allowed entries.";
            }

            if (! is_int($used) || $used < 0) {
                $errors[] = "$label has invalid used entries.";
            }

            if (is_int($allowed) && is_int($used) && $used > $allowed) {
                $errors[] = "$label has used entries greater than allowed entries.";
            }

            if (is_int($allowed) && is_int($used) && $remaining !== $allowed - $used) {
                $errors[] = "$label has an invalid remaining entry count.";
            }

            if (! in_array($status, [
                Guest::STATUS_ACTIVE,
                Guest::STATUS_PARTIALLY_USED,
                Guest::STATUS_FULLY_USED,
                Guest::STATUS_CANCELLED,
            ], true)) {
                $errors[] = "$label has an invalid guest status.";
            }

            if (! array_key_exists('admission_disabled', $guest) || ! is_bool($guest['admission_disabled'])) {
                $errors[] = "$label is missing a disabled/admission flag.";
            }

            if ($status === Guest::STATUS_CANCELLED && ($guest['admission_disabled'] ?? null) !== true) {
                $errors[] = "$label is cancelled but is not marked disabled.";
            }

            if ($this->phoneNumbers->normalize($phone) !== $phone) {
                $errors[] = "$label has a phone number outside the approved normalized format.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['offline_package' => $errors]);
        }
    }
}