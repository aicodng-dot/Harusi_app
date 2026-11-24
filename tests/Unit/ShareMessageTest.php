<?php

namespace Tests\Unit;

use App\Services\Sharing\ShareMessage;
use PHPUnit\Framework\TestCase;

class ShareMessageTest extends TestCase
{
    private const VALUES = [
        'guest_name' => 'John Michael', 'event_name' => 'Neema & Baraka', 'event_date' => '20 Desemba 2026',
        'event_time' => '', 'venue' => 'Dar es Salaam', 'invitation_url' => 'https://example.test/i/abc',
    ];

    public function test_placeholders_are_replaced_and_empty_label_lines_dropped(): void
    {
        $body = "Hello {{guest_name}}!\nDate: {{event_date}}\nTime: {{event_time}}\nVenue: {{ venue }}\n{{invitation_url}} {{unknown}}";
        $this->assertSame("Hello John Michael!\nDate: 20 Desemba 2026\nVenue: Dar es Salaam\nhttps://example.test/i/abc {{unknown}}", ShareMessage::render($body, self::VALUES));
        // A value can never inject new lines into the message.
        $this->assertSame('Hi A B', ShareMessage::render('Hi {{guest_name}}', ['guest_name' => "A\nB"]));
    }

    public function test_problems_are_reported(): void
    {
        $this->assertSame([], ShareMessage::problems('Hi {{guest_name}}: {{invitation_url}}'));
        $this->assertSame(['empty'], ShareMessage::problems('   '));
        $this->assertContains('missing_url', ShareMessage::problems('Hi {{guest_name}}'));
        $this->assertContains('unknown_placeholder', ShareMessage::problems('{{phone}} {{invitation_url}}'));
        $this->assertContains('broken_placeholder', ShareMessage::problems('{{guest_name} {{invitation_url}}'));
        $this->assertContains('too_long', ShareMessage::problems(str_repeat('a', 1001).'{{invitation_url}}'));
    }

    public function test_clean_removes_html_and_control_characters(): void
    {
        $this->assertSame("Hello alert(1)\n\nBye", ShareMessage::clean("Hello <script>alert(1)</script>\r\n\r\n\r\n\x07Bye  "));
    }

    public function test_phone_numbers_are_normalised_for_links(): void
    {
        $this->assertSame('255712345678', ShareMessage::phoneDigits('+255 712 345 678'));
        $this->assertSame('255712345678', ShareMessage::phoneDigits('0712345678'));
        $this->assertSame('255712345678', ShareMessage::phoneDigits('00255712345678'));
        $this->assertSame('', ShareMessage::phoneDigits(''));
        $this->assertSame('', ShareMessage::phoneDigits('12345'));
        $this->assertSame('', ShareMessage::phoneDigits('+0712'));
    }

    public function test_links_are_properly_encoded(): void
    {
        $text = "Hi John & Co!\nLink: https://x.test/i/a?b=1#c 🎉";
        $this->assertSame('https://wa.me/255712345678?text=Hi%20John%20%26%20Co%21%0ALink%3A%20https%3A%2F%2Fx.test%2Fi%2Fa%3Fb%3D1%23c%20%F0%9F%8E%89',
            ShareMessage::whatsappUrl('255712345678', $text));
        $this->assertStringStartsWith('https://wa.me/?text=Hi', ShareMessage::whatsappUrl('', 'Hi'));
        $this->assertSame('sms:+255712345678?&body=Hi%20%26%20bye', ShareMessage::smsUrl('255712345678', 'Hi & bye'));
        $this->assertSame('sms:?&body=Hi', ShareMessage::smsUrl('', 'Hi'));
    }

    public function test_sms_segments_follow_gsm_and_unicode_limits(): void
    {
        $this->assertSame(['characters' => 160, 'segments' => 1, 'unicode' => false], ShareMessage::segments(str_repeat('a', 160)));
        $this->assertSame(2, ShareMessage::segments(str_repeat('a', 161))['segments']);
        $this->assertSame(2, ShareMessage::segments(str_repeat('€', 81))['segments'], 'extension characters count twice');
        $this->assertSame(['characters' => 70, 'segments' => 1, 'unicode' => true], ShareMessage::segments(str_repeat('ş', 70)));
        $this->assertSame(2, ShareMessage::segments(str_repeat('ş', 71))['segments']);
        $this->assertSame(0, ShareMessage::segments('')['segments']);
    }
}
