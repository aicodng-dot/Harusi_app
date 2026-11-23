<?php

namespace App\Services;

use RuntimeException;

class OfflineScannerPackageCryptoService
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly CanonicalJson $json)
    {
    }

    public function encryptPayload(array $payload, string $password): array
    {
        $iterations = (int) config('services.offline_scanner_package.pbkdf2_iterations', 210000);
        $salt = random_bytes(32);
        $iv = random_bytes(12);
        $key = $this->deriveKey($password, $salt, $iterations);
        $tag = '';
        $payloadJson = $this->json->encode($payload);

        $ciphertext = openssl_encrypt($payloadJson, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt offline scanner package payload.');
        }

        return [
            'format' => 'harusi.offline-scanner.package',
            'schema_version' => (string) $payload['schema_version'],
            'package_export_id' => (string) $payload['package_export_id'],
            'session_uuid' => (string) $payload['session']['session_uuid'],
            'encryption_algorithm' => 'AES-256-GCM',
            'password_kdf_algorithm' => 'PBKDF2-HMAC-SHA256',
            'password_kdf_parameters' => [
                'hash' => 'SHA-256',
                'iterations' => $iterations,
                'key_length_bytes' => 32,
                'salt_bytes' => 32,
            ],
            'salt' => base64_encode($salt),
            'initialization_vector' => base64_encode($iv),
            'encrypted_payload' => base64_encode($ciphertext),
            'authentication_tag' => base64_encode($tag),
        ];
    }

    public function decryptPayload(array $envelope, string $password): array
    {
        $parameters = $envelope['password_kdf_parameters'] ?? [];
        $iterations = (int) ($parameters['iterations'] ?? 0);

        if ($iterations < 1) {
            throw new RuntimeException('Invalid package KDF parameters.');
        }

        $salt = base64_decode((string) ($envelope['salt'] ?? ''), true);
        $iv = base64_decode((string) ($envelope['initialization_vector'] ?? ''), true);
        $ciphertext = base64_decode((string) ($envelope['encrypted_payload'] ?? ''), true);
        $tag = base64_decode((string) ($envelope['authentication_tag'] ?? ''), true);

        if ($salt === false || $iv === false || $ciphertext === false || $tag === false) {
            throw new RuntimeException('Invalid package envelope encoding.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->deriveKey($password, $salt, $iterations),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt offline scanner package payload.');
        }

        return json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
    }

    private function deriveKey(string $password, string $salt, int $iterations): string
    {
        return hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
    }
}