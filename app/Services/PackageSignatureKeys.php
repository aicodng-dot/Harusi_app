<?php

namespace App\Services;

use RuntimeException;

/**
 * Key handling for Ed25519 offline scanner package signatures.
 *
 * Mirrors PackageSignatureService: the configured secret
 * (OFFLINE_SCANNER_PACKAGE_ED25519_PRIVATE_KEY_BASE64) is strict base64 of either a
 * 32-byte seed or a 64-byte libsodium secret key. Nothing here ever returns, logs or
 * prints the private key; callers only receive the 32-byte public key.
 *
 * Signed message = CanonicalJson of the package envelope WITHOUT the three signature
 * fields (signature_algorithm, signature_key_id, digital_signature).
 */
class PackageSignatureKeys
{
    public const SIGNATURE_FIELDS = ['signature_algorithm', 'signature_key_id', 'digital_signature'];

    public function __construct(private readonly CanonicalJson $json) {}

    public static function sodiumAvailable(): bool
    {
        return function_exists('sodium_crypto_sign_detached') && function_exists('sodium_crypto_sign_verify_detached');
    }

    /** New random 32-byte seed, base64 encoded. Only for the manual key-generation command. */
    public function generateSeedBase64(): string
    {
        $this->requireSodium();

        return base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
    }

    /** Raw 32-byte public key for a base64 seed or secret key. */
    public function publicKeyFromSecretBase64(string $secretBase64): string
    {
        $this->requireSodium();
        $secret = base64_decode(trim($secretBase64), true);
        if ($secret === false || $secret === '') {
            throw new RuntimeException('The signing key is not valid base64.');
        }
        if (strlen($secret) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($secret));
        } elseif (strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        } else {
            throw new RuntimeException('The signing key must decode to a 32-byte seed or a 64-byte secret key.');
        }
        sodium_memzero($secret);

        return $public;
    }

    /** Public key of the currently configured server key, or null when none is configured. */
    public function configuredPublicKey(): ?string
    {
        $configured = (string) config('services.offline_scanner_package.ed25519_private_key_base64', '');

        return trim($configured) === '' ? null : $this->publicKeyFromSecretBase64($configured);
    }

    /** Stable, shareable identifier of a public key: "SHA256:" + lowercase hex digest. */
    public function fingerprint(string $publicKey): string
    {
        return 'SHA256:'.hash('sha256', $publicKey);
    }

    /** The exact bytes that were signed for a decoded package file. */
    public function signedMessage(array $package): string
    {
        foreach (self::SIGNATURE_FIELDS as $field) {
            unset($package[$field]);
        }

        return $this->json->encode($package);
    }

    /**
     * Verifies a decoded .wqrpkg package against a raw 32-byte public key, exactly as the
     * Android scanner must: Ed25519 only, signature over the canonical envelope.
     */
    public function verifyPackage(array $package, string $publicKey): bool
    {
        $this->requireSodium();
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ($package['signature_algorithm'] ?? null) !== 'Ed25519'
            || ! is_string($package['digital_signature'] ?? null)) {
            return false;
        }
        $signature = base64_decode($package['digital_signature'], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $this->signedMessage($package), $publicKey);
    }

    private function requireSodium(): void
    {
        if (! self::sodiumAvailable()) {
            throw new RuntimeException('Ed25519 package signing requires the PHP Sodium extension.');
        }
    }
}
