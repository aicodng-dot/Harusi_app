<?php

namespace App\Services\Tenancy;

use LengthException;
use RuntimeException;

class OwnershipManifestFile
{
    public function read(string $path): string
    {
        if (! $this->isLocalPath($path)) {
            throw new RuntimeException('Manifest unavailable.');
        }
        $resolved = @realpath($path);
        if ($resolved === false || ! $this->isLocalPath($resolved) || ! @is_file($resolved)) {
            throw new RuntimeException('Manifest unavailable.');
        }

        $stream = @fopen($resolved, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Manifest unavailable.');
        }

        try {
            $metadata = @fstat($stream);
            if ($metadata === false || ($metadata['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('Manifest unavailable.');
            }
            if ($metadata['size'] > OwnershipManifest::MAX_BYTES) {
                throw new LengthException('Manifest exceeds limit.');
            }

            // Bound the actual read even if the file grows after fstat.
            $json = @stream_get_contents($stream, OwnershipManifest::MAX_BYTES + 1);
            if ($json === false) {
                throw new RuntimeException('Manifest unavailable.');
            }
            if (strlen($json) > OwnershipManifest::MAX_BYTES) {
                throw new LengthException('Manifest exceeds limit.');
            }

            return $json;
        } finally {
            @fclose($stream);
        }
    }

    private function isLocalPath(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, '//')) {
            return false;
        }

        // Allow Windows drive-qualified paths, while rejecting URL/stream
        // schemes (including file://, php:// and phar://).
        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized) !== 1
            || preg_match('/^[a-z]:\//i', $normalized) === 1;
    }
}
