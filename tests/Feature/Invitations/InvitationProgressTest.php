<?php

namespace Tests\Feature\Invitations;

use App\Models\NotificationLog;
use App\Services\Invitations\InvitationDesign;
use App\Services\Invitations\InvitationProgress;
use App\Services\Invitations\InvitationStudio;
use App\Services\Invitations\Render\InvitationRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** "Continue Invitation" resolver, the event-page entry, and the Working Flow 14.1 gallery UX. */
class InvitationProgressTest extends TestCase
{
    use InvitationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('invitations');
    }

    private function next(int $eventId = 101): array
    {
        return app(InvitationProgress::class)->forEvent(app(InvitationStudio::class)->event($eventId), 'sw');
    }

    private function save(array $f, array $document = []): void
    {
        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.save', ['event' => 101]), ['document' => ['template' => 'floral_classic'] + $document])->assertOk();
    }

    private function generate(array $f, array $keys): void
    {
        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.generate', ['event' => 101]), ['guests' => array_map(fn ($k) => $f['guests'][$k]->id, $keys)])->assertOk();
    }

    public function test_next_step_follows_saved_progress_and_resumes_correctly(): void
    {
        $f = $this->invitationFixture();
        $continue = fn () => $this->actingAs($f['owner'])->get(route('customer.events.invitation.continue', ['event' => 101]));

        // 1. Nothing chosen yet.
        $this->assertSame(['design', 1, []], [$this->next()['next'], $this->next()['step'], $this->next()['done']]);
        $continue()->assertRedirect(route('customer.events.template', ['event' => 101]));

        // 2. Design chosen, card never saved.
        $this->actingAs($f['owner'])->post(route('customer.events.template.store', ['event' => 101]), ['template' => 'floral_classic'])->assertRedirect();
        $this->assertSame(['card', 2, ['design']], [$this->next()['next'], $this->next()['step'], $this->next()['done']]);
        $continue()->assertRedirect(route('customer.events.invitation', ['event' => 101]));

        // 3/4. Card saved; guests exist, none has a card: create all three.
        $this->save($f);
        $state = $this->next();
        $this->assertSame(['cards', 4, 3, 0, 3], [$state['next'], $state['step'], $state['counts']['guests'], $state['counts']['ready'], $state['counts']['to_create']]);
        $continue()->assertRedirect(route('customer.events.invitation.guests', ['event' => 101]));

        // Some ready, some not: only the remaining one is counted.
        $this->generate($f, ['single', 'double']);
        $this->assertSame([2, 1], [$this->next()['counts']['ready'], $this->next()['counts']['to_create']]);

        // 5. All cards ready -> share.
        $this->generate($f, ['special']);
        $this->assertSame(['share', 5], [$this->next()['next'], $this->next()['step']]);
        $continue()->assertRedirect(route('customer.events.invitation.status', ['event' => 101]));

        // A design change makes every card need updating again.
        $this->save($f, ['colors' => ['bg' => '#fff0f0']]);
        $this->assertSame(['cards', 3], [$this->next()['next'], $this->next()['counts']['to_create']]);
        $this->generate($f, ['single', 'double', 'special']);

        // 6. Everyone sent -> manage.
        foreach ($f['guests'] as $guest) {
            NotificationLog::query()->create(['guest_id' => $guest->id, 'event_id' => 101, 'channel' => 'whatsapp', 'message_type' => 'digital_card',
                'recipient_phone' => '+255700000000', 'status' => 'sent', 'provider' => 'meta']);
        }
        $this->assertSame(['manage', 'share', InvitationProgress::STAGES], [$this->next()['next'], $this->next()['stage'], $this->next()['done']]);
        $continue()->assertRedirect(route('customer.events.invitation.status', ['event' => 101]));
    }

    public function test_event_without_guests_is_sent_to_guests_and_cancelled_guests_do_not_count(): void
    {
        $f = $this->invitationFixture();
        $this->save($f);
        DB::table('guests')->where('event_id', 101)->update(['status' => 'cancelled']);

        $this->assertSame(['guests', 3, 0], [$this->next()['next'], $this->next()['step'], $this->next()['counts']['guests']]);
        $this->actingAs($f['owner'])->get(route('customer.events.invitation.continue', ['event' => 101]))
            ->assertRedirect(route('customer.events.invitation.guests', ['event' => 101]));
    }

    public function test_continue_is_read_only_and_limited_to_the_event_owner(): void
    {
        $f = $this->invitationFixture();
        $before = [DB::table('invitation_designs')->count(), DB::table('qr_codes')->count(), DB::table('invitation_cards')->count(), DB::table('invitation_activity')->count()];

        $this->actingAs($f['owner'])->get(route('customer.events.invitation.continue', ['event' => 101]))->assertRedirect();
        $this->assertSame($before, [DB::table('invitation_designs')->count(), DB::table('qr_codes')->count(), DB::table('invitation_cards')->count(), DB::table('invitation_activity')->count()]);
        $this->actingAs($f['foreign'])->get(route('customer.events.invitation.continue', ['event' => 101]))->assertNotFound();
        $this->actingAs($f['admin'])->get(route('customer.events.invitation.continue', ['event' => 101]))->assertForbidden();
        auth()->logout();
        $this->get(route('customer.events.invitation.continue', ['event' => 101]))->assertRedirect(route('login'));
    }

    public function test_event_page_offers_one_continue_invitation_action(): void
    {
        $f = $this->invitationFixture();
        $url = route('customer.events.invitation.continue', ['event' => 101]);

        $this->actingAs($f['owner'])->get(route('customer.events.dashboard', ['event' => 101]))->assertOk()
            ->assertSee('Mialiko Yako')->assertSee('Endelea na Mialiko')->assertSee('Hatua 1 kati ya 5 — Chagua Muundo')
            ->assertSee('href="'.$url.'" data-db-invitation', false);
        $this->save($f);
        $this->actingAs($f['owner'])->get(route('customer.events.dashboard', ['event' => 101, 'lang' => 'en']))
            ->assertSee('Your Invitation')->assertSee('Continue Invitation')->assertSee('Step 4 of 5 — Create Cards')->assertSee('Next: create 3 guest cards.');
    }

    public function test_gallery_shows_the_step_the_large_preview_and_one_clear_action(): void
    {
        $f = $this->invitationFixture();
        $large = route('customer.events.invitation.thumb', ['event' => 101, 'template' => 'floral_classic', 'lang' => 'sw', 'size' => 'large']);

        $page = $this->actingAs($f['owner'])->get(route('customer.events.template', ['event' => 101]));
        $page->assertOk()->assertSee('Hatua 1 kati ya 5 — Chagua Muundo')->assertSee('Chagua muundo unaoupenda. Unaweza kuubadilisha wakati wowote.')
            ->assertSee('Tumia Muundo Huu na Endelea')->assertSee('data-src="'.e($large).'"', false)->assertSee('Tazama')->assertDontSee('Muundo wako wa sasa');
        $this->assertSame(1, substr_count($page->getContent(), 'aria-current="step"'));

        // The large preview is rendered on demand at 900 px; the grid thumbnail stays small.
        $image = $this->actingAs($f['owner'])->get($large)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertSame(900, imagesx(imagecreatefromstring($image->getContent())));
        $small = $this->actingAs($f['owner'])->get(route('customer.events.invitation.thumb', ['event' => 101, 'template' => 'floral_classic']))->getContent();
        $this->assertSame(330, imagesx(imagecreatefromstring($small)));

        // Choosing goes straight to the editor; coming back shows the current design and "continue".
        $this->actingAs($f['owner'])->post(route('customer.events.template.store', ['event' => 101]), ['template' => 'floral_classic'])
            ->assertRedirect(route('customer.events.invitation', ['event' => 101]));
        $back = $this->actingAs($f['owner'])->get(route('customer.events.template', ['event' => 101]));
        $back->assertSee('Muundo wako wa sasa')->assertSee('Endelea na Muundo Huu');
        $this->assertMatchesRegularExpression('/value="floral_classic" checked/', $back->getContent());
        // Completed stages link back; this page stays the current one.
        $this->save($f);
        $this->actingAs($f['owner'])->get(route('customer.events.template', ['event' => 101, 'lang' => 'en']))
            ->assertSee('Step 1 of 5 — Choose Design')->assertSee('href="'.route('customer.events.invitation', ['event' => 101]).'" title="Customize Card"', false);
    }

    public function test_missing_artwork_never_breaks_a_design(): void
    {
        $event = (object) ['event_name' => 'Neema & Baraka', 'bride_name' => null, 'groom_name' => null, 'venue_name' => 'Grand Hall',
            'event_date' => '2026-12-14', 'event_time' => '18:00:00', 'event_type' => 'wedding'];
        $spec = InvitationDesign::resolve(InvitationDesign::defaults('floral_classic', 'wedding'), $event)['spec'];
        $spec['decor'][] = ['asset' => 'artwork_that_was_deleted', 'anchor' => 'tl', 'w' => 200];
        $renderer = new InvitationRenderer;

        $image = $renderer->render($spec, 300);

        $this->assertSame(300, imagesx($image));
        $this->assertContains('artwork_missing', $renderer->warnings());
        $this->assertArrayHasKey('decor.0', $renderer->layoutMap());
        $this->assertArrayNotHasKey('decor.2', $renderer->layoutMap());
    }
}
