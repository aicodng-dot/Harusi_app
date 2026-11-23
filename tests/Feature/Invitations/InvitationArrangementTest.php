<?php

namespace Tests\Feature\Invitations;

use App\Services\Invitations\InvitationDesign;
use App\Services\Invitations\InvitationTemplateCatalog;
use App\Services\Invitations\Render\InvitationRenderer;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Free arrangement: dragging elements, per-element font size, hiding individual
 * decorations, photo frame shapes/borders and the crop focal point.
 */
class InvitationArrangementTest extends TestCase
{
    use InvitationFixtures;
    use RefreshDatabase;

    private const PAYLOAD = 'https://example.test/scanner/verify/Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3Ab3x';

    private function event(): object
    {
        return (object) ['event_name' => 'Neema & Baraka', 'bride_name' => null, 'groom_name' => null, 'venue_name' => 'Grand Hall',
            'event_date' => '2026-12-14', 'event_time' => '18:00:00', 'event_type' => 'wedding'];
    }

    /** @return array{0: InvitationRenderer, 1: \GdImage, 2: array} */
    private function render(string $template, array $changes, ?array $guest = null, int $width = 600): array
    {
        [$doc, $errors] = InvitationDesign::validate($changes, InvitationDesign::defaults($template, 'wedding'));
        $this->assertSame([], $errors, json_encode($changes));
        $spec = InvitationDesign::resolve($doc, $this->event())['spec'];
        $renderer = new InvitationRenderer;

        return [$renderer, $renderer->render($spec, $width, $guest), $spec];
    }

    private function hash(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return md5((string) ob_get_clean());
    }

    public function test_layout_overrides_are_validated_and_bounded(): void
    {
        $defaults = InvitationDesign::defaults('photo_elegant', 'wedding');
        [$doc, $errors] = InvitationDesign::validate([
            'layout' => ['blocks' => ['names' => ['dx' => 40.26, 'dy' => -12, 'size' => 1.35], 'intro' => ['hidden' => true], 'label' => ['dx' => 0, 'size' => 1]],
                'photo' => ['dx' => 20, 'size' => 0.9]],
            'decor' => ['items' => ['1' => ['hidden' => true], '0' => ['size' => 1.2, 'dy' => 30]]],
            'photo' => ['shape' => 'circle', 'border' => 'thick'],
        ], $defaults);

        $this->assertSame([], $errors);
        $this->assertSame(['names' => ['dx' => 40.3, 'dy' => -12.0, 'size' => 1.35], 'intro' => ['hidden' => true]], $doc['layout']['blocks'], 'Defaults are not stored.');
        $this->assertSame(['dx' => 20.0, 'size' => 0.9], $doc['layout']['photo']);
        $this->assertSame(['1' => ['hidden' => true], '0' => ['dy' => 30.0, 'size' => 1.2]], $doc['decor']['items']);
        $this->assertSame(['circle', 'thick'], [$doc['photo']['shape'], $doc['photo']['border']]);

        foreach ([
            ['layout' => ['blocks' => ['qr' => ['dx' => 10]]]], ['layout' => ['blocks' => ['guest' => ['dy' => 10]]]],
            ['layout' => ['blocks' => ['names' => ['dx' => 5000]]]], ['layout' => ['blocks' => ['names' => ['size' => 4]]]],
            ['layout' => ['blocks' => ['names' => ['hidden' => 'yes']]]], ['layout' => ['blocks' => ['names' => ['x' => 1]]]],
            ['layout' => ['photo' => ['hidden' => true]]], ['decor' => ['items' => ['12' => ['hidden' => true]]]],
            ['decor' => ['items' => ['a' => ['hidden' => true]]]], ['photo' => ['shape' => 'heart']], ['photo' => ['border' => 'neon']],
        ] as $bad) {
            [, $errors] = InvitationDesign::validate($bad, $defaults);
            $this->assertNotSame([], $errors, 'Accepted: '.json_encode($bad));
        }

        // A submitted layout replaces the old one, so "reset" really removes overrides.
        [$reset] = InvitationDesign::validate(['layout' => ['blocks' => []], 'decor' => ['items' => []]], $doc);
        $this->assertSame([], $reset['layout']['blocks']);
        $this->assertSame([], $reset['decor']['items']);
    }

