<?php

namespace Tests\Feature\ReferenceUi;

use App\Models\User;
use App\Services\Invitations\InvitationTemplateCatalog;
use App\Services\Tenancy\CustomerEventCreationException;
use App\Services\Tenancy\CustomerEventTemplateSelector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Working Flow 14 — Choose Invitation Template (reference UI/13_Choose_Invitation_Template.png). */
class Screen14ChooseInvitationTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_shows_the_event_and_reference_templates_with_live_details(): void
    {
        $users = $this->fixture();

        $page = $this->actingAs($users['owner'])->get(route('customer.events.template', ['event' => 101]));

        $page->assertOk()->assertViewIs('reference_ui.13_choose_invitation_template')
            ->assertSee('<html lang="sw">', false)->assertSee('Chagua Muundo wa Kadi')->assertSee('Chagua muundo unaopenda kwa tukio lako.')
            ->assertSee('Tukio Ulilochagua')->assertSee('Neema &amp; Baraka', false)->assertSee('14 Desemba 2026 · Grand Hall, Dar es Salaam')
            ->assertSeeInOrder(['Harusi', 'Send-Off', 'Kitchen Party', 'Sherehe', 'Maua', 'Maridadi', 'Kisasa'])
            ->assertSeeInOrder(['Floral Classic', 'Peony Romance'])
            ->assertSee('Tumia Muundo Huu')->assertSee('Onyesha miundo zaidi')
            ->assertSee('href="'.route('customer.events.dashboard', ['event' => 101]).'"', false)
            ->assertSee('href="'.route('customer.home').'"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->assertSame('wedding', $page->viewData('category'));
        $this->assertSame(0, substr_count($page->getContent(), ' checked'));
        // Previews are rendered by the invitation engine with this event's details.
        $this->assertCount(12, $page->viewData('templates'));
        foreach (array_keys($page->viewData('templates')) as $key) {
            $page->assertSee(route('customer.events.invitation.thumb', ['event' => 101, 'template' => $key, 'lang' => 'sw']), false);
        }
        $this->assertSame(100, $page->viewData('libraryTotal'));
    }

    public function test_categories_filter_the_registry_and_default_to_the_event_type(): void
    {
        $users = $this->fixture();
        $view = fn (string $url) => $this->actingAs($users['owner'])->get($url)->assertOk();
        $catalog = InvitationTemplateCatalog::all();

        // Send-off event defaults to the send-off chip; every result suits a send-off.
        $sendOff = $view(route('customer.events.template', ['event' => 102]));
        $this->assertSame('send_off', $sendOff->viewData('category'));
        foreach (array_keys($sendOff->viewData('templates')) as $key) {
            $this->assertContains('send_off', $catalog[$key]['events']);
        }
        // Style chips filter by style; the original identifiers keep their styles.
        $floral = $view(route('customer.events.template', ['event' => 101, 'category' => 'floral', 'page' => 5]));
        $this->assertContains('floral_classic', array_keys($floral->viewData('templates')));
        $this->assertSame(['floral'], array_values(array_unique(array_column($floral->viewData('templates'), 'category'))));
        $this->assertContains('minimal_modern', array_keys($view(route('customer.events.template', ['event' => 101, 'category' => 'modern']))->viewData('templates')));
        $this->assertSame(['photo'], array_values(array_unique(array_column($view(route('customer.events.template', ['event' => 101, 'category' => 'photo']))->viewData('templates'), 'category'))));
        // Kitchen-party event defaults to its chip; unknown chips show the whole library, paged.
        $this->assertSame('kitchen_party', $view(route('customer.events.template', ['event' => 103]))->viewData('category'));
        $all = $view(route('customer.events.template', ['event' => 101, 'category' => 'gold']));
        $this->assertNull($all->viewData('category'));
        $this->assertSame(100, $all->viewData('total'));
        $this->assertCount(12, $all->viewData('templates'));
        $this->assertCount(24, $view(route('customer.events.template', ['event' => 101, 'category' => 'all', 'page' => 2]))->viewData('templates'));
        // Search matches names and styles.
        $search = $view(route('customer.events.template', ['event' => 101, 'category' => 'all', 'q' => 'kanga']))->viewData('templates');
        $this->assertNotEmpty($search);
        foreach ($search as $template) {
            $this->assertStringContainsString('kanga', mb_strtolower($template['name'].' '.$template['palette']));
        }
    }

    public function test_selection_is_saved_on_the_authorized_event_only(): void
    {
        $users = $this->fixture();
        $before = DB::table('events')->where('id', '!=', 101)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->actingAs($users['owner'])->post(route('customer.events.template.store', ['event' => 101]), ['template' => 'royal_green', 'category' => 'modern'])
            ->assertRedirect(route('customer.events.invitation', ['event' => 101]))
            ->assertSessionHas('invitation_template_saved', 'royal_green');

        $this->assertSame('royal_green', DB::table('events')->where('id', 101)->value('invitation_template'));
        $this->assertSame($before, DB::table('events')->where('id', '!=', 101)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $page = $this->actingAs($users['owner'])->get(route('customer.events.template', ['event' => 101]));
        $page->assertSee('Muundo wa Royal Green umehifadhiwa kwa tukio hili.')->assertSee('Muundo wako wa sasa');
        $this->assertMatchesRegularExpression('/value="royal_green" checked/', $page->getContent());
    }

    public function test_repeated_submissions_are_idempotent_and_a_new_choice_replaces_the_old(): void
    {
        $users = $this->fixture();

        foreach (['floral_classic', 'floral_classic', 'photo_elegant'] as $template) {
            $this->actingAs($users['owner'])->post(route('customer.events.template.store', ['event' => 101]), ['template' => $template])->assertRedirect();
        }

        $this->assertSame('photo_elegant', DB::table('events')->where('id', 101)->value('invitation_template'));
        $this->assertSame(1, DB::table('events')->where('id', 101)->count());
    }

    #[DataProvider('invalidTemplates')]
    public function test_unsupported_templates_are_rejected(mixed $template): void
    {
        $users = $this->fixture();

        $this->actingAs($users['owner'])->post(route('customer.events.template.store', ['event' => 101]), $template === '__missing__' ? [] : ['template' => $template])
            ->assertStatus(422)->assertSee('Chagua muundo wa kadi ili kuendelea.');
        $this->assertNull(DB::table('events')->where('id', 101)->value('invitation_template'));
    }

    public static function invalidTemplates(): array
    {
        return ['missing' => ['__missing__'], 'empty' => [''], 'unknown' => ['gold_luxury'], 'display name' => ['Royal Green'],
            'case variant' => ['Royal_Green'], 'array' => [['royal_green']]];
    }

    #[DataProvider('deniedEvents')]
    public function test_foreign_revoked_unassigned_and_unknown_events_are_refused(string $event): void
    {
        $users = $this->fixture();

        $this->actingAs($users['owner'])->get('/customer/events/'.$event.'/template')->assertNotFound()->assertDontSee('Foreign private event');
        $this->actingAs($users['owner'])->post('/customer/events/'.$event.'/template', ['template' => 'royal_green'])->assertNotFound();
        $this->assertSame(0, DB::table('events')->whereNotNull('invitation_template')->count());
    }

    public static function deniedEvents(): array
    {
        return ['foreign workspace' => ['201'], 'revoked membership' => ['202'], 'noncanonical membership' => ['203'],
            'unassigned legacy' => ['204'], 'missing' => ['999999'], 'leading zero' => ['0101']];
    }

    public function test_archived_and_cancelled_events_are_read_only(): void
    {
        $users = $this->fixture();

        foreach (['archived', 'cancelled'] as $status) {
            DB::table('events')->where('id', 101)->update(['status' => $status, 'invitation_template' => null]);
            $page = $this->actingAs($users['owner'])->get(route('customer.events.template', ['event' => 101]));
            $page->assertOk()->assertSee('Tukio hili halitumiki tena. Unaweza kuangalia miundo tu.');
            $this->assertMatchesRegularExpression('/<button class="tp-submit"[^>]*disabled/', $page->getContent());
            $this->actingAs($users['owner'])->post(route('customer.events.template.store', ['event' => 101]), ['template' => 'royal_green'])
                ->assertStatus(409)->assertSee('muundo wake hauwezi kubadilishwa');
            $this->assertNull(DB::table('events')->where('id', 101)->value('invitation_template'));
        }
    }

    public function test_authority_is_rechecked_inside_the_save_transaction(): void
    {
        $users = $this->fixture();
        $revoked = false;
        $inSelector = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$revoked, &$inSelector, $users): void {
            if (str_starts_with($sql, 'select "id", "role", "is_active" from "users"')) {
                $inSelector = true;
            }
            if ($inSelector && ! $revoked && str_starts_with($sql, 'select "id", "workspace_id", "user_id", "role", "is_active" from "workspace_users"')) {
                $revoked = true;
                DB::table('workspace_users')->where('user_id', $users['owner']->id)->update(['is_active' => false]);
            }
        });

        $this->actingAs($users['owner'])->post(route('customer.events.template.store', ['event' => 101]), ['template' => 'royal_green'])->assertNotFound();

        $this->assertTrue($revoked);
        $this->assertNull(DB::table('events')->where('id', 101)->value('invitation_template'));
    }

    public function test_storage_failure_rolls_back_and_offers_a_safe_retry(): void
    {
        $users = $this->fixture();
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_starts_with($sql, 'update "events"')) {
                throw new RuntimeException('synthetic failure SQLSTATE secret');
            }
        });

        $this->actingAs($users['owner'])->post(route('customer.events.template.store', ['event' => 101]), ['template' => 'royal_green'])
            ->assertStatus(503)->assertSee('Hatukuweza kuhifadhi muundo sasa hivi. Hakuna kilichobadilishwa.')
            ->assertDontSee('SQLSTATE')->assertDontSee('synthetic failure');
        $this->assertNull(DB::table('events')->where('id', 101)->value('invitation_template'));
    }

    public function test_selector_validates_its_inputs(): void
    {
        $users = $this->fixture();
        $selector = app(CustomerEventTemplateSelector::class);

        foreach ([['x', 101, 'royal_green'], [$users['owner']->id, '101', 'royal_green'], [$users['owner']->id, 101, 'nope']] as [$user, $event, $template]) {
            try {
                $selector->select(DB::connection(), $user, $event, $template);
                $this->fail('Expected invalid input.');
            } catch (CustomerEventCreationException $exception) {
                $this->assertSame('invalid_input', $exception->reason());
            }
        }
    }

    public function test_viewing_is_read_only(): void
    {
        $users = $this->fixture();
        $writes = [];
        $listening = true;
        DB::listen(function (QueryExecuted $event) use (&$writes, &$listening): void {
            if ($listening && preg_match('/^\s*(insert|update|delete|replace)\b/i', $event->sql) && ! str_contains($event->sql, '"cache')) {
                $writes[] = $event->sql;
            }
        });

        $this->actingAs($users['owner'])->get(route('customer.events.template', ['event' => 101, 'template' => 'royal_green', 'category' => 'modern']))->assertOk();
        $listening = false;

        $this->assertSame([], $writes);
        $this->assertNull(DB::table('events')->where('id', 101)->value('invitation_template'));
    }

    public function test_dashboard_and_setup_progress_open_the_template_screen(): void
    {
        $users = $this->fixture();
        DB::table('guests')->insert(['event_id' => 101, 'name' => 'Synthetic guest', 'phone_number' => '+255710000001', 'manual_code' => '8001',
            'pass_type' => 'single', 'allowed_entries' => 1, 'used_entries' => 0, 'status' => 'active']);
        $url = route('customer.events.template', ['event' => 101]);

        // The dashboard now has one smart entry: Continue Invitation resolves to the template screen first.
        $continue = route('customer.events.invitation.continue', ['event' => 101]);
        $this->actingAs($users['owner'])->get(route('customer.events.dashboard', ['event' => 101]))->assertSee('href="'.$continue.'" data-db-invitation', false);
        $this->actingAs($users['owner'])->get($continue)->assertRedirect($url);
        $this->actingAs($users['owner'])->get(route('customer.events.setup', ['event' => 101]))->assertSee('href="'.$url.'" data-sp-template', false);
    }

    public function test_language_preselection_and_staff_boundaries(): void
    {
        $users = $this->fixture();

        $en = $this->actingAs($users['owner'])->get(route('customer.events.template', ['event' => 101, 'lang' => 'en', 'template' => 'minimal_modern']));
        $en->assertSee('<html lang="en">', false)->assertSee('Choose Card Design')->assertSee('Use This Design')->assertSee('Show more designs')
            ->assertSee(route('customer.events.invitation.thumb', ['event' => 101, 'template' => 'minimal_modern', 'lang' => 'en']), false);
        $this->assertMatchesRegularExpression('/value="minimal_modern" checked/', $en->getContent());
        $this->actingAs($users['owner'])->get(route('customer.events.template', ['event' => 101, 'template' => 'nope']))->assertOk();

        foreach (['admin', 'scanner'] as $role) {
            $this->actingAs($users[$role])->get(route('customer.events.template', ['event' => 101]))->assertForbidden();
            $this->actingAs($users[$role])->post(route('customer.events.template.store', ['event' => 101]), ['template' => 'royal_green'])->assertForbidden();
        }
        auth()->logout();
        $this->get(route('customer.events.template', ['event' => 101]))->assertRedirect(route('login'));
    }

    private function fixture(): array
    {
        $users = [];
        foreach (['owner' => 'customer', 'foreign' => 'customer', 'admin' => 'admin', 'scanner' => 'scanner'] as $key => $role) {
            $users[$key] = User::query()->create([
                'name' => ucfirst($key).' Synthetic', 'email' => $key.'@example.invalid', 'phone_number' => '+25578800006'.count($users),
                'password' => 'synthetic-pass-123', 'role' => $role, 'is_active' => true, 'preferred_language' => 'sw',
                'gate_name' => $role === 'scanner' ? 'Main Gate' : null,
            ]);
        }
        foreach ([10 => 'Owner Studio', 13 => 'Foreign Studio', 14 => 'Revoked Studio', 15 => 'Noncanonical Studio'] as $id => $name) {
            DB::table('workspaces')->insert(['id' => $id, 'name' => $name]);
        }
        foreach ([[10, 'owner', 1], [13, 'foreign', 1], [14, 'owner', 0], [15, 'owner', 2]] as [$workspace, $user, $active]) {
            DB::table('workspace_users')->insert(['workspace_id' => $workspace, 'user_id' => $users[$user]->id, 'role' => 'owner', 'is_active' => $active]);
        }
        foreach ([
            [101, 'Neema & Baraka', 'Grand Hall, Dar es Salaam', '2026-12-14', '18:00:00', 'wedding', 10],
            [102, 'Send-Off ya Neema', 'Slipway', '2027-01-10', '16:00:00', 'send_off', 10],
            [103, 'Kitchen Party', 'Masaki', '2027-01-20', null, 'kitchen_party', 10],
            [201, 'Foreign private event', 'Hidden', '2026-12-20', null, 'wedding', 13],
            [202, 'Revoked event', 'Hidden', '2026-12-20', null, 'wedding', 14],
            [203, 'Noncanonical event', 'Hidden', '2026-12-20', null, 'wedding', 15],
            [204, 'Unassigned event', 'Hidden', '2026-12-20', null, 'wedding', null],
        ] as [$id, $name, $venue, $date, $time, $type, $workspace]) {
            DB::table('events')->insert(['id' => $id, 'event_name' => $name, 'venue_name' => $venue, 'event_date' => $date, 'event_time' => $time,
                'event_type' => $type, 'status' => 'active', 'workspace_id' => $workspace]);
        }

        return $users;
    }
}
