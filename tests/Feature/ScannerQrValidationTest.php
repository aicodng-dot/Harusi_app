<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
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
                'phone_number' => '+255712345678',
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

    #[DataProvider('inactiveEventStatuses')]
    public function test_inactive_event_denies_validation_without_changing_existing_data(string $status): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_DOUBLE, 2, 1);
        $scanner = $this->scannerUser();
        $this->actingAs($scanner)->postJson(route('scanner.validate'), ['qr_token' => $token])
            ->assertOk()->assertJsonPath('status', 'valid');

        Event::query()->whereKey($guest->event_id)->update(['status' => $status]);
        $before = $this->scannerDataSnapshot();

        $this->postJson(route('scanner.validate'), ['qr_token' => $token])
            ->assertForbidden()->assertJsonMissingPath('guest_name');

        $this->assertSame($before, $this->scannerDataSnapshot());
    }

    public static function inactiveEventStatuses(): array
    {
        return [
            'archived' => [Event::STATUS_ARCHIVED],
            'cancelled' => [Event::STATUS_CANCELLED],
        ];
    }

    public function test_event_status_is_read_again_after_offline_middleware_cached_the_event(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_SINGLE, 1, 0);
        $scanner = $this->scannerUser();
        $before = $this->scannerDataSnapshot();
        $changed = false;

        Event::retrieved(function (Event $event) use ($guest, &$changed): void {
            if (! $changed && $event->id === $guest->event_id) {
                $changed = true;
                DB::table('events')->where('id', $event->id)->update(['status' => Event::STATUS_CANCELLED]);
            }
        });

        $this->actingAs($scanner)->postJson(route('scanner.validate'), ['qr_token' => $token])
            ->assertForbidden()->assertJsonMissingPath('guest_name');

        $this->assertTrue($changed);
        $this->assertSame($before, $this->scannerDataSnapshot());
    }

    #[DataProvider('unauthorizedActors')]
    public function test_unauthorized_identity_cannot_validate_or_write_scans(string $actor, int $status): void
    {
        [, $token] = $this->makeGuest(Guest::PASS_SINGLE, 1, 0);
        if ($actor !== 'guest') {
            $scanner = $this->scannerUser();
            $scanner->load('event');
            $this->actingAs($scanner);
            // Change persisted authority while the authenticated model stays stale.
            User::query()->whereKey($scanner->id)->update(match ($actor) {
                'inactive' => ['is_active' => false],
                'admin' => ['role' => User::ROLE_ADMIN],
                'customer' => ['role' => User::ROLE_CUSTOMER],
            });
        }
        $before = $this->scannerDataSnapshot();

        $this->postJson(route('scanner.validate'), ['qr_token' => $token])
            ->assertStatus($status)->assertJsonMissingPath('guest_name');

        $this->assertSame($before, $this->scannerDataSnapshot());
    }

    public static function unauthorizedActors(): array
    {
        return [
            'unauthenticated' => ['guest', 401],
            'inactive scanner' => ['inactive', 401],
            'scanner role revoked to admin' => ['admin', 403],
            'scanner role revoked to customer' => ['customer', 403],
        ];
    }

    public function test_persisted_reassignment_denies_the_old_event_without_changing_guest_or_qr_data(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_DOUBLE, 2, 1);
        $scanner = $this->scannerUser();
        $scanner->load('event');
        $this->actingAs($scanner);
        $otherEvent = Event::factory()->create();
        User::query()->whereKey($scanner->id)->update(['event_id' => $otherEvent->id]);
        $before = $this->scannerDataSnapshot();

        $this->withSession(['selected_event_id' => $guest->event_id])
            ->postJson(route('scanner.validate'), ['qr_token' => $token, 'event_id' => $guest->event_id])
            ->assertOk()->assertExactJson([
                'status' => Checkin::RESULT_WRONG_EVENT,
                'message' => 'This QR code belongs to a different event.',
            ]);

        $after = $this->scannerDataSnapshot();
        foreach (['guests', 'qr_codes', 'admissions'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
        // Business rejection retains its existing anonymous audit rows.
        $this->assertDatabaseCount('checkins', 1);
        $this->assertDatabaseCount('scan_logs', 1);
        $this->assertDatabaseHas('checkins', [
            'event_id' => $otherEvent->id, 'guest_id' => null, 'qr_code_id' => null,
            'scan_result' => Checkin::RESULT_WRONG_EVENT, 'entries_added' => 0,
        ]);
        $this->assertDatabaseHas('scan_logs', [
            'event_id' => $otherEvent->id, 'guest_id' => null, 'token_hash' => null,
            'result' => Checkin::RESULT_WRONG_EVENT,
        ]);
    }

    public function test_assigned_active_event_ignores_foreign_inactive_session_and_request_event(): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_DOUBLE, 2, 1);
        $otherEvent = Event::factory()->create(['status' => Event::STATUS_CANCELLED]);

        $this->actingAs($this->scannerUser())
            ->withSession(['selected_event_id' => $otherEvent->id])
            ->postJson(route('scanner.validate'), ['qr_token' => $token, 'event_id' => $otherEvent->id])
            ->assertOk()->assertJsonPath('status', 'valid')->assertJsonPath('guest_id', $guest->id);

        $this->assertSame(1, $guest->fresh()->used_entries);
        $this->assertDatabaseCount('admissions', 0);
        $this->assertDatabaseHas('checkins', ['event_id' => $guest->event_id, 'entries_added' => 0]);
    }

    #[DataProvider('unassignedEventContexts')]
    public function test_unassigned_fallback_is_preserved_with_the_event_status_check(bool $selected, string $status): void
    {
        [$guest, $token] = $this->makeGuest(Guest::PASS_SINGLE, 1, 0);
        $event = $selected ? Event::factory()->create() : Event::findOrFail($guest->event_id);
        $guest->update(['event_id' => $event->id]);
        $event->update(['status' => $status]);
        $scanner = $this->scannerUser();
        $scanner->update(['event_id' => null]);
        $before = $this->scannerDataSnapshot();

        $this->actingAs($scanner);
        if ($selected) {
            $this->withSession(['selected_event_id' => $event->id]);
        }
        $response = $this->postJson(route('scanner.validate'), ['qr_token' => $token]);

        if ($status !== Event::STATUS_ACTIVE) {
            $response->assertForbidden()->assertJsonMissingPath('guest_name');
            $this->assertSame($before, $this->scannerDataSnapshot());
        } else {
            $response->assertOk()->assertJsonPath('status', 'valid')->assertJsonPath('guest_id', $guest->id);
            $this->assertSame(0, $guest->fresh()->used_entries);
            $this->assertDatabaseCount('admissions', 0);
            $this->assertDatabaseHas('checkins', ['event_id' => $event->id, 'entries_added' => 0]);
        }
    }

    public static function unassignedEventContexts(): array
    {
        return [
            'selected active' => [true, Event::STATUS_ACTIVE],
            'default active' => [false, Event::STATUS_ACTIVE],
            'selected cancelled' => [true, Event::STATUS_CANCELLED],
            'default archived' => [false, Event::STATUS_ARCHIVED],
        ];
    }

    private function scannerDataSnapshot(): array
    {
        $snapshot = [];
        foreach (['guests', 'qr_codes', 'admissions', 'checkins', 'scan_logs'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
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
