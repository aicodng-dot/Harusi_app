<?php

namespace App\Services\Events;

use App\Models\Guest;

/**
 * Deterministic parsing of guest lists typed or pasted on the Add Guests page.
 * Plain string rules only: no AI, no external services.
 *
 * Names mode ("Paste a list of names"):
 *   - one guest per line; a single line may also be split on commas or semicolons;
 *   - list markers such as "1.", "2)", "-", "*" and bullets are removed;
 *   - a phone number written on the same line is picked out;
 *   - optional: "each word is a separate guest" for lists typed on one line with spaces.
 *
 * Structured mode ("Paste guest details"), one guest per line, for example
 *   John Michael, 0712345678, VIP, 2
 * Cells are separated by a tab, "|", ";" or "," (the first of these found on the line).
 * Every cell is classified by its content, so the columns may come in any order:
 *   - a phone number (Tanzanian or international digits, 9-15 digits)  -> phone_number
 *   - a whole number 1..100                                          -> allowed_entries
 *   - a known pass word (single, double, special, family, vip, ...)  -> pass_type
 *   - anything else                                                  -> name
 * A first line that is a header (name / jina / phone / ...) is skipped.
 *
 * Defaults (documented on the page): no phone = guest without phone; no pass and no
 * number = Single (1 person); the number of people decides the pass when the pass word is
 * missing or is a label such as VIP / Regular: 1 = Single, 2 = Double, 3+ = Special.
 */
class GuestTextParser
{
    public const MAX_ROWS = CustomerGuests::IMPORT_MAX_ROWS;

    public const MAX_TEXT = 100_000;

    /** Words that name a real pass type. */
    private const PASSES = [
        'single' => Guest::PASS_SINGLE, 'mmoja' => Guest::PASS_SINGLE, 's' => Guest::PASS_SINGLE,
        'double' => Guest::PASS_DOUBLE, 'wawili' => Guest::PASS_DOUBLE, 'couple' => Guest::PASS_DOUBLE, 'wanandoa' => Guest::PASS_DOUBLE, 'd' => Guest::PASS_DOUBLE,
        'special' => Guest::PASS_SPECIAL, 'family' => Guest::PASS_SPECIAL, 'familia' => Guest::PASS_SPECIAL, 'maalum' => Guest::PASS_SPECIAL,
        'special/family' => Guest::PASS_SPECIAL, 'special / family' => Guest::PASS_SPECIAL, 'group' => Guest::PASS_SPECIAL, 'kikundi' => Guest::PASS_SPECIAL,
    ];

    /** Labels people commonly write that are not pass types: the number of people decides. */
    private const LABELS = ['vip', 'v.i.p', 'regular', 'normal', 'kawaida', 'guest', 'mgeni', 'general', 'standard', 'ordinary'];

    private const HEADER_WORDS = ['name', 'names', 'jina', 'majina', 'guest', 'guest name', 'full name', 'mgeni', 'phone', 'phone number', 'phone_number', 'simu', 'pass', 'pass type', 'pass_type'];

    /**
     * @return array{rows: list<array{name: string, phone_number: string, pass_type: string, allowed_entries: string}>, truncated: bool}
     */
    public static function names(string $text, bool $splitWords = false): array
    {
        $rows = [];
        foreach (self::lines($text) as $line) {
            $items = preg_split('/\s*[,;]\s*/u', $line) ?: [];
            foreach ($items as $item) {
                [$phone, $rest] = self::extractPhone($item);
                $rest = self::stripMarker($rest);
                if ($rest === '' && $phone === '') {
                    continue;
                }
                if ($rest !== '' && $phone === '' && preg_match('/\p{L}/u', $rest) !== 1 && preg_match('/\d/', $rest) === 1) {
                    // "Zawadi, 0712": digits without letters are a (possibly incomplete) phone, not a name;
                    // validation then reports the phone so the owner can complete it.
                    [$phone, $rest] = [$rest, ''];
                }
                if ($rest === '') {
                    // "John, 0712345678": a phone on its own belongs to the name before it.
                    $last = array_key_last($rows);
                    if ($last !== null && $rows[$last]['phone_number'] === '') {
                        $rows[$last]['phone_number'] = $phone;
                    }

                    continue;
                }
                $parts = $splitWords && $phone === '' ? (preg_split('/\s+/u', $rest) ?: []) : [$rest];
                foreach ($parts as $part) {
                    if ($part === '' || self::isHeader($part)) {
                        continue;
                    }
                    $rows[] = ['name' => $part, 'phone_number' => $phone, 'pass_type' => '', 'allowed_entries' => ''];
                }
            }
        }

        return self::limit($rows);
    }

