<?php

namespace Tests\Feature\Invitations;

use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\QrCode;
use App\Services\Invitations\InvitationStudio;
use App\Services\Invitations\Render\InvitationRenderer;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** End-to-end invitation workflow over HTTP: editor, guests, cards, exports and delivery. */
class InvitationWorkflowTest extends TestCase
{
    use InvitationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('invitations');
    }

    private function design(array $overrides = []): array
    {
        return array_replace_recursive(['template' => 'floral_classic', 'content' => ['language' => 'sw']], $overrides);
    }

    private function generate(array $f, array $guestKeys, bool $force = false): array
    {
        $ids = array_map(fn ($key) => $f['guests'][$key]->id, $guestKeys);

        return $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.generate', ['event' => 101]), ['guests' => $ids, 'force' => $force])
            ->assertOk()->json('results');
    }

    /** Samples every QR module of the exported card and compares it with $payload's matrix. */
    private function assertQrEncodes(string $png, string $payload): void
    {
        $studio = app(InvitationStudio::class);
        $event = $studio->event(101);
        ['spec' => $spec] = $studio->spec($event, $studio->design($event, 'sw')['doc']);
        $image = imagecreatefromstring($png);
        $box = (new InvitationRenderer)->qrBox($spec, imagesx($image), $payload);
        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $this->assertGreaterThanOrEqual(5, $box['module'], 'Each QR module must be at least 5 px at export size.');
        $dark = function (int $col, int $row) use ($image, $box): bool {
            $rgb = imagecolorat($image, $box['offset_x'] + $col * $box['module'] + intdiv($box['module'], 2), $box['offset_y'] + $row * $box['module'] + intdiv($box['module'], 2));

            return (($rgb >> 16 & 255) + ($rgb >> 8 & 255) + ($rgb & 255)) / 3 < 128;
        };
        $n = $box['modules'];
        for ($row = -4; $row < $n + 4; $row++) {
            for ($col = -4; $col < $n + 4; $col++) {
                $expected = $row >= 0 && $col >= 0 && $row < $n && $col < $n && $matrix->get($col, $row) === 1;
                $this->assertSame($expected, $dark($col, $row), "QR module ($col,$row) differs (quiet zone included).");
            }
        }
    }

    public function test_editor_is_limited_to_the_owner_of_the_event(): void
    {
        $f = $this->invitationFixture();

        $page = $this->actingAs($f['owner'])->get(route('customer.events.invitation', ['event' => 101]));
        $page->assertOk()->assertViewIs('reference_ui.14_edit_invitation_card')->assertSee('Hariri Kadi')->assertSee('Rangi')->assertSee('Fonti')
            ->assertSee('Picha')->assertSee('Maandishi')->assertSee('Hifadhi Mabadiliko')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->actingAs($f['owner'])->get(route('customer.events.invitation', ['event' => 101, 'lang' => 'en']))->assertSee('Edit Card')->assertSee('Save Changes');
        $this->actingAs($f['foreign'])->get(route('customer.events.invitation', ['event' => 101]))->assertNotFound();
        $this->actingAs($f['owner'])->get(route('customer.events.invitation', ['event' => 201]))->assertNotFound();
        $this->actingAs($f['admin'])->get(route('customer.events.invitation', ['event' => 101]))->assertForbidden();
        $this->actingAs($f['foreign'])->postJson(route('customer.events.invitation.save', ['event' => 101]), ['document' => $this->design()])->assertNotFound();
        auth()->logout();
        $this->get(route('customer.events.invitation', ['event' => 101]))->assertRedirect(route('login'));
    }

    public function test_saving_validates_versions_and_never_changes_the_event_record(): void
    {
        $f = $this->invitationFixture();
        $before = (array) DB::table('events')->where('id', 101)->first(['event_name', 'venue_name', 'event_date', 'event_time', 'bride_name', 'groom_name']);
        $url = route('customer.events.invitation.save', ['event' => 101]);

        $this->actingAs($f['owner'])->postJson($url, ['document' => $this->design(['colors' => ['bg' => '#fefaf0'], 'content' => ['venue' => 'Ukumbi Mpya', 'names' => 'Neema na Baraka']]), 'version' => 0])
            ->assertOk()->assertJson(['ok' => true, 'version' => 1]);
        $this->assertSame($before, (array) DB::table('events')->where('id', 101)->first(['event_name', 'venue_name', 'event_date', 'event_time', 'bride_name', 'groom_name']));
        $this->assertSame('floral_classic', DB::table('events')->where('id', 101)->value('invitation_template'));
        $stored = json_decode(DB::table('invitation_designs')->where('event_id', 101)->value('document'), true);
        $this->assertSame('Ukumbi Mpya', $stored['content']['venue']);

        $this->actingAs($f['owner'])->postJson($url, ['document' => $this->design(), 'version' => 0])->assertStatus(409);
        $this->actingAs($f['owner'])->postJson($url, ['document' => $this->design(['colors' => ['bg' => 'javascript:alert(1)']]), 'version' => 1])->assertStatus(422);
        $this->actingAs($f['owner'])->postJson($url, ['document' => $this->design(['qr' => ['token' => 'forged']]), 'version' => 1])->assertStatus(422);
        $this->actingAs($f['owner'])->postJson($url, ['document' => $this->design(['photo' => ['id' => str_repeat('a', 32)]]), 'version' => 1])->assertStatus(422);
        $this->assertSame(1, (int) DB::table('invitation_designs')->where('event_id', 101)->value('version'));
        $this->assertSame(1, DB::table('invitation_activity')->where('event_id', 101)->where('action', 'design_saved')->count());

        DB::table('events')->where('id', 101)->update(['status' => 'archived']);
        $this->actingAs($f['owner'])->postJson($url, ['document' => $this->design(), 'version' => 1])->assertStatus(409);
    }

    public function test_preview_renders_the_unsaved_draft_with_the_card_engine(): void
    {
        $f = $this->invitationFixture();
        $url = route('customer.events.invitation.preview', ['event' => 101]);

        $first = $this->actingAs($f['owner'])->postJson($url, ['document' => $this->design()])->assertOk()->assertHeader('Content-Type', 'image/png');
        $size = getimagesizefromstring($first->getContent());
        $this->assertSame([600, 800], [$size[0], $size[1]]);
        $second = $this->actingAs($f['owner'])->postJson($url, ['document' => $this->design(['colors' => ['bg' => '#14213d']])])->assertOk();
        $this->assertNotSame($first->getContent(), $second->getContent());
        $this->assertSame(0, DB::table('invitation_designs')->count(), 'Previewing never saves.');
        $this->assertSame(0, QrCode::query()->count(), 'Previewing never creates credentials.');
        $this->actingAs($f['owner'])->postJson($url, ['document' => ['template' => 'nope']])->assertStatus(422);
    }

    public function test_photo_upload_is_validated_reencoded_and_private(): void
    {
        $f = $this->invitationFixture();
        $url = route('customer.events.invitation.photo.store', ['event' => 101]);
        $image = imagecreatetruecolor(900, 1200);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 80));
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        $response = $this->actingAs($f['owner'])->post($url, ['photo' => UploadedFile::fake()->createWithContent('couple.jpg', $jpeg)], ['Accept' => 'application/json'])->assertOk();
        $id = $response->json('id');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $id);
        Storage::disk('invitations')->assertExists('events/101/photos/'.$id.'.jpg');
        $this->actingAs($f['owner'])->get(route('customer.events.invitation.photo', ['event' => 101, 'photo' => $id]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($f['foreign'])->get(route('customer.events.invitation.photo', ['event' => 101, 'photo' => $id]))->assertNotFound();
        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.save', ['event' => 101]), ['document' => $this->design(['template' => 'photo_elegant', 'photo' => ['id' => $id, 'zoom' => 1.4]])])->assertOk();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->actingAs($f['owner'])->post($url, ['photo' => UploadedFile::fake()->createWithContent('x.jpg', $svg)], ['Accept' => 'application/json'])->assertStatus(422);
        $small = imagecreatetruecolor(100, 100);
        ob_start();
        imagepng($small);
        $this->actingAs($f['owner'])->post($url, ['photo' => UploadedFile::fake()->createWithContent('small.png', (string) ob_get_clean())], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_template_gallery_thumbnails_and_template_switch_keep_owner_wording(): void
    {
        $f = $this->invitationFixture();
        $this->actingAs($f['owner'])->get(route('customer.events.invitation.thumb', ['event' => 101, 'template' => 'royal_green']))
            ->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->actingAs($f['owner'])->get(route('customer.events.invitation.thumb', ['event' => 101, 'template' => 'nope']))->assertNotFound();
        $this->actingAs($f['foreign'])->get(route('customer.events.invitation.thumb', ['event' => 101, 'template' => 'royal_green']))->assertNotFound();

        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.save', ['event' => 101]), ['document' => $this->design(['colors' => ['bg' => '#fefaf0'], 'content' => ['message' => 'Karibu sana!']])])->assertOk();
        $this->actingAs($f['owner'])->post(route('customer.events.template.store', ['event' => 101]), ['template' => 'royal_green'])
            ->assertRedirect(route('customer.events.invitation', ['event' => 101]));
        $doc = json_decode(DB::table('invitation_designs')->where('event_id', 101)->value('document'), true);
        $this->assertSame('royal_green', $doc['template']);
        $this->assertSame('Karibu sana!', $doc['content']['message']);
        $this->assertSame([], $doc['colors']);
    }

    public function test_recipients_show_only_this_events_guests(): void
    {
        $f = $this->invitationFixture();

        $page = $this->actingAs($f['owner'])->get(route('customer.events.invitation.guests', ['event' => 101]));
        $page->assertOk()->assertViewIs('reference_ui.31_select_invitation_recipients')->assertSee('Chagua Wapokeaji')
            ->assertSee('Asha Mrema')->assertSee('John Komba')->assertSee('Familia ya Mzee Nuru')->assertDontSee('Foreign Guest')
            ->assertSee('Maalum · 5')->assertSee('+255 712 ••• 214')->assertDontSee('+255712000214');
        $this->actingAs($f['owner'])->get(route('customer.events.invitation.guests', ['event' => 101, 'q' => 'john']))->assertSee('John Komba')->assertDontSee('Asha Mrema');
        $this->actingAs($f['foreign'])->get(route('customer.events.invitation.guests', ['event' => 101]))->assertNotFound();
    }

    public function test_each_guest_gets_a_personal_card_with_their_own_unique_credential(): void
    {
        $f = $this->invitationFixture();
        $existing = $this->giveToken($f['guests']['single']);
        $entriesBefore = Guest::query()->orderBy('id')->get(['id', 'allowed_entries', 'used_entries', 'status'])->toArray();

        $results = $this->generate($f, ['single', 'double', 'special']);
        $this->assertSame(['generated', 'generated', 'generated'], array_column($results, 'status'));

        // Existing credential kept; missing ones issued once; all unique and active.
        $this->assertSame($existing, QrCode::query()->where('guest_id', $f['guests']['single']->id)->value('qr_token'));
        $tokens = QrCode::query()->whereIn('guest_id', array_map(fn ($g) => $g->id, $f['guests']))->pluck('qr_token', 'guest_id');
        $this->assertCount(3, $tokens);
        $this->assertCount(3, $tokens->unique());
        foreach ($tokens as $token) {
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{64}\z/', $token);
        }
        // Rendering never changes admission state.
        $this->assertSame($entriesBefore, Guest::query()->orderBy('id')->get(['id', 'allowed_entries', 'used_entries', 'status'])->toArray());
        $this->assertSame(0, DB::table('admissions')->count());
        $this->assertSame(0, DB::table('checkins')->count());

        $disk = Storage::disk('invitations');
        foreach ($f['guests'] as $guest) {
            $card = DB::table('invitation_cards')->where('event_id', 101)->where('guest_id', $guest->id)->first();
            $png = $disk->get($card->png_path);
            $this->assertSame([1500, 2000], array_slice(getimagesizefromstring($png), 0, 2));
            $this->assertStringStartsWith('%PDF-1.4', $disk->get($card->pdf_path));
            $this->assertQrEncodes($png, route('scanner.verify-token', ['token' => $tokens[$guest->id]]));
            $this->assertSame(hash('sha256', 'invitation-card|'.$tokens[$guest->id]), $card->token_fingerprint);
        }
        $this->assertCount(3, array_unique(array_map(fn ($g) => md5($disk->get("events/101/cards/{$g->id}.png")), $f['guests'])));
    }

    public function test_regeneration_is_idempotent_and_design_changes_keep_the_same_credential(): void
    {
        $f = $this->invitationFixture();
        $this->generate($f, ['double']);
        $token = QrCode::query()->where('guest_id', $f['guests']['double']->id)->value('qr_token');
        $card = DB::table('invitation_cards')->where('guest_id', $f['guests']['double']->id)->first();

        $this->assertSame('current', $this->generate($f, ['double'])[0]['status']);
        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.save', ['event' => 101]), ['document' => $this->design(['colors' => ['bg' => '#f0e8ff']])])->assertOk();
        $this->assertSame('generated', $this->generate($f, ['double'])[0]['status']);

        $this->assertSame($token, QrCode::query()->where('guest_id', $f['guests']['double']->id)->value('qr_token'));
        $this->assertSame(1, QrCode::query()->where('guest_id', $f['guests']['double']->id)->count());
        $this->assertNotSame($card->design_fingerprint, DB::table('invitation_cards')->where('guest_id', $f['guests']['double']->id)->value('design_fingerprint'));
        $this->assertSame(1, DB::table('invitation_cards')->where('guest_id', $f['guests']['double']->id)->count());
    }

    public function test_cross_event_revoked_and_cancelled_guests_are_refused(): void
    {
        $f = $this->invitationFixture();
        $url = route('customer.events.invitation.generate', ['event' => 101]);

        $this->actingAs($f['owner'])->postJson($url, ['guests' => [$f['guests']['single']->id, $f['foreignGuest']->id]])->assertNotFound();
        $this->actingAs($f['foreign'])->postJson($url, ['guests' => [$f['guests']['single']->id]])->assertNotFound();
        $this->actingAs($f['owner'])->postJson($url, ['guests' => ['1 OR 1=1']])->assertStatus(422);
        $this->assertSame(0, DB::table('invitation_cards')->count());
        $this->assertSame(0, QrCode::query()->count());

        $this->giveToken($f['guests']['single']);
        QrCode::query()->where('guest_id', $f['guests']['single']->id)->update(['is_active' => false, 'revoked_at' => now()]);
        $f['guests']['double']->update(['status' => Guest::STATUS_CANCELLED]);
        $results = $this->generate($f, ['single', 'double']);
        $this->assertSame(['revoked', 'cancelled'], array_column($results, 'status'));
        $this->assertSame(1, QrCode::query()->count(), 'Revoked credentials are never silently replaced.');
        $this->assertSame(0, DB::table('invitation_cards')->count());
    }

    public function test_downloads_preview_and_batch_zip_contain_the_right_cards(): void
    {
        $f = $this->invitationFixture();
        $this->generate($f, ['single', 'double', 'special']);
        $guest = $f['guests']['double'];
        $card = fn (string $format, array $extra = []) => route('customer.events.invitation.card', ['event' => 101, 'guest' => $guest->id, 'format' => $format] + $extra);

        $this->actingAs($f['owner'])->get($card('png'))->assertOk()->assertHeader('Content-Type', 'image/png');
        $jpg = $this->actingAs($f['owner'])->get($card('jpg', ['download' => 1]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('attachment', (string) $jpg->headers->get('Content-Disposition'));
        $this->assertStringContainsString('John-Komba', (string) $jpg->headers->get('Content-Disposition'));
        $pdf = $this->actingAs($f['owner'])->get($card('pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $content = $pdf->baseResponse->getFile()->getContent();
        $this->assertStringContainsString('/MediaBox [0 0 432 576]', $content);
        $this->assertStringContainsString('/Width 1800 /Height 2400', $content);
        $this->actingAs($f['foreign'])->get($card('png'))->assertNotFound();
        $this->actingAs($f['owner'])->get(route('customer.events.invitation.card', ['event' => 101, 'guest' => $f['foreignGuest']->id, 'format' => 'png']))->assertNotFound();

        $page = $this->actingAs($f['owner'])->get(route('customer.events.invitation.guest', ['event' => 101, 'guest' => $guest->id]));
        $page->assertOk()->assertViewIs('reference_ui.06_invitation_preview')->assertSee('Uhakiki wa Kadi')->assertSee('John Komba')
            ->assertSee('Kadi ya watu 2')->assertSee($card('png'), false);

        $zipResponse = $this->actingAs($f['owner'])->post(route('customer.events.invitation.download', ['event' => 101]), [
            'guests' => array_map(fn ($g) => $g->id, $f['guests']), 'format' => 'all',
        ])->assertOk();
        $zip = new ZipArchive;
        $zip->open($zipResponse->baseResponse->getFile()->getPathname());
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);
        $this->assertSame(['001-Asha-Mrema.pdf', '001-Asha-Mrema.png', '002-Familia-ya-Mzee-Nuru.pdf', '002-Familia-ya-Mzee-Nuru.png', '003-John-Komba.pdf', '003-John-Komba.png'], $names);
        $this->assertSame(Storage::disk('invitations')->get("events/101/cards/{$guest->id}.png"), $zip->getFromName('003-John-Komba.png'));
        $zip->close();
    }

    public function test_whatsapp_is_not_simulated_when_unconfigured(): void
    {
        $f = $this->invitationFixture();
        config(['services.whatsapp.meta.access_token' => null, 'services.whatsapp.meta.phone_number_id' => null]);
        $this->generate($f, ['single']);
        Http::fake();

        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.send', ['event' => 101]), ['guests' => [$f['guests']['single']->id]])
            ->assertStatus(409)->assertJson(['configured' => false]);
        $this->assertSame(0, NotificationLog::query()->count());
        Http::assertNothingSent();
        $this->actingAs($f['owner'])->get(route('customer.events.invitation.guests', ['event' => 101]))->assertSee('WhatsApp bado haijaunganishwa');
    }

    public function test_whatsapp_send_records_provider_response_prevents_duplicates_and_tracks_webhooks(): void
    {
        $f = $this->invitationFixture();
        config(['services.whatsapp.meta' => ['api_base_url' => 'https://graph.example.test', 'api_version' => 'v21.0', 'access_token' => 'test-token',
            'phone_number_id' => '123', 'app_secret' => 'webhook-secret', 'invitation_template' => 'pangaevents_invitation', 'invitation_template_language' => 'sw']]);
        Http::fake(['graph.example.test/*' => Http::response(['messages' => [['id' => 'wamid.TEST1']]], 200)]);
        $this->generate($f, ['single', 'double']);
        $send = fn (array $guests, bool $resend = false) => $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.send', ['event' => 101]), ['guests' => $guests, 'resend' => $resend])->assertOk()->json('results');

        $this->assertSame('sent', $send([$f['guests']['single']->id])[0]['status']);
        $log = NotificationLog::query()->sole();
        $this->assertSame('wamid.TEST1', $log->provider_message_id);
        $this->assertSame('digital_card', $log->message_type);
        $this->assertSame('+255712000214', $log->recipient_phone);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['type'] === 'template' && $body['template']['name'] === 'pangaevents_invitation'
                && $body['template']['components'][0]['type'] === 'header'
                && str_contains($body['template']['components'][0]['parameters'][0]['image']['link'], '/invitations/media/')
                && $body['template']['components'][1]['parameters'][0]['text'] === 'Asha Mrema';
        });
        $this->assertSame('already_sent', $send([$f['guests']['single']->id])[0]['status']);
        $this->assertSame(1, NotificationLog::query()->count());
        $this->assertSame('sent', $send([$f['guests']['single']->id], true)[0]['status']);
        $this->assertSame(2, NotificationLog::query()->count());

        // The signed media link serves the card; tampering or regenerating invalidates it.
        $media = $log->context['header_image_url'];
        $this->get($media)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get($media.'x')->assertForbidden();

        // Verified status webhooks move the log forward only.
        $webhook = function (string $status) {
            $body = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.TEST1', 'status' => $status]]]]]]]]);

            return $this->call('POST', '/webhooks/whatsapp/meta', [], [], [], ['CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'webhook-secret')], $body);
        };
        $webhook('delivered')->assertOk();
        $this->assertSame('delivered', $log->fresh()->status);
        $webhook('sent')->assertOk();
        $webhook('failed')->assertOk();
        $this->assertSame('delivered', $log->fresh()->status);
        $this->call('POST', '/webhooks/whatsapp/meta', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.TEST1', 'status' => 'read']]]]]]]]))->assertOk();
        $this->assertSame('delivered', $log->fresh()->status, 'Unsigned webhooks are ignored.');

        // A guest whose card is outdated is not sent a stale image.
        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.save', ['event' => 101]), ['document' => $this->design(['colors' => ['bg' => '#fff0f0']])])->assertOk();
        $this->assertSame('needs_card', $send([$f['guests']['double']->id])[0]['status']);
        $this->generate($f, ['single']);
        $this->get($media)->assertNotFound();

        $status = $this->actingAs($f['owner'])->get(route('customer.events.invitation.status', ['event' => 101]));
        $status->assertOk()->assertViewIs('reference_ui.17_invitation_sending_progress')->assertSee('Hali ya Mialiko');
        $this->assertSame(3, $status->viewData('counts')['guests']);
    }
}
