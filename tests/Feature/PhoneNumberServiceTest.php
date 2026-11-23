<?php

namespace Tests\Feature;

use App\Services\PhoneNumberService;
use Tests\TestCase;

class PhoneNumberServiceTest extends TestCase
{
    public function test_tanzanian_phone_formats_normalize_to_canonical_international_format(): void
    {
        $phones = app(PhoneNumberService::class);

        $this->assertSame('+255788000001', $phones->normalize('+255788000001'));
        $this->assertSame('+255788000001', $phones->normalize('255788000001'));
        $this->assertSame('+255788000001', $phones->normalize('0788000001'));
        $this->assertSame('+255788000001', $phones->normalize('788000001'));
    }

    public function test_excel_scientific_notation_phone_numbers_normalize_without_float_rounding(): void
    {
        $phones = app(PhoneNumberService::class);

        $this->assertSame('+255700000000', $phones->normalize('2.557E+11'));
        $this->assertSame('+255700000000', $phones->normalize('+2.557E+11'));
        $this->assertSame('+255700000000', $phones->normalize('2557E+8'));
        $this->assertNull($phones->normalize('2.557E+10'));
    }

    public function test_invalid_tanzanian_phone_numbers_are_rejected(): void
    {
        $phones = app(PhoneNumberService::class);

        foreach ([
            '25578800001',
            '+25578800001',
            '078800001',
            '2550788000001',
            'abc0788000001',
            '+2557880000011',
        ] as $phoneNumber) {
            $this->assertNull($phones->normalize($phoneNumber), $phoneNumber);
        }
    }
}