    public function test_dragging_moves_only_the_dragged_element(): void
    {
        [$plain] = $this->render('floral_classic', []);
        $before = $plain->layoutMap();
        [$moved, $image] = $this->render('floral_classic', ['layout' => ['blocks' => ['names' => ['dx' => -120, 'dy' => 60]]]]);
        $after = $moved->layoutMap();

        $this->assertEqualsWithDelta($before['names'][0] - 120, $after['names'][0], 0.5);
        $this->assertEqualsWithDelta($before['names'][1] + 60, $after['names'][1], 0.5);
        [$far] = $this->render('floral_classic', ['layout' => ['blocks' => ['names' => ['dx' => -900]]]]);
        $this->assertEqualsWithDelta(20, $far->layoutMap()['names'][0], 0.01, 'Clamped to the 20-unit card margin.');
        foreach (['label', 'date', 'venue', 'qr', 'guest'] as $key) {
            $this->assertSame($before[$key], $after[$key], "$key must not move.");
        }
        $this->assertNotSame($this->hash($plain->render(InvitationDesign::resolve(InvitationDesign::defaults('floral_classic', 'wedding'), $this->event())['spec'], 600)), $this->hash($image));
    }

    public function test_elements_dragged_onto_the_qr_are_lifted_and_the_qr_stays_exact(): void
    {
        $guest = ['name' => 'John Komba', 'pass_line' => 'Kadi ya watu 2', 'manual_code' => '0987', 'qr_payload' => self::PAYLOAD];
        [$renderer, $image, $spec] = $this->render('floral_classic', [
            'layout' => ['blocks' => ['names' => ['dy' => 900, 'size' => 1.5], 'date' => ['dy' => 700]]],
            'decor' => ['items' => ['1' => ['dx' => -400, 'dy' => -150, 'size' => 1.6]]],
        ], $guest, 1500);

        $this->assertContains('moved_clear_of_qr', $renderer->warnings());
        $map = $renderer->layoutMap();
        foreach (['names', 'date'] as $key) {
            [$x, $y, $w, $h] = $map[$key];
            foreach (['qr', 'guest'] as $protected) {
                [$px, $py, $pw, $ph] = $map[$protected];
                $this->assertFalse($x < $px + $pw && $x + $w > $px && $y < $py + $ph && $y + $h > $py, "$key overlaps $protected");
            }
        }
        // Artwork may pass behind the tile, but the QR itself is drawn last and stays exact.
        $box = $renderer->qrBox($spec, 1500, self::PAYLOAD);
        $matrix = Encoder::encode(self::PAYLOAD, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        for ($row = -4; $row < $box['modules'] + 4; $row++) {
            for ($col = -4; $col < $box['modules'] + 4; $col++) {
                $rgb = imagecolorat($image, $box['offset_x'] + $col * $box['module'] + intdiv($box['module'], 2), $box['offset_y'] + $row * $box['module'] + intdiv($box['module'], 2));
                $dark = (($rgb >> 16 & 255) + ($rgb >> 8 & 255) + ($rgb & 255)) / 3 < 128;
                $expected = $row >= 0 && $col >= 0 && $row < $box['modules'] && $col < $box['modules'] && $matrix->get($col, $row) === 1;
                $this->assertSame($expected, $dark, "QR module ($col,$row)");
            }
        }
    }

    public function test_per_element_font_size_and_hiding(): void
    {
        [$plain] = $this->render('floral_classic', []);
        [$bigger] = $this->render('floral_classic', ['layout' => ['blocks' => ['names' => ['size' => 1.4], 'label' => ['size' => 0.7]]]]);
        $this->assertGreaterThan($plain->layoutMap()['names'][3] * 1.2, $bigger->layoutMap()['names'][3]);
        $this->assertLessThan($plain->layoutMap()['label'][3], $bigger->layoutMap()['label'][3]);
        $this->assertEqualsWithDelta($plain->layoutMap()['date'][3], $bigger->layoutMap()['date'][3], 0.01, 'Other blocks keep their size.');

        [$hidden] = $this->render('floral_classic', ['layout' => ['blocks' => ['intro' => ['hidden' => true], 'motif' => ['hidden' => true], 'divider' => ['hidden' => true]]]]);
        foreach (['intro', 'motif', 'divider'] as $key) {
            $this->assertArrayHasKey($key, $plain->layoutMap());
            $this->assertArrayNotHasKey($key, $hidden->layoutMap());
        }
        $this->assertArrayHasKey('names', $hidden->layoutMap());
    }

    public function test_individual_decorations_hide_move_and_resize(): void
    {
        [$plain] = $this->render('floral_classic', []);
        $this->assertArrayHasKey('decor.0', $plain->layoutMap());
        $this->assertArrayHasKey('decor.1', $plain->layoutMap());

        [$changed] = $this->render('floral_classic', ['decor' => ['items' => ['0' => ['hidden' => true], '1' => ['size' => 1.5, 'dx' => -100]]]]);
        $this->assertArrayNotHasKey('decor.0', $changed->layoutMap());
        $this->assertEqualsWithDelta($plain->layoutMap()['decor.1'][2] * 1.5, $changed->layoutMap()['decor.1'][2], 0.5);

        [$vector] = $this->render('eucalyptus', ['decor' => ['items' => ['2' => ['hidden' => true]]]]);
        $this->assertSame(['decor.0', 'decor.1', 'decor.3'], array_values(array_filter(array_keys($vector->layoutMap()), fn ($k) => str_starts_with($k, 'decor.'))));
    }

    public function test_photo_frame_shapes_borders_and_photo_arrangement(): void
    {
        $hashes = [];
        foreach (['arch', 'circle', 'oval', 'rounded', 'square'] as $shape) {
            [$renderer, $image] = $this->render('photo_elegant', ['photo' => ['shape' => $shape]]);
            [, , $w, $h] = $renderer->layoutMap()['photo'];
            if (in_array($shape, ['circle', 'square'], true)) {
                $this->assertEqualsWithDelta($w, $h, 0.01, "$shape frames are square");
            }
            $hashes[$shape] = $this->hash($image);
        }
        $this->assertCount(5, array_unique($hashes));
        $this->assertNotContains('band', InvitationDesign::photoShapes(InvitationTemplateCatalog::get('photo_elegant')));
        $this->assertContains('band', InvitationDesign::photoShapes(InvitationTemplateCatalog::get('photo_band_modern')));
        $this->assertSame([], InvitationDesign::photoShapes(InvitationTemplateCatalog::get('floral_classic')));

        $borders = [];
        foreach (['none', 'line', 'double', 'thick'] as $border) {
            $borders[] = $this->hash($this->render('photo_elegant', ['photo' => ['border' => $border]])[1]);
        }
        $this->assertCount(4, array_unique($borders));

        [$plain] = $this->render('photo_elegant', []);
        [$moved] = $this->render('photo_elegant', ['layout' => ['photo' => ['dx' => 120, 'dy' => -30, 'size' => 0.8]]]);
        [$x, $y, $w] = $plain->layoutMap()['photo'];
        [$mx, $my, $mw] = $moved->layoutMap()['photo'];
        $this->assertEqualsWithDelta($w * 0.8, $mw, 0.01);
        $this->assertEqualsWithDelta($x + $w * 0.1 + 120, $mx, 0.5);
        $this->assertEqualsWithDelta(max(20, $y + ($plain->layoutMap()['photo'][3] * 0.1) - 30), $my, 0.5);
    }

    public function test_crop_focal_point_changes_the_rendered_photo(): void
    {
        Storage::fake('invitations');
        $f = $this->invitationFixture();
        $image = imagecreatetruecolor(1600, 900);
        imagefilledrectangle($image, 0, 0, 799, 899, imagecolorallocate($image, 200, 30, 30));
        imagefilledrectangle($image, 800, 0, 1599, 899, imagecolorallocate($image, 30, 30, 200));
        ob_start();
        imagejpeg($image, null, 95);
        $id = $this->actingAs($f['owner'])->post(route('customer.events.invitation.photo.store', ['event' => 101]),
            ['photo' => UploadedFile::fake()->createWithContent('wide.jpg', (string) ob_get_clean())], ['Accept' => 'application/json'])->json('id');

        $sample = function (float $x) use ($f, $id): array {
            $response = $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.preview', ['event' => 101]), ['document' => [
                'template' => 'photo_elegant', 'photo' => ['id' => $id, 'x' => $x, 'zoom' => 1, 'shape' => 'square'],
            ]])->assertOk();
            $layout = json_decode(base64_decode((string) $response->headers->get('X-Invitation-Layout')), true);
            [$px, $py, $pw, $ph] = $layout['photo'];
            $png = imagecreatefromstring($response->getContent());
            $rgb = imagecolorat($png, (int) (($px + $pw / 2) / 2), (int) (($py + $ph / 2) / 2));

