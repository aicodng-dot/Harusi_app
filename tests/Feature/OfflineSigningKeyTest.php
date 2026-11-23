<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use App\Services\PackageSignatureKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfflineSigningKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! PackageSignatureKeys::sodiumAvailable()) {
            $this->markTestSkipped('PHP Sodium extension is required for Ed25519 signing tests.');
        }
    }

    public function test_ed25519_package_from_the_admin_route_verifies_like_the_android_scanner(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
        $this->configureEd25519(base64_encode($seed));

        $package = $this->generatePackage();

        $this->assertSame('Ed25519', $package['signature_algorithm']);
        $this->assertSame('prod-key-2026', $package['signature_key_id']);
        $keys = app(PackageSignatureKeys::class);
        $this->assertTrue($keys->verifyPackage($package, $public));

        // Independent re-implementation of the Android check (no app helper).
        $message = $package;
        unset($message['signature_algorithm'], $message['signature_key_id'], $message['digital_signature']);
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($package['digital_signature'], true),
            $this->canonical($message),
            $public,
        ));

        // Tampering with any signed field, or another key, fails verification.
        $tampered = $package;
        $tampered['ciphertext'] = base64_encode(random_bytes(32));
        $this->assertFalse($keys->verifyPackage($tampered, $public));
        $other = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $this->assertFalse($keys->verifyPackage($package, $other));
        $downgraded = $package;
        $downgraded['signature_algorithm'] = 'HMAC-SHA256';
        $this->assertFalse($keys->verifyPackage($downgraded, $public));
    }

    public function test_64_byte_secret_key_encoding_is_accepted_and_matches_the_seed_public_key(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $keys = app(PackageSignatureKeys::class);

        $this->assertSame(
            sodium_crypto_sign_publickey($pair),
            $keys->publicKeyFromSecretBase64(base64_encode(sodium_crypto_sign_secretkey($pair))),
        );
        $this->expectExceptionMessage('32-byte seed or a 64-byte secret key');
        $keys->publicKeyFromSecretBase64(base64_encode(random_bytes(16)));
    }

    public function test_missing_key_blocks_package_generation_without_creating_a_session(): void
    {
        $this->configureEd25519('');

        [$admin, $event, $payload] = $this->packageFixture();
        $this->actingAs($admin)->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.offline-scanner-sessions.store', $event), $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('offline_scanner_sessions', 0);
        $this->assertDatabaseCount('offline_scanner_packages', 0);
    }

    public function test_check_command_prints_public_key_and_never_the_private_key(): void
    {
        $seed = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $this->configureEd25519($seed);
        $public = base64_encode(app(PackageSignatureKeys::class)->publicKeyFromSecretBase64($seed));

        $exit = Artisan::call('offline-scanner:signing-key-check', ['--public-key' => $public]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString($public, $output);
        $this->assertStringContainsString('Self-test passed', $output);
        $this->assertStringContainsString('MATCHES', $output);
        $this->assertStringNotContainsString($seed, $output);
    }

    public function test_check_command_verifies_a_package_file_and_rejects_a_tampered_one(): void
    {
        $seed = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $this->configureEd25519($seed);
        $package = $this->generatePackage();
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'harusi-sign-'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir.'/good.wqrpkg', json_encode($package));
        $package['package_expires_at'] = '2099-01-01T00:00:00Z';
        file_put_contents($dir.'/bad.wqrpkg', json_encode($package));

        try {
            $this->assertSame(0, Artisan::call('offline-scanner:signing-key-check', ['--package' => $dir.'/good.wqrpkg']));
            $this->assertStringContainsString('VALID', Artisan::output());
            $this->assertSame(1, Artisan::call('offline-scanner:signing-key-check', ['--package' => $dir.'/bad.wqrpkg']));
            $this->assertStringContainsString('INVALID', Artisan::output());
        } finally {
            array_map('unlink', glob($dir.'/*'));
            rmdir($dir);
        }
    }

    public function test_check_command_fails_when_no_key_is_configured(): void
    {
        $this->configureEd25519('');

        $this->assertSame(1, Artisan::call('offline-scanner:signing-key-check'));
        $this->assertStringContainsString('not configured', Artisan::output());
    }

    public function test_generate_command_writes_a_private_file_outside_the_app_and_prints_only_the_public_key(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'harusi-keygen-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $file = $dir.DIRECTORY_SEPARATOR.'offline-signing.key';

        try {
            $this->assertSame(0, Artisan::call('offline-scanner:signing-key-generate', ['--output' => $file]));
            $output = Artisan::output();
            $seed = trim((string) file_get_contents($file));
            $this->assertSame(SODIUM_CRYPTO_SIGN_SEEDBYTES, strlen((string) base64_decode($seed, true)));
            $this->assertStringNotContainsString($seed, $output);
            $public = base64_encode(app(PackageSignatureKeys::class)->publicKeyFromSecretBase64($seed));
            $this->assertStringContainsString($public, $output);

            // Never overwrites an existing key file.
            $this->assertNotSame(0, Artisan::call('offline-scanner:signing-key-generate', ['--output' => $file]));
            $this->assertSame($seed, trim((string) file_get_contents($file)));
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function test_generate_command_refuses_paths_inside_the_application(): void
    {
        $target = storage_path('framework'.DIRECTORY_SEPARATOR.'never-'.bin2hex(random_bytes(4)).'.key');

        $this->assertNotSame(0, Artisan::call('offline-scanner:signing-key-generate', ['--output' => base_path('offline.key')]));
        $this->assertFileDoesNotExist(base_path('offline.key'));
        if (str_starts_with(str_replace('\\', '/', strtolower(storage_path())), str_replace('\\', '/', strtolower(base_path())))) {
            $this->assertNotSame(0, Artisan::call('offline-scanner:signing-key-generate', ['--output' => $target]));
            $this->assertFileDoesNotExist($target);
        }
    }

    private function configureEd25519(string $seedBase64): void
    {
        config([
            'services.offline_scanner_package.signature_algorithm' => 'ed25519',
            'services.offline_scanner_package.allow_hmac_fallback' => false,
            'services.offline_scanner_package.ed25519_private_key_base64' => $seedBase64,
            'services.offline_scanner_package.signing_key_id' => 'prod-key-2026',
            'services.offline_scanner_package.pbkdf2_iterations' => 1000,
        ]);
    }

    private function generatePackage(): array
    {
        [$admin, $event, $payload] = $this->packageFixture();
        $response = $this->actingAs($admin)->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.offline-scanner-sessions.store', $event), $payload);
        $response->assertOk();

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function packageFixture(): array
    {
        Storage::fake((string) config('offline_scanner.disk', 'offline_scanner'));
        $event = Event::factory()->create(['event_name' => 'Signed Wedding']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $master = User::factory()->create(['role' => User::ROLE_SCANNER, 'event_id' => $event->id, 'gate_name' => 'Main Gate']);
        $guest = Guest::query()->create([
            'event_id' => $event->id, 'name' => 'Signed Guest', 'phone_number' => '+255700000321',
            'manual_code' => '0101', 'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1, 'used_entries' => 0,
        ]);
        QrCode::query()->create(['guest_id' => $guest->id, 'qr_token' => Guest::makeSecureToken(), 'is_active' => true, 'generated_at' => now()]);
        $password = 'Str0ng-Package-Password!';

        return [$admin, $event, [
            'master_scanner_user_id' => $master->id,
            'master_gate_name' => 'Main Gate',
            'package_expires_at' => now()->addDay()->format('Y-m-d H:i'),
            'package_password' => $password,
            'package_password_confirmation' => $password,
            'confirm_generation' => '1',
        ]];
    }

    private function canonical(mixed $value): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (array_is_list($value)) {
                return array_map($sort, $value);
            }
            ksort($value, SORT_STRING);

            return array_map($sort, $value);
        };

        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
