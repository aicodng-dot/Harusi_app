<?php

namespace Tests\Feature\ReferenceUi;

use App\Http\Controllers\CustomerEventTypeController;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Working Flow 09 — Choose Event Type (reference UI/04_Choose_Event_Type.png). */
class Screen09ChooseEventTypeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = CustomerEventTypeController::SESSION_KEY;

    public function test_guests_are_redirected_and_staff_are_forbidden(): void
    {
        $users = $this->fixture();

        $this->get(route('customer.events.create'))->assertRedirect(route('login'));
        $this->post(route('customer.events.type'), ['event_type' => 'wedding'])->assertRedirect(route('login'));
        foreach (['admin', 'scanner'] as $role) {
            $this->actingAs($users[$role])->get(route('customer.events.create'))->assertForbidden();
            $this->actingAs($users[$role])->post(route('customer.events.type'), ['event_type' => 'wedding'])->assertForbidden();
        }
    }

    public function test_page_renders_the_four_reference_types_with_stable_values_and_private_headers(): void
    {
        $users = $this->fixture();

        $response = $this->actingAs($users['single'])->get(route('customer.events.create'));

        $response->assertOk()->assertViewIs('reference_ui.04_choose_event_type')
            ->assertSee('<html lang="sw">', false)->assertSee('Chagua Aina ya Tukio')
            ->assertSee('Chagua aina ya tukio unalotaka kuandaa.')
            ->assertSeeInOrder(['Harusi', 'Send-Off', 'Kitchen Party', 'Sherehe Nyingine'])
            ->assertSee('Endelea')->assertSee('aria-label="Hatua 1 kati ya 4"', false)
            ->assertSee('href="'.route('customer.home').'"', false)
            ->assertDontSee('data-et-workspace', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        preg_match_all('/name="event_type" value="([^"]+)"/', $response->getContent(), $values);
        $this->assertSame(CustomerEventTypeController::TYPES, $values[1]);
        $this->assertSame(0, substr_count($response->getContent(), ' checked'));
        foreach (['Wedding', 'Send_Off', 'Kitchen_Party', 'Other_Celebration'] as $asset) {
            $response->assertSee('assets/reference_ui/04_Choose_Event_Type/04_Choose_Event_Type_'.$asset.'.webp', false);
            $this->assertFileExists(public_path('assets/reference_ui/04_Choose_Event_Type/04_Choose_Event_Type_'.$asset.'.webp'));
        }

        $this->actingAs($users['single'])->get(route('customer.events.create', ['lang' => 'en']))
            ->assertSee('<html lang="en">', false)->assertSee('Choose Event Type')
            ->assertSeeInOrder(['Wedding', 'Send-Off', 'Kitchen Party', 'Other Celebration'])->assertSee('Continue');
    }

    public function test_valid_selection_is_kept_in_scoped_session_state_without_creating_records(): void
    {
        $users = $this->fixture();
        $before = $this->counts();
        $writes = $this->writes(function () use ($users): void {
            $this->actingAs($users['single'])->post(route('customer.events.type'), ['event_type' => 'kitchen_party'])
                ->assertRedirect(route('customer.events.details'));
        });

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->counts());
        $this->assertSame(['user_id' => $users['single']->id, 'workspace_id' => 10, 'event_type' => 'kitchen_party'], session(self::KEY));

        $page = $this->actingAs($users['single'])->get(route('customer.events.create'));
        $page->assertOk();
        $this->assertMatchesRegularExpression('/value="kitchen_party" checked/', $page->getContent());
        // The stored choice survives a language change and a later visit.
        $en = $this->actingAs($users['single'])->get(route('customer.events.create', ['lang' => 'en']));
        $this->assertMatchesRegularExpression('/value="kitchen_party" checked/', $en->getContent());
        $en->assertSee('Choose Event Type');
    }

    public function test_selecting_again_replaces_the_previous_choice(): void
    {
        $users = $this->fixture();

        $this->actingAs($users['single'])->post(route('customer.events.type'), ['event_type' => 'wedding']);
        $this->actingAs($users['single'])->post(route('customer.events.type'), ['event_type' => 'other']);

        $this->assertSame('other', session(self::KEY)['event_type']);
        $html = $this->actingAs($users['single'])->get(route('customer.events.create'))->getContent();
        $this->assertSame(1, preg_match_all('/ checked/', $html));
    }

    #[DataProvider('invalidTypes')]
    public function test_unsupported_or_manipulated_types_are_rejected(mixed $value): void
    {
        $users = $this->fixture();
        $payload = $value === '__missing__' ? [] : ['event_type' => $value];

        $response = $this->actingAs($users['single'])->post(route('customer.events.type'), $payload);

        $response->assertStatus(422)->assertSee('Chagua aina ya tukio ili kuendelea.')
            ->assertSee('Tafadhali rekebisha taarifa zilizoonyeshwa hapa chini.');
        $this->assertNull(session(self::KEY));
    }

    public static function invalidTypes(): array
    {
        return ['missing' => ['__missing__'], 'empty' => [''], 'unknown' => ['funeral'], 'translated label' => ['Harusi'],
            'case variant' => ['Wedding'], 'array' => [['wedding']], 'inner space' => ['send off'], 'sql-like' => ["wedding' or '1'='1"]];
    }

    public function test_query_preselection_accepts_only_supported_values(): void
    {
        $users = $this->fixture();

        $valid = $this->actingAs($users['single'])->get(route('customer.events.create', ['type' => 'send_off', 'lang' => 'en']));
        $this->assertMatchesRegularExpression('/value="send_off" checked/', $valid->getContent());
        $this->assertStringContainsString(e(route('customer.events.create', ['lang' => 'sw', 'type' => 'send_off'])), $valid->getContent());
        $invalid = $this->actingAs($users['single'])->get(route('customer.events.create', ['type' => 'funeral']));
        $this->assertSame(0, substr_count($invalid->getContent(), ' checked'));
        $this->assertNull(session(self::KEY));
    }

    #[DataProvider('foreignWorkspaces')]
    public function test_untrusted_workspace_ids_never_authorize_a_selection(string $workspace): void
    {
        $users = $this->fixture();

        foreach (['single', 'multi'] as $key) {
            $this->actingAs($users[$key])->post(route('customer.events.type'), ['event_type' => 'wedding', 'workspace_id' => $workspace])
                ->assertStatus(422);
            $this->assertNull(session(self::KEY), $key);
        }
    }

    public static function foreignWorkspaces(): array
    {
        return ['another customer' => ['13'], 'revoked membership' => ['14'], 'noncanonical membership' => ['15'],
            'missing workspace' => ['999'], 'not a number' => ['10abc'], 'negative' => ['-10'], 'zero padded' => ['010']];
    }

    public function test_multiple_workspaces_require_an_explicit_authorized_choice(): void
    {
        $users = $this->fixture();

        $page = $this->actingAs($users['multi'])->get(route('customer.events.create'));
        $page->assertOk()->assertSee('Sehemu ya kazi ya tukio hili')->assertSee('Multi Alpha')->assertSee('Multi Beta')
            ->assertDontSee('Foreign Studio')->assertDontSee('Revoked Studio');
        $this->assertSame(0, substr_count($page->getContent(), ' checked'));

        $this->actingAs($users['multi'])->post(route('customer.events.type'), ['event_type' => 'wedding'])
            ->assertStatus(422)->assertSee('Chagua sehemu ya kazi ili kuendelea.');
        $this->assertNull(session(self::KEY));

        $this->actingAs($users['multi'])->post(route('customer.events.type'), ['event_type' => 'wedding', 'workspace_id' => '12'])
            ->assertRedirect(route('customer.events.details'));
        $this->assertSame(['user_id' => $users['multi']->id, 'workspace_id' => 12, 'event_type' => 'wedding'], session(self::KEY));
        $html = $this->actingAs($users['multi'])->get(route('customer.events.create'))->getContent();
        $this->assertMatchesRegularExpression('/name="workspace_id" value="12" checked/', $html);
    }

    public function test_customer_without_workspace_is_sent_to_explicit_workspace_creation(): void
    {
        $users = $this->fixture();
        $before = $this->counts();

        $this->actingAs($users['new'])->get(route('customer.events.create'))->assertRedirect(route('customer.workspaces.create'));
        $this->actingAs($users['new'])->post(route('customer.events.type'), ['event_type' => 'wedding'])
            ->assertRedirect(route('customer.workspaces.create'));

        $this->assertSame($before, $this->counts());
        $this->assertNull(session(self::KEY));
        // My Events keeps the workspace-first start for this customer.
        $this->actingAs($users['new'])->get(route('customer.home'))
            ->assertSee('href="'.route('customer.workspaces.create').'"', false)->assertDontSee('href="'.route('customer.events.create').'"', false);
    }

    public function test_authority_is_rechecked_on_submit_and_on_every_visit(): void
    {
        $users = $this->fixture();
        $this->actingAs($users['single'])->post(route('customer.events.type'), ['event_type' => 'wedding']);
        $this->assertNotNull(session(self::KEY));

        DB::table('workspace_users')->where('user_id', $users['single']->id)->update(['is_active' => false]);

        $this->actingAs($users['single'])->get(route('customer.events.create'))->assertRedirect(route('customer.workspaces.create'));
        $this->actingAs($users['single'])->post(route('customer.events.type'), ['event_type' => 'other'])
            ->assertRedirect(route('customer.workspaces.create'));
        $this->assertNull(session(self::KEY));
    }

    public function test_a_stored_choice_never_carries_over_to_another_account_or_lost_workspace(): void
    {
        $users = $this->fixture();

        $foreign = ['user_id' => $users['single']->id, 'workspace_id' => 10, 'event_type' => 'wedding'];
        $html = $this->actingAs($users['multi'])->withSession([self::KEY => $foreign])->get(route('customer.events.create'))->getContent();
        $this->assertSame(0, substr_count($html, ' checked'));
        $this->assertNull(session(self::KEY));

        $stale = ['user_id' => $users['multi']->id, 'workspace_id' => 13, 'event_type' => 'wedding'];
        $html = $this->actingAs($users['multi'])->withSession([self::KEY => $stale])->get(route('customer.events.create'))->getContent();
        $this->assertSame(0, substr_count($html, ' checked'));
        $this->assertNull(session(self::KEY));
    }

    public function test_my_events_create_action_opens_choose_event_type(): void
    {
        $users = $this->fixture();

        $this->actingAs($users['single'])->get(route('customer.home'))->assertOk()
            ->assertSee('href="'.route('customer.events.create').'"', false)->assertSee('Unda Tukio Jipya');
    }

    public function test_suspended_account_cannot_open_or_submit(): void
    {
        $users = $this->fixture();
        DB::table('users')->where('id', $users['single']->id)->update(['is_active' => false]);

        $this->actingAs($users['single']->fresh())->get(route('customer.events.create'))->assertRedirect();
        $this->actingAs($users['single']->fresh())->post(route('customer.events.type'), ['event_type' => 'wedding'])->assertRedirect();
        $this->assertNull(session(self::KEY));
    }

    private function counts(): array
    {
        return array_map(fn (string $table) => DB::table($table)->count(), ['events' => 'events', 'workspaces' => 'workspaces', 'workspace_users' => 'workspace_users', 'workspace_provisionings' => 'workspace_provisionings']);
    }

    private function writes(callable $operation): array
    {
        $writes = [];
        $listening = true;
        DB::listen(function (QueryExecuted $event) use (&$writes, &$listening): void {
            if ($listening && preg_match('/^\s*(insert|update|delete|replace)\b/i', $event->sql) && ! str_contains($event->sql, '"cache')) {
                $writes[] = $event->sql;
            }
        });
        try {
            $operation();
        } finally {
            $listening = false;
        }

        return $writes;
    }

    private function fixture(): array
    {
        $users = [];
        foreach (['single' => 'customer', 'multi' => 'customer', 'new' => 'customer', 'foreign' => 'customer', 'admin' => 'admin', 'scanner' => 'scanner'] as $key => $role) {
            $users[$key] = User::query()->create([
                'name' => ucfirst($key).' Synthetic', 'email' => $key.'@example.invalid', 'phone_number' => '+25578800002'.count($users),
                'password' => 'synthetic-pass-123', 'role' => $role, 'is_active' => true, 'preferred_language' => 'sw',
                'gate_name' => $role === 'scanner' ? 'Main Gate' : null,
            ]);
        }
        foreach ([10 => 'Single Studio', 11 => 'Multi Alpha', 12 => 'Multi Beta', 13 => 'Foreign Studio', 14 => 'Revoked Studio', 15 => 'Noncanonical Studio'] as $id => $name) {
            DB::table('workspaces')->insert(['id' => $id, 'name' => $name]);
        }
        foreach ([[10, 'single', 'owner', 1], [11, 'multi', 'owner', 1], [12, 'multi', 'manager', 1], [13, 'foreign', 'owner', 1],
            [14, 'single', 'owner', 0], [14, 'multi', 'owner', 0], [15, 'single', 'owner', 2], [15, 'multi', 'owner', 2]] as [$workspace, $user, $role, $active]) {
            DB::table('workspace_users')->insert(['workspace_id' => $workspace, 'user_id' => $users[$user]->id, 'role' => $role, 'is_active' => $active]);
        }

        return $users;
    }
}
