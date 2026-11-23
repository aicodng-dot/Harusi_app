<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScannerManualSearchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '4821';

    private const PHONE = '0712345678';

    private const STORED_PHONE = '+255712345678';

    public function test_authorized_code_and_phone_searches_are_read_only(): void
    {
        [, $scanner, $guest] = $this->fixture();
        $before = $this->snapshot();

        foreach ([self::CODE, self::PHONE, self::STORED_PHONE] as $query) {
            $this->actingAs($scanner)->get(route('scanner.manual-search', ['q' => $query]))
                ->assertOk()->assertSee($guest->name)->assertSee(self::STORED_PHONE)
                ->assertSee(self::CODE)->assertSee('Confirm Admission');
        }

        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('inactiveEventQueries')]
    public function test_inactive_event_refuses_manual_search_and_preserves_existing_history(string $status, string $query): void
    {
        [$event, $scanner, $guest] = $this->fixture();
        $this->actingAs($scanner)->get(route('scanner.manual-search', ['q' => self::CODE]))
            ->assertOk()->assertSee($guest->name);
        $this->post(route('scanner.manual-admit'), [
            'guest_id' => $guest->id, 'entries_to_admit' => 1, 'search' => self::CODE,
        ])->assertRedirect(route('scanner.manual-search', ['q' => self::CODE]));
        $event->update(['status' => $status]);
        $before = $this->snapshot();

        $response = $this->get(route('scanner.manual-search', match ($query) {
            'code' => ['q' => self::CODE],
            'phone' => ['q' => self::PHONE],
            'empty' => [],
        }))->assertForbidden();

        $this->assertNoGuestDisclosure($response, $guest);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, $guest->fresh()->used_entries);
        $this->assertDatabaseCount('admissions', 1);
        $this->assertDatabaseCount('checkins', 1);
    }

    public static function inactiveEventQueries(): array
    {
        return [
            'archived code' => [Event::STATUS_ARCHIVED, 'code'],
            'cancelled code' => [Event::STATUS_CANCELLED, 'code'],
            'archived phone' => [Event::STATUS_ARCHIVED, 'phone'],
            'cancelled phone' => [Event::STATUS_CANCELLED, 'phone'],
            'archived empty query' => [Event::STATUS_ARCHIVED, 'empty'],
            'cancelled empty query' => [Event::STATUS_CANCELLED, 'empty'],
        ];
    }

    public function test_event_status_is_read_from_the_current_record_not_a_cached_identity_relation(): void
    {
        [$event, $scanner, $guest] = $this->fixture();
        $before = $this->snapshot();
        $changed = false;
        User::retrieved(function (User $user) use ($scanner, $event, &$changed): void {
            if (! $changed && $user->id === $scanner->id) {
                $changed = true;
                $user->load('event');
                DB::table('events')->where('id', $event->id)->update(['status' => Event::STATUS_CANCELLED]);
            }
        });

        $response = $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => self::CODE]))->assertForbidden();

        $this->assertTrue($changed);
        $this->assertNoGuestDisclosure($response, $guest);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('explicitEventPointers')]
    public function test_missing_explicit_event_is_refused_instead_of_searching_the_default_event(bool $assigned): void
    {
        [$event, $scanner, $guest] = $this->fixture();
        $missingEvent = Event::factory()->create();
        $missingId = $missingEvent->id;
        $missingEvent->delete();
        $scanner->update(['event_id' => $assigned ? $missingId : null]);
        $before = $this->snapshot();

        $response = $this->actingAs($scanner)
            ->withSession(['selected_event_id' => $assigned ? $event->id : $missingId])
            ->get(route('scanner.manual-search', ['q' => self::CODE, 'event_id' => $event->id]))
            ->assertForbidden();

        $this->assertNoGuestDisclosure($response, $guest);
        $this->assertSame($before, $this->snapshot());
    }

    public static function explicitEventPointers(): array
    {
        return ['assigned missing Event' => [true], 'selected missing Event' => [false]];
    }

    #[DataProvider('malformedSelections')]
    public function test_malformed_selected_event_is_refused_without_default_event_fallback(mixed $selection): void
    {
        [, $scanner, $guest] = $this->fixture();
        $scanner->update(['event_id' => null]);
        $before = $this->snapshot();

        $response = $this->actingAs($scanner)->withSession(['selected_event_id' => $selection])
            ->get(route('scanner.manual-search', ['q' => self::CODE]))->assertForbidden();

        $this->assertNoGuestDisclosure($response, $guest);
        $this->assertSame($before, $this->snapshot());
    }

    public static function malformedSelections(): array
    {
        return ['array' => [[1]], 'boolean' => [true], 'zero' => [0], 'non-numeric string' => ['abc']];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_unauthorized_or_revoked_identity_cannot_search(string $actor, ?int $status): void
    {
        [, $scanner, $guest] = $this->fixture();
        if ($actor !== 'guest') {
            $scanner->load('event');
            $this->actingAs($scanner);
            User::query()->whereKey($scanner->id)->update(match ($actor) {
                'inactive' => ['is_active' => false],
                'admin' => ['role' => User::ROLE_ADMIN],
                'customer' => ['role' => User::ROLE_CUSTOMER],
            });
        }
        $before = $this->snapshot();

        $response = $this->get(route('scanner.manual-search', ['q' => self::CODE]));

        $status === null ? $response->assertRedirect(route('login')) : $response->assertStatus($status);
        $this->assertNoGuestDisclosure($response, $guest);
        $this->assertSame($before, $this->snapshot());
    }

    public static function unauthorizedActors(): array
    {
        return [
            'unauthenticated' => ['guest', null],
            'deactivated scanner' => ['inactive', null],
            'scanner role revoked to admin' => ['admin', 403],
            'scanner role revoked to customer' => ['customer', 403],
        ];
    }

    public function test_persisted_reassignment_searches_only_the_current_event_despite_stale_identity_and_session(): void
    {
        [$event, $scanner, $oldGuest] = $this->fixture();
        $scanner->load('event');
        $this->actingAs($scanner);
        $currentEvent = Event::factory()->create();
        $currentGuest = $this->guest($currentEvent, 'Current Event Guest');
        User::query()->whereKey($scanner->id)->update(['event_id' => $currentEvent->id]);
        $before = $this->snapshot();

        foreach ([self::CODE, self::PHONE] as $query) {
            $this->withSession(['selected_event_id' => $event->id])
                ->get(route('scanner.manual-search', ['q' => $query, 'event_id' => $event->id]))
                ->assertOk()->assertSee($currentGuest->name)->assertDontSee($oldGuest->name);
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_active_assignment_ignores_foreign_inactive_session_and_client_event(): void
    {
        [, $scanner, $guest] = $this->fixture();
        $otherEvent = Event::factory()->create(['status' => Event::STATUS_CANCELLED]);
        $otherGuest = $this->guest($otherEvent, 'Foreign Event Guest');
        $before = $this->snapshot();

        $this->actingAs($scanner)->withSession(['selected_event_id' => $otherEvent->id])
            ->get(route('scanner.manual-search', ['q' => self::CODE, 'event_id' => $otherEvent->id]))
            ->assertOk()->assertSee($guest->name)->assertDontSee($otherGuest->name);

        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('unassignedContexts')]
    public function test_unassigned_fallback_is_preserved_for_active_events_and_refused_for_inactive_events(bool $selected, string $status, bool $stringId = false): void
    {
        [$event, $scanner, $guest] = $this->fixture();
        if ($selected) {
            $event = Event::factory()->create();
            $guest = $this->guest($event, 'Selected Event Guest');
        }
        $scanner->update(['event_id' => null]);
        $event->update(['status' => $status]);
        $before = $this->snapshot();
        $this->actingAs($scanner);
        if ($selected) {
            $this->withSession(['selected_event_id' => $stringId ? (string) $event->id : $event->id]);
        }

        $response = $this->get(route('scanner.manual-search', ['q' => self::CODE]));

        if ($status === Event::STATUS_ACTIVE) {
            $response->assertOk()->assertSee($guest->name);
            if ($selected) {
                $response->assertDontSee('Manual Authority Guest');
            }
        } else {
            $response->assertForbidden();
            $this->assertNoGuestDisclosure($response, $guest);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function unassignedContexts(): array
    {
        return [
            'selected active numeric string' => [true, Event::STATUS_ACTIVE, true],
            'default active' => [false, Event::STATUS_ACTIVE],
            'selected archived' => [true, Event::STATUS_ARCHIVED],
            'default cancelled' => [false, Event::STATUS_CANCELLED],
        ];
    }

    private function assertNoGuestDisclosure(TestResponse $response, Guest $guest): void
    {
        $response->assertDontSee($guest->name)->assertDontSee('Manual Authority Guest')
            ->assertDontSee(self::STORED_PHONE)->assertDontSee('GUEST:')->assertDontSee('Confirm Admission');
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['guests', 'qr_codes', 'admissions', 'checkins', 'scan_logs'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function guest(Event $event, string $name): Guest
    {
        $guest = Guest::query()->create([
            'event_id' => $event->id, 'name' => $name, 'phone_number' => self::PHONE,
            'manual_code' => self::CODE, 'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2, 'used_entries' => 0,
        ]);
        QrCode::query()->create([
            'guest_id' => $guest->id, 'qr_token' => Guest::makeSecureToken(),
            'is_active' => true, 'generated_at' => now(),
        ]);

        return $guest->fresh('qrCode');
    }

    private function fixture(): array
    {
        $event = Event::defaultEvent();
        $scanner = User::factory()->create(['event_id' => $event->id, 'role' => User::ROLE_SCANNER]);

        return [$event, $scanner, $this->guest($event, 'Manual Authority Guest')];
    }
}
