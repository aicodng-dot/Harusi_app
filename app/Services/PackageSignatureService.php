<?php

namespace App\Services;

use RuntimeException;

class PackageSignatureService
{
    public function __construct(private readonly CanonicalJson $json)
    {
    }

    public function sign(array $envelope): array
    {
        $algorithm = strtolower((string) config('services.offline_scanner_package.signature_algorithm', 'ed25519'));
        $message = $this->json->encode($envelope);
        $keyId = (string) config('services.offline_scanner_package.signing_key_id', 'server-key-1');

        if ($algorithm === 'ed25519') {
            return [
                'signature_algorithm' => 'Ed25519',
                'signature_key_id' => $keyId,
                'digital_signature' => base64_encode($this->signEd25519($message)),
            ];
        }

        if ($algorithm === 'hmac-sha256' && (bool) config('services.offline_scanner_package.allow_hmac_fallback', false)) {
            $key = (string) config('services.offline_scanner_package.hmac_key', '');
            if ($key === '') {
                throw new RuntimeException('Offline scanner HMAC signing key is not configured.');
            }

            return [
                'signature_algorithm' => 'HMAC-SHA256',
                'signature_key_id' => $keyId,
                'digital_signature' => base64_encode(hash_hmac('sha256', $message, $key, true)),
            ];
        }

        throw new RuntimeException('Unsupported offline scanner package signature algorithm.');
    }

    private function signEd25519(string $message): string
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('Ed25519 package signing requires the PHP Sodium extension.');
        }

        $privateKey = base64_decode((string) config('services.offline_scanner_package.ed25519_private_key_base64'), true);

        if ($privateKey === false || $privateKey === '') {
            throw new RuntimeException('Offline scanner Ed25519 signing key is not configured.');
        }

        if (strlen($privateKey) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $keypair = sodium_crypto_sign_seed_keypair($privateKey);
            $privateKey = sodium_crypto_sign_secretkey($keypair);
        }

        if (strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Offline scanner Ed25519 signing key must be a base64 seed or secret key.');
        }

        return sodium_crypto_sign_detached($message, $privateKey);
    }
}