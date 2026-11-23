<?php

namespace App\Services;

class QrTokenCanonicalizer
{
    public function canonicalize(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $query = parse_url($value, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            if (! empty($params['token']) && is_string($params['token'])) {
                return trim($params['token']);
            }
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (is_string($path) && str_contains($path, '/')) {
            $segments = array_values(array_filter(explode('/', $path)));
            return trim((string) end($segments));
        }

        return $value;
    }

    public function hash(string $value): string
    {
        return hash('sha256', $this->canonicalize($value));
    }
}