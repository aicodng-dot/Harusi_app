<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScannerQrValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_qr_validation_returns_guest_details_and_records_scan(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_SINGLE, 1, 0);
        $scanner = $this->scannerUser();

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => $token])
            ->assertOk()
            ->assertExactJson([
                'status' => 'valid',
                'guest_id' => $guest->id,
                'guest_name' => 'Validation Guest',
                'phone_number' => '0712345678',
                'pass_type' => 'single',
                'allowed_entries' => 1,
                'used_entries' => 0,
                'remaining_entries' => 1,
            ]);

        $this->assertSame(0, $guest->fresh()->used_entries);
        $this->assertDatabaseHas('checkins', [
            'guest_id' => $guest->id,
            'user_id' => $scanner->id,
            'scan_result' => Checkin::RESULT_VALID,
            'entries_added' => 0,
            'used_entries_after_scan' => 0,
            'remaining_entries_after_scan' => 1,
        ]);
    }

    public function test_validation_extracts_token_from_scanned_url(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_DOUBLE, 2, 0);

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.validate'), [
                'scanned_url' => 'https://wedding.test/scanner/verify/'.$token,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'valid')
            ->assertJsonPath('guest_id', $guest->id)
            ->assertJsonPath('pass_type', 'double')
            ->assertJsonPath('remaining_entries', 2);
    }

    public function test_invalid_qr_validation_records_invalid_attempt(): void
    {
        $scanner = $this->scannerUser();

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => 'not-registered'])
            ->assertOk()
            ->assertExactJson([
                'status' => 'invalid',
                'message' => 'This QR code is not registered.',
            ]);

        $this->assertDatabaseHas('checkins', [
            'guest_id' => null,
            'qr_code_id' => null,
            'user_id' => $scanner->id,
            'scan_result' => Checkin::RESULT_INVALID,
        ]);
    }

    public function test_malformed_qr_token_is_rejected_and_logged(): void
    {
        $scanner = $this->scannerUser();

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => 'short-token'])
            ->assertOk()
            ->assertExactJson([
                'status' => 'invalid',
                'message' => 'This QR code is not registered.',
            ]);

        $this->assertDatabaseHas('checkins', [
            'guest_id' => null,
            'qr_code_id' => null,
            'user_id' => $scanner->id,
            'scan_result' => Checkin::RESULT_INVALID,
        ]);
    }

    public function test_revoked_qr_validation_returns_revoked_and_records_scan(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_SINGLE, 1, 0);
        $guest->qrCode->update(['is_active' => false, 'revoked_at' => now()]);

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.validate'), ['qr_token' => $token])
            ->assertOk()
            ->assertExactJson([
                'status' => 'revoked',
                'message' => 'This QR code has been revoked.',
            ]);

        $this->assertDatabaseHas('checkins', [
            'guest_id' => $guest->id,
            'qr_code_id' => $guest->qrCode->id,
            'scan_result' => Checkin::RESULT_REVOKED,
        ]);
    }

    public function test_fully_used_qr_validation_returns_already_used_and_does_not_increment_usage(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_SINGLE, 1, 1);

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.validate'), ['qr_token' => $token])
            ->assertOk()
            ->assertExactJson([
                'status' => 'already_used',
                'message' => 'This pass has already been fully used.',
            ]);

        $this->assertSame(1, $guest->fresh()->used_entries);
        $this->assertDatabaseHas('checkins', [
            'guest_id' => $guest->id,
            'qr_code_id' => $guest->qrCode->id,
            'scan_result' => Checkin::RESULT_ALREADY_USED,
            'entries_added' => 0,
        ]);
    }

    public function test_cancelled_qr_validation_returns_cancelled_and_records_scan(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_DOUBLE, 2, 0, Guest::STATUS_CANCELLED);

        $this->actingAs($this->scannerUser())
            ->postJson(route('scanner.validate'), ['qr_token' => $token])
            ->assertOk()
            ->assertExactJson([
                'status' => 'cancelled',
                'message' => 'This pass has been cancelled.',
            ]);

        $this->assertDatabaseHas('checkins', [
            'guest_id' => $guest->id,
            'qr_code_id' => $guest->qrCode->id,
            'scan_result' => Checkin::RESULT_CANCELLED,
        ]);
    }

    private function makeGuest(
        string $passType,
        int $allowedEntries,
        int $usedEntries,
        string $status = Guest::STATUS_ACTIVE,
    ): array {
        $token = Guest::makeSecureToken();
        $guest = Guest::query()->create([
            'name' => 'Validation Guest',
            'phone_number' => '0712345678',
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
            'used_entries' => $usedEntries,
            'status' => $status,
        ]);

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => $token,
            'is_active' => true,
            'generated_at' => now(),
        ]);

        return [$guest->fresh('qrCode'), $token];
    }

    private function scannerUser(): User
    {
        return User::query()->create([
            'name' => 'Scanner User',
            'email' => uniqid('scanner', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SCANNER,
            'gate_name' => 'Main Gate',
        ]);
    }
}
