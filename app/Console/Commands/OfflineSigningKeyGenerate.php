<?php

namespace App\Console\Commands;

use App\Services\PackageSignatureKeys;
use Illuminate\Console\Command;
use Throwable;

/**
 * Manual, operator-run generation of an Ed25519 package signing key.
 *
 * The private seed is written only to a new file OUTSIDE the application directory and
 * is never printed. Only the public key and its fingerprint are shown, for the Android
 * scanner configuration. Never run this from a deployment script: rotating the key
 * makes every installed scanner reject new packages until it trusts the new public key.
 */
class OfflineSigningKeyGenerate extends Command
{
    protected $signature = 'offline-scanner:signing-key-generate
        {--output= : New file (outside the application directory) that will receive the base64 private seed}';

    protected $description = 'Generate an offline scanner Ed25519 signing key into a private file (never printed)';

    public function handle(PackageSignatureKeys $keys): int
    {
        if (! PackageSignatureKeys::sodiumAvailable()) {
            $this->error('The PHP Sodium extension is not enabled.');

            return self::FAILURE;
        }

        $output = trim((string) $this->option('output'));
        if ($output === '') {
            $this->error('Pass --output=<path> for a new file outside the application directory.');

            return self::INVALID;
        }

        $directory = realpath(dirname($output));
        if ($directory === false || ! is_dir($directory)) {
            $this->error('The output directory does not exist.');

            return self::INVALID;
        }
        $target = $directory.DIRECTORY_SEPARATOR.basename($output);
        $base = realpath(base_path());
        if ($base !== false && $this->inside($target, $base)) {
            $this->error('Refusing to write a private key inside the application directory (it could be committed or served).');

            return self::INVALID;
        }
        if (file_exists($target)) {
            $this->error('The output file already exists; refusing to overwrite a key.');

            return self::INVALID;
        }

        try {
            $seed = $keys->generateSeedBase64();
            $handle = @fopen($target, 'x');
            if ($handle === false) {
                $this->error('Unable to create the output file.');

                return self::FAILURE;
            }
            fwrite($handle, $seed.PHP_EOL);
            fclose($handle);
            @chmod($target, 0600);
            $public = $keys->publicKeyFromSecretBase64($seed);
            unset($seed);
        } catch (Throwable $exception) {
            $this->error('Key generation failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Private seed written to: '.$target);
        $this->line('Public key (base64, give this to the Android scanner): '.base64_encode($public));
        $this->line('Public key fingerprint: '.$keys->fingerprint($public));
        $this->newLine();
        $this->line('Next: copy the file contents into OFFLINE_SCANNER_PACKAGE_ED25519_PRIVATE_KEY_BASE64 in the server .env,');
        $this->line('then run "php artisan offline-scanner:signing-key-check". Store the file in a password manager or vault and delete local copies.');

        return self::SUCCESS;
    }

    private function inside(string $path, string $root): bool
    {
        $normalize = static fn (string $value): string => rtrim(str_replace('\\', '/', strtolower($value)), '/').'/';

        return str_starts_with($normalize($path), $normalize($root));
    }
}
