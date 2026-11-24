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
 * RSVP on the personal invitation page and the owner's RSVP page
 * (docs/RSVP_AND_GUEST_TICKETS.md): explicit POST only, idempotent keyed submissions,
 * revision conflicts, deadline and change policy, separate from passes/QR/admission.
 */
class RsvpTest extends TestCase
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

    /** Generates the guest's card and issues their personal link; returns the key. */
    private function issue(Guest $guest): string
    {
        $this->actingAs($this->f['owner'])->postJson(route('customer.events.invitation.generate', ['event' => 101]), ['guests' => [$guest->id]])->assertOk();
        $url = $this->actingAs($this->f['owner'])->get(route('customer.events.share.guest', ['event' => 101, 'guest' => $guest->id]))->viewData('link')['url'];
        auth()->logout();

        return basename($url);
    }

    private function reply(string $key, array $data)
    {
        return $this->post('/i/'.$key.'/rsvp', $data + ['submission' => bin2hex(random_bytes(16))]);
    }

    private function snapshot(): array
    {
        return [
            DB::table('guests')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('qr_codes')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('admissions')->count(), DB::table('checkins')->count(),
        ];
    }

    public function test_issuing_the_link_creates_a_pending_rsvp_and_opening_the_page_changes_nothing(): void
    {
        $guest = $this->f['guests']['double'];
        $this->assertSame(0, DB::table('guest_rsvps')->count());
        $key = $this->issue($guest);
        $rsvp = DB::table('guest_rsvps')->first();
        $this->assertSame([(int) $guest->id, 'pending', 0], [(int) $rsvp->guest_id, $rsvp->response, (int) $rsvp->revision]);
        $this->assertNotNull($rsvp->invited_at);

        $before = [DB::table('guest_rsvps')->get()->toArray(), $this->snapshot()];
        $page = $this->get('/i/'.$key)->assertOk();
        $page->assertSee('Utahudhuria?')->assertSee('Ndiyo, nitakuja')->assertSee('Mtakuja wangapi?')->assertSee('name="count"', false);
        $this->get('/i/'.$key.'?lang=en')->assertOk()->assertSee('Will you attend?');
        $this->assertEquals($before, [DB::table('guest_rsvps')->get()->toArray(), $this->snapshot()], 'GET never answers or changes anything');
        $this->assertSame(0, DB::table('guest_rsvp_submissions')->count());
    }

    public function test_guest_replies_with_headcount_and_note_without_touching_the_pass(): void
    {
        $guest = $this->f['guests']['special']; // 5 entries
        $key = $this->issue($guest);
        $before = $this->snapshot();

        $this->reply($key, ['response' => 'attending', 'count' => 6, 'revision' => 0])->assertRedirect()->assertSessionHas('rsvp_status', 'invalid');
        $this->reply($key, ['response' => 'attending', 'count' => 3, 'note' => '<b>Tutafika</b> mapema', 'revision' => 0])
            ->assertRedirect(route('invitations.public', ['key' => $key]).'#rsvp')->assertSessionHas('rsvp_status', 'saved');
        $rsvp = DB::table('guest_rsvps')->first();
        $this->assertSame(['attending', 3, 'Tutafika mapema', 1, 'guest'], [$rsvp->response, (int) $rsvp->attending_count, $rsvp->note, (int) $rsvp->revision, $rsvp->response_source]);
        $this->assertNotNull($rsvp->responded_at);
        $this->get('/i/'.$key)->assertSee('Jibu lako')->assertSee('watu 3')->assertSee('Badilisha jibu langu');

        // Change to "not attending": headcount cleared, first-response time kept.
        $first = $rsvp->responded_at;
        $this->travel(10)->minutes();
        $this->reply($key, ['response' => 'not_attending', 'count' => 3, 'revision' => 1])->assertSessionHas('rsvp_status', 'saved');
        $rsvp = DB::table('guest_rsvps')->first();
        $this->assertSame(['not_attending', null, 2], [$rsvp->response, $rsvp->attending_count, (int) $rsvp->revision]);
        $this->assertSame($first, $rsvp->responded_at);
        $this->assertNotSame($first, $rsvp->response_changed_at);

        $this->assertSame($before, $this->snapshot(), 'guest, QR and admission records are untouched');
        Http::assertNothingSent();
    }

    public function test_submissions_are_idempotent_and_stale_forms_conflict(): void
    {
        $key = $this->issue($this->f['guests']['single']);
        $yes = bin2hex(random_bytes(16));
        $this->post('/i/'.$key.'/rsvp', ['response' => 'attending', 'revision' => 0, 'submission' => $yes])->assertSessionHas('rsvp_status', 'saved');
        // Double tap / refresh of the same form: applied once.
        $this->post('/i/'.$key.'/rsvp', ['response' => 'attending', 'revision' => 0, 'submission' => $yes])->assertSessionHas('rsvp_status', 'replay');
        $this->assertSame(1, (int) DB::table('guest_rsvps')->value('revision'));
        $this->assertSame(1, DB::table('guest_rsvp_submissions')->count());

        // A later NO, then a replay of the old YES must not restore YES.
        $this->reply($key, ['response' => 'not_attending', 'revision' => 1])->assertSessionHas('rsvp_status', 'saved');
        $this->post('/i/'.$key.'/rsvp', ['response' => 'attending', 'revision' => 0, 'submission' => $yes])->assertSessionHas('rsvp_status', 'replay');
        $this->assertSame('not_attending', DB::table('guest_rsvps')->value('response'));
        // Same key, different content.
        $this->post('/i/'.$key.'/rsvp', ['response' => 'not_attending', 'note' => 'x', 'revision' => 2, 'submission' => $yes])->assertSessionHas('rsvp_status', 'key_conflict');
        // A form opened before the last change (stale revision) is refused.
        $this->reply($key, ['response' => 'attending', 'revision' => 1])->assertSessionHas('rsvp_status', 'stale');
        $this->assertSame('not_attending', DB::table('guest_rsvps')->value('response'));
        // Repeating the current answer with a new key: a no-change receipt, nothing else changes.
        $this->reply($key, ['response' => 'not_attending', 'revision' => 2])->assertSessionHas('rsvp_status', 'no_change');
        $this->assertSame(2, (int) DB::table('guest_rsvps')->value('revision'));
        $this->assertSame(1, DB::table('guest_rsvp_submissions')->where('no_change', true)->count());
        // Malformed input.
        $this->post('/i/'.$key.'/rsvp', ['response' => 'maybe', 'submission' => bin2hex(random_bytes(16))])->assertSessionHasErrors('response');
        $this->post('/i/'.$key.'/rsvp', ['response' => 'attending'])->assertSessionHasErrors('submission');
        $this->assertSame(0, DB::table('guest_rsvp_submissions')->where('request_key_hash', $yes)->count(), 'only hashes of submission keys are stored');
    }

    public function test_deadline_change_policy_and_availability(): void
    {
        $guest = $this->f['guests']['single'];
        $key = $this->issue($guest);

        DB::table('events')->where('id', 101)->update(['rsvp_allow_changes' => false]);
        $this->reply($key, ['response' => 'attending', 'revision' => 0])->assertSessionHas('rsvp_status', 'saved');
        $this->reply($key, ['response' => 'not_attending', 'revision' => 1])->assertSessionHas('rsvp_status', 'locked');
        $this->get('/i/'.$key)->assertSee('Majibu hayawezi kubadilishwa')->assertDontSee('name="response"', false);

        DB::table('events')->where('id', 101)->update(['rsvp_allow_changes' => true, 'rsvp_deadline' => now()->subMinute()]);
        $this->reply($key, ['response' => 'not_attending', 'revision' => 1])->assertSessionHas('rsvp_status', 'closed');
        $this->assertSame('attending', DB::table('guest_rsvps')->value('response'));

        // No owner deadline: replies close at the end of the event day (Tanzania time).
        DB::table('events')->where('id', 101)->update(['rsvp_deadline' => null, 'event_date' => now()->subDays(2)->toDateString()]);
        $this->reply($key, ['response' => 'not_attending', 'revision' => 1])->assertSessionHas('rsvp_status', 'closed');
        DB::table('events')->where('id', 101)->update(['event_date' => now()->addDays(5)->toDateString()]);
        $this->reply($key, ['response' => 'not_attending', 'revision' => 1])->assertSessionHas('rsvp_status', 'saved');

        // RSVP switched off: no question and no POST.
        DB::table('events')->where('id', 101)->update(['rsvp_enabled' => false]);
        $this->get('/i/'.$key)->assertOk()->assertDontSee('Utahudhuria?')->assertDontSee('Jibu lako');
        $this->reply($key, ['response' => 'attending', 'revision' => 2])->assertNotFound();
        DB::table('events')->where('id', 101)->update(['rsvp_enabled' => true]);

        // Cancelled guest / unknown key: the same not-found answer.
        DB::table('guests')->where('id', $guest->id)->update(['status' => Guest::STATUS_CANCELLED]);
        $this->reply($key, ['response' => 'attending', 'revision' => 2])->assertNotFound();
        $this->reply(str_repeat('A', 32), ['response' => 'attending'])->assertNotFound();
    }

    public function test_owner_page_summary_settings_record_export_and_isolation(): void
    {
        $g = $this->f['guests'];
        $keys = ['single' => $this->issue($g['single']), 'double' => $this->issue($g['double']), 'special' => $this->issue($g['special'])];
        $this->reply($keys['double'], ['response' => 'attending', 'count' => 2, 'note' => '=HYPERLINK("x")', 'revision' => 0]);
        $this->reply($keys['special'], ['response' => 'not_attending', 'revision' => 0]);

        $page = $this->actingAs($this->f['owner'])->get(route('customer.events.rsvp', ['event' => 101]))->assertOk();
        $this->assertSame(['invited' => 3, 'attending' => 1, 'not_attending' => 1, 'pending' => 1, 'expected_people' => 2], $page->viewData('summary'));
        $this->assertSame(['John Komba'], collect($this->actingAs($this->f['owner'])->get(route('customer.events.rsvp', ['event' => 101, 'response' => 'attending']))->viewData('rows')->items())->pluck('name')->all());
        $this->assertSame(['Asha Mrema'], collect($this->actingAs($this->f['owner'])->get(route('customer.events.rsvp', ['event' => 101, 'q' => 'asha']))->viewData('rows')->items())->pluck('name')->all());

        // Owner records a phoned-in reply (works after the guest deadline too).
        DB::table('events')->where('id', 101)->update(['rsvp_deadline' => now()->subDay()]);
        $this->actingAs($this->f['owner'])->post(route('customer.events.rsvp.record', ['event' => 101]),
            ['guest_id' => $g['single']->id, 'response' => 'attending', 'submission' => bin2hex(random_bytes(16))])->assertRedirect()->assertSessionHas('status');
        $row = DB::table('guest_rsvps')->where('guest_id', $g['single']->id)->first();
        $this->assertSame(['attending', 'owner'], [$row->response, $row->response_source]);
        $this->assertSame($this->f['owner']->id, (int) DB::table('guest_rsvp_submissions')->where('source', 'owner')->value('user_id'));

        // Settings: deadline entered in Tanzania time is stored in UTC.
        $this->actingAs($this->f['owner'])->put(route('customer.events.rsvp.settings', ['event' => 101]), ['enabled' => '1', 'allow_changes' => '1', 'deadline' => '2026-12-10T18:00'])->assertRedirect();
        $this->assertSame('2026-12-10 15:00:00', (string) DB::table('events')->where('id', 101)->value('rsvp_deadline'));
        $this->actingAs($this->f['owner'])->put(route('customer.events.rsvp.settings', ['event' => 101]), ['deadline' => 'not-a-date'])->assertSessionHasErrors('deadline');
        $this->actingAs($this->f['owner'])->put(route('customer.events.rsvp.settings', ['event' => 101]), [])->assertRedirect();
        $event = DB::table('events')->where('id', 101)->first(['rsvp_enabled', 'rsvp_allow_changes', 'rsvp_deadline']);
        $this->assertSame([0, 0, null], [(int) $event->rsvp_enabled, (int) $event->rsvp_allow_changes, $event->rsvp_deadline]);

        // CSV: UTF-8 BOM, formula-safe.
        $csv = $this->actingAs($this->f['owner'])->get(route('customer.events.rsvp.export', ['event' => 101]))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('John Komba', $csv);

        // Guest list RSVP filter.
        $names = fn (string $v) => $this->actingAs($this->f['owner'])->get(route('customer.events.invitation.guests', ['event' => 101, 'rsvp' => $v]))->viewData('guests')->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Asha Mrema', 'John Komba'], $names('attending'));
        $this->assertSame(['Familia ya Mzee Nuru'], $names('not_attending'));

        // Isolation: other customers, other events, foreign guests.
        foreach ([route('customer.events.rsvp', ['event' => 101]), route('customer.events.rsvp.export', ['event' => 101])] as $url) {
            $this->actingAs($this->f['foreign'])->get($url)->assertNotFound();
        }
        $this->actingAs($this->f['foreign'])->post(route('customer.events.rsvp.record', ['event' => 101]), ['guest_id' => $g['single']->id, 'response' => 'not_attending', 'submission' => bin2hex(random_bytes(16))])->assertNotFound();
        $this->actingAs($this->f['owner'])->post(route('customer.events.rsvp.record', ['event' => 101]), ['guest_id' => $this->f['foreignGuest']->id, 'response' => 'attending', 'submission' => bin2hex(random_bytes(16))])->assertNotFound();
        $this->actingAs($this->f['foreign'])->put(route('customer.events.rsvp.settings', ['event' => 101]), ['enabled' => '1'])->assertNotFound();
        $this->actingAs($this->f['admin'])->get(route('customer.events.rsvp', ['event' => 101]))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_dashboard_and_checklist_use_real_replies(): void
    {
        $key = $this->issue($this->f['guests']['double']);
        $this->issue($this->f['guests']['single']);
        $this->reply($key, ['response' => 'attending', 'count' => 2, 'revision' => 0]);
        $stats = $this->actingAs($this->f['owner'])->get(route('customer.events.dashboard', ['event' => 101]))->assertOk()->viewData('stats');
        $this->assertSame([1, 1, 2], [$stats['rsvp_confirmed'], $stats['rsvp_pending'], $stats['rsvp_invited']]);
        $steps = collect($this->actingAs($this->f['owner'])->get(route('customer.events.setup', ['event' => 101]))->viewData('progress')['steps'])->keyBy('key');
        $this->assertSame('in_progress', $steps['rsvp']['status']);
    }

    public function test_rsvp_replies_are_rate_limited_per_link(): void
    {
        $key = $this->issue($this->f['guests']['single']);
        for ($i = 0; $i < 10; $i++) {
            $this->reply($key, ['response' => 'attending', 'revision' => $i === 0 ? 0 : 1])->assertRedirect();
        }
        $this->reply($key, ['response' => 'attending', 'revision' => 1])->assertStatus(429);
    }
}
