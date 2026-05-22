<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminQrCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_generate_open_download_and_validate_guest_qr_code(): void
    {
        Storage::fake('public');

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $scanner = $this->userWithRole(User::ROLE_SCANNER);
        $guest = Guest::query()->create([
            'name' => 'QR Test Guest',
            'phone_number' => '+255 700 111 222',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 4,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.guests.qr.generate', $guest))
            ->assertRedirect();

        $qrCode = $guest->fresh('qrCode')->qrCode;

        $this->assertNotNull($qrCode);
        $this->assertNotEmpty($qrCode->qr_token);
        $this->assertNotEmpty($qrCode->qr_image_path);
        $this->assertTrue($qrCode->is_active);
        $this->assertStringContainsString('guest-'.$guest->id.'-'.$qrCode->qr_token.'.png', $qrCode->qr_image_path);
        Storage::disk('public')->assertExists($qrCode->qr_image_path);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", Storage::disk('public')->get($qrCode->qr_image_path));

        $openResponse = $this->actingAs($admin)->get(route('admin.guests.qr', $guest));
        $openResponse->assertOk();
        $openResponse->assertHeader('Content-Type', 'image/png');
        $openResponse->assertDontSee('QR Test Guest', false);
        $openResponse->assertDontSee('+255 700 111 222', false);

        $downloadResponse = $this->actingAs($admin)->get(route('admin.guests.qr.download', $guest));
        $downloadResponse->assertOk();
        $downloadResponse->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('qr_test_guest_'.str_pad((string) $guest->id, 3, '0', STR_PAD_LEFT).'_qr.png', (string) $downloadResponse->headers->get('Content-Disposition'));

        $this->actingAs($scanner)
            ->get(route('scanner.verify-token', $qrCode->qr_token))
            ->assertOk()
            ->assertSee($qrCode->qr_token);

        $this->actingAs($scanner)
            ->postJson(route('scanner.verify'), ['token' => $qrCode->qr_token])
            ->assertOk()
            ->assertJsonPath('result', 'valid');
    }

    public function test_regenerating_qr_replaces_old_token_and_old_token_no_longer_validates(): void
    {
        Storage::fake('public');

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $scanner = $this->userWithRole(User::ROLE_SCANNER);
        $guest = Guest::query()->create([
            'name' => 'Regenerate Guest',
            'phone_number' => '+255 700 333 444',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();
        $firstQrCode = $guest->fresh('qrCode')->qrCode;
        $oldToken = $firstQrCode->qr_token;
        $oldImagePath = $firstQrCode->qr_image_path;

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();
        $newQrCode = $guest->fresh('qrCode')->qrCode;

        $this->assertNotSame($oldToken, $newQrCode->qr_token);
        $this->assertNotSame($oldImagePath, $newQrCode->qr_image_path);
        Storage::disk('public')->assertMissing($oldImagePath);
        Storage::disk('public')->assertExists($newQrCode->qr_image_path);

        $this->actingAs($scanner)
            ->postJson(route('scanner.verify'), ['token' => $oldToken])
            ->assertNotFound()
            ->assertJsonPath('result', 'invalid');

        $this->actingAs($scanner)
            ->postJson(route('scanner.verify'), ['token' => $newQrCode->qr_token])
            ->assertOk()
            ->assertJsonPath('result', 'valid');
    }

    public function test_revoked_qr_code_does_not_validate(): void
    {
        Storage::fake('public');

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $scanner = $this->userWithRole(User::ROLE_SCANNER);
        $guest = Guest::query()->create([
            'name' => 'Revoked QR Guest',
            'phone_number' => '+255 700 555 666',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();
        $guest = $guest->fresh('qrCode');

        $this->actingAs($admin)
            ->patch(route('admin.guests.revoke', $guest))
            ->assertRedirect();

        $this->actingAs($scanner)
            ->postJson(route('scanner.verify'), ['token' => $guest->qrCode->qr_token])
            ->assertOk()
            ->assertJsonPath('result', 'revoked')
            ->assertJsonPath('ok', false);
    }

    public function test_qr_codes_page_lists_guests_with_filters_and_individual_actions(): void
    {
        Storage::fake('public');

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $activeGuest = $this->guest('Active QR Guest', Guest::PASS_SINGLE, 1);
        $missingGuest = $this->guest('Missing QR Guest', Guest::PASS_DOUBLE, 2);
        $fullyUsedGuest = $this->guest('Fully Used QR Guest', Guest::PASS_SINGLE, 1, 1);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $activeGuest))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $fullyUsedGuest))->assertRedirect();
        $fullyUsedGuest = $fullyUsedGuest->fresh('qrCode');

        $this->actingAs($admin)
            ->get(route('admin.qr-codes.index'))
            ->assertOk()
            ->assertSee('Active QR Guest')
            ->assertSee('Missing QR Guest')
            ->assertSee('Generate missing QR codes')
            ->assertSee('Download all ZIP')
            ->assertSee('Export QR list CSV')
            ->assertSee('Regenerate')
            ->assertSee('Generate QR');

        $this->actingAs($admin)
            ->get(route('admin.qr-codes.index', ['filter' => 'missing_qr']))
            ->assertOk()
            ->assertSee('Missing QR Guest')
            ->assertDontSee('Active QR Guest');

        $this->actingAs($admin)
            ->get(route('admin.qr-codes.index', ['filter' => 'fully_used']))
            ->assertOk()
            ->assertSee('Fully Used QR Guest')
            ->assertDontSee('Missing QR Guest');

        $this->actingAs($admin)
            ->patch(route('admin.qr-codes.deactivate', $activeGuest))
            ->assertRedirect();

        $this->assertFalse($activeGuest->fresh('qrCode')->qrCode->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.qr-codes.activate', $activeGuest))
            ->assertRedirect();

        $this->assertTrue($activeGuest->fresh('qrCode')->qrCode->is_active);
    }

    public function test_admin_can_batch_generate_missing_qr_codes(): void
    {
        Storage::fake('public');

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $guestWithQr = $this->guest('Existing QR Guest', Guest::PASS_SINGLE, 1);
        $missingOne = $this->guest('Batch Missing One', Guest::PASS_DOUBLE, 2);
        $missingTwo = $this->guest('Batch Missing Two', Guest::PASS_SPECIAL, 4);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guestWithQr))->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.qr-codes.generate-missing'))
            ->assertRedirect();

        $this->assertNotNull($missingOne->fresh('qrCode')->qrCode);
        $this->assertNotNull($missingTwo->fresh('qrCode')->qrCode);
        $this->assertDatabaseCount('qr_codes', 3);
    }

    public function test_admin_can_download_all_qr_codes_as_zip_and_export_qr_list_csv(): void
    {
        Storage::fake('public');

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $john = $this->guest('John Peter', Guest::PASS_SINGLE, 1);
        $asha = $this->guest('Asha QR Export', Guest::PASS_DOUBLE, 2);
        $missing = $this->guest('Missing Export QR', Guest::PASS_SPECIAL, 4);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $john))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $asha))->assertRedirect();

        $zipResponse = $this->actingAs($admin)->get(route('admin.qr-codes.download-all'));
        $zipResponse->assertOk();
        $zipResponse->assertHeader('Content-Type', 'application/zip');
        $this->assertStringStartsWith('PK', $zipResponse->getContent());
        $this->assertStringContainsString('john_peter_'.str_pad((string) $john->id, 3, '0', STR_PAD_LEFT).'_qr.png', $zipResponse->getContent());
        $this->assertStringContainsString('asha_qr_export_'.str_pad((string) $asha->id, 3, '0', STR_PAD_LEFT).'_qr.png', $zipResponse->getContent());
        $this->assertStringNotContainsString('missing_export_qr', $zipResponse->getContent());

        $csvResponse = $this->actingAs($admin)->get(route('admin.qr-codes.export'));
        $csvResponse->assertOk();
        $csvResponse->assertDownload();
        $csv = $csvResponse->streamedContent();
        $this->assertStringContainsString('Guest ID', $csv);
        $this->assertStringContainsString('John Peter', $csv);
        $this->assertStringContainsString('active', $csv);
        $this->assertStringContainsString('Missing Export QR', $csv);
        $this->assertStringContainsString('missing', $csv);
    }

    public function test_deactivated_and_revoked_qr_codes_do_not_validate_and_regeneration_replaces_old_token(): void
    {
        Storage::fake('public');

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $scanner = $this->userWithRole(User::ROLE_SCANNER);
        $guest = $this->guest('Secure QR Guest', Guest::PASS_DOUBLE, 2);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();
        $qrCode = $guest->fresh('qrCode')->qrCode;
        $firstToken = $qrCode->qr_token;

        $this->actingAs($admin)->patch(route('admin.qr-codes.deactivate', $guest))->assertRedirect();

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => $firstToken])
            ->assertOk()
            ->assertJsonPath('status', 'revoked');

        $this->actingAs($admin)->patch(route('admin.qr-codes.activate', $guest))->assertRedirect();

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => $firstToken])
            ->assertOk()
            ->assertJsonPath('status', 'valid');

        $this->actingAs($admin)->patch(route('admin.guests.revoke', $guest))->assertRedirect();

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => $firstToken])
            ->assertOk()
            ->assertJsonPath('status', 'revoked');

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();
        $newToken = $guest->fresh('qrCode')->qrCode->qr_token;

        $this->assertNotSame($firstToken, $newToken);

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => $firstToken])
            ->assertOk()
            ->assertJsonPath('status', 'invalid');

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => $newToken])
            ->assertOk()
            ->assertJsonPath('status', 'valid');
    }

    private function guest(
        string $name,
        string $passType,
        int $allowedEntries,
        int $usedEntries = 0,
        string $status = Guest::STATUS_ACTIVE,
    ): Guest {
        return Guest::query()->create([
            'name' => $name,
            'phone_number' => '+255 700 '.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
            'used_entries' => $usedEntries,
            'status' => $status,
        ]);
    }

    private function userWithRole(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => uniqid($role, true).'@example.com',
            'password' => Hash::make('password'),
            'role' => $role,
            'gate_name' => $role === User::ROLE_SCANNER ? 'Main Gate' : null,
        ]);
    }
}
