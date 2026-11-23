<?php

namespace App\Console\Commands;

use App\Services\PackageSignatureKeys;
use App\Services\PackageSignatureService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Checks the configured offline scanner signing key without revealing it.
 *
 * Prints only the derived public key and fingerprint, signs and verifies a self-test
 * envelope with the real signing service, and optionally verifies a .wqrpkg file the
 * same way the Android scanner must (against the configured or a given public key).
 */
class OfflineSigningKeyCheck extends Command
{
    protected $signature = 'offline-scanner:signing-key-check
        {--package= : Path to a .wqrpkg package file to verify}
        {--public-key= : Base64 public key to verify against instead of the configured key}';

    protected $description = 'Verify the configured offline scanner Ed25519 key and optionally a package signature (never prints the private key)';

    public function handle(PackageSignatureKeys $keys, PackageSignatureService $signatures): int
    {
        if (! PackageSignatureKeys::sodiumAvailable()) {
            $this->error('The PHP Sodium extension is not enabled.');

            return self::FAILURE;
        }

        $algorithm = strtolower((string) config('services.offline_scanner_package.signature_algorithm', 'ed25519'));
        $this->line('Signature algorithm: '.$algorithm);
        $this->line('Signing key id: '.(string) config('services.offline_scanner_package.signing_key_id', 'server-key-1'));

        $public = null;
        $given = trim((string) $this->option('public-key'));
        try {
            $configured = $keys->configuredPublicKey();
        } catch (Throwable $exception) {
            $this->error('Configured key is invalid: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($configured === null) {
            $this->warn('OFFLINE_SCANNER_PACKAGE_ED25519_PRIVATE_KEY_BASE64 is not configured; package generation will fail.');
        } else {
            $this->line('Server public key (base64): '.base64_encode($configured));
            $this->line('Server public key fingerprint: '.$keys->fingerprint($configured));
            if ($algorithm !== 'ed25519') {
                $this->warn('The configured algorithm is not ed25519; the Ed25519 key is unused.');
            } else {
                $probe = ['format' => 'harusi.offline-scanner.signing-self-test', 'nonce' => bin2hex(random_bytes(16))];
                $signed = $probe + $signatures->sign($probe);
                if (! $keys->verifyPackage($signed, $configured)) {
                    $this->error('Self-test FAILED: the signing service output does not verify with the derived public key.');

                    return self::FAILURE;
                }
                $this->info('Self-test passed: sign + verify with the configured key.');
            }
            $public = $configured;
        }

        if ($given !== '') {
            $decoded = base64_decode($given, true);
            if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $this->error('--public-key must be base64 of a 32-byte Ed25519 public key.');

                return self::INVALID;
            }
            if ($configured !== null) {
                $this->line(hash_equals($configured, $decoded)
                    ? 'The given public key MATCHES the configured server key.'
                    : 'The given public key does NOT match the configured server key.');
            }
            $public = $decoded;
        }

        $path = trim((string) $this->option('package'));
        if ($path === '') {
            return $configured === null && $given === '' ? self::FAILURE : self::SUCCESS;
        }
        if ($public === null) {
            $this->error('No public key available to verify the package.');

            return self::FAILURE;
        }
        $contents = is_file($path) ? @file_get_contents($path) : false;
        $package = $contents === false ? null : json_decode($contents, true);
        if (! is_array($package)) {
            $this->error('The package file could not be read as JSON.');

            return self::FAILURE;
        }
        $this->line('Package key id: '.(string) ($package['signature_key_id'] ?? '(none)'));
        if ($keys->verifyPackage($package, $public)) {
            $this->info('Package signature VALID.');

            return self::SUCCESS;
        }
        $this->error('Package signature INVALID for this public key.');

        return self::FAILURE;
    }
}