            return [$rgb >> 16 & 255, $rgb & 255];
        };
        [$redLeft, $blueLeft] = $sample(0.0);
        [$redRight, $blueRight] = $sample(1.0);
        $this->assertGreaterThan($blueLeft, $redLeft, 'Focal point at the left shows the red half.');
        $this->assertGreaterThan($redRight, $blueRight, 'Focal point at the right shows the blue half.');
    }

    public function test_editor_offers_the_arrangement_tools_and_saves_them(): void
    {
        $f = $this->invitationFixture();
        $page = $this->actingAs($f['owner'])->get(route('customer.events.invitation', ['event' => 101]));
        $page->assertOk()->assertSee('Mpangilio wa Vipengele')->assertSee('Hamisha vipengele kwenye kadi')->assertSee('Vipande vya mapambo');
        $labels = array_column($page->viewData('elements'), 'label', 'key');
        $this->assertSame('Majina', $labels['names']);
        $this->assertSame('Pambo 1 · juu kushoto', $labels['decor.0']);
        $this->assertArrayNotHasKey('qr', $labels, 'The QR is never a movable element.');

        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.save', ['event' => 101]), ['document' => [
            'template' => 'photo_elegant', 'layout' => ['blocks' => ['names' => ['dy' => 30, 'size' => 1.2]], 'photo' => ['dx' => 10]],
            'decor' => ['items' => ['0' => ['hidden' => true]]], 'photo' => ['shape' => 'oval', 'border' => 'line'],
        ]])->assertOk();
        $photoPage = $this->actingAs($f['owner'])->get(route('customer.events.invitation', ['event' => 101]));
        $photoPage->assertSee('Umbo la fremu ya picha')->assertSee('Kipanga picha')->assertSee('Yai');
        $doc = $photoPage->viewData('design')['doc'];
        $this->assertSame(['dy' => 30.0, 'size' => 1.2], $doc['layout']['blocks']['names']);
        $this->assertSame(['0' => ['hidden' => true]], $doc['decor']['items']);
        $this->assertSame('oval', $doc['photo']['shape']);

        $layout = json_decode(base64_decode((string) $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.preview', ['event' => 101]), ['document' => $doc])
            ->assertOk()->headers->get('X-Invitation-Layout')), true);
        $this->assertSame('protected', $layout['qr'][4]);
        $this->assertSame('text', $layout['names'][4]);
        $this->assertSame('photo', $layout['photo'][4]);
        $this->assertArrayNotHasKey('decor.0', $layout);
    }
}
