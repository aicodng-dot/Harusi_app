<?php

namespace Tests\Feature\Invitations;

use App\Services\Invitations\InvitationDesign;
use App\Services\Invitations\InvitationWording;
use App\Services\Invitations\Render\InvitationRenderer;
use Tests\TestCase;

/** Design document validation, event resolution, wording and rendering rules. */
class InvitationDesignTest extends TestCase
{
    private function event(array $overrides = []): object
    {
        return (object) array_merge(['event_name' => 'Neema & Baraka', 'bride_name' => null, 'groom_name' => null, 'venue_name' => 'Grand Hall, Dar es Salaam',
            'event_date' => '2026-12-14', 'event_time' => '18:00:00', 'event_type' => 'wedding'], $overrides);
    }

    private function png(array $spec, ?array $guest = null, int $width = 300): string
    {
        ob_start();
        imagepng((new InvitationRenderer)->render($spec, $width, $guest));

        return (string) ob_get_clean();
    }

    public function test_validation_accepts_allowed_values_and_rejects_everything_else(): void
    {
        $defaults = InvitationDesign::defaults('floral_classic', 'wedding');
        [$doc, $errors] = InvitationDesign::validate([
            'template' => 'royal_green', 'colors' => ['bg' => '#ABCDEF', 'nope' => '#000000'], 'fonts' => ['heading' => 'cinzel'],
            'font_scale' => 1.1, 'decor' => ['art' => false, 'frame' => 'double'], 'qr' => ['position' => 'left', 'size' => 'l'],
            'content' => ['language' => 'en', 'style' => 'warm', 'names' => "  Neema\u{0007} &  Baraka ", 'unknown' => 'x'],
        ], $defaults);

        $this->assertSame([], $errors);
        $this->assertSame('royal_green', $doc['template']);
        $this->assertSame(['bg' => '#abcdef'], $doc['colors']);
        $this->assertSame('cinzel', $doc['fonts']['heading']);
        $this->assertFalse($doc['decor']['art']);
        $this->assertSame('Neema & Baraka', $doc['content']['names']);
        $this->assertArrayNotHasKey('unknown', $doc['content']);

        foreach ([
            ['template' => 'gold'], ['colors' => ['bg' => 'red']], ['colors' => ['bg' => '#12345']], ['fonts' => ['heading' => 'Arial']],
            ['font_scale' => 3], ['font_scale' => 'NaN'], ['decor' => ['frame' => '../../x']], ['qr' => ['position' => 'top']], ['qr' => ['size' => 'xl']],
            ['qr' => ['token' => 'abc']], ['qr' => ['payload' => 'https://evil.test']], ['content' => ['language' => 'fr']],
            ['content' => ['names' => str_repeat('a', 121)]], ['content' => ['intro' => ['array']]], ['photo' => ['id' => '../../etc/passwd']],
            ['photo' => ['zoom' => 9]],
        ] as $bad) {
            [, $errors] = InvitationDesign::validate($bad, $defaults);
            $this->assertNotSame([], $errors, 'Accepted invalid input: '.json_encode($bad));
        }
    }

    public function test_resolution_uses_authoritative_event_facts_and_reports_missing_details(): void
    {
        $doc = InvitationDesign::defaults('floral_classic', 'wedding');
        ['spec' => $spec, 'warnings' => $warnings] = InvitationDesign::resolve($doc, $this->event());
        $this->assertSame([], $warnings);
        $this->assertSame('Neema & Baraka', $spec['text']['names']);
        $this->assertSame('Grand Hall, Dar es Salaam', $spec['text']['venue']);
        $this->assertSame(['Jumatatu', '14', 'Desemba', '2026'], [$spec['text']['weekday'], $spec['text']['day'], $spec['text']['month'], $spec['text']['year']]);
        $this->assertSame('Saa 12:00 Jioni', $spec['text']['time']);
        $this->assertSame('Mwaliko wa Harusi', $spec['text']['label']);

        ['spec' => $spec, 'warnings' => $warnings] = InvitationDesign::resolve($doc, $this->event(['event_date' => null, 'event_time' => null, 'venue_name' => null]));
        $this->assertEqualsCanonicalizing(['missing_date', 'missing_time', 'missing_venue'], $warnings);
        $this->assertSame('', $spec['text']['day'], 'Missing dates are never invented.');

        // Invitation-only wording overrides the card, not the event.
        $doc['content']['venue'] = 'Ukumbi wa Mlimani';
        $this->assertSame('Ukumbi wa Mlimani', InvitationDesign::resolve($doc, $this->event())['spec']['text']['venue']);
        $this->assertSame('Bi Neema & Bw Baraka', InvitationDesign::resolve($doc, $this->event(['bride_name' => 'Bi Neema', 'groom_name' => 'Bw Baraka']))['spec']['text']['names']);
    }

