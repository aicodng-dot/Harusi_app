<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScannerQrVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_event_verification_preserves_the_nested_payload_and_only_records_scan_history(): void
    {
        [$guest, $token, $scanner] = $this->fixture();
        $before = $this->scannerDataSnapshot();

        $this->actingAs($scanner)->postJson(route('scanner.verify'), ['token' => $token])
            ->assertOk()->assertExactJson([
                'ok' => true,
                'result' => Checkin::RESULT_VALID,
                'message' => 'Valid pass. Admit up to 1 more.',
                'guest' => [
                    'id' => $guest->id,
                    'guest_name' => $guest->name,
                    'phone_number' => $guest->phone_number,
                    'pass_type' => $guest->passTypeLabel(),
                    'pass_type_key' => Guest::PASS_DOUBLE,
                    'allowed_admissions' => 2,
                    'admitted_count' => 1,
                    'remaining_admissions' => 1,
                    'allowed_entries' => 2,
                    'used_entries' => 1,
                    'remaining_entries' => 1,
                    'usage_status' => $guest->usageStatus(),
                    'system_status' => Guest::STATUS_PARTIALLY_USED,
                    'token_preview' => substr($token, -8),
                ],
                'token' => $token,
            ]);

        $this->assertPassDataUnchanged($before);
        $this->assertDatabaseCount('checkins', 1);
        $this->assertDatabaseCount('scan_logs', 1);
        $this->assertDatabaseHas('checkins', [
            'event_id' => $guest->event_id, 'guest_id' => $guest->id,
            'qr_code_id' => $guest->qrCode->id, 'user_id' => $scanner->id,
            'entries_added' => 0, 'scan_result' => Checkin::RESULT_VALID,
            'used_entries_after_scan' => 1, 'remaining_entries_after_scan' => 1,
        ]);
        $this->assertDatabaseHas('scan_logs', [
            'event_id' => $guest->event_id, 'guest_id' => $guest->id,
            'token_hash' => Guest::hashToken($token), 'action' => 'verify',
            'result' => Checkin::RESULT_VALID, 'quantity' => null,
        ]);
    }

    public function test_verification_reports_the_full_special_allowance_without_consuming_entries(): void
    {
        [$guest, $token, $scanner] = $this->fixture();
        $guest->update(['pass_type' => Guest::PASS_SPECIAL, 'allowed_entries' => 100, 'used_entries' => 89]);
        $before = $this->scannerDataSnapshot();

        $this->actingAs($scanner)->postJson(route('scanner.verify'), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('guest.pass_type_key', Guest::PASS_SPECIAL)
            ->assertJsonPath('guest.allowed_admissions', 100)
            ->assertJsonPath('guest.admitted_count', 89)
            ->assertJsonPath('guest.remaining_admissions', 11)
            ->assertJsonPath('guest.allowed_entries', 100)
            ->assertJsonPath('guest.used_entries', 89)
            ->assertJsonPath('guest.remaining_entries', 11);

        $this->assertPassDataUnchanged($before);
        $this->assertDatabaseHas('checkins', [
            'guest_id' => $guest->id,
            'entries_added' => 0,
            'used_entries_after_scan' => 89,
            'remaining_entries_after_scan' => 11,
        ]);
    }

    public function test_verification_returns_the_canonical_token_from_a_scanned_url(): void
    {
        [$guest, $token, $scanner] = $this->fixture();

        $this->actingAs($scanner)->postJson(route('scanner.verify'), [
            'token' => 'https://wedding.test/scanner/verify/'.$token,
        ])->assertOk()->assertJsonPath('token', $token)->assertJsonPath('guest.id', $guest->id);
    }

    #[DataProvider('invalidTokens')]
    public function test_active_event_invalid_tokens_keep_the_404_response_and_anonymous_history(string $token): void
    {
        [, , $scanner] = $this->fixture();
        $before = $this->scannerDataSnapshot();

        $this->actingAs($scanner)->postJson(route('scanner.verify'), ['token' => $token])
            ->assertNotFound()->assertExactJson([
                'ok' => false, 'result' => Checkin::RESULT_INVALID,
                'message' => 'Invalid QR code. This pass was not found.',
            ]);

        $this->assertPassDataUnchanged($before);
        $this->assertDatabaseCount('checkins', 1);
        $this->assertDatabaseCount('scan_logs', 1);
        $this->assertDatabaseHas('checkins', [
            'event_id' => $scanner->event_id, 'guest_id' => null, 'qr_code_id' => null,
            'entries_added' => 0, 'scan_result' => Checkin::RESULT_INVALID,
        ]);
        $this->assertDatabaseHas('scan_logs', [
            'guest_id' => null, 'token_hash' => null, 'result' => Checkin::RESULT_INVALID,
        ]);
    }

    public static function invalidTokens(): array
    {
        return ['malformed' => ['short-token'], 'unknown' => [str_repeat('Z', 64)]];
    }

    #[DataProvider('unavailablePasses')]
    public function test_active_event_unavailable_passes_keep_the_200_response_without_admission(string $state): void
    {
        [$guest, $token, $scanner] = $this->fixture();
        match ($state) {
            'revoked' => $guest->qrCode->update(['is_active' => false, 'revoked_at' => now()]),
            'cancelled' => $guest->update(['status' => Guest::STATUS_CANCELLED]),
            'already_used' => $guest->update(['status' => Guest::STATUS_FULLY_USED, 'used_entries' => 2]),
        };
        $before = $this->scannerDataSnapshot();

        $this->actingAs($scanner)->postJson(route('scanner.verify'), ['token' => $token])
            ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('result', $state)
            ->assertJsonPath('guest.id', $guest->id)->assertJsonPath('token', $token);

        $this->assertPassDataUnchanged($before);
        $this->assertDatabaseCount('checkins', 1);
        $this->assertDatabaseCount('scan_logs', 1);
        $this->assertDatabaseHas('checkins', ['scan_result' => $state, 'entries_added' => 0]);
        $this->assertDatabaseHas('scan_logs', ['result' => $state, 'action' => 'verify']);
    }

    public static function unavailablePasses(): array
    {
        return ['revoked' => ['revoked'], 'cancelled' => ['cancelled'], 'fully used' => ['already_used']];
    }

    #[DataProvider('inactiveEventInputs')]
    public function test_inactive_event_refuses_before_input_processing_and_preserves_existing_history(string $status, string $input): void
    {
        [$guest, $token, $scanner] = $this->fixture();
        $this->actingAs($scanner)->postJson(route('scanner.verify'), ['token' => $token])->assertOk();
        Event::query()->whereKey($guest->event_id)->update(['status' => $status]);
        $before = $this->scannerDataSnapshot();
        $payload = match ($input) {
            'valid' => ['token' => $token],
            'unknown' => ['token' => str_repeat('Z', 64)],
            'missing' => [],
        };

        $this->postJson(route('scanner.verify'), $payload)->assertForbidden()
            ->assertJsonMissingPath('guest')->assertJsonMissingPath('token');

        $this->assertSame($before, $this->scannerDataSnapshot());
    }

    public static function inactiveEventInputs(): array
    {
        return [
            'archived valid token' => [Event::STATUS_ARCHIVED, 'valid'],
            'cancelled valid token' => [Event::STATUS_CANCELLED, 'valid'],
            'archived unknown token' => [Event::STATUS_ARCHIVED, 'unknown'],
            'cancelled unknown token' => [Event::STATUS_CANCELLED, 'unknown'],
            'archived missing token' => [Event::STATUS_ARCHIVED, 'missing'],
            'cancelled missing token' => [Event::STATUS_CANCELLED, 'missing'],
        ];
    }

    public function test_verification_reads_current_status_after_offline_middleware_cached_the_event(): void
    {
        [$guest, $token, $scanner] = $this->fixture();
        $before = $this->scannerDataSnapshot();
        $changed = false;
        Event::retrieved(function (Event $event) use ($guest, &$changed): void {
            if (! $changed && $event->id === $guest->event_id) {
                $changed = true;
                DB::table('events')->where('id', $event->id)->update(['status' => Event::STATUS_CANCELLED]);
            }
        });

        $this->actingAs($scanner)->postJson(route('scanner.verify'), ['token' => $token])
            ->assertForbidden()->assertJsonMissingPath('guest')->assertJsonMissingPath('token');

        $this->assertTrue($changed);
        $this->assertSame($before, $this->scannerDataSnapshot());
    }

    #[DataProvider('unauthorizedActors')]
    public function test_unauthorized_identity_cannot_verify_or_write_scans(string $actor, int $status): void
    {
        [, $token, $scanner] = $this->fixture();
        if ($actor !== 'guest') {
            $scanner->load('event');
            $this->actingAs($scanner);
            User::query()->whereKey($scanner->id)->update(match ($actor) {
                'inactive' => ['is_active' => false],
                'admin' => ['role' => User::ROLE_ADMIN],
                'customer' => ['role' => User::ROLE_CUSTOMER],
            });
        }
        $before = $this->scannerDataSnapshot();

        $this->postJson(route('scanner.verify'), ['token' => $token])->assertStatus($status)
            ->assertJsonMissingPath('guest')->assertJsonMissingPath('token');

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

    public function test_persisted_reassignment_keeps_the_wrong_event_response_and_anonymous_history(): void
    {
        [$guest, $token, $scanner] = $this->fixture();
        $scanner->load('event');
        $this->actingAs($scanner);
        $otherEvent = Event::factory()->create();
        User::query()->whereKey($scanner->id)->update(['event_id' => $otherEvent->id]);
        $before = $this->scannerDataSnapshot();

        $this->withSession(['selected_event_id' => $guest->event_id])
            ->postJson(route('scanner.verify'), ['token' => $token, 'event_id' => $guest->event_id])
            ->assertStatus(409)->assertExactJson([
                'ok' => false, 'result' => Checkin::RESULT_WRONG_EVENT,
                'status' => Checkin::RESULT_WRONG_EVENT,
                'message' => 'This QR code belongs to a different event.',
            ]);

        $this->assertPassDataUnchanged($before);
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

    public function test_active_assignment_ignores_foreign_inactive_session_and_client_event(): void
    {
        [$guest, $token, $scanner] = $this->fixture();
        $otherEvent = Event::factory()->create(['status' => Event::STATUS_CANCELLED]);
        $before = $this->scannerDataSnapshot();

        $this->actingAs($scanner)->withSession(['selected_event_id' => $otherEvent->id])
            ->postJson(route('scanner.verify'), ['token' => $token, 'event_id' => $otherEvent->id])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('guest.id', $guest->id);

        $this->assertPassDataUnchanged($before);
        $this->assertDatabaseHas('checkins', ['event_id' => $guest->event_id, 'entries_added' => 0]);
    }

    #[DataProvider('unassignedEventContexts')]
    public function test_unassigned_fallback_is_preserved_with_the_event_status_check(bool $selected, string $status): void
    {
        [$guest, $token, $scanner] = $this->fixture();
        $event = $selected ? Event::factory()->create() : Event::findOrFail($guest->event_id);
        $guest->update(['event_id' => $event->id]);
        $event->update(['status' => $status]);
        $scanner->update(['event_id' => null]);
        $before = $this->scannerDataSnapshot();

        $this->actingAs($scanner);
        if ($selected) {
            $this->withSession(['selected_event_id' => $event->id]);
        }
        $response = $this->postJson(route('scanner.verify'), ['token' => $token]);

        if ($status !== Event::STATUS_ACTIVE) {
            $response->assertForbidden()->assertJsonMissingPath('guest')->assertJsonMissingPath('token');
            $this->assertSame($before, $this->scannerDataSnapshot());
        } else {
            $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('guest.id', $guest->id);
            $this->assertPassDataUnchanged($before);
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

    public function test_active_event_missing_token_keeps_validation_errors_without_scan_writes(): void
    {
        [, , $scanner] = $this->fixture();
        $before = $this->scannerDataSnapshot();

        $this->actingAs($scanner)->postJson(route('scanner.verify'), [])
            ->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->assertSame($before, $this->scannerDataSnapshot());
    }

    private function assertPassDataUnchanged(array $before): void
    {
        $after = $this->scannerDataSnapshot();
        foreach (['guests', 'qr_codes', 'admissions'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
    }

    private function scannerDataSnapshot(): array
    {
        $snapshot = [];
        foreach (['guests', 'qr_codes', 'admissions', 'checkins', 'scan_logs'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function fixture(): array
    {
        $event = Event::defaultEvent();
        $guest = Guest::query()->create([
            'event_id' => $event->id, 'name' => 'Verification Guest',
            'phone_number' => '0712345678', 'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2, 'used_entries' => 1, 'status' => Guest::STATUS_PARTIALLY_USED,
        ]);
        $token = Guest::makeSecureToken();
        QrCode::query()->create([
            'guest_id' => $guest->id, 'qr_token' => $token, 'is_active' => true, 'generated_at' => now(),
        ]);
        $scanner = User::factory()->create(['event_id' => $event->id, 'role' => User::ROLE_SCANNER]);

        return [$guest->fresh('qrCode'), $token, $scanner];
    }
}
