<?php

namespace Tests\Feature\ReferenceUi;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Working Flow 08 — My Events List (reference UI/20_My_Events_List.png). */
class Screen08MyEventsListTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'synthetic-pass-123';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_customer_login_reaches_the_events_list_with_private_headers(): void
    {
        $this->fixture();

        $this->post(route('login.attempt'), ['identifier' => 'owner@example.invalid', 'password' => self::PASSWORD])
            ->assertRedirect(route('customer.home'));
        $response = $this->get(route('customer.home'));

        $response->assertOk()->assertViewIs('reference_ui.20_my_events_list')
            ->assertSee('<html lang="sw">', false)->assertSee('Matukio Yangu')->assertSee('PangaEvents')
            ->assertDontSee('Wedding QR Admission System')
            ->assertSee('Dhibiti na angalia matukio yote uliyounda.')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_guests_are_redirected_and_other_roles_are_forbidden(): void
    {
        $users = $this->fixture();

        $this->get(route('customer.home'))->assertRedirect(route('login'));
        $this->actingAs($users['admin'])->get(route('customer.home'))->assertForbidden();
        $this->actingAs($users['scanner'])->get(route('customer.home'))->assertForbidden();
    }

    public function test_admin_and_scanner_logins_keep_their_destinations(): void
    {
        $this->fixture();

        $this->post(route('login.attempt'), ['identifier' => 'admin@example.invalid', 'password' => self::PASSWORD])
            ->assertRedirect(route('admin.dashboard'));
        $this->post(route('logout'));
        $this->post(route('login.attempt'), ['identifier' => 'scanner@example.invalid', 'password' => self::PASSWORD])
            ->assertRedirect(route('scanner.dashboard'));
    }

    public function test_only_events_from_active_owner_or_manager_memberships_are_listed_with_real_details(): void
    {
        $users = $this->fixture();

        $response = $this->actingAs($users['owner'])->get(route('customer.home'));

        $response->assertOk();
        $events = $response->viewData('events');
        // Ongoing, then upcoming by date (undated last), completed, archived, cancelled.
        $this->assertSame([102, 101, 103, 107, 106, 104, 105, 108], $events->pluck('id')->map(fn ($id) => (int) $id)->all());
        $response->assertSee('Neema &amp; Baraka', false)->assertSee('14 Desemba 2026')
            ->assertSee('Grand Hall, Dar es Salaam')->assertSee('Wageni 3')
            ->assertSee('Harusi ya Rehema &amp; John', false)->assertSee('Inaendelea')
            ->assertSee('Inakuja')->assertSee('Imekamilika')->assertSee('Imehifadhiwa')->assertSee('Imeghairiwa')
            ->assertSee('Manager Desk Party');
        foreach (['Foreign private event', 'Revoked membership event', 'Noncanonical membership event', 'Unassigned legacy event', 'Inactive workspace event'] as $hidden) {
            $response->assertDontSee($hidden);
        }
        $response->assertDontSee('foreign@example.invalid');
        $this->assertSame(3, (int) $events->firstWhere('id', 101)->guests_count);
        $this->assertSame(0, (int) $events->firstWhere('id', 104)->guests_count);
        // Two workspaces: each card names its workspace.
        $response->assertSee('Sehemu ya kazi: Owner Studio')->assertSee('Sehemu ya kazi: Manager Desk');
    }

    public function test_status_counts_and_filters_use_stored_status_and_event_date(): void
    {
        $users = $this->fixture();

        $response = $this->actingAs($users['owner'])->get(route('customer.home'));
        $this->assertSame(['all' => 8, 'upcoming' => 4, 'ongoing' => 1, 'completed' => 1, 'archived' => 1, 'cancelled' => 1],
            $response->viewData('counts'));

        foreach (['upcoming' => [101, 103, 106, 107], 'ongoing' => [102], 'completed' => [104], 'archived' => [105], 'cancelled' => [108]] as $filter => $ids) {
            $filtered = $this->actingAs($users['owner'])->get(route('customer.home', ['status' => $filter]));
            $filtered->assertOk();
            $this->assertMatchesRegularExpression('/class="ev-chip is-active"[^>]*aria-current="true"\s+data-ev-filter="'.$filter.'"/', $filtered->getContent());
            $this->assertEqualsCanonicalizing($ids, $this->idsOf($filtered), $filter);
        }
        $bogus = $this->actingAs($users['owner'])->get(route('customer.home', ['status' => 'deleted']));
        $this->assertSame('all', $bogus->viewData('filter'));
        $this->assertCount(8, $this->idsOf($bogus));
    }

    public function test_search_matches_name_venue_couple_and_date_only_within_authorized_events(): void
    {
        $users = $this->fixture();
        $search = fn (string $q) => $this->idsOf($this->actingAs($users['owner'])->get(route('customer.home', ['q' => $q])));

        $this->assertSame([101], $search('NEEMA'));
        $this->assertSame([101], $search('baraka'));
        $this->assertSame([103], $search('serena'));
        $this->assertSame([103], $search('2027-01'));
        $this->assertSame([], $search('Foreign'));
        $this->assertSame([], $search('%'));
        $this->assertSame([], $search('_'));

        $none = $this->actingAs($users['owner'])->get(route('customer.home', ['q' => 'zzzz hakuna', 'lang' => 'en']));
        $none->assertOk()->assertSee('No events found')->assertSee('No events match &quot;zzzz hakuna&quot;', false)
            ->assertSee('data-ev-state="no-results"', false)->assertSee('Show all events');
        $this->assertSame(0, $none->viewData('counts')['all']);
    }

    public function test_new_customer_without_workspace_sees_the_creation_start_and_nothing_is_provisioned(): void
    {
        $users = $this->fixture();
        $before = [DB::table('workspaces')->count(), DB::table('workspace_users')->count(), DB::table('workspace_provisionings')->count()];
        $response = null;
        $queries = $this->captureQueries(function () use ($users, &$response): void {
            $response = $this->actingAs($users['new'])->get(route('customer.home'));
        });

        $response->assertOk()->assertSee('data-ev-state="no-workspace"', false)
            ->assertSee('Bado huna tukio')
            ->assertSee('Unda tukio lako la kwanza ili kuanza kutuma mialiko na kusimamia wageni.')
            ->assertSee('Unda Tukio')
            ->assertSee('href="'.route('customer.workspaces.create').'"', false)
            ->assertDontSee('data-ev-search', false);
        $this->assertSame($before, [DB::table('workspaces')->count(), DB::table('workspace_users')->count(), DB::table('workspace_provisionings')->count()]);
        $this->assertNull($response->viewData('events'));
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(from|join)\s+["`]?(events|guests)\b/i', $sql);
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $sql);
        }

        $en = $this->actingAs($users['new'])->get(route('customer.home', ['lang' => 'en']));
        $en->assertSee('No events yet')->assertSee('Create your first event to start sending invitations and managing guests.')
            ->assertSee('Create Event');
    }

    public function test_workspace_without_events_shows_the_empty_list_and_create_action(): void
    {
        $users = $this->fixture();

        $response = $this->actingAs($users['empty'])->get(route('customer.home'));

        $response->assertOk()->assertSee('data-ev-state="empty"', false)->assertSee('Bado huna tukio')
            ->assertSee('Sehemu yako ya kazi Empty Studio iko tayari.')
            ->assertSee('Unda Tukio Jipya')->assertSee('href="'.route('customer.events.create').'"', false)
            ->assertDontSee('data-ev-search', false);
        $this->assertSame(0, $response->viewData('events')->total());
    }

    public function test_create_event_never_points_to_a_fake_or_admin_destination(): void
    {
        $users = $this->fixture();

        $html = $this->actingAs($users['owner'])->get(route('customer.home'))->assertOk()->getContent();

        // With a workspace, Create Event opens Choose Event Type (Working Flow 09).
        $this->assertMatchesRegularExpression('/<a class="ev-primary ev-create" href="'.preg_quote(route('customer.events.create'), '/').'"/', $html);
        preg_match_all('/href="([^"]+)"/', $html, $links);
        foreach ($links[1] as $href) {
            $path = parse_url(html_entity_decode($href), PHP_URL_PATH);
            $this->assertTrue(in_array($path, ['/customer', '/customer/workspaces/create', '/customer/events/create', '/customer/profile'], true)
                || preg_match('#\A/customer/events/(101|102|103|104|105|106|107|108)/dashboard\z#', (string) $path) === 1
                || str_starts_with((string) $path, '/build/') || str_starts_with((string) $path, '/assets/'), $href);
        }
        $this->assertStringNotContainsString('/admin/', $html);
    }

    public function test_event_ids_in_urls_never_expose_another_customers_event(): void
    {
        $users = $this->fixture();

        $tampered = $this->actingAs($users['owner'])->get('/customer?event_id=201&workspace_id=9&user_id=4&per_page=500&page=1');
        $tampered->assertOk()->assertDontSee('Foreign private event');
        $this->assertSame(10, $tampered->viewData('events')->perPage());
        $this->actingAs($users['owner'])->get('/admin/events/201/edit')->assertForbidden();
        $this->actingAs($users['owner'])->get('/admin/events')->assertForbidden();
        // Working Flow 12: each card links only to its own authorized dashboard.
        preg_match_all('#/customer/events/(\d+)/dashboard#', $tampered->getContent(), $cards);
        $this->assertEqualsCanonicalizing([102, 101, 103, 107, 106, 104, 105, 108], array_map('intval', array_unique($cards[1])));
        $this->assertStringNotContainsString('/customer/events/201/dashboard', $tampered->getContent());
    }

    public function test_missing_cover_date_and_venue_use_fallbacks(): void
    {
        $users = $this->fixture();

        $response = $this->actingAs($users['owner'])->get(route('customer.home', ['q' => 'Planning', 'lang' => 'en']));

        $response->assertOk()->assertSee('Planning Without Details')->assertSee('Date not set yet')
            ->assertSee('Venue not set yet')->assertSee('No guests yet')->assertSee('class="ev-cover"', false);
        $this->assertSame(substr_count($response->getContent(), 'class="ev-card"'), substr_count($response->getContent(), 'class="ev-cover"'));
    }

    public function test_list_queries_do_not_grow_with_the_number_of_events(): void
    {
        $users = $this->fixture();
        $count = function () use ($users): int {
            return count($this->captureQueries(fn () => $this->actingAs($users['owner'])->get(route('customer.home'))->assertOk()));
        };
        $small = $count();
        for ($n = 1; $n <= 6; $n++) {
            DB::table('events')->insert(['id' => 400 + $n, 'event_name' => 'Bulk '.$n, 'workspace_id' => 11, 'status' => 'active', 'event_date' => '2027-05-0'.$n]);
            DB::table('guests')->insert(['event_id' => 400 + $n, 'name' => 'Bulk guest '.$n, 'phone_number' => '+25571999000'.$n, 'manual_code' => '9'.$n.'00', 'pass_type' => 'single', 'allowed_entries' => 1, 'used_entries' => 0, 'status' => 'active']);
        }

        $this->assertSame($small, $count());
    }

    public function test_language_defaults_to_kiswahili_follows_preference_and_is_retained(): void
    {
        $users = $this->fixture();

        $this->actingAs($users['owner'])->get(route('customer.home'))->assertSee('<html lang="sw">', false);
        $users['owner']->forceFill(['preferred_language' => 'en'])->save();
        $this->actingAs($users['owner'])->get(route('customer.home'))->assertSee('<html lang="en">', false)
            ->assertSee('My Events')->assertSee('Upcoming')->assertSee('Ongoing')->assertSee('Completed')->assertSee('Archived')->assertSee('Cancelled');
        $this->actingAs($users['owner'])->get(route('customer.home', ['lang' => 'sw']))->assertSee('<html lang="sw">', false);
        $this->assertSame('sw', session('login_language'));
        $this->actingAs($users['owner'])->get(route('customer.home'))->assertSee('<html lang="sw">', false)
            ->assertSee('Unda Tukio Jipya')->assertSee('Nyumbani')->assertSee('Wageni')->assertSee('Ripoti');
        $this->actingAs($users['owner'])->get(route('customer.home', ['lang' => 'fr']))->assertSee('<html lang="sw">', false);
    }

    public function test_language_chips_keep_search_and_filter(): void
    {
        $users = $this->fixture();

        $html = $this->actingAs($users['owner'])->get(route('customer.home', ['q' => 'neema', 'status' => 'upcoming']))->getContent();

        $this->assertStringContainsString('href="'.e(route('customer.home', ['lang' => 'en', 'q' => 'neema', 'status' => 'upcoming'])).'"', $html);
    }

    public function test_storage_failure_shows_a_safe_retry_state(): void
    {
        $users = $this->fixture();
        Schema::rename('guests', 'guests_unavailable');

        $response = $this->actingAs($users['owner'])->get(route('customer.home'));

        $response->assertStatus(500)->assertSee('Tumeshindwa kupakia matukio yako')->assertSee('Jaribu tena')
            ->assertDontSee('SQLSTATE')->assertDontSee('guests_unavailable')->assertDontSee('owner@example.invalid');
    }

    #[DataProvider('accountChanges')]
    public function test_suspended_or_reassigned_account_sees_no_events(array $change, int $status): void
    {
        $users = $this->fixture();
        DB::table('users')->where('id', $users['owner']->id)->update($change);

        $response = $this->actingAs($users['owner']->fresh())->get(route('customer.home'));

        $response->assertStatus($status)->assertDontSee('Neema &amp; Baraka', false);
    }

    public static function accountChanges(): array
    {
        // Existing middleware signs a suspended account out; a role change is forbidden.
        return ['suspended' => [['is_active' => false], 302], 'became admin' => [['role' => 'admin'], 403]];
    }

    public function test_logout_from_the_events_list_ends_the_session(): void
    {
        $users = $this->fixture();

        $html = $this->actingAs($users['owner'])->get(route('customer.home'))->getContent();
        $this->assertStringContainsString('action="'.route('logout').'"', $html);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get(route('customer.home'))->assertRedirect(route('login'));
    }

    private function idsOf(TestResponse $response): array
    {
        $response->assertOk();
        $events = $response->viewData('events');

        return $events === null ? [] : $events->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function captureQueries(callable $operation): array
    {
        $queries = [];
        $listening = true;
        DB::listen(function (QueryExecuted $event) use (&$queries, &$listening): void {
            if ($listening) {
                $queries[] = $event->sql;
            }
        });
        try {
            $operation();
        } finally {
            $listening = false;
        }

        return $queries;
    }

    private function fixture(): array
    {
        $users = [];
        foreach (['owner' => 'customer', 'manager' => 'customer', 'empty' => 'customer', 'new' => 'customer', 'foreign' => 'customer', 'admin' => 'admin', 'scanner' => 'scanner'] as $key => $role) {
            $users[$key] = User::query()->create([
                'name' => ucfirst($key).' Synthetic', 'email' => $key.'@example.invalid', 'phone_number' => '+2557880000'.str_pad((string) (count($users) + 10), 2, '0', STR_PAD_LEFT),
                'password' => self::PASSWORD, 'role' => $role, 'is_active' => true, 'preferred_language' => 'sw',
                'gate_name' => $role === 'scanner' ? 'Main Gate' : null,
            ]);
        }
        foreach ([10 => 'Owner Studio', 11 => 'Manager Desk', 12 => 'Empty Studio', 13 => 'Foreign Studio', 14 => 'Revoked Studio', 15 => 'Noncanonical Studio'] as $id => $name) {
            DB::table('workspaces')->insert(['id' => $id, 'name' => $name]);
        }
        foreach ([[10, 'owner', 'owner', 1], [11, 'owner', 'manager', 1], [12, 'empty', 'owner', 1], [13, 'foreign', 'owner', 1], [14, 'owner', 'owner', 0], [15, 'owner', 'owner', 2]] as [$workspace, $user, $role, $active]) {
            DB::table('workspace_users')->insert(['workspace_id' => $workspace, 'user_id' => $users[$user]->id, 'role' => $role, 'is_active' => $active]);
        }
        $events = [
            [101, 'Neema & Baraka', 'Neema', 'Baraka', 'Grand Hall, Dar es Salaam', '2026-12-14', 'active', 10, 3],
            [102, 'Harusi ya Rehema & John', null, null, 'Hyatt, Dar es Salaam', '2026-11-12', 'active', 10, 1],
            [103, 'Sherehe ya Kuzaliwa ya Asha', null, null, 'Serena Hotel', '2027-01-03', 'active', 10, 0],
            [104, 'Send-off ya Kijana', null, null, 'Slipway', '2026-08-12', 'active', 10, 0],
            [105, 'Archived Gala', null, null, 'Mlimani', '2025-11-30', 'archived', 10, 0],
            [106, 'Planning Without Details', null, null, null, null, 'active', 10, 0],
            [107, 'Manager Desk Party', null, null, 'Masaki', '2027-02-14', 'active', 11, 2],
            [108, 'Cancelled Beach Party', null, null, 'Coco Beach', '2026-12-31', 'cancelled', 10, 0],
            [201, 'Foreign private event', null, null, 'Foreign venue', '2026-12-20', 'active', 13, 4],
            [202, 'Revoked membership event', null, null, 'Hidden', '2026-12-20', 'active', 14, 0],
            [203, 'Noncanonical membership event', null, null, 'Hidden', '2026-12-20', 'active', 15, 0],
            [204, 'Unassigned legacy event', null, null, 'Hidden', '2026-12-20', 'active', null, 0],
        ];
        $code = 1000;
        foreach ($events as [$id, $name, $bride, $groom, $venue, $date, $status, $workspace, $guests]) {
            DB::table('events')->insert(['id' => $id, 'event_name' => $name, 'bride_name' => $bride, 'groom_name' => $groom, 'venue_name' => $venue,
                'event_date' => $date, 'status' => $status, 'workspace_id' => $workspace]);
            for ($g = 1; $g <= $guests; $g++) {
                DB::table('guests')->insert(['event_id' => $id, 'name' => "Guest $id-$g", 'phone_number' => '+2557'.($id * 100 + $g).'000', 'manual_code' => (string) $code++,
                    'pass_type' => 'single', 'allowed_entries' => 1, 'used_entries' => 0, 'status' => 'active']);
            }
        }

        return $users;
    }
}
