<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdmissionScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_qr_code_does_not_expose_guest_name_or_phone(): void
    {
        Storage::fake('public');

        [$guest] = $this->makeGuest('Asha Private', '+255 700 100 200', Guest::PASS_DOUBLE, 2);

        $response = $this->actingAs($this->userWithRole(User::ROLE_ADMIN))
            ->get(route('admin.guests.qr', $guest));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertDontSee('Asha Private', false);
        $response->assertDontSee('+255 700 100 200', false);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $response->getContent());
    }

    public function test_scanner_allows_partial_use_and_blocks_over_limit(): void
    {
        [$guest, $token] = $this->makeGuest('Family Pass', '+255 700 300 400', Guest::PASS_SPECIAL, 3);
        $scanner = $this->userWithRole(User::ROLE_SCANNER);

        $this->actingAs($scanner)
            ->postJson(route('scanner.verify'), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('guest.phone_number', '+255 700 300 400')
            ->assertJsonPath('guest.allowed_entries', 3)
            ->assertJsonPath('guest.used_entries', 0)
            ->assertJsonPath('guest.remaining_admissions', 3);

        $this->actingAs($scanner)
            ->postJson(route('scanner.admit'), [
            'token' => $token,
            'quantity' => 2,
            'admitted_by' => 'Gate A',
        ])
            ->assertOk()
            ->assertJsonPath('guest.remaining_admissions', 1);

        $this->actingAs($scanner)
            ->postJson(route('scanner.admit'), [
            'token' => $token,
            'quantity' => 2,
        ])
            ->assertStatus(422)
            ->assertJsonPath('result', 'error');

        $this->assertSame(2, $guest->fresh()->used_entries);
        $this->assertDatabaseHas('admissions', ['guest_id' => $guest->id, 'quantity' => 2]);
        $this->assertDatabaseHas('checkins', ['guest_id' => $guest->id, 'entries_added' => 2, 'scan_result' => 'admitted']);
    }

    public function test_revoked_qr_code_cannot_be_admitted(): void
    {
        [$guest, $token] = $this->makeGuest('Revoked Guest', '+255 700 500 600', Guest::PASS_SINGLE, 1);
        $guest->qrCode->update(['is_active' => false, 'revoked_at' => now()]);

        $this->actingAs($this->userWithRole(User::ROLE_SCANNER))
            ->postJson(route('scanner.admit'), [
            'token' => $token,
            'quantity' => 1,
        ])
            ->assertStatus(409)
            ->assertJsonPath('result', 'revoked');

        $this->assertDatabaseCount('admissions', 0);
    }

    private function makeGuest(
        string $name,
        string $phone,
        string $passType,
        int $allowed,
        string $status = Guest::STATUS_ACTIVE,
    ): array {
        $token = Guest::makeSecureToken();
        $guest = Guest::query()->create([
            'name' => $name,
            'phone_number' => $phone,
            'pass_type' => $passType,
            'allowed_entries' => $allowed,
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

    private function userWithRole(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => $role.'@example.com',
            'password' => Hash::make('password'),
            'role' => $role,
            'gate_name' => $role === User::ROLE_SCANNER ? 'Main Gate' : null,
        ]);
    }
}
