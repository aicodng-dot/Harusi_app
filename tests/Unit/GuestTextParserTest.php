<?php

namespace Tests\Unit;

use App\Services\Events\GuestTextParser;
use PHPUnit\Framework\TestCase;

/** Deterministic guest-list parsing used by Add Guests (no AI, plain string rules). */
class GuestTextParserTest extends TestCase
{
    public function test_names_one_per_line_with_markers_and_blank_lines_removed(): void
    {
        $text = "1. John Michael\n2) Mary Joseph\n\n- Peter James\n• Sarah   Daniel\n* Asha";
        $this->assertSame(['John Michael', 'Mary Joseph', 'Peter James', 'Sarah Daniel', 'Asha'], array_column(GuestTextParser::names($text)['rows'], 'name'));
    }

    public function test_names_split_on_commas_and_semicolons_and_pick_out_phones(): void
    {
        $rows = GuestTextParser::names("John Michael, Mary Joseph; Peter\nAsha Mrema 0712 345 678\nJuma, 0755123456")['rows'];
        $this->assertSame(['John Michael', 'Mary Joseph', 'Peter', 'Asha Mrema', 'Juma'], array_column($rows, 'name'));
        $this->assertSame('0712 345 678', $rows[3]['phone_number']);
        $this->assertSame('0755123456', $rows[4]['phone_number'], 'a phone after a comma belongs to the name before it');
        $this->assertSame('', $rows[0]['phone_number']);
    }

    public function test_names_treat_a_bare_number_as_the_previous_guests_phone(): void
    {
        $rows = GuestTextParser::names("Zawadi Kileo, 0712
Rehema")['rows'];
        $this->assertSame([['Zawadi Kileo', '0712'], ['Rehema', '']], array_map(fn ($r) => [$r['name'], $r['phone_number']], $rows));
    }

    public function test_names_split_words_only_when_asked(): void
    {
        $this->assertCount(1, GuestTextParser::names('John Michael Mary Joseph')['rows']);
        $this->assertSame(['John', 'Michael', 'Mary', 'Joseph'], array_column(GuestTextParser::names('John Michael Mary Joseph', true)['rows'], 'name'));
    }

    public function test_names_skip_a_header_line(): void
    {
        $this->assertSame(['Asha'], array_column(GuestTextParser::names("Name\nAsha")['rows'], 'name'));
    }

    public function test_structured_recognises_columns_in_any_order_and_separator(): void
    {
        $rows = GuestTextParser::structured("John Michael, 0712345678, VIP, 2\n0755555555 | Mary Joseph | 1 | Regular\nPeter James\t3\t0788888888\tFamily")['rows'];
        $this->assertSame([
            ['name' => 'John Michael', 'phone_number' => '0712345678', 'pass_type' => 'VIP', 'allowed_entries' => '2'],
            ['name' => 'Mary Joseph', 'phone_number' => '0755555555', 'pass_type' => 'Regular', 'allowed_entries' => '1'],
            ['name' => 'Peter James', 'phone_number' => '0788888888', 'pass_type' => 'Family', 'allowed_entries' => '3'],
        ], $rows);
    }

    public function test_structured_separator_is_taken_from_the_text_and_header_is_skipped(): void
    {
        $rows = GuestTextParser::structured("name|phone|pass|people\nAsha Mrema|0712345678|single|1\nJuma||double|")['rows'];
        $this->assertSame(['Asha Mrema', 'Juma'], array_column($rows, 'name'));
        $this->assertSame(['0712345678', ''], array_column($rows, 'phone_number'));
    }

    public function test_structured_line_without_separator_still_finds_the_phone(): void
    {
        $rows = GuestTextParser::structured('Asha Mrema 0712345678')['rows'];
        $this->assertSame(['Asha Mrema', '0712345678'], [$rows[0]['name'], $rows[0]['phone_number']]);
    }

    public function test_normalise_applies_documented_defaults(): void
    {
        $n = fn (string $pass, string $count) => GuestTextParser::normalise(['name' => 'A', 'phone_number' => '', 'pass_type' => $pass, 'allowed_entries' => $count]);
        $this->assertSame(['single', '1'], array_values(array_slice($n('', ''), 2)));
        $this->assertSame(['double', '2'], array_values(array_slice($n('VIP', '2'), 2)));
        $this->assertSame(['special', '4'], array_values(array_slice($n('vip', '4'), 2)));
        $this->assertSame(['single', '1'], array_values(array_slice($n('Regular', ''), 2)));
        $this->assertSame(['double', '2'], array_values(array_slice($n('couple', ''), 2)));
        $this->assertSame(['special', '5'], array_values(array_slice($n('Familia', '5'), 2)));
        $this->assertSame(['special', '3'], array_values(array_slice($n('single', '3'), 2)), 'the number of people wins over the word');
        $this->assertSame(['special', ''], array_values(array_slice($n('special', ''), 2)), 'Special without a count is left for validation to report');
        $this->assertSame('premium', $n('Premium', '')['pass_type'], 'an unknown word is kept so validation reports it');
    }

    public function test_lists_are_capped_and_report_truncation(): void
    {
        $result = GuestTextParser::names(implode("\n", array_map(fn ($i) => 'Guest '.$i.'a', range(1, GuestTextParser::MAX_ROWS + 5))));
        $this->assertCount(GuestTextParser::MAX_ROWS, $result['rows']);
        $this->assertTrue($result['truncated']);
    }
}
