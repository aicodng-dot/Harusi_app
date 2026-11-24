<?php

namespace Tests\Feature\ReferenceUi;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Working Flow 12 — Event Dashboard (reference UI/12_Event_Dashboard.png). */
class Screen12EventDashboardTest extends TestCase
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

    public function test_authorized_customer_sees_the_dashboard_with_real_event_details(): void
    {
        $users = $this->fixture();

        $page = $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 101]));

        $page->assertOk()->assertViewIs('reference_ui.12_event_dashboard')
            ->assertSee('<html lang="sw">', false)->assertSee('Dashibodi ya Tukio')->assertSee('Muhtasari wa tukio lako.')
            ->assertSee('Mwaliko wa Harusi')->assertSee('Neema')->assertSee('Baraka')->assertSee('class="db-amp"', false)
            ->assertSee('Desemba')->assertSee('2026')->assertSee('Grand Hall, Dar es Salaam')
            ->assertSee('Saa 12:00 Jioni')->assertSee('Inakuja')
            ->assertSee('04_Choose_Event_Type_Wedding.webp', false)
            ->assertSee('href="'.route('customer.home').'"', false)
            ->assertSee('href="'.route('customer.events.setup', ['event' => 101]).'"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->assertNull(session('selected_event_id'));
    }

    public function test_statistics_reflect_this_events_records_with_guests_and_entries_distinct(): void
    {
        $users = $this->fixture();
        $this->populate(101, 102);
        DB::table('users')->where('id', $users['scanner']->id)->update(['event_id' => 101]);

        $page = $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 101]));
        $stats = $page->viewData('stats');

        $this->assertSame([4, 2, 2, null, 1, 7, 3, 4, 2, 43], [
            $stats['guests'], $stats['invitations_sent'], $stats['invitations_remaining'], $stats['rsvp_confirmed'], $stats['scanners'],
            $stats['permitted_entries'], $stats['used_entries'], $stats['remaining_entries'], $stats['admitted_guests'], $stats['entries_percent'],
        ]);
        $page->assertSee('Nafasi 3 kati ya 7 zimetumika')->assertSee('Wageni 2 wameingia · Nafasi 4 zimebaki')
            ->assertSee('Hivi karibuni')->assertSee('Majibu ya RSVP yatapatikana hivi karibuni.')
            ->assertSee('Mialiko Yako')->assertSee('Endelea na Mialiko')->assertSee('Bado kuna wageni 2 ambao hawajapewa mialiko.');

        // The second event's records never leak into the first (and vice versa).
        $other = $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 102]))->viewData('stats');
        $this->assertSame([1, 0, 9, 0, 0], [$other['guests'], $other['invitations_sent'], $other['permitted_entries'], $other['used_entries'], $other['admitted_guests']]);
    }

    public function test_unsupported_metrics_are_never_fabricated(): void
    {
        $users = $this->fixture();
        $this->populate(101, 102);
        // QR tickets are not delivered invitations.
        foreach (DB::table('guests')->where('event_id', 101)->pluck('id') as $guest) {
            DB::table('qr_codes')->insert(['guest_id' => $guest, 'qr_token' => hash('sha256', 'synthetic'.$guest), 'is_active' => true]);
        }

        $page = $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 101]));

        $this->assertSame(2, $page->viewData('stats')['invitations_sent']);
        $this->assertNull($page->viewData('stats')['rsvp_confirmed']);
        $this->assertMatchesRegularExpression('/data-stat="confirmed".*?db-stat-value is-unavailable">Hivi karibuni</s', $page->getContent());
    }

    public function test_new_event_without_guests_renders_zero_states(): void
    {
        $users = $this->fixture();

        $page = $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 103, 'lang' => 'en']));

        $page->assertOk()->assertSee('<html lang="en">', false)->assertSee('Event Dashboard')->assertSee('Kitchen Party Invitation')
            ->assertSee('2:30 PM')->assertSee('No admission entries yet. Add guests first.')->assertSee('Add Your Event Guests')
            ->assertSee('Not assigned')->assertSee('Event setup: 1 of 5 (20%)');
        $stats = $page->viewData('stats');
        $this->assertSame([0, 0, 0, 0], [$stats['guests'], $stats['invitations_sent'], $stats['permitted_entries'], $stats['used_entries']]);
    }

    public function test_missing_optional_details_use_fallbacks(): void
    {
        $users = $this->fixture();

        $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 104]))->assertOk()
            ->assertSee('Tarehe bado haijawekwa')->assertSee('Ukumbi bado haujawekwa')->assertSee('Kamilisha Maelezo ya Tukio')
            ->assertSee('class="db-hero-art is-placeholder"', false);
    }

    #[DataProvider('historical')]
    public function test_historical_events_remain_viewable_read_only(string $status, string $notice): void
    {
        $users = $this->fixture();
        DB::table('events')->where('id', 101)->update(['status' => $status]);

        $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 101]))->assertOk()->assertSee($notice);
        $this->assertSame($status, DB::table('events')->where('id', 101)->value('status'));
    }

    public static function historical(): array
    {
        return ['archived' => ['archived', 'Tukio hili limehifadhiwa.'], 'cancelled' => ['cancelled', 'Tukio hili limeghairiwa.']];
    }

    #[DataProvider('deniedEvents')]
    public function test_foreign_revoked_unassigned_and_unknown_events_are_refused(string $event): void
    {
        $users = $this->fixture();
        $this->populate(201, 101);

        $response = $this->actingAs($users['owner'])->get('/customer/events/'.$event.'/dashboard');

        $response->assertNotFound()->assertDontSee('Foreign private event')->assertDontSee('Revoked event');
    }

    public static function deniedEvents(): array
    {
        return ['foreign workspace' => ['201'], 'revoked membership' => ['202'], 'noncanonical membership' => ['203'],
            'unassigned legacy' => ['204'], 'missing' => ['999999'], 'zero' => ['0'], 'leading zero' => ['0101'], 'text' => ['abc']];
    }

    public function test_revoked_membership_loses_access_and_other_roles_are_refused(): void
    {
        $users = $this->fixture();
        $this->actingAs($users['manager'])->get(route('customer.events.dashboard', ['event' => 105]))->assertOk();
        $this->actingAs($users['manager'])->get(route('customer.events.dashboard', ['event' => 101]))->assertNotFound();

        DB::table('workspace_users')->where('user_id', $users['owner']->id)->where('workspace_id', 10)->update(['is_active' => false]);
        $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 101]))->assertNotFound();

        foreach (['admin', 'scanner'] as $role) {
            $this->actingAs($users[$role])->get(route('customer.events.dashboard', ['event' => 101]))->assertForbidden();
        }
        auth()->logout();
        $this->get(route('customer.events.dashboard', ['event' => 101]))->assertRedirect(route('login'));
    }

    public function test_viewing_the_dashboard_is_read_only_and_query_count_is_constant(): void
    {
        $users = $this->fixture();
        $this->populate(101, 102);
        $count = function () use ($users): array {
            $queries = [];
            $listening = true;
            DB::listen(function (QueryExecuted $event) use (&$queries, &$listening): void {
                if ($listening) {
                    $queries[] = $event->sql;
                }
            });
            $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 101]))->assertOk();
            $listening = false;

            return $queries;
        };

        $first = $count();
        foreach ($first as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $sql);
        }
        for ($n = 0; $n < 5; $n++) {
            $this->guest(101, 'active', 1, 0);
        }
        $this->assertCount(count($first), $count());
    }

    public function test_my_events_cards_open_their_own_dashboards(): void
    {
        $users = $this->fixture();

        $html = $this->actingAs($users['owner'])->get(route('customer.home'))->assertOk()->getContent();

        foreach ([101 => 'Neema &amp; Baraka', 103 => 'Kitchen Party ya Neema'] as $id => $name) {
            $this->assertMatchesRegularExpression('#<a class="ev-card-link" href="'.preg_quote(route('customer.events.dashboard', ['event' => $id]), '#').'">'.preg_quote($name, '#').'</a>#', $html);
        }
        $this->assertStringNotContainsString('/customer/events/201/dashboard', $html);
        $this->actingAs($users['owner'])->get(route('customer.home', ['q' => 'kitchen']))->assertSee('/customer/events/103/dashboard', false)
            ->assertDontSee('/customer/events/101/dashboard', false);
    }

    public function test_setup_progress_and_dashboard_link_to_each_other(): void
    {
        $users = $this->fixture();

        $this->actingAs($users['owner'])->get(route('customer.events.setup', ['event' => 101]))
            ->assertSee('href="'.route('customer.events.dashboard', ['event' => 101]).'" data-sp-dashboard', false);
        $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 101]))
            ->assertSee('class="db-hero-open" href="'.route('customer.events.setup', ['event' => 101]).'"', false);
    }

    public function test_creation_still_produces_one_event_and_reaches_its_dashboard(): void
    {
        $users = $this->fixture();
        $this->actingAs($users['owner'])->post(route('customer.events.type'), ['event_type' => 'send_off']);
        $this->actingAs($users['owner'])->get(route('customer.events.details'));
        $payload = ['event_name' => 'Send-Off ya Neema', 'event_datetime' => '2027-03-01T15:00', 'venue_name' => 'Slipway', 'request_key' => session('customer_event_setup')['request_key']];

        $this->actingAs($users['owner'])->post(route('customer.events.store'), $payload);
        $this->actingAs($users['owner'])->post(route('customer.events.store'), $payload);

        $id = DB::table('events')->where('event_name', 'Send-Off ya Neema')->value('id');
        $this->assertSame(1, DB::table('events')->where('event_name', 'Send-Off ya Neema')->count());
        $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => $id]))->assertOk()
            ->assertSee('Mwaliko wa Send-Off')->assertSee('Saa 9:00 Mchana');
    }

    public function test_pending_actions_never_link_to_admin_or_fake_pages(): void
    {
        $users = $this->fixture();

        $html = $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 101]))->getContent();

        preg_match_all('/href="([^"]+)"/', $html, $links);
        foreach ($links[1] as $href) {
            $path = (string) parse_url(html_entity_decode($href), PHP_URL_PATH);
            $this->assertTrue(in_array($path, ['/customer', '/customer/events/101/setup', '/customer/events/101/dashboard', '/customer/events/101/invitation/continue', '/customer/events/101/edit', '/customer/events/101/template', /* navigation shell: */ '/customer/help', '/customer/profile', '/customer/events/create', '/customer/events/101/edit', '/customer/events/101/invitation', '/customer/events/101/invitation/guests', '/customer/events/101/guests/add', '/customer/events/101/invitation/status', '/customer/events/101/share', '/customer/events/101/share/history', '/customer/events/101/share/templates', ''], true) || str_starts_with($path, '/build/') || str_starts_with($path, '/assets/'), $href);
        }
        $this->assertStringNotContainsString('/admin/', $html);
        $this->assertStringContainsString('aria-current="page" aria-label="Nyumbani — Dashibodi ya Neema &amp; Baraka"', $html);
        $this->assertStringContainsString('data-db-pending="Ongeza Wageni wa Tukio itapatikana hivi karibuni."', $html);
    }

    public function test_storage_failure_shows_a_safe_retry_state(): void
    {
        $users = $this->fixture();
        Schema::rename('checkins', 'checkins_unavailable');

        $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 101]))->assertStatus(500)
            ->assertSee('Tumeshindwa kupakia takwimu za tukio')->assertSee('Jaribu tena')->assertDontSee('SQLSTATE')->assertDontSee('checkins_unavailable');
    }

    /** Event $event: 4 active guests (allowed 1+2+2+2=7) + 1 cancelled; 2 sent cards; 3 admitted entries by 2 guests. */
    private function populate(int $event, int $other): void
    {
        $ids = [$this->guest($event, 'active', 1, 1), $this->guest($event, 'active', 2, 2), $this->guest($event, 'active', 2, 0), $this->guest($event, 'active', 2, 0)];
        $cancelled = $this->guest($event, 'cancelled', 5, 0);
        foreach ([[$ids[0], 'sent'], [$ids[0], 'sent'], [$ids[1], 'sent'], [$ids[2], 'simulated'], [$ids[3], 'failed'], [$cancelled, 'sent']] as [$guest, $status]) {
            DB::table('notification_logs')->insert(['event_id' => $event, 'guest_id' => $guest, 'channel' => 'whatsapp', 'message_type' => 'digital_card',
                'recipient_phone' => '+255710000000', 'message' => 'synthetic', 'status' => $status, 'provider' => 'synthetic']);
        }
        foreach ([[$ids[0], 1, 'admitted'], [$ids[1], 2, 'admitted'], [$ids[2], 1, 'duplicate']] as [$guest, $entries, $result]) {
            DB::table('checkins')->insert(['event_id' => $event, 'guest_id' => $guest, 'entries_added' => $entries, 'used_entries_after_scan' => $entries, 'scan_result' => $result]);
        }
        // The other event gets its own guest that must never be counted here.
        $this->guest($other, 'active', 9, 0);
    }

    private function guest(int $event, string $status, int $allowed, int $used): int
    {
        static $sequence = 0;
        $sequence++;

        return (int) DB::table('guests')->insertGetId(['event_id' => $event, 'name' => 'Synthetic guest '.$sequence, 'phone_number' => '+2557200'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            'manual_code' => (string) (7000 + $sequence), 'pass_type' => 'single', 'allowed_entries' => $allowed, 'used_entries' => $used, 'status' => $status]);
    }

    private function fixture(): array
    {
        $users = [];
        foreach (['owner' => 'customer', 'manager' => 'customer', 'foreign' => 'customer', 'admin' => 'admin', 'scanner' => 'scanner'] as $key => $role) {
            $users[$key] = User::query()->create([
                'name' => ucfirst($key).' Synthetic', 'email' => $key.'@example.invalid', 'phone_number' => '+25578800005'.count($users),
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
            [101, 'Neema & Baraka', 'Grand Hall, Dar es Salaam', '2026-12-14', '18:00:00', 'wedding', 10],
            [102, 'Second Event', 'Hall B', '2026-12-20', null, null, 10],
            [103, 'Kitchen Party ya Neema', 'Masaki', '2027-01-20', '14:30:00', 'kitchen_party', 10],
            [104, 'Planning Without Details', null, null, null, null, 10],
            [105, 'Manager Party', 'Masaki', '2027-02-14', null, 'other', 11],
            [201, 'Foreign private event', 'Hidden', '2026-12-20', null, null, 13],
            [202, 'Revoked event', 'Hidden', '2026-12-20', null, null, 14],
            [203, 'Noncanonical event', 'Hidden', '2026-12-20', null, null, 15],
            [204, 'Unassigned event', 'Hidden', '2026-12-20', null, null, null],
        ] as [$id, $name, $venue, $date, $time, $type, $workspace]) {
            DB::table('events')->insert(['id' => $id, 'event_name' => $name, 'venue_name' => $venue, 'event_date' => $date, 'event_time' => $time,
                'event_type' => $type, 'status' => 'active', 'workspace_id' => $workspace]);
        }

        return $users;
    }
}
