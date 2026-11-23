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

class ScannerAdmissionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('inactiveStatuses')]
    public function test_event_closed_after_successful_verification_cannot_admit_or_change_existing_history(string $status): void
    {
        [$event, $scanner, $guest, $token] = $this->fixture();
        $this->actingAs($scanner)->postJson(route('scanner.admit'), $this->payload($token))->assertOk();
        $this->postJson(route('scanner.verify'), ['token' => $token])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('guest.remaining_entries', 1);
        $event->update(['status' => $status]);
        $before = $this->snapshot();

        $this->postJson(route('scanner.admit'), $this->payload($token))
            ->assertForbidden()->assertJsonMissingPath('guest');

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, $guest->fresh()->used_entries);
    }

    public static function inactiveStatuses(): array
    {
        return ['archived' => [Event::STATUS_ARCHIVED], 'cancelled' => [Event::STATUS_CANCELLED]];
    }

    #[DataProvider('inactiveInvalidTokens')]
    public function test_inactive_event_cannot_write_history_for_unknown_or_empty_canonical_tokens(string $status, string $token): void
    {
        [$event, $scanner] = $this->fixture();
        $event->update(['status' => $status]);
        $before = $this->snapshot();

        $this->actingAs($scanner)->postJson(route('scanner.admit'), $this->payload($token))
            ->assertForbidden()->assertJsonMissingPath('guest');

        $this->assertSame($before, $this->snapshot());
    }

    public static function inactiveInvalidTokens(): array
    {
        return [
            'archived unknown' => [Event::STATUS_ARCHIVED, str_repeat('Z', 64)],
            'cancelled unknown' => [Event::STATUS_CANCELLED, str_repeat('Z', 64)],
            'archived empty canonical token' => [Event::STATUS_ARCHIVED, 'https://wedding.test/?token='],
            'cancelled empty canonical token' => [Event::STATUS_CANCELLED, 'https://wedding.test/?token='],
        ];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_lifecycle_is_rechecked_inside_the_admission_transaction_after_middleware(string $status): void
    {
        [$event, $scanner, , $token] = $this->fixture();
        $this->actingAs($scanner)->postJson(route('scanner.verify'), ['token' => $token])->assertOk();
        $before = $this->snapshot();
        $changed = false;
        DB::connection()->beforeStartingTransaction(function () use ($event, $status, &$changed): void {
            if (! $changed) {
                $changed = true;
                DB::table('events')->where('id', $event->id)->update(['status' => $status]);
            }
        });

        $this->postJson(route('scanner.admit'), $this->payload($token))
            ->assertForbidden()->assertJsonMissingPath('guest');

        $this->assertTrue($changed);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_event_removed_at_the_transaction_boundary_is_refused_without_admission_or_history(): void
    {
        [$event, $scanner, , $token] = $this->fixture();
        $this->actingAs($scanner)->postJson(route('scanner.verify'), ['token' => $token])->assertOk();
        $before = $this->snapshot();
        $changed = false;
        DB::connection()->beforeStartingTransaction(function () use ($event, &$changed): void {
            if (! $changed) {
                $changed = true;
                DB::table('events')->where('id', $event->id)->delete();
            }
        });

        $this->postJson(route('scanner.admit'), $this->payload($token))
            ->assertForbidden()->assertJsonMissingPath('guest');

        $this->assertTrue($changed);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('explicitEventPointers')]
    public function test_missing_explicit_event_does_not_authorize_a_default_event_pass(bool $assigned): void
    {
        [$event, $scanner, , $token] = $this->fixture();
        $missingEvent = Event::factory()->create();
        $missingId = $missingEvent->id;
        $missingEvent->delete();
        $scanner->update(['event_id' => $assigned ? $missingId : null]);
        $before = $this->snapshot();

        $this->actingAs($scanner)->withSession(['selected_event_id' => $assigned ? $event->id : $missingId])
            ->postJson(route('scanner.admit'), $this->payload($token) + ['event_id' => $event->id])
            ->assertForbidden()->assertJsonMissingPath('guest');

        $this->assertSame($before, $this->snapshot());
    }

    public static function explicitEventPointers(): array
    {
        return ['assigned missing Event' => [true], 'selected missing Event' => [false]];
    }

    #[DataProvider('nonScalarEventContexts')]
    public function test_changed_session_context_cannot_turn_event_authority_into_a_multi_event_query(string $shape): void
    {
        [$event, $scanner, , $token] = $this->fixture();
        $scanner->update(['event_id' => null]);
        $before = $this->snapshot();
        $changed = false;
        Event::retrieved(function (Event $read) use ($event, $shape, &$changed): void {
            if (! $changed && $read->id === $event->id) {
                $changed = true;
                request()->session()->put('selected_event_id', match ($shape) {
                    'array' => [$event->id],
                    'object' => (object) ['id' => $event->id],
                    'boolean' => true,
                    'float' => (float) $event->id,
                });
            }
        });

        $this->actingAs($scanner)->withSession(['selected_event_id' => $event->id])
            ->postJson(route('scanner.admit'), $this->payload($token))->assertForbidden();

        $this->assertTrue($changed);
        $this->assertSame($before, $this->snapshot());
    }

    public static function nonScalarEventContexts(): array
    {
        return ['array' => ['array'], 'object' => ['object'], 'boolean' => ['boolean'], 'float' => ['float']];
    }

    #[DataProvider('revocations')]
    public function test_current_scanner_authority_is_rechecked_in_the_transaction(string $change): void
    {
        [, $scanner, , $token] = $this->fixture();
        $otherEvent = Event::factory()->create();
        $this->actingAs($scanner)->postJson(route('scanner.verify'), ['token' => $token])->assertOk();
        $before = $this->snapshot();
        $changed = false;
        DB::connection()->beforeStartingTransaction(function () use ($scanner, $otherEvent, $change, &$changed): void {
            if (! $changed) {
                $changed = true;
                DB::table('users')->where('id', $scanner->id)->update(match ($change) {
                    'reassigned' => ['event_id' => $otherEvent->id],
                    'unassigned' => ['event_id' => null],
                    'inactive' => ['is_active' => false],
                    'admin' => ['role' => User::ROLE_ADMIN],
                    'customer' => ['role' => User::ROLE_CUSTOMER],
                });
            }
        });

        $this->postJson(route('scanner.admit'), $this->payload($token))
            ->assertForbidden()->assertJsonMissingPath('guest');

        $this->assertTrue($changed);
        $this->assertSame($before, $this->snapshot());
    }

    public static function revocations(): array
    {
        return [
            'reassigned' => ['reassigned'], 'assignment removed' => ['unassigned'],
            'deactivated' => ['inactive'], 'role changed to admin' => ['admin'],
            'role changed to customer' => ['customer'],
        ];
    }

    public function test_reassignment_before_request_retains_wrong_event_response_and_anonymous_rejection_history(): void
    {
        [$event, $scanner, $guest, $token] = $this->fixture();
        $this->actingAs($scanner)->postJson(route('scanner.verify'), ['token' => $token])->assertOk();
        $otherEvent = Event::factory()->create();
        DB::table('users')->where('id', $scanner->id)->update(['event_id' => $otherEvent->id]);
        $before = $this->snapshot();

        $this->withSession(['selected_event_id' => $event->id])
            ->postJson(route('scanner.admit'), $this->payload($token) + ['event_id' => $event->id])
            ->assertStatus(409)->assertExactJson([
                'ok' => false, 'status' => Checkin::RESULT_WRONG_EVENT,
                'result' => Checkin::RESULT_WRONG_EVENT,
                'message' => 'This QR code belongs to a different event.',
            ]);

        $after = $this->snapshot();
        foreach (['guests', 'qr_codes', 'admissions'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
        $this->assertSame($before['checkins'], array_slice($after['checkins'], 0, 1));
        $this->assertSame($before['scan_logs'], array_slice($after['scan_logs'], 0, 1));
        $this->assertDatabaseCount('checkins', 2);
        $this->assertDatabaseCount('scan_logs', 2);
        $this->assertDatabaseHas('checkins', [
            'event_id' => $otherEvent->id, 'guest_id' => null, 'qr_code_id' => null,
            'entries_added' => 0, 'scan_result' => Checkin::RESULT_WRONG_EVENT,
        ]);
        $this->assertDatabaseHas('scan_logs', [
            'event_id' => $otherEvent->id, 'guest_id' => null,
            'token_hash' => null, 'result' => Checkin::RESULT_WRONG_EVENT,
        ]);
        $this->assertSame(0, $guest->fresh()->used_entries);
    }

    #[DataProvider('unassignedContexts')]
    public function test_genuine_unassigned_fallback_preserves_active_admission_and_refuses_inactive_events(bool $selected, string $status, bool $stringId = false): void
    {
        [$event, $scanner, $guest, $token] = $this->fixture();
        if ($selected) {
            $event = Event::factory()->create();
            $guest->update(['event_id' => $event->id]);
        }
        $scanner->update(['event_id' => null]);
        $event->update(['status' => $status]);
        $before = $this->snapshot();
        $this->actingAs($scanner);
        if ($selected) {
            $this->withSession(['selected_event_id' => $stringId ? (string) $event->id : $event->id]);
        }

        $response = $this->postJson(route('scanner.admit'), $this->payload($token));

        if ($status !== Event::STATUS_ACTIVE) {
            $response->assertForbidden()->assertJsonMissingPath('guest');
            $this->assertSame($before, $this->snapshot());
        } else {
            $response->assertOk()->assertJsonPath('status', 'admitted')
                ->assertJsonPath('guest.used_entries', 1)->assertJsonPath('guest.remaining_entries', 1);
            $this->assertDatabaseHas('admissions', ['event_id' => $event->id, 'guest_id' => $guest->id, 'quantity' => 1]);
            $this->assertDatabaseHas('checkins', ['event_id' => $event->id, 'guest_id' => $guest->id, 'entries_added' => 1]);
            $this->assertDatabaseHas('scan_logs', ['event_id' => $event->id, 'guest_id' => $guest->id, 'action' => 'admit']);
        }
    }

    public static function unassignedContexts(): array
    {
        return [
            'selected active' => [true, Event::STATUS_ACTIVE],
            'selected active numeric string' => [true, Event::STATUS_ACTIVE, true],
            'default active' => [false, Event::STATUS_ACTIVE],
            'selected archived' => [true, Event::STATUS_ARCHIVED],
            'default cancelled' => [false, Event::STATUS_CANCELLED],
        ];
    }

    #[DataProvider('activeInvalidTokens')]
    public function test_active_event_keeps_existing_invalid_token_responses_and_zero_entry_history(string $token, int $status, string $message): void
    {
        [, $scanner] = $this->fixture();
        $before = $this->snapshot();

        $this->actingAs($scanner)->postJson(route('scanner.admit'), $this->payload($token))
            ->assertStatus($status)->assertExactJson([
                'ok' => false, 'status' => Checkin::RESULT_INVALID,
                'result' => Checkin::RESULT_INVALID, 'message' => $message,
            ]);

        $after = $this->snapshot();
        foreach (['guests', 'qr_codes', 'admissions'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
        $this->assertDatabaseCount('checkins', 1);
        $this->assertDatabaseCount('scan_logs', 1);
        $this->assertDatabaseHas('checkins', ['guest_id' => null, 'entries_added' => 0, 'scan_result' => 'invalid']);
        $this->assertDatabaseHas('scan_logs', ['guest_id' => null, 'token_hash' => null, 'result' => 'invalid']);
    }

    public static function activeInvalidTokens(): array
    {
        return [
            'unknown' => [str_repeat('Z', 64), 404, 'Invalid QR code. This pass was not found.'],
            'empty canonical token' => ['https://wedding.test/?token=', 422, 'Invalid QR code. Scan again.'],
        ];
    }

    private function payload(string $token): array
    {
        return ['token' => $token, 'quantity' => 1];
    }

    private function snapshot(): array
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
        $scanner = User::factory()->create(['event_id' => $event->id, 'role' => User::ROLE_SCANNER]);
        $guest = Guest::query()->create([
            'event_id' => $event->id, 'name' => 'Admission Authority Guest',
            'phone_number' => '0712345678', 'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2, 'used_entries' => 0,
        ]);
        $token = Guest::makeSecureToken();
        QrCode::query()->create([
            'guest_id' => $guest->id, 'qr_token' => $token, 'is_active' => true, 'generated_at' => now(),
        ]);

        return [$event, $scanner, $guest->fresh('qrCode'), $token];
    }
}
