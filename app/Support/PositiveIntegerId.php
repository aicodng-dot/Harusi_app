<?php

namespace App\Support;

final class PositiveIntegerId
{
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }

        $maximum = (string) PHP_INT_MAX;
        $length = strlen($value);

        if ($length > strlen($maximum)
            || ($length === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }

        return (int) $value;
    }
}
