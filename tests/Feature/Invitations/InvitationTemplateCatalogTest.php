<?php

namespace Tests\Feature\Invitations;

use App\Services\Invitations\InvitationDesign;
use App\Services\Invitations\InvitationFonts;
use App\Services\Invitations\InvitationTemplateCatalog;
use App\Services\Invitations\Render\InvitationRenderer;
use App\Services\Invitations\Render\Ornaments;
use Tests\TestCase;

/** The published template library: size, identity, coverage and genuine distinctness. */
class InvitationTemplateCatalogTest extends TestCase
{
    private const DIMENSIONS = ['layout', 'palette', 'font_set', 'decor_set', 'frame', 'pattern', 'background', 'motif', 'divider', 'date_style', 'guest_style', 'qr_position', 'names_font'];

    public function test_library_has_one_hundred_templates_with_stable_original_identifiers(): void
    {
        $all = InvitationTemplateCatalog::all();

        $this->assertCount(100, $all);
        foreach (['floral_classic', 'minimal_modern', 'royal_green', 'photo_elegant'] as $original) {
            $this->assertArrayHasKey($original, $all, "Working Flow 14 identifier $original must stay valid.");
        }
        foreach ($all as $key => $template) {
            $this->assertMatchesRegularExpression('/\A[a-z0-9_]{1,40}\z/', $key);
            $this->assertSame($key, $template['key']);
            $this->assertNotSame('', $template['name']);
            $this->assertNotEmpty($template['events']);
            $this->assertArrayHasKey($template['layout'], InvitationTemplateCatalog::LAYOUTS);
            $this->assertContains($template['frame'], Ornaments::FRAMES);
            $this->assertContains($template['pattern'], Ornaments::PATTERNS);
            $this->assertContains($template['motif'], Ornaments::MOTIFS);
            $this->assertContains($template['divider'], Ornaments::DIVIDERS);
            $this->assertContains($template['background'], InvitationDesign::BACKGROUNDS);
            foreach ($template['fonts'] as $font) {
                $this->assertTrue(InvitationFonts::isValid($font));
                $this->assertFileExists(InvitationFonts::path($font));
            }
            foreach ($template['decor'] as $item) {
                if (($item['type'] ?? 'art') === 'art') {
                    $this->assertFileExists(resource_path('invitation/decor/'.$item['asset'].'.png'));
                }
            }
            foreach ($template['colors'] as $color) {
                $this->assertMatchesRegularExpression('/\A#[0-9a-f]{6}\z/', $color);
            }
        }
        $this->assertCount(100, array_unique(array_column($all, 'name')), 'Template names must be unique.');
    }

    public function test_required_categories_and_event_types_are_covered(): void
    {
        $all = InvitationTemplateCatalog::all();
        $categories = array_count_values(array_column($all, 'category'));

        foreach (['floral', 'elegant', 'modern', 'luxury', 'traditional', 'botanical', 'romantic', 'photo', 'editorial', 'faith', 'send_off', 'kitchen_party', 'celebration'] as $category) {
            $this->assertGreaterThanOrEqual(5, $categories[$category] ?? 0, "Category $category needs a real choice of designs.");
        }
        foreach (['wedding' => 40, 'send_off' => 20, 'kitchen_party' => 10, 'celebration' => 10] as $chip => $minimum) {
            $this->assertGreaterThanOrEqual($minimum, count(InvitationTemplateCatalog::filter($chip)));
        }
        $this->assertGreaterThanOrEqual(8, count(array_filter($all, fn ($t) => $t['photo_shape'] !== null)), 'Photo-based designs');
        $this->assertGreaterThanOrEqual(10, count(array_unique(array_column($all, 'layout'))), 'Distinct compositions');
    }

    public function test_every_pair_of_templates_differs_in_several_structural_dimensions(): void
    {
        $all = array_values(InvitationTemplateCatalog::all());
        $minimum = PHP_INT_MAX;
        for ($i = 0; $i < count($all); $i++) {
            for ($j = $i + 1; $j < count($all); $j++) {
                $differences = count(array_filter(self::DIMENSIONS, fn ($d) => $all[$i][$d] !== $all[$j][$d]));
                $minimum = min($minimum, $differences);
                $this->assertGreaterThanOrEqual(4, $differences, "{$all[$i]['key']} and {$all[$j]['key']} are too similar (a recolour is not a new design).");
            }
        }
        $this->assertGreaterThanOrEqual(4, $minimum);
    }

    public function test_every_template_renders_a_distinct_image(): void
    {
        $renderer = new InvitationRenderer;
        $event = (object) ['event_name' => 'Neema & Baraka', 'bride_name' => null, 'groom_name' => null, 'venue_name' => 'Grand Hall',
            'event_date' => '2026-12-14', 'event_time' => '18:00:00', 'event_type' => 'wedding'];
        $hashes = [];
        foreach (InvitationTemplateCatalog::all() as $key => $template) {
            ['spec' => $spec] = InvitationDesign::resolve(InvitationDesign::defaults($key, 'wedding'), $event);
            $image = $renderer->render($spec, 150);
            $this->assertSame(150, imagesx($image));
            $this->assertSame(200, imagesy($image));
            ob_start();
            imagepng($image);
            $hashes[$key] = md5((string) ob_get_clean());
        }

        $this->assertCount(100, array_unique($hashes), 'Two templates rendered identical images.');
    }

    public function test_filters_and_search(): void
    {
        $this->assertSame(['floral'], array_values(array_unique(array_column(InvitationTemplateCatalog::filter('floral'), 'category'))));
        $this->assertNotEmpty(InvitationTemplateCatalog::filter(null, 'Royal'));
        $this->assertSame([], InvitationTemplateCatalog::filter(null, 'no-such-design-xyz'));
        $this->assertNull(InvitationTemplateCatalog::category('gold'));
        $this->assertSame('celebration', InvitationTemplateCatalog::defaultCategory('other'));
    }
}
