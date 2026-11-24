<?php

namespace App\Services\Sharing;

use App\Services\Invitations\InvitationWording;
use Illuminate\Support\Carbon;

/**
 * Plain-text invitation messages for manual sharing (WhatsApp click-to-chat, SMS links and
 * the phone's share sheet). Nothing here sends anything: it only prepares text and links
 * that the customer opens and sends themselves.
 *
 *  - Placeholders are a fixed list; anything else in {{ }} is rejected.
 *  - Text is cleaned to plain text (tags and control characters removed) and always shown
 *    escaped; links are built with rawurlencode, so message text cannot change the URL.
 *  - The same rules are mirrored in resources/js/reference_ui/customer_share.js for the
 *    live preview; the server re-validates everything it stores.
 */
class ShareMessage
{
    public const PLACEHOLDERS = ['guest_name', 'event_name', 'event_date', 'event_time', 'venue', 'invitation_url'];

    public const MAX_LENGTH = 1000;

    public const CHANNELS = ['whatsapp', 'sms', 'share'];

    /** GSM 03.38 basic set plus the extension table (extension characters count twice). */
    private const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENDED = '^{}\\[~]|€';

    /**
     * Built-in templates, in the customer's language.
     *
     * @return array<string, array{name: string, body: string}>
     */
    public static function defaults(string $language): array
    {
        $keys = ['whatsapp', 'sms', 'short'];
        $out = [];
        foreach ($keys as $key) {
            $out['default_'.$key] = [
                'name' => __('customer_share.defaults.'.$key.'.name', [], $language),
                'body' => __('customer_share.defaults.'.$key.'.body', [], $language),
            ];
        }

        return $out;
    }

    /** Plain text only: no tags, no control characters other than new lines, tidy line ends. */
    public static function clean(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = strip_tags($body);
        $body = (string) preg_replace('/[^\P{C}\n]+/u', '', $body);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $body));
    }

    /** @return list<string> translation keys of problems; empty when the text can be used */
    public static function problems(string $body): array
    {
        $problems = [];
        if (trim($body) === '') {
            return ['empty'];
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            $problems[] = 'too_long';
        }
        preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/u', $body, $matches);
        foreach ($matches[1] as $name) {
            if (! in_array($name, self::PLACEHOLDERS, true)) {
                $problems[] = 'unknown_placeholder';
                break;
            }
        }
        // Unbalanced braces usually mean a mistyped placeholder.
        $stripped = (string) preg_replace('/\{\{\s*[a-z_]+\s*\}\}/', '', $body);
        if (str_contains($stripped, '{{') || str_contains($stripped, '}}')) {
            $problems[] = 'broken_placeholder';
        }
        if (preg_match('/\{\{\s*invitation_url\s*\}\}/', $body) !== 1) {
            $problems[] = 'missing_url';
        }

        return array_values(array_unique($problems));
    }

    /**
     * Event values shared by every guest of the event.
     *
     * @return array{event_name: string, event_date: string, event_time: string, venue: string}
     */
    public static function eventValues(object $event, string $language): array
    {
        $date = '';
        if (! empty($event->event_date)) {
            $date = InvitationWording::dateParts(Carbon::parse($event->event_date), $language)['date_short'];
        }
        $time = empty($event->event_time) ? '' : (string) InvitationWording::time(substr((string) $event->event_time, 0, 5), $language);

        return [
            'event_name' => trim((string) $event->event_name),
            'event_date' => $date,
            'event_time' => $time,
            'venue' => trim((string) $event->venue_name),
        ];
    }

    /** Replaces the known placeholders; values are plain text. */
    public static function render(string $body, array $values): string
    {
        $lines = [];
        foreach (explode("\n", $body) as $line) {
            $empty = false;
            $out = (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $m) use ($values, &$empty): string {
                if (! in_array($m[1], self::PLACEHOLDERS, true)) {
                    return $m[0];
                }
                $value = trim(str_replace(["\r", "\n"], ' ', (string) ($values[$m[1]] ?? '')));
                $empty = $empty || $value === '';

                return $value;
            }, $line);
            // "Time: {{event_time}}" with no time set: drop the whole label line.
            if ($empty && preg_match('/:\s*\z/u', $out) === 1) {
                continue;
            }
            $lines[] = $out;
        }

        return implode("\n", $lines);
    }

    /** Digits of a phone number in international form, or '' when it cannot be dialled. */
    public static function phoneDigits(?string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/\A0[67]\d{8}\z/', $digits) === 1) {
            // A Tanzanian local number (07…/06…) written without the country code.
            $digits = '255'.substr($digits, 1);
        }

        return preg_match('/\A[1-9]\d{8,14}\z/', $digits) === 1 ? $digits : '';
    }

    /** WhatsApp click-to-chat link; without a number WhatsApp asks the customer to pick a chat. */
    public static function whatsappUrl(string $digits, string $text): string
    {
        return 'https://wa.me/'.$digits.'?text='.rawurlencode($text);
    }

    /** Native SMS composer link. "?&body=" is understood by both Android and iOS messaging apps. */
    public static function smsUrl(string $digits, string $text): string
    {
        return 'sms:'.($digits === '' ? '' : '+'.$digits).'?&body='.rawurlencode($text);
    }

    /**
     * SMS length estimate: GSM-7 (160 / 153 per part) or Unicode (70 / 67 per part).
     *
     * @return array{characters: int, segments: int, unicode: bool}
     */
    public static function segments(string $text): array
    {
        $units = 0;
        $unicode = false;
        foreach (mb_str_split($text) as $char) {
            if (str_contains(self::GSM_EXTENDED, $char)) {
                $units += 2;
            } elseif (str_contains(self::GSM, $char)) {
                $units++;
            } else {
                $unicode = true;
                break;
            }
        }
        if ($unicode) {
            $units = count(preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY)); // UTF-16 code units approx.
            foreach (mb_str_split($text) as $char) {
                if (strlen($char) === 4) {
                    $units++; // characters outside the BMP (emoji) take two UTF-16 units
                }
            }
            $segments = $units <= 70 ? 1 : (int) ceil($units / 67);
        } else {
            $segments = $units <= 160 ? 1 : (int) ceil($units / 153);
        }

        return ['characters' => mb_strlen($text), 'segments' => $text === '' ? 0 : $segments, 'unicode' => $unicode];
    }
}
