<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Services\ManualCodeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class GuestManualCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_code_service_formats_numbers_with_leading_zeroes(): void
    {
        $this->assertSame('0047', app(ManualCodeService::class)->format(47));
    }

    public function test_guest_manual_code_is_generated_as_four_digit_string(): void
    {
        $guest = $this->guest();

        $this->assertIsString($guest->manual_code);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $guest->manual_code);
    }

    public function test_manual_code_preserves_leading_zeroes(): void
    {
        $guest = $this->guest([
            'manual_code' => '0047',
        ]);

        $this->assertSame('0047', $guest->fresh()->manual_code);
    }

    public function test_manual_code_must_be_exactly_four_digits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->guest([
            'manual_code' => '47',
        ]);
    }

    public function test_manual_code_is_unique_inside_each_event(): void
    {
        $firstEvent = Event::query()->create([
            'event_name' => 'First Wedding',
            'status' => Event::STATUS_ACTIVE,
        ]);
        $secondEvent = Event::query()->create([
            'event_name' => 'Second Wedding',
            'status' => Event::STATUS_ACTIVE,
        ]);

        $this->guest([
            'event_id' => $firstEvent->id,
            'manual_code' => '0047',
        ]);

        $this->guest([
            'event_id' => $secondEvent->id,
            'manual_code' => '0047',
        ]);

        $this->expectException(QueryException::class);

        $this->guest([
            'event_id' => $firstEvent->id,
            'manual_code' => '0047',
        ]);
    }

    private function guest(array $overrides = []): Guest
    {
        return Guest::query()->create(array_merge([
            'name' => 'Manual Code Guest',
            'phone_number' => '0712345678',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ], $overrides));
    }
}