    public function test_family_hosted_wording_in_both_languages_is_independent_of_the_interface(): void
    {
        $doc = InvitationDesign::defaults('floral_classic', 'wedding', 'sw');
        $doc['content'] = array_merge($doc['content'], ['host_mode' => 'both_families', 'host_one' => 'Bw. Abdallah Suleiman', 'host_two' => 'Bi. Fatma Hamisi']);
        $sw = InvitationDesign::resolve($doc, $this->event())['spec']['text'];
        $this->assertSame('Familia ya Bw. Abdallah Suleiman na Familia ya Bi. Fatma Hamisi', $sw['host_line']);
        $this->assertStringStartsWith('Zinayo heshima kubwa kukualika', $sw['intro']);

        $doc['content']['language'] = 'en';
        $doc['content']['style'] = 'warm';
        $en = InvitationDesign::resolve($doc, $this->event())['spec']['text'];
        $this->assertSame('The family of Bw. Abdallah Suleiman and the family of Bi. Fatma Hamisi', $en['host_line']);
        $this->assertSame('Joyfully invite you to celebrate the wedding of their beloved children.', $en['intro']);
        $this->assertSame('Wedding Invitation', $en['label']);
        $this->assertSame('6:00 PM', $en['time']);

        // A layout without a hosts block joins the family into one natural sentence.
        $doc['template'] = 'photo_elegant';
        $doc['content']['host_mode'] = 'bride_family';
        $doc['content']['language'] = 'sw';
        $doc['content']['style'] = 'formal';
        $text = InvitationDesign::resolve($doc, $this->event())['spec']['text'];
        $this->assertSame('Familia ya Bw. Abdallah Suleiman inayo heshima kubwa kukualika katika sherehe ya harusi ya mtoto wao.', $text['intro']);

        // Missing host names fall back to the couple's own wording and warn.
        $doc['content']['host_one'] = null;
        $resolved = InvitationDesign::resolve($doc, $this->event());
        $this->assertContains('hosts_missing', $resolved['warnings']);
        $this->assertSame('Tunayo heshima kubwa kukualika katika sherehe ya ndoa yetu.', $resolved['spec']['text']['intro']);
    }

    public function test_every_tone_has_distinct_wording_for_every_event_type_and_language(): void
    {
        foreach (InvitationWording::LANGUAGES as $language) {
            foreach (['wedding', 'send_off', 'kitchen_party', 'other'] as $type) {
                foreach (InvitationWording::HOST_MODES as $mode) {
                    $intros = array_column(InvitationWording::options($language, $type, $mode), 'intro');
                    $this->assertCount(5, array_unique($intros), "$language/$type/$mode tones must differ.");
                }
            }
        }
        $this->assertSame('Kadi maalum · watu 7', InvitationWording::passLine('sw', 'special', 7));
        $this->assertSame('Admits 2', InvitationWording::passLine('en', 'double', 2));
        $this->assertSame('Saa 2:30 Asubuhi', InvitationWording::time('08:30:00', 'sw'));
    }

    public function test_colour_text_and_font_changes_render_differently_and_reset_restores_the_original(): void
    {
        $event = $this->event();
        $original = InvitationDesign::defaults('floral_classic', 'wedding');
        $base = $this->png(InvitationDesign::resolve($original, $event)['spec']);

        foreach ([['colors' => ['bg' => '#203040']], ['content' => ['names' => 'Amani & Zawadi']], ['fonts' => ['heading' => 'cinzel']], ['font_scale' => 1.15], ['decor' => ['frame' => 'deco']]] as $change) {
            [$doc] = InvitationDesign::validate($change, $original);
            $this->assertNotSame($base, $this->png(InvitationDesign::resolve($doc, $event)['spec']), 'No visible change for '.json_encode($change));
        }
        [$reset] = InvitationDesign::validate([], InvitationDesign::defaults('floral_classic', 'wedding'));
        $this->assertSame($base, $this->png(InvitationDesign::resolve($reset, $event)['spec']));
    }

    public function test_long_text_never_overflows_and_is_reported(): void
    {
        $doc = InvitationDesign::defaults('floral_classic', 'wedding');
        $doc['content']['names'] = str_repeat('Mwanaisha Abdulrahman ', 5).'& '.str_repeat('Barakaeli Mwakalinga ', 4);
        $doc['content']['message'] = str_repeat('Karibu sana tusherehekee pamoja. ', 7);
        $renderer = new InvitationRenderer;
        $renderer->render(InvitationDesign::resolve($doc, $this->event())['spec'], 300);
        $this->assertNotEmpty(array_intersect(['text_shrunk', 'text_truncated', 'text_dropped'], $renderer->warnings()));
    }
}
