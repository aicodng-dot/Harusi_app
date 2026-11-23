<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Invitations\InvitationFixtures;
use Tests\TestCase;

/**
 * P4-048: administrators assign staff scanners to customer-created Events, and those
 * scanners admit only that Event's guests through the existing scanner endpoints.
 */
class ScannerCustomerEventAssignmentTest extends TestCase
{
    use InvitationFixtures;
    use RefreshDatabase;

    private function legacyEvent(): Event
    {
        return Event::factory()->create(['event_name' => 'Admin Gala', 'status' => Event::STATUS_ACTIVE]);
    }

    private function scannerPayload(array $extra = []): array
    {
        return $extra + ['name' => 'Customer Gate Scanner', 'email' => 'cust-gate@example.invalid', 'password' => 'scanner-pass-123',
            'password_confirmation' => 'scanner-pass-123', 'role' => User::ROLE_SCANNER, 'gate_name' => 'North Gate', 'is_active' => '1'];
    }

    public function test_admin_can_assign_a_new_scanner_to_a_customer_event_and_sees_it_labelled(): void
    {
        $f = $this->invitationFixture();
        $this->legacyEvent();

        $this->actingAs($f['admin'])->get(route('admin.users.create'))->assertOk()
            ->assertSee('Neema &amp; Baraka — Customer: Owner Studio', false)->assertSee('Admin Gala');

        $this->actingAs($f['admin'])->post(route('admin.users.store'), $this->scannerPayload(['event_id' => 101]))
            ->assertRedirect(route('admin.users.index'));
        $scanner = User::query()->where('email', 'cust-gate@example.invalid')->sole();
        $this->assertSame(101, (int) $scanner->event_id);
        $this->assertSame(User::ROLE_SCANNER, $scanner->role);
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $scanner->id]);

        $this->actingAs($f['admin'])->get(route('admin.users.index'))->assertOk()
            ->assertSee('Customer Gate Scanner')->assertSee('Customer: Owner Studio');
    }

    public function test_admin_can_reassign_between_customer_and_admin_events_and_delete(): void
    {
        $f = $this->invitationFixture();
        $legacy = $this->legacyEvent();
        $scanner = $f['scanner']; // assigned to customer event 101

        $this->actingAs($f['admin'])->get(route('admin.users.edit', $scanner))->assertOk();
        $this->actingAs($f['admin'])->put(route('admin.users.update', $scanner), $this->scannerPayload([
            'name' => 'Gate Scanner', 'email' => 'gate@example.invalid', 'password' => '', 'password_confirmation' => '', 'event_id' => $legacy->id,
        ]))->assertRedirect(route('admin.users.index'));
        $this->assertSame($legacy->id, (int) $scanner->fresh()->event_id);

        $this->actingAs($f['admin'])->put(route('admin.users.update', $scanner), $this->scannerPayload([
            'name' => 'Gate Scanner', 'email' => 'gate@example.invalid', 'password' => '', 'password_confirmation' => '', 'event_id' => 201,
        ]))->assertRedirect(route('admin.users.index'));
        $this->assertSame(201, (int) $scanner->fresh()->event_id);

        $this->actingAs($f['admin'])->delete(route('admin.users.destroy', $scanner))->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseMissing('users', ['id' => $scanner->id]);
    }

    public function test_inactive_customer_events_cannot_be_assigned(): void
    {
        $f = $this->invitationFixture();
        foreach ([Event::STATUS_ARCHIVED, Event::STATUS_CANCELLED] as $status) {
            DB::table('events')->where('id', 201)->update(['status' => $status]);
            $this->actingAs($f['admin'])->postJson(route('admin.users.store'), $this->scannerPayload(['event_id' => 201]))
                ->assertUnprocessable()->assertJsonValidationErrors('event_id');
        }
        $this->assertDatabaseMissing('users', ['email' => 'cust-gate@example.invalid']);
    }

    public function test_customers_and_scanners_cannot_manage_scanner_assignments(): void
    {
        $f = $this->invitationFixture();
        foreach ([$f['owner'], $f['scanner']] as $user) {
            $this->actingAs($user)->post(route('admin.users.store'), $this->scannerPayload(['event_id' => 101]))->assertForbidden();
        }
        $this->assertDatabaseMissing('users', ['email' => 'cust-gate@example.invalid']);
    }

    public function test_customer_event_scanner_admits_only_its_own_guests(): void
    {
        $f = $this->invitationFixture();
        $legacy = $this->legacyEvent();
        $legacyGuest = Guest::query()->create(['event_id' => $legacy->id, 'name' => 'Admin Guest', 'phone_number' => '+255711000555', 'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1]);
        $own = $this->giveToken($f['guests']['double']);
        $foreign = $this->giveToken($f['foreignGuest']);
        $legacyToken = $this->giveToken($legacyGuest);
        $scanner = $f['scanner'];

        $this->actingAs($scanner)->postJson(route('scanner.validate'), ['qr_token' => $own])->assertOk()->assertJsonPath('status', Checkin::RESULT_VALID);
        $this->actingAs($scanner)->postJson(route('scanner.admit'), ['qr_token' => $own, 'entries_to_admit' => 1])->assertOk();

        foreach ([$foreign, $legacyToken] as $token) {
            $this->actingAs($scanner)->postJson(route('scanner.admit'), ['qr_token' => $token, 'entries_to_admit' => 1])
                ->assertStatus(409)->assertJsonPath('result', Checkin::RESULT_WRONG_EVENT);
        }
        $this->actingAs($scanner)->post(route('scanner.manual-admit'), ['guest_id' => $f['foreignGuest']->id, 'entries_to_admit' => 1]);
        $this->actingAs($scanner)->post(route('scanner.manual-admit'), ['guest_id' => $legacyGuest->id, 'entries_to_admit' => 1]);

        $this->assertSame(1, (int) $f['guests']['double']->fresh()->used_entries);
        $this->assertSame(0, (int) $f['foreignGuest']->fresh()->used_entries);
        $this->assertSame(0, (int) $legacyGuest->fresh()->used_entries);
    }

    public function test_admission_limits_and_duplicate_prevention_hold_for_customer_events(): void
    {
        $f = $this->invitationFixture();
        $token = $this->giveToken($f['guests']['double']);
        $scanner = $f['scanner'];

        $this->actingAs($scanner)->postJson(route('scanner.admit'), ['qr_token' => $token, 'entries_to_admit' => 3])->assertStatus(422);
        $this->actingAs($scanner)->postJson(route('scanner.admit'), ['qr_token' => $token, 'entries_to_admit' => 2])->assertOk()
            ->assertJsonPath('guest.remaining_entries', 0);
        $this->actingAs($scanner)->postJson(route('scanner.admit'), ['qr_token' => $token, 'entries_to_admit' => 1])
            ->assertStatus(409)->assertJsonPath('result', Checkin::RESULT_ALREADY_USED);
        $this->actingAs($scanner)->post(route('scanner.manual-admit'), ['guest_id' => $f['guests']['double']->id, 'entries_to_admit' => 1]);

        $guest = $f['guests']['double']->fresh();
        $this->assertSame(2, (int) $guest->used_entries);
        $this->assertSame(Guest::STATUS_FULLY_USED, $guest->status);
        $this->assertSame(1, Checkin::query()->where('guest_id', $guest->id)->where('scan_result', Checkin::RESULT_ADMITTED)->count());
    }

    public function test_scanner_of_an_archived_customer_event_is_refused(): void
    {
        $f = $this->invitationFixture();
        $token = $this->giveToken($f['guests']['single']);
        DB::table('events')->where('id', 101)->update(['status' => Event::STATUS_ARCHIVED]);

        $this->actingAs($f['scanner'])->postJson(route('scanner.validate'), ['qr_token' => $token])->assertForbidden();
        $this->actingAs($f['scanner'])->postJson(route('scanner.admit'), ['qr_token' => $token, 'entries_to_admit' => 1])->assertForbidden();
        $this->assertSame(0, (int) $f['guests']['single']->fresh()->used_entries);
    }

    public function test_offline_sessions_remain_limited_to_admin_events(): void
    {
        // Offline packages for customer events are not supported yet (admin event selection,
        // layout and every offline service are admin-event scoped); the boundary must refuse.
        $f = $this->invitationFixture();

        $this->actingAs($f['admin'])->get(route('admin.offline-scanner-sessions.create', 101))->assertNotFound();
        $this->actingAs($f['admin'])->post(route('admin.offline-scanner-sessions.store', 101), [
            'master_scanner_user_id' => $f['scanner']->id, 'master_gate_name' => 'Main Gate', 'confirm_generation' => '1',
        ])->assertNotFound();
        $this->assertDatabaseCount('offline_scanner_sessions', 0);
    }
}
