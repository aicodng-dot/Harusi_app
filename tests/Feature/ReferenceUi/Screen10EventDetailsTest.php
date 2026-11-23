<?php

namespace Tests\Feature\ReferenceUi;

use App\Models\Event;
use App\Models\User;
use App\Services\Tenancy\CustomerEventCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Working Flow 10 — Event Details (reference UI/05_Event_Details.png). */
class Screen10EventDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'customer_event_setup';

    private const VALID = ['event_name' => 'Neema & Baraka', 'event_datetime' => '2026-12-14T18:00', 'venue_name' => 'Grand Hall, Dar es Salaam'];

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

    public function test_choose_event_type_opens_event_details_with_the_selected_type(): void
    {
        $users = $this->fixture();

        $this->actingAs($users['single'])->post(route('customer.events.type'), ['event_type' => 'wedding'])
            ->assertRedirect(route('customer.events.details'));
        $page = $this->actingAs($users['single'])->get(route('customer.events.details'));

        $page->assertOk()->assertViewIs('reference_ui.05_event_details')
            ->assertSee('<html lang="sw">', false)->assertSee('Maelezo ya Tukio')->assertSee('Jaza maelezo ya msingi ya tukio lako.')
            ->assertSee('Majina (Mfano: Neema &amp; Baraka)', false)->assertSee('Tarehe na Saa')->assertSee('Ukumbi')
            ->assertSee('Tengeneza Kadi')->assertSee('data-event-type="wedding"', false)
            ->assertSee('href="'.route('customer.events.create').'"', false)
            ->assertSee('type="datetime-local"', false)->assertSee('min="2026-11-13T00:00"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $key = session(self::KEY)['request_key'];
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $key);
        $page->assertSee('name="request_key" value="'.$key.'"', false);
        // The key stays stable for the same setup.
        $this->actingAs($users['single'])->get(route('customer.events.details'));
        $this->assertSame($key, session(self::KEY)['request_key']);
    }

    #[DataProvider('types')]
    public function test_each_supported_type_creates_exactly_one_authorized_event(string $type, string $label): void
    {
        $users = $this->fixture();
        $this->openDetails($users['single'], $type);
        $before = Event::query()->count();

        $response = $this->actingAs($users['single'])->post(route('customer.events.store'), self::VALID + ['request_key' => session(self::KEY)['request_key']]);

        $response->assertRedirectContains('/setup')->assertSessionHas('customer_event_created', 'Neema & Baraka');
        $this->assertSame($before + 1, Event::query()->count());
        $event = DB::table('events')->orderByDesc('id')->first();
        $response->assertRedirect(route('customer.events.setup', ['event' => $event->id]));
        $this->assertSame(['Neema & Baraka', $type, 'Grand Hall, Dar es Salaam', 'active', 10, null, null],
            [$event->event_name, $event->event_type, $event->venue_name, $event->status, (int) $event->workspace_id, $event->bride_name, $event->groom_name]);
        $this->assertStringStartsWith('2026-12-14', (string) $event->event_date);
        $this->assertSame('18:00:00', $event->event_time);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $event->creation_key);
        $this->assertNull(session(self::KEY));

        $list = $this->actingAs($users['single'])->get(route('customer.home'));
        $list->assertOk()->assertSee('Tukio lako &quot;Neema &amp; Baraka&quot; limeundwa.', false)
            ->assertSee('14 Desemba 2026, 18:00')->assertSee($label);
    }

    public static function types(): array
    {
        return ['wedding' => ['wedding', 'Harusi'], 'send_off' => ['send_off', 'Send-Off'], 'kitchen_party' => ['kitchen_party', 'Kitchen Party'], 'other' => ['other', 'Sherehe Nyingine']];
    }

    public function test_labels_follow_the_selected_type_and_language(): void
    {
        $users = $this->fixture();

        $this->openDetails($users['single'], 'kitchen_party');
        $this->actingAs($users['single'])->get(route('customer.events.details'))->assertSee('Jina la tukio (Mfano: Kitchen Party ya Neema)');
        $this->actingAs($users['single'])->get(route('customer.events.details', ['lang' => 'en']))
            ->assertSee('<html lang="en">', false)->assertSee('Event Details')->assertSee('Event name (Example: Neema&#039;s Kitchen Party)', false)
            ->assertSee('Date and Time')->assertSee('Venue')->assertSee('Create Card');
    }

    #[DataProvider('invalidDetails')]
    public function test_invalid_details_are_rejected_with_preserved_input_and_no_event(array $override, string $field, string $message): void
    {
        $users = $this->fixture();
        $this->openDetails($users['single'], 'wedding');
        $before = Event::query()->count();

        $response = $this->actingAs($users['single'])->post(route('customer.events.store'), array_merge(self::VALID, $override, ['request_key' => session(self::KEY)['request_key']]));

        $response->assertStatus(422)->assertSee($message, false)->assertSee('Tafadhali rekebisha taarifa zilizoonyeshwa hapa chini.');
        $this->assertMatchesRegularExpression('/id="ed-error-'.$field.'" class="ed-error"\s*>/', $response->getContent());
        $this->assertSame($before, Event::query()->count());
        $this->assertNotNull(session(self::KEY));
        foreach (self::VALID as $name => $value) {
            if (! array_key_exists($name, $override)) {
                $response->assertSee('value="'.e($value).'"', false);
            }
        }
    }

    public static function invalidDetails(): array
    {
        return [
            'missing names' => [['event_name' => ''], 'event_name', 'Andika majina ya maharusi.'],
            'blank names' => [['event_name' => "   \u{00a0}"], 'event_name', 'Andika majina ya maharusi.'],
            'long names' => [['event_name' => str_repeat('a', 161)], 'event_name', 'Tumia herufi 1 hadi 160'],
            'control characters' => [['event_name' => "Neema\u{0007}"], 'event_name', 'Tumia herufi 1 hadi 160'],
            'array names' => [['event_name' => ['Neema']], 'event_name', 'Tumia herufi 1 hadi 160'],
            'missing date' => [['event_datetime' => ''], 'event_datetime', 'Chagua tarehe na saa ya tukio.'],
            'impossible date' => [['event_datetime' => '2026-02-30T10:00'], 'event_datetime', 'Tarehe au saa si sahihi.'],
            'impossible time' => [['event_datetime' => '2026-12-14T25:00'], 'event_datetime', 'Tarehe au saa si sahihi.'],
            'space separator' => [['event_datetime' => '2026-12-14 18:00'], 'event_datetime', 'Tarehe au saa si sahihi.'],
            'words' => [['event_datetime' => 'tomorrow'], 'event_datetime', 'Tarehe au saa si sahihi.'],
            'past date' => [['event_datetime' => '2026-11-12T23:59'], 'event_datetime', 'Tarehe ya tukio haiwezi kuwa ya zamani.'],
            'too far' => [['event_datetime' => '2036-11-14T10:00'], 'event_datetime', 'Chagua tarehe ndani ya miaka 10 ijayo.'],
            'missing venue' => [['venue_name' => ''], 'venue_name', 'Andika ukumbi wa tukio.'],
            'long venue' => [['venue_name' => str_repeat('v', 161)], 'venue_name', 'Tumia herufi 1 hadi 160 kwa jina la ukumbi.'],
        ];
    }

    public function test_today_is_accepted_and_seconds_are_ignored(): void
    {
        $users = $this->fixture();
        $this->openDetails($users['single'], 'other');

        $this->actingAs($users['single'])->post(route('customer.events.store'), ['event_datetime' => '2026-11-13T07:30:45'] + self::VALID + ['request_key' => session(self::KEY)['request_key']])
            ->assertRedirectContains('/setup');
        $this->assertSame('07:30:00', DB::table('events')->orderByDesc('id')->value('event_time'));
    }

    public function test_missing_or_foreign_setup_returns_to_choose_event_type(): void
    {
        $users = $this->fixture();

        $this->actingAs($users['single'])->get(route('customer.events.details'))
            ->assertRedirect(route('customer.events.create'))->assertSessionHas('event_setup_notice', 'setup_missing');
        foreach ([
            ['user_id' => $users['multi']->id, 'workspace_id' => 10, 'event_type' => 'wedding'],
            ['user_id' => $users['single']->id, 'workspace_id' => 13, 'event_type' => 'wedding'],
            ['user_id' => $users['single']->id, 'workspace_id' => 10, 'event_type' => 'funeral'],
            ['user_id' => (string) $users['single']->id, 'workspace_id' => 10, 'event_type' => 'wedding'],
        ] as $setup) {
            $this->actingAs($users['single'])->withSession([self::KEY => $setup])->get(route('customer.events.details'))
                ->assertRedirect(route('customer.events.create'));
            $this->assertNull(session(self::KEY));
            $this->actingAs($users['single'])->withSession([self::KEY => $setup + ['request_key' => (string) Str::uuid()]])
                ->post(route('customer.events.store'), self::VALID)->assertRedirect(route('customer.events.create'));
        }
        $this->assertSame(1, Event::query()->count());
        $this->actingAs($users['single'])->get(route('customer.events.create'))->assertSee('Chagua aina ya tukio kwanza');
    }

    public function test_forged_ownership_fields_cannot_change_the_new_event(): void
    {
        $users = $this->fixture();
        $this->openDetails($users['single'], 'send_off');

        $this->actingAs($users['single'])->post(route('customer.events.store'), self::VALID + [
            'request_key' => session(self::KEY)['request_key'], 'workspace_id' => 13, 'user_id' => $users['foreign']->id,
            'event_type' => 'other', 'status' => 'archived', 'creation_key' => str_repeat('a', 64), 'id' => 999,
        ])->assertRedirectContains('/setup');

        $event = DB::table('events')->orderByDesc('id')->first();
        $this->assertSame([10, 'send_off', 'active'], [(int) $event->workspace_id, $event->event_type, $event->status]);
        $this->assertNotSame(999, (int) $event->id);
        $this->assertNotSame(str_repeat('a', 64), $event->creation_key);
        $this->assertSame(0, DB::table('events')->where('workspace_id', 13)->where('event_name', 'Neema & Baraka')->count());
    }

    public function test_revoked_access_before_submit_creates_nothing(): void
    {
        $users = $this->fixture();
        $this->openDetails($users['single'], 'wedding');
        $key = session(self::KEY)['request_key'];
        DB::table('workspace_users')->where('user_id', $users['single']->id)->update(['is_active' => false]);

        $this->actingAs($users['single'])->post(route('customer.events.store'), self::VALID + ['request_key' => $key])
            ->assertRedirect(route('customer.workspaces.create'));
        $this->assertSame(1, Event::query()->count());
    }

    public function test_authority_is_rechecked_inside_the_creation_transaction(): void
    {
        $users = $this->fixture();
        $this->openDetails($users['single'], 'wedding');
        $key = session(self::KEY)['request_key'];
        $revoked = false;
        $inCreator = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$revoked, &$inCreator, $users): void {
            // The creator's own account lock (no platform column) starts its transaction; revoke
            // after the request-level eligibility check, right before its locked membership read.
            if (str_starts_with($sql, 'select "id", "role", "is_active" from "users"')) {
                $inCreator = true;
            }
            if ($inCreator && ! $revoked && str_starts_with($sql, 'select "id", "workspace_id", "user_id", "role", "is_active" from "workspace_users"')) {
                $revoked = true;
                DB::table('workspace_users')->where('user_id', $users['single']->id)->update(['is_active' => false]);
            }
        });

        $this->actingAs($users['single'])->post(route('customer.events.store'), self::VALID + ['request_key' => $key])
            ->assertRedirect(route('customer.events.create'));

        $this->assertTrue($revoked);
        $this->assertSame(1, Event::query()->count());
        $this->assertNull(session(self::KEY));
    }

    public function test_repeated_submissions_resolve_to_one_event(): void
    {
        $users = $this->fixture();
        $this->openDetails($users['single'], 'wedding');
        $setup = session(self::KEY);
        $payload = self::VALID + ['request_key' => $setup['request_key']];

        $this->actingAs($users['single'])->post(route('customer.events.store'), $payload)->assertRedirectContains('/setup');
        // A second tap after the setup was consumed resolves to the same completed event.
        $this->actingAs($users['single'])->post(route('customer.events.store'), $payload)
            ->assertRedirectContains('/setup')->assertSessionHas('customer_event_created', 'Neema & Baraka');
        // A concurrent twin that still carried the setup also resolves to the same row.
        $this->actingAs($users['single'])->withSession([self::KEY => $setup])->post(route('customer.events.store'), $payload)
            ->assertRedirectContains('/setup');

        $this->assertSame(1, DB::table('events')->where('event_name', 'Neema & Baraka')->count());
        $this->assertSame(2, Event::query()->count());
        $id = DB::table('events')->where('event_name', 'Neema & Baraka')->value('id');
        $this->actingAs($users['single'])->post(route('customer.events.store'), $payload)
            ->assertRedirect(route('customer.events.setup', ['event' => $id]));
    }

    public function test_creator_is_idempotent_per_account_and_key(): void
    {
        $users = $this->fixture();
        $creator = app(CustomerEventCreator::class);
        $key = (string) Str::uuid();
        $details = ['event_type' => 'other', 'event_name' => 'Asha Birthday', 'event_date' => '2027-01-03', 'event_time' => '14:00', 'venue_name' => 'Golden Tulip'];

        $first = $creator->create(DB::connection(), $users['single']->id, 10, $key, $details);
        $second = $creator->create(DB::connection(), $users['single']->id, 10, $key, $details);
        $other = $creator->create(DB::connection(), $users['multi']->id, 11, $key, $details);

        $this->assertTrue($first['created']);
        $this->assertSame(['event_id' => $first['event_id'], 'created' => false], $second);
        $this->assertTrue($other['created']);
        $this->assertNotSame($first['event_id'], $other['event_id']);
        $this->assertSame(2, DB::table('events')->where('event_name', 'Asha Birthday')->count());
    }

    public function test_storage_failure_rolls_back_and_offers_a_safe_retry(): void
    {
        $users = $this->fixture();
        $this->openDetails($users['single'], 'wedding');
        $key = session(self::KEY)['request_key'];
        $fail = true;
        $level = DB::connection()->transactionLevel();
        DB::connection()->beforeExecuting(function (string $sql) use (&$fail): void {
            if ($fail && preg_match('/^insert into "events"/', $sql)) {
                throw new RuntimeException('synthetic storage failure SQLSTATE secret');
            }
        });

        $response = $this->actingAs($users['single'])->post(route('customer.events.store'), self::VALID + ['request_key' => $key]);

        $response->assertStatus(503)->assertSee('Hatukuweza kuunda tukio lako sasa hivi. Hakuna kilichohifadhiwa.')
            ->assertDontSee('SQLSTATE')->assertDontSee('synthetic storage failure')->assertSee('value="Neema &amp; Baraka"', false);
        $this->assertSame(1, Event::query()->count());
        $this->assertSame($level, DB::connection()->transactionLevel());
        $this->assertSame($key, session(self::KEY)['request_key']);

        $fail = false;
        $this->actingAs($users['single'])->post(route('customer.events.store'), self::VALID + ['request_key' => $key])->assertRedirectContains('/setup');
        $this->assertSame(2, Event::query()->count());
    }

    public function test_multiple_workspaces_use_the_explicitly_chosen_workspace(): void
    {
        $users = $this->fixture();
        $this->actingAs($users['multi'])->post(route('customer.events.type'), ['event_type' => 'other', 'workspace_id' => '12']);
        $this->actingAs($users['multi'])->get(route('customer.events.details'))->assertOk()->assertSee('Sehemu ya kazi: Multi Beta');

        $this->actingAs($users['multi'])->post(route('customer.events.store'), self::VALID + ['request_key' => session(self::KEY)['request_key']]);

        $this->assertSame(12, (int) DB::table('events')->orderByDesc('id')->value('workspace_id'));
    }

    public function test_legacy_events_and_admin_creation_remain_compatible(): void
    {
        $users = $this->fixture();

        $legacy = DB::table('events')->where('event_name', 'Legacy Gala')->first();
        $this->assertNull($legacy->event_type);
        $this->assertNull($legacy->event_time);
        $this->assertNull($legacy->creation_key);
        $this->actingAs($users['single'])->get(route('customer.home'))->assertOk()->assertSee('Legacy Gala')->assertSee('20 Desemba 2026');

        $this->actingAs($users['admin'])->post(route('admin.events.store'), ['event_name' => 'Admin Synthetic Event', 'status' => 'active'])
            ->assertRedirect(route('admin.dashboard'));
        $admin = DB::table('events')->where('event_name', 'Admin Synthetic Event')->first();
        $this->assertNotNull($admin);
        $this->assertNull($admin->event_type);
        $this->assertNull($admin->creation_key);
    }

    public function test_guests_staff_and_customers_without_workspace_are_refused(): void
    {
        $users = $this->fixture();

        $this->get(route('customer.events.details'))->assertRedirect(route('login'));
        $this->post(route('customer.events.store'), self::VALID)->assertRedirect(route('login'));
        foreach (['admin', 'scanner'] as $role) {
            $this->actingAs($users[$role])->get(route('customer.events.details'))->assertForbidden();
            $this->actingAs($users[$role])->post(route('customer.events.store'), self::VALID)->assertForbidden();
        }
        $this->actingAs($users['new'])->get(route('customer.events.details'))->assertRedirect(route('customer.workspaces.create'));
        $this->actingAs($users['new'])->post(route('customer.events.store'), self::VALID)->assertRedirect(route('customer.workspaces.create'));
        $this->assertSame(1, Event::query()->count());
    }

    private function openDetails(User $user, string $type): TestResponse
    {
        $this->actingAs($user)->post(route('customer.events.type'), ['event_type' => $type])->assertRedirect(route('customer.events.details'));

        return $this->actingAs($user)->get(route('customer.events.details'))->assertOk();
    }

    private function fixture(): array
    {
        $users = [];
        foreach (['single' => 'customer', 'multi' => 'customer', 'new' => 'customer', 'foreign' => 'customer', 'admin' => 'admin', 'scanner' => 'scanner'] as $key => $role) {
            $users[$key] = User::query()->create([
                'name' => ucfirst($key).' Synthetic', 'email' => $key.'@example.invalid', 'phone_number' => '+25578800003'.count($users),
                'password' => 'synthetic-pass-123', 'role' => $role, 'is_active' => true, 'preferred_language' => 'sw',
                'gate_name' => $role === 'scanner' ? 'Main Gate' : null,
            ]);
        }
        foreach ([10 => 'Single Studio', 11 => 'Multi Alpha', 12 => 'Multi Beta', 13 => 'Foreign Studio'] as $id => $name) {
            DB::table('workspaces')->insert(['id' => $id, 'name' => $name]);
        }
        foreach ([[10, 'single', 'owner'], [11, 'multi', 'owner'], [12, 'multi', 'manager'], [13, 'foreign', 'owner']] as [$workspace, $user, $role]) {
            DB::table('workspace_users')->insert(['workspace_id' => $workspace, 'user_id' => $users[$user]->id, 'role' => $role, 'is_active' => true]);
        }
        // One legacy-shaped event (no type, time or creation key) in the customer's workspace.
        DB::table('events')->insert(['event_name' => 'Legacy Gala', 'venue_name' => 'Old Hall', 'event_date' => '2026-12-20', 'status' => 'active', 'workspace_id' => 10]);
        DB::table('events')->where('event_name', 'Default Wedding Event')->delete();

        return $users;
    }
}
