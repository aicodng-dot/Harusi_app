<?php

namespace App\Services;

use InvalidArgumentException;

class PhoneNumberService
{
    public const INVALID_MESSAGE = 'Invalid phone number format.';

    public function normalize(?string $phoneNumber): ?string
    {
        $cleaned = $this->clean($phoneNumber);

        if ($cleaned === '') {
            return null;
        }

        if (preg_match('/^\+255\d{9}$/', $cleaned)) {
            return $cleaned;
        }

        if (preg_match('/^255\d{9}$/', $cleaned)) {
            return '+'.$cleaned;
        }

        if (preg_match('/^0\d{9}$/', $cleaned)) {
            return '+255'.substr($cleaned, 1);
        }

        if (preg_match('/^[1-9]\d{8}$/', $cleaned)) {
            return '+255'.$cleaned;
        }

        return null;
    }

    public function normalizeOrFail(?string $phoneNumber): string
    {
        $normalized = $this->normalize($phoneNumber);

        if ($normalized === null) {
            throw new InvalidArgumentException(self::INVALID_MESSAGE);
        }

        return $normalized;
    }

    private function clean(?string $phoneNumber): string
    {
        $phoneNumber = trim((string) $phoneNumber);
        $phoneNumber = preg_replace('/[\s\-\(\)\[\]\{\}]/', '', $phoneNumber) ?? '';

        return $this->expandScientificNotation($phoneNumber);
    }

    private function expandScientificNotation(string $value): string
    {
        if (! preg_match('/^(\+?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/', $value, $matches)) {
            return $value;
        }

        $exponentText = ltrim($matches[4], '+-');
        if (strlen($exponentText) > 3) {
            return $value;
        }

        $exponent = (int) $matches[4];
        $digits = $matches[2].($matches[3] ?? '');
        $decimalPosition = strlen($matches[2]) + $exponent;

        if ($exponent < 0 || $decimalPosition < strlen($digits) || $decimalPosition > 15) {
            return $value;
        }

        $expanded = $matches[1].$digits.str_repeat('0', $decimalPosition - strlen($digits));

        return $matches[1].(ltrim(substr($expanded, strlen($matches[1])), '0') ?: '0');
    }
}