    /**
     * @return array{rows: list<array{name: string, phone_number: string, pass_type: string, allowed_entries: string}>, truncated: bool}
     */
    public static function structured(string $text): array
    {
        $lines = self::lines($text);
        $rows = [];
        foreach ($lines as $number => $line) {
            // Each line uses its own separator, so a list mixing pasted spreadsheet rows (tabs) and typed rows (commas) works.
            $delimiter = self::delimiter([$line]);
            $cells = $delimiter === null ? [$line] : array_map('trim', explode($delimiter, $line));
            if ($number === 0 && self::looksLikeHeader($cells)) {
                continue;
            }
            $row = ['name' => '', 'phone_number' => '', 'pass_type' => '', 'allowed_entries' => ''];
            $names = [];
            foreach ($cells as $cell) {
                $cell = trim($cell, " \t\"'");
                if ($cell === '') {
                    continue;
                }
                $lower = mb_strtolower($cell);
                if ($row['phone_number'] === '' && self::isPhone($cell)) {
                    $row['phone_number'] = $cell;
                } elseif ($row['allowed_entries'] === '' && preg_match('/\A\d{1,3}\z/', $cell) === 1 && (int) $cell >= 1 && (int) $cell <= Guest::MAX_ALLOWED_ENTRIES) {
                    $row['allowed_entries'] = (string) (int) $cell;
                } elseif ($row['pass_type'] === '' && (isset(self::PASSES[$lower]) || in_array($lower, self::LABELS, true))) {
                    $row['pass_type'] = $cell;
                } else {
                    $names[] = $cell;
                }
            }
            if ($delimiter === null && $names !== []) {
                // A line without separators: still pick a phone number out of it.
                [$phone, $rest] = self::extractPhone($names[0]);
                if ($phone !== '' && $row['phone_number'] === '') {
                    $row['phone_number'] = $phone;
                    $names[0] = $rest;
                }
            }
            $row['name'] = self::stripMarker(implode(' ', array_filter($names, fn ($n) => trim($n) !== '')));
            if (implode('', $row) === '') {
                continue;
            }
            $rows[] = $row;
        }

        return self::limit($rows);
    }

    /**
     * Turns one entered row into the canonical input of CustomerGuests::validate():
     * the pass word becomes single/double/special, labels such as VIP and a blank pass
     * take their pass from the number of people, and Single/Double get their fixed count.
     *
     * @param  array<string, mixed>  $row
     * @return array{name: string, phone_number: string, pass_type: string, allowed_entries: string}
     */
    public static function normalise(array $row): array
    {
        $text = fn (string $key, int $max) => is_scalar($row[$key] ?? null) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $row[$key])), 0, $max) : '';
        $name = $text('name', 200);
        $phone = $text('phone_number', 40);
        $passWord = mb_strtolower($text('pass_type', 40));
        $count = $text('allowed_entries', 10);
        $pass = self::PASSES[$passWord] ?? null;
        if ($pass === null) {
            // Blank, a label (VIP, Regular) or an unknown word: the count decides.
            $pass = $passWord === '' || in_array($passWord, self::LABELS, true) ? self::passForCount($count) : $passWord;
        } elseif ($pass !== Guest::PASS_SPECIAL && $count !== '' && ctype_digit($count) && (int) $count !== ($pass === Guest::PASS_SINGLE ? 1 : 2)) {
            // "Single, 3": the number of people written wins over the word.
            $pass = self::passForCount($count);
        }
        if ($pass === Guest::PASS_SINGLE) {
            $count = '1';
        } elseif ($pass === Guest::PASS_DOUBLE) {
            $count = '2';
        }

        return ['name' => $name, 'phone_number' => $phone, 'pass_type' => $pass, 'allowed_entries' => $count];
    }

    /** blank or 1 = single, 2 = double, 3+ (or anything else) = special, validated later. */
    public static function passForCount(string $count): string
    {
        if ($count === '' || $count === '1') {
            return Guest::PASS_SINGLE;
        }

        return $count === '2' ? Guest::PASS_DOUBLE : Guest::PASS_SPECIAL;
    }

    public static function isPhone(string $value): bool
    {
        $compact = preg_replace('/[\s\-().]/', '', $value) ?? '';

        return preg_match('/\A\+?\d{9,15}\z/', $compact) === 1;
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        $text = mb_substr(str_replace("\u{00A0}", ' ', $text), 0, self::MAX_TEXT);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn (string $l) => $l !== ''));
    }

    /** @param list<string> $lines */
    private static function delimiter(array $lines): ?string
    {
        $sample = implode("\n", array_slice($lines, 0, 50));
        foreach (["\t", '|', ';', ','] as $candidate) {
            if (str_contains($sample, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string} phone and the remaining text */
    private static function extractPhone(string $text): array
    {
        if (preg_match('/(\+?\d[\d\s\-().]{7,18}\d)/', $text, $match) === 1 && self::isPhone($match[1])) {
            return [trim($match[1]), trim(str_replace($match[1], ' ', $text), " \t-:")];
        }

        return ['', trim($text)];
    }

    private static function stripMarker(string $text): string
    {
        $text = preg_replace('/\A(?:\d{1,4}\s*[.)\-:]\s*|[-*•·–—>]+\s*)/u', '', trim($text)) ?? $text;

        return trim((string) preg_replace('/\s+/u', ' ', $text), " \t\"'");
    }

    private static function isHeader(string $text): bool
    {
        return in_array(mb_strtolower(trim($text)), self::HEADER_WORDS, true);
    }

    /** @param list<string> $cells */
    private static function looksLikeHeader(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (self::isHeader(str_replace('_', ' ', $cell)) || self::isHeader($cell)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{name: string, phone_number: string, pass_type: string, allowed_entries: string}>  $rows
     * @return array{rows: list<array{name: string, phone_number: string, pass_type: string, allowed_entries: string}>, truncated: bool}
     */
    private static function limit(array $rows): array
    {
        return ['rows' => array_slice($rows, 0, self::MAX_ROWS), 'truncated' => count($rows) > self::MAX_ROWS];
    }
}
