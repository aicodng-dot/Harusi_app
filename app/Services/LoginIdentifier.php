<?php

namespace App\Services;

class LoginIdentifier
{
    public function __construct(private readonly PhoneNumberService $phones) {}

    /** @return array{email: string}|array{phone_number: string}|null */
    public function resolve(mixed $value): ?array
    {
        if (! is_string($value) || strlen($value) > 255) {
            return null;
        }

        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            // Preserve existing email storage and database comparison semantics.
            return ['email' => $value];
        }

        $phone = $this->phones->normalize($value);

        return $phone === null ? null : ['phone_number' => $phone];
    }

    public function rateLimitKey(mixed $value): string
    {
        $identity = $this->resolve($value);

        if ($identity !== null) {
            $field = array_key_first($identity);
            $canonical = $field.':'.strtolower($identity[$field]);
        } else {
            $canonical = 'invalid:'.(is_string($value) ? strtolower(trim(substr($value, 0, 255))) : 'non-string');
        }

        return hash('sha256', $canonical);
    }
}
