<?php

namespace Tests\Feature\ReferenceUi;

use App\Models\User;
use App\Services\Events\EventSetupProgress;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Working Flow 11 — Event Setup Progress (reference UI/30_Event_Setup_Progress.png). */
class Screen11EventSetupProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_successful_creation_opens_setup_progress_for_the_new_event(): void
    {
        $users = $this->fixture();
        $this->actingAs($users['owner'])->post(route('customer.events.type'), ['event_type' => 'wedding']);
        $this->actingAs($users['owner'])->get(route('customer.events.details'));
        $payload = ['event_name' => 'Amani & Zawadi', 'event_datetime' => '2027-02-14T16:30', 'venue_name' => 'Serena Hotel', 'request_key' => session('customer_event_setup')['request_key']];

        $response = $this->actingAs($users['owner'])->post(route('customer.events.store'), $payload);
        $id = (int) DB::table('events')->where('event_name', 'Amani & Zawadi')->value('id');
        $response->assertRedirect(route('customer.events.setup', ['event' => $id]));
        // A repeated submission resolves to the same event and the same page.
        $this->actingAs($users['owner'])->post(route('customer.events.store'), $payload)
            ->assertRedirect(route('customer.events.setup', ['event' => $id]));
        $this->assertSame(1, DB::table('events')->where('event_name', 'Amani & Zawadi')->count());

        $page = $this->actingAs($users['owner'])->get(route('customer.events.setup', ['event' => $id]));
        $page->assertOk()->assertViewIs('reference_ui.30_event_setup_progress')
            ->assertSee('Mpangilio wa Tukio')->assertSee('Tukio lako &quot;Amani &amp; Zawadi&quot; limeundwa.', false)
            ->assertSee('14 Februari 2027')->assertSee('16:30')->assertSee('Serena Hotel')
            ->assertSee('30_Event_Setup_Progress_Wedding_Ring.webp', false)
            ->assertSee('Hatua 1 kati ya 5')->assertSee('20%')->assertSee('aria-valuenow="20"', false);
        $this->assertSame(['details' => 'completed', 'guests' => 'not_started', 'invitations' => 'not_started', 'rsvp' => 'unavailable', 'scanner' => 'not_started', 'reports' => 'not_started'],
            $this->statuses($page));
        $this->assertSame('guests', $page->viewData('progress')['current']);
    }

    public function test_progress_reflects_real_records_and_is_calculated_from_applicable_steps(): void
    {
        $users = $this->fixture();
        $this->guests(101, 4);
        $this->guests(101, 1, 'cancelled');
        $ids = DB::table('guests')->where('event_id', 101)->where('status', '!=', 'cancelled')->orderBy('id')->pluck('id')->all();
        // Two real deliveries (one duplicated), plus logs that must not count.
        $this->log(101, $ids[0], 'digital_card', 'sent');
        $this->log(101, $ids[0], 'digital_card', 'sent');
        $this->log(101, $ids[1], 'digital_card', 'sent');
        $this->log(101, $ids[2], 'digital_card', 'simulated');
        $this->log(101, $ids[3], 'digital_card', 'failed');
        $this->log(101, $ids[3], 'guest_code', 'sent');
        DB::table('users')->where('id', $users['scanner']->id)->update(['event_id' => 101]);

        $page = $this->actingAs($users['owner'])->get(route('customer.events.setup', ['event' => 101]));

        $page->assertOk()->assertSee('Wageni 4 wameongezwa.')->assertSee('Mialiko 2 kati ya 4 imetumwa.')->assertSee('Mhakiki 1 amepangwa.');
        $this->assertSame(['details' => 'completed', 'guests' => 'completed', 'invitations' => 'in_progress', 'rsvp' => 'unavailable', 'scanner' => 'completed', 'reports' => 'not_started'],
            $this->statuses($page));
        $progress = $page->viewData('progress');
        $this->assertSame([3, 5, 60, 'invitations'], [$progress['completed'], $progress['total'], $progress['percent'], $progress['current']]);
        $page->assertSee('Hatua 3 kati ya 5')->assertSee('60%');
    }

    public function test_fully_completed_setup_never_counts_the_unavailable_rsvp_step(): void
    {
        $users = $this->fixture();
        $this->guests(101, 2);
        foreach (DB::table('guests')->where('event_id', 101)->pluck('id') as $guest) {
            $this->log(101, $guest, 'digital_card', 'sent');
        }
        DB::table('users')->where('id', $users['scanner']->id)->update(['event_id' => 101]);
        DB::table('checkins')->insert(['event_id' => 101, 'guest_id' => DB::table('guests')->where('event_id', 101)->value('id'), 'user_id' => $users['scanner']->id, 'entries_added' => 1, 'used_entries_after_scan' => 1, 'scan_result' => 'success', 'checked_in_at' => now()]);

        $page = $this->actingAs($users['owner'])->get(route('customer.events.setup', ['event' => 101]));

        $this->assertSame('unavailable', $this->statuses($page)['rsvp']);
        $this->assertSame([5, 5, 100, null], array_values(array_intersect_key($page->viewData('progress'), array_flip(['completed', 'total', 'percent', 'current']))));
        $page->assertSee('Hivi karibuni')->assertSee('href="'.route('customer.events.dashboard', ['event' => 101]).'"', false);
    }

    public function test_missing_optional_details_show_fallbacks_and_are_not_completed(): void
    {
        $users = $this->fixture();

        $page = $this->actingAs($users['owner'])->get(route('customer.events.setup', ['event' => 102, 'lang' => 'en']));

        $page->assertOk()->assertSee('<html lang="en">', false)->assertSee('Event Setup')->assertSee('Date not set yet')
            ->assertSee('Venue not set yet')->assertSee('Complete the missing event details.')->assertSee('Step 0 of 5')->assertSee('0%');
        $this->assertSame('in_progress', $this->statuses($page)['details']);
    }

    public function test_viewing_progress_is_read_only(): void
    {
        $users = $this->fixture();
        $this->guests(101, 3);
        $writes = [];
        $listening = true;
        DB::listen(function (QueryExecuted $event) use (&$writes, &$listening): void {
            if ($listening && preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $event->sql) && ! str_contains($event->sql, '"cache')) {
                $writes[] = $event->sql;
            }
        });

        $this->actingAs($users['owner'])->get(route('customer.events.setup', ['event' => 101]))->assertOk();
        $listening = false;

        $this->assertSame([], $writes);
    }

    #[DataProvider('deniedEvents')]
    public function test_foreign_revoked_unassigned_and_unknown_events_are_refused(string $event): void
    {
        $users = $this->fixture();

        $response = $this->actingAs($users['owner'])->get('/customer/events/'.$event.'/setup');

        $response->assertNotFound();
        $response->assertDontSee('Foreign private event')->assertDontSee('Revoked event')->assertDontSee('Unassigned event');
    }

    public static function deniedEvents(): array
    {
        return ['foreign workspace' => ['201'], 'revoked membership' => ['202'], 'noncanonical membership' => ['203'],
            'unassigned legacy' => ['204'], 'missing' => ['999999'], 'zero' => ['0'], 'leading zero' => ['0101'], 'text' => ['abc']];
    }

    public function test_access_is_lost_when_the_membership_is_revoked(): void
    {
        $users = $this->fixture();
        $this->actingAs($users['owner'])->get(route('customer.events.setup', ['event' => 101]))->assertOk();

        DB::table('workspace_users')->where('user_id', $users['owner']->id)->where('workspace_id', 10)->update(['is_active' => false]);

        $this->actingAs($users['owner'])->get(route('customer.events.setup', ['event' => 101]))->assertNotFound();
    }

    public function test_managers_can_view_and_other_roles_cannot(): void
    {
        $users = $this->fixture();

        $this->actingAs($users['manager'])->get(route('customer.events.setup', ['event' => 103]))->assertOk()->assertSee('Manager Party');
        $this->actingAs($users['manager'])->get(route('customer.events.setup', ['event' => 101]))->assertNotFound();
        foreach (['admin', 'scanner'] as $role) {
            $this->actingAs($users[$role])->get(route('customer.events.setup', ['event' => 101]))->assertForbidden();
        }
        auth()->logout();
        $this->get(route('customer.events.setup', ['event' => 101]))->assertRedirect(route('login'));
    }

    public function test_pending_destinations_never_link_to_fake_or_admin_pages(): void
    {
        $users = $this->fixture();

        $html = $this->actingAs($users['owner'])->get(route('customer.events.setup', ['event' => 101]))->getContent();

        preg_match_all('/href="([^"]+)"/', $html, $links);
        foreach ($links[1] as $href) {
            $path = (string) parse_url(html_entity_decode($href), PHP_URL_PATH);
            $this->assertTrue(in_array($path, ['/customer', '/customer/events/101/setup', '/customer/events/101/dashboard', '/customer/events/101/template'], true) || str_starts_with($path, '/build/') || str_starts_with($path, '/assets/'), $href);
        }
        $this->assertStringNotContainsString('/admin/', $html);
        // Working Flow 12: Continue opens this event's dashboard; step cards stay honest notes.
        $this->assertStringContainsString('href="'.route('customer.events.dashboard', ['event' => 101]).'" data-sp-dashboard', $html);
        $this->assertStringContainsString('Orodha ya Wageni itapatikana hivi karibuni.', $html);
        $this->assertStringContainsString('Kuhariri maelezo ya tukio kutapatikana hivi karibuni.', $html);
    }

    public function test_archived_events_show_their_lifecycle_read_only(): void
    {
        $users = $this->fixture();
        DB::table('events')->where('id', 101)->update(['status' => 'archived']);

        $this->actingAs($users['owner'])->get(route('customer.events.setup', ['event' => 101]))->assertOk()->assertSee('Tukio hili limehifadhiwa.');
        $this->assertSame('archived', DB::table('events')->where('id', 101)->value('status'));
    }

    public function test_storage_failure_shows_a_safe_retry_state(): void
    {
        $users = $this->fixture();
        Schema::rename('checkins', 'checkins_unavailable');

        $response = $this->actingAs($users['owner'])->get(route('customer.events.setup', ['event' => 101]));

        $response->assertStatus(500)->assertSee('Tumeshindwa kupakia maendeleo ya tukio')->assertSee('Jaribu tena')
            ->assertDontSee('SQLSTATE')->assertDontSee('checkins_unavailable');
    }

    public function test_service_steps_follow_the_reference_order(): void
    {
        $this->assertSame(['details', 'guests', 'invitations', 'rsvp', 'scanner', 'reports'], EventSetupProgress::STEPS);
    }

    private function statuses(TestResponse $response): array
    {
        return collect($response->viewData('progress')['steps'])->mapWithKeys(fn (array $step) => [$step['key'] => $step['status']])->all();
    }

    private function guests(int $event, int $count, string $status = 'active'): void
    {
        static $sequence = 0;
        for ($n = 0; $n < $count; $n++) {
            $sequence++;
            DB::table('guests')->insert(['event_id' => $event, 'name' => 'Synthetic guest '.$sequence, 'phone_number' => '+2557100'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
                'manual_code' => (string) (5000 + $sequence), 'pass_type' => 'single', 'allowed_entries' => 1, 'used_entries' => 0, 'status' => $status]);
        }
    }

    private function log(int $event, int $guest, string $type, string $status): void
    {
        DB::table('notification_logs')->insert(['event_id' => $event, 'guest_id' => $guest, 'channel' => 'whatsapp', 'message_type' => $type,
            'recipient_phone' => '+255710000000', 'message' => 'synthetic', 'status' => $status, 'provider' => 'synthetic']);
    }

    private function fixture(): array
    {
        $users = [];
        foreach (['owner' => 'customer', 'manager' => 'customer', 'foreign' => 'customer', 'admin' => 'admin', 'scanner' => 'scanner'] as $key => $role) {
            $users[$key] = User::query()->create([
                'name' => ucfirst($key).' Synthetic', 'email' => $key.'@example.invalid', 'phone_number' => '+25578800004'.count($users),
                'password' => 'synthetic-pass-123', 'role' => $role, 'is_active' => true, 'preferred_language' => 'sw',
                'gate_name' => $role === 'scanner' ? 'Main Gate' : null,
            ]);
        }
        foreach ([10 => 'Owner Studio', 11 => 'Manager Desk', 13 => 'Foreign Studio', 14 => 'Revoked Studio', 15 => 'Noncanonical Studio'] as $id => $name) {
            DB::table('workspaces')->insert(['id' => $id, 'name' => $name]);
        }
        foreach ([[10, 'owner', 'owner', 1], [11, 'manager', 'manager', 1], [13, 'foreign', 'owner', 1], [14, 'owner', 'owner', 0], [15, 'owner', 'owner', 2]] as [$workspace, $user, $role, $active]) {
            DB::table('workspace_users')->insert(['workspace_id' => $workspace, 'user_id' => $users[$user]->id, 'role' => $role, 'is_active' => $active]);
        }
        foreach ([
            [101, 'Neema & Baraka', 'Grand Hall', '2026-12-14', 10],
            [102, 'Planning Without Details', null, null, 10],
            [103, 'Manager Party', 'Masaki', '2027-02-14', 11],
            [201, 'Foreign private event', 'Hidden', '2026-12-20', 13],
            [202, 'Revoked event', 'Hidden', '2026-12-20', 14],
            [203, 'Noncanonical event', 'Hidden', '2026-12-20', 15],
            [204, 'Unassigned event', 'Hidden', '2026-12-20', null],
        ] as [$id, $name, $venue, $date, $workspace]) {
            DB::table('events')->insert(['id' => $id, 'event_name' => $name, 'venue_name' => $venue, 'event_date' => $date, 'status' => 'active', 'workspace_id' => $workspace]);
        }

        return $users;
    }
}
