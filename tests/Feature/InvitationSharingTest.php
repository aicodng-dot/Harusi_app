<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesGuestQrDisk;
use Tests\Feature\Invitations\InvitationFixtures;
use Tests\TestCase;

/**
 * Invitation Sharing Center: manual WhatsApp / SMS / share-sheet sharing without any
 * messaging API. Nothing is sent by the server; only what the customer did is recorded,
 * guest / QR / admission records never change, and every id is scoped to the owner's event.
 */
class InvitationSharingTest extends TestCase
{
    use FakesGuestQrDisk;
    use InvitationFixtures;
    use RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGuestQrDisk();
        Storage::fake('invitations');
        Http::fake();
        $this->f = $this->invitationFixture();
    }

    private function generate(array $guests): void
    {
        $this->actingAs($this->f['owner'])->postJson(route('customer.events.invitation.generate', ['event' => 101]), ['guests' => array_map(fn ($g) => $g->id, $guests)])->assertOk();
    }

    private function snapshot(): array
    {
        return [
            DB::table('guests')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('qr_codes')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('admissions')->count(), DB::table('checkins')->count(), DB::table('notification_logs')->count(),
        ];
    }

    private function publicLink(Guest $guest): string
    {
        $page = $this->actingAs($this->f['owner'])->get(route('customer.events.share.guest', ['event' => 101, 'guest' => $guest->id]))->assertOk();
        $url = $page->viewData('link')['url'];
        $this->assertNotNull($url);

        return $url;
    }

    public function test_hub_shows_honest_counts_and_is_owner_only(): void
    {
        $this->generate([$this->f['guests']['single']]);
        $page = $this->actingAs($this->f['owner'])->get(route('customer.events.share', ['event' => 101]))->assertOk();
        $this->assertSame(['guests' => 3, 'with_phone' => 3, 'cards' => 1, 'not_shared' => 3, 'initiated' => 0, 'confirmed' => 0, 'skipped' => 0, 'awaiting' => 3], $page->viewData('stats'));
        $page->assertSee(__('customer_share.honesty', [], 'sw'))->assertDontSee('Delivered')->assertDontSee('Imefika');

        foreach ([
            route('customer.events.share', ['event' => 101]),
            route('customer.events.share.create', ['event' => 101]),
            route('customer.events.share.history', ['event' => 101]),
            route('customer.events.share.templates', ['event' => 101]),
            route('customer.events.share.guest', ['event' => 101, 'guest' => $this->f['guests']['single']->id]),
        ] as $url) {
            $this->actingAs($this->f['foreign'])->get($url)->assertNotFound();
        }
        $this->actingAs($this->f['owner'])->get(route('customer.events.share.guest', ['event' => 101, 'guest' => $this->f['foreignGuest']->id]))->assertNotFound();
        $this->actingAs($this->f['admin'])->get(route('customer.events.share', ['event' => 101]))->assertForbidden();
        auth()->logout();
        $this->get(route('customer.events.share', ['event' => 101]))->assertRedirect();
    }

    public function test_individual_whatsapp_and_sms_links_are_personal_and_encoded(): void
    {
        $guest = $this->f['guests']['single'];
        $before = $this->actingAs($this->f['owner'])->get(route('customer.events.share.guest', ['event' => 101, 'guest' => $guest->id]))->assertOk();
        $this->assertNull($before->viewData('link')['url']);
        $before->assertSee(__('customer_share.guest.no_link.no_card', [], 'sw'));

        $this->generate([$guest]);
        $url = $this->publicLink($guest);
        $this->assertMatchesRegularExpression('#/i/[A-Za-z0-9_-]{32}$#', $url);
        $this->assertSame($url, $this->publicLink($guest), 'the same link is reused');
        $page = $this->actingAs($this->f['owner'])->get(route('customer.events.share.guest', ['event' => 101, 'guest' => $guest->id]));
        $html = html_entity_decode($page->getContent());
        $this->assertStringContainsString('https://wa.me/255712000214?text=Habari%20Asha%20Mrema%21', $html);
        $this->assertStringContainsString(rawurlencode($url), $html, 'the personal invitation link is inside the message');
        $this->assertStringContainsString(rawurlencode('Neema & Baraka'), $html);
        $this->assertStringContainsString('sms:+255712000214?&body=Habari%20Asha%20Mrema', $html);
        // The guest list shows a Share action for the guest.
        $this->actingAs($this->f['owner'])->get(route('customer.events.invitation.guests', ['event' => 101]))
            ->assertSee(route('customer.events.share.guest', ['event' => 101, 'guest' => $guest->id]), false);
    }

    public function test_public_link_shows_only_that_guest_and_stops_when_the_credential_changes(): void
    {
        $guest = $this->f['guests']['single'];
        $this->generate([$guest, $this->f['guests']['double']]);
        $url = $this->publicLink($guest);
        $key = basename($url);
        auth()->logout();

        $page = $this->get('/i/'.$key)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $page->assertSee('Asha Mrema')->assertSee('Neema &amp; Baraka', false)->assertDontSee('John Komba')
            ->assertDontSee('712000214')->assertDontSee('+255');
        $card = $this->get('/i/'.$key.'/card.png')->assertOk();
        $this->assertSame('image/png', $card->headers->get('Content-Type'));
        $this->get('/i/'.str_repeat('A', 32))->assertNotFound()->assertSee(__('customer_share.public.not_found_title', [], 'sw'));
        $this->get('/i/'.str_repeat('A', 32).'/card.png')->assertNotFound();

        // Revoked QR -> link no longer works.
        DB::table('qr_codes')->where('guest_id', $guest->id)->update(['revoked_at' => now(), 'is_active' => false]);
        $this->get('/i/'.$key)->assertNotFound();
        DB::table('qr_codes')->where('guest_id', $guest->id)->update(['revoked_at' => null, 'is_active' => true]);
        $this->get('/i/'.$key)->assertOk();

        // Replaced credential -> old link dead, a new link is issued after the card is regenerated.
        DB::table('qr_codes')->where('guest_id', $guest->id)->update(['qr_token' => Guest::makeSecureToken()]);
        $this->get('/i/'.$key)->assertNotFound();
        $this->actingAs($this->f['owner']);
        $this->generate([$guest]);
        $fresh = $this->publicLink($guest);
        $this->assertNotSame($url, $fresh);
        auth()->logout();
        $this->get('/i/'.$key)->assertNotFound();
        $this->get('/i/'.basename($fresh))->assertOk();

        // Cancelled guest or inactive event -> not available.
        DB::table('guests')->where('id', $guest->id)->update(['status' => Guest::STATUS_CANCELLED]);
        $this->get('/i/'.basename($fresh))->assertNotFound();
        DB::table('guests')->where('id', $guest->id)->update(['status' => Guest::STATUS_ACTIVE]);
        DB::table('events')->where('id', 101)->update(['status' => 'cancelled']);
        $this->get('/i/'.basename($fresh))->assertNotFound();
        // Only a hash of the key is stored in clear.
        $this->assertSame(0, DB::table('invitation_links')->where('key_hash', basename($fresh))->count());
        $this->assertSame(1, DB::table('invitation_links')->where('key_hash', hash('sha256', basename($fresh)))->count());
    }

    public function test_recording_individual_sharing_is_honest_idempotent_and_changes_no_guest_data(): void
    {
        $guest = $this->f['guests']['single'];
        $this->generate([$guest]);
        $this->publicLink($guest);
        $before = $this->snapshot();
        $record = fn (string $action, array $extra = []) => $this->actingAs($this->f['owner'])
            ->postJson(route('customer.events.share.record', ['event' => 101]), ['guest_id' => $guest->id, 'channel' => 'whatsapp', 'action' => $action] + $extra);

        $record('initiated')->assertOk()->assertJson(['state' => 'initiated']);
        $record('initiated')->assertOk()->assertJson(['state' => 'initiated']);
        $record('confirmed')->assertOk()->assertJson(['state' => 'confirmed']);
        $first = DB::table('invitation_shares')->value('confirmed_at');
        $this->travel(5)->minutes();
        $record('confirmed')->assertOk()->assertJson(['state' => 'confirmed']);
        $record('initiated')->assertOk()->assertJson(['state' => 'confirmed'], 'opening again does not undo a confirmation');
        $row = DB::table('invitation_shares')->first();
        $this->assertSame(1, DB::table('invitation_shares')->count());
        $this->assertSame(3, (int) $row->attempts);
        $this->assertSame($first, $row->confirmed_at);
        $record('unconfirm')->assertOk()->assertJson(['state' => 'initiated']);
        $record('delivered')->assertStatus(422);
        $this->actingAs($this->f['owner'])->postJson(route('customer.events.share.record', ['event' => 101]), ['guest_id' => $this->f['foreignGuest']->id, 'channel' => 'sms', 'action' => 'initiated'])->assertNotFound();
        $this->actingAs($this->f['foreign'])->postJson(route('customer.events.share.record', ['event' => 101]), ['guest_id' => $guest->id, 'channel' => 'sms', 'action' => 'initiated'])->assertNotFound();

        $this->assertSame($before, $this->snapshot(), 'guests, QR codes, admissions and notification logs are untouched');
        Http::assertNothingSent();
    }

    public function test_guided_bulk_session_selects_progresses_pauses_and_resumes(): void
    {
        $g = $this->f['guests'];
        $noPhone = Guest::query()->create(['event_id' => 101, 'name' => 'Bila Simu', 'phone_number' => '', 'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1]);
        $this->generate([$g['single'], $g['double'], $noPhone]); // "special" has no card
        // Already confirmed individually -> left out by "skip already sent".
        $this->actingAs($this->f['owner'])->postJson(route('customer.events.share.record', ['event' => 101]), ['guest_id' => $g['double']->id, 'channel' => 'sms', 'action' => 'confirmed'])->assertOk();
        $before = $this->snapshot();

        $wizard = $this->actingAs($this->f['owner'])->get(route('customer.events.share.create', ['event' => 101]))->assertOk();
        $this->assertCount(4, $wizard->viewData('pool'));
        $this->assertArrayNotHasKey('name', $wizard->viewData('pool')[0], 'the wizard data carries no names or phones');

        $store = fn (array $data) => $this->actingAs($this->f['owner'])->from(route('customer.events.share.create', ['event' => 101]))
            ->post(route('customer.events.share.sessions.store', ['event' => 101]), $data + ['channel' => 'whatsapp', 'message' => 'Hi {{guest_name}} {{invitation_url}}']);
        $store(['audience' => 'all', 'message' => 'Hi {{guest_name}}'])->assertSessionHasErrors('message');
        $store(['audience' => 'all', 'message' => 'Hi {{phone}} {{invitation_url}}'])->assertSessionHasErrors('message');
        $store(['audience' => 'pass', 'pass' => ['special'], 'with_phone' => '1'])->assertSessionHasErrors('audience');
        $response = $store(['audience' => 'all', 'with_phone' => '1', 'skip_done' => '1', 'template' => 'default_whatsapp']);
        $run = DB::table('invitation_share_sessions')->first();
        $response->assertRedirect(route('customer.events.share.sessions.show', ['event' => 101, 'session' => $run->id]));
        $this->assertSame([$g['single']->id], json_decode($run->recipient_ids, true), 'no card, no phone and already-sent guests are left out');

        $all = $store(['audience' => 'all', 'skip_done' => '0']);
        $run2 = DB::table('invitation_share_sessions')->orderByDesc('id')->first();
        $this->assertEqualsCanonicalizing([$g['single']->id, $g['double']->id, $noPhone->id], json_decode($run2->recipient_ids, true));
        $show = $this->actingAs($this->f['owner'])->get(route('customer.events.share.sessions.show', ['event' => 101, 'session' => $run2->id]))->assertOk();
        $recipients = collect($show->viewData('recipients'))->keyBy('id');
        $this->assertSame('255755000251', $recipients[$g['double']->id]['phone']);
        $this->assertSame('', $recipients[$noPhone->id]['phone']);
        $this->assertNotNull($recipients[$g['double']->id]['elsewhere'], 'already confirmed elsewhere is flagged');
        $this->assertMatchesRegularExpression('#/i/[A-Za-z0-9_-]{32}$#', $recipients[$noPhone->id]['url']);

        $progress = fn (array $data, $user = null) => $this->actingAs($user ?? $this->f['owner'])
            ->postJson(route('customer.events.share.sessions.progress', ['event' => 101, 'session' => $run2->id]), $data);
        $progress(['action' => 'initiated', 'guest_id' => $g['single']->id, 'position' => 0])->assertOk()->assertJson(['state' => 'initiated']);
        $progress(['action' => 'confirmed', 'guest_id' => $g['single']->id, 'position' => 1])->assertOk()->assertJson(['state' => 'confirmed']);
        $progress(['action' => 'skipped', 'guest_id' => $noPhone->id, 'position' => 2])->assertOk()->assertJson(['state' => 'skipped']);
        $progress(['action' => 'confirmed', 'guest_id' => $g['special']->id])->assertNotFound(); // not in this run
        $progress(['action' => 'pause', 'position' => 2])->assertOk();
        $this->assertSame('paused', DB::table('invitation_share_sessions')->where('id', $run2->id)->value('status'));
        $this->assertSame(2, (int) DB::table('invitation_share_sessions')->where('id', $run2->id)->value('position'));
        $hub = $this->actingAs($this->f['owner'])->get(route('customer.events.share', ['event' => 101]));
        $this->assertContains($run2->id, array_map(fn ($s) => $s->id, $hub->viewData('sessions')));
        // Resume: the same position comes back.
        $again = $this->actingAs($this->f['owner'])->get(route('customer.events.share.sessions.show', ['event' => 101, 'session' => $run2->id]))->assertOk();
        $this->assertSame(2, $again->viewData('position'));
        $this->assertSame('active', DB::table('invitation_share_sessions')->where('id', $run2->id)->value('status'));
        $this->assertSame('confirmed', collect($again->viewData('recipients'))->firstWhere('id', $g['single']->id)['state']);
        $progress(['action' => 'message', 'message' => 'no link'])->assertStatus(422);
        $progress(['action' => 'message', 'message' => '<b>Karibu</b> {{guest_name}} {{invitation_url}}'])->assertOk()->assertJson(['message' => 'Karibu {{guest_name}} {{invitation_url}}']);
        $progress(['action' => 'complete', 'position' => 3])->assertOk();
        $this->assertSame('completed', DB::table('invitation_share_sessions')->where('id', $run2->id)->value('status'));
        $progress(['action' => 'confirmed', 'guest_id' => $noPhone->id])->assertStatus(409);

        // Another manager of the same event cannot open or drive this customer's run.
        $manager = User::query()->create(['name' => 'Manager', 'email' => 'manager@example.invalid', 'phone_number' => '+255788000090',
            'password' => 'synthetic-pass-123', 'role' => 'customer', 'is_active' => true, 'preferred_language' => 'sw']);
        DB::table('workspace_users')->insert(['workspace_id' => 10, 'user_id' => $manager->id, 'role' => 'manager', 'is_active' => 1]);
        $this->actingAs($manager)->get(route('customer.events.share.sessions.show', ['event' => 101, 'session' => $run2->id]))->assertNotFound();
        $this->actingAs($this->f['foreign'])->get(route('customer.events.share.sessions.show', ['event' => 101, 'session' => $run2->id]))->assertNotFound();
        $this->actingAs($this->f['foreign'])->get(route('customer.events.share.sessions.show', ['event' => 201, 'session' => $run2->id]))->assertNotFound();

        // Statuses feed the guest list filters and the history.
        $names = fn (array $q) => $this->actingAs($this->f['owner'])->get(route('customer.events.invitation.guests', ['event' => 101] + $q))->viewData('guests')->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Asha Mrema', 'John Komba'], $names(['share' => 'confirmed']));
        $this->assertSame(['Familia ya Mzee Nuru'], $names(['share' => 'not_shared']));
        $this->assertSame(['Bila Simu'], $names(['share' => 'skipped']));
        $history = $this->actingAs($this->f['owner'])->get(route('customer.events.share.history', ['event' => 101, 'channel' => 'whatsapp', 'status' => 'confirmed']))->assertOk();
        $this->assertSame(['Asha Mrema'], collect($history->viewData('rows')->items())->pluck('name')->all());
        $this->assertSame(['John Komba'], collect($this->actingAs($this->f['owner'])->get(route('customer.events.share.history', ['event' => 101, 'q' => 'komba']))->viewData('rows')->items())->pluck('name')->all());

        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_guest_list_selection_starts_a_run_with_only_those_guests(): void
    {
        $g = $this->f['guests'];
        $this->generate([$g['single'], $g['double'], $g['special']]);
        $this->actingAs($this->f['owner'])->post(route('customer.events.share.select', ['event' => 101]), ['guest_ids' => $g['double']->id.','.$g['special']->id])
            ->assertRedirect(route('customer.events.share.create', ['event' => 101, 'from' => 'selection']));
        $wizard = $this->actingAs($this->f['owner'])->get(route('customer.events.share.create', ['event' => 101, 'from' => 'selection']))->assertOk();
        $this->assertEqualsCanonicalizing([$g['double']->id, $g['special']->id], $wizard->viewData('selection'));
        $this->actingAs($this->f['owner'])->post(route('customer.events.share.sessions.store', ['event' => 101]),
            ['audience' => 'selection', 'channel' => 'sms', 'message' => '{{guest_name}} {{invitation_url}}'])->assertRedirect();
        $this->assertEqualsCanonicalizing([$g['double']->id, $g['special']->id], json_decode(DB::table('invitation_share_sessions')->value('recipient_ids'), true));

        $this->actingAs($this->f['owner'])->post(route('customer.events.share.select', ['event' => 101]), ['guest_ids' => (string) $this->f['foreignGuest']->id])->assertNotFound();
        $this->actingAs($this->f['owner'])->post(route('customer.events.share.select', ['event' => 101]), ['guest_ids' => 'x'])->assertStatus(422);
        $this->actingAs($this->f['foreign'])->post(route('customer.events.share.select', ['event' => 101]), ['guest_ids' => (string) $g['double']->id])->assertNotFound();
    }

    public function test_message_templates_are_validated_scoped_and_plain_text(): void
    {
        $url = route('customer.events.share.templates.store', ['event' => 101]);
        $this->actingAs($this->f['owner'])->post($url, ['name' => 'VIP', 'body' => '<i>Karibu</i> {{guest_name}}: {{invitation_url}}'])->assertRedirect();
        $template = DB::table('invitation_share_templates')->first();
        $this->assertSame('Karibu {{guest_name}}: {{invitation_url}}', $template->body);
        $this->actingAs($this->f['owner'])->post($url, ['name' => 'Bad', 'body' => '{{guest_phone}} {{invitation_url}}'])->assertSessionHasErrors('body');
        $this->actingAs($this->f['owner'])->post($url, ['name' => 'Bad', 'body' => 'No link'])->assertSessionHasErrors('body');
        $this->assertSame(1, DB::table('invitation_share_templates')->count());

        $page = $this->actingAs($this->f['owner'])->get(route('customer.events.share.templates', ['event' => 101]))->assertOk();
        $page->assertSee('Karibu {{guest_name}}', false);
        $wizard = $this->actingAs($this->f['owner'])->get(route('customer.events.share.create', ['event' => 101]));
        $this->assertArrayHasKey('saved_'.$template->id, $wizard->viewData('templates'));

        $this->actingAs($this->f['owner'])->put(route('customer.events.share.templates.update', ['event' => 101, 'template' => $template->id]), ['name' => 'VIP 2', 'body' => 'Hi {{invitation_url}}'])->assertRedirect();
        $this->assertSame('VIP 2', DB::table('invitation_share_templates')->value('name'));
        $this->actingAs($this->f['foreign'])->put(route('customer.events.share.templates.update', ['event' => 101, 'template' => $template->id]), ['name' => 'x', 'body' => '{{invitation_url}}'])->assertNotFound();
        $this->actingAs($this->f['foreign'])->delete(route('customer.events.share.templates.destroy', ['event' => 201, 'template' => $template->id]))->assertNotFound();
        $this->actingAs($this->f['owner'])->delete(route('customer.events.share.templates.destroy', ['event' => 101, 'template' => $template->id]))->assertRedirect();
        $this->assertSame(0, DB::table('invitation_share_templates')->count());
    }

    public function test_inactive_event_is_read_only(): void
    {
        $guest = $this->f['guests']['single'];
        $this->generate([$guest]);
        DB::table('events')->where('id', 101)->update(['status' => 'archived']);
        $this->actingAs($this->f['owner'])->get(route('customer.events.share', ['event' => 101]))->assertOk()->assertSee(__('customer_share.hub.read_only', [], 'sw'));
        $this->actingAs($this->f['owner'])->get(route('customer.events.share.create', ['event' => 101]))->assertStatus(409);
        $this->actingAs($this->f['owner'])->postJson(route('customer.events.share.record', ['event' => 101]), ['guest_id' => $guest->id, 'channel' => 'whatsapp', 'action' => 'initiated'])->assertStatus(409);
        $page = $this->actingAs($this->f['owner'])->get(route('customer.events.share.guest', ['event' => 101, 'guest' => $guest->id]))->assertOk();
        $this->assertTrue($page->viewData('readOnly'));
        $page->assertDontSee('data-sh-action="whatsapp"', false);
    }
}
