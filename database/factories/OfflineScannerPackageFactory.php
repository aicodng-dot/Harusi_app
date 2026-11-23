<?php

namespace Database\Factories;

use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfflineScannerPackage>
 */
class OfflineScannerPackageFactory extends Factory
{
    public function definition(): array
    {
        $fingerprint = hash('sha256', fake()->uuid());

        return [
            'offline_scanner_session_id' => OfflineScannerSession::factory(),
            'file_name' => 'event-package.wqrpkg',
            'file_path' => 'offline-packages/'.fake()->uuid().'.wqrpkg',
            'storage_disk' => config('offline_scanner.disk', 'offline_scanner'),
            'internal_format' => config('offline_scanner.formats.package', 'harusi.offline-scanner.package'),
            'file_hash' => hash('sha256', fake()->uuid()),
            'encrypted_file_size' => fake()->numberBetween(2048, 1000000),
            'encryption_algorithm' => 'AES-256-GCM',
            'password_kdf_algorithm' => 'argon2id',
            'password_kdf_parameters' => ['memory' => 65536, 'iterations' => 3],
            'signature_algorithm' => 'Ed25519',
            'signature_key_id' => 'server-key-1',
            'package_fingerprint' => $fingerprint,
            'generated_by_user_id' => User::factory(),
            'generated_at' => now(),
        ];
    }
}