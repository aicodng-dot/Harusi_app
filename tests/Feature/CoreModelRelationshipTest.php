<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

class CoreModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_qr_code_checkin_relationships_work(): void
    {
        $user = User::query()->create([
            'name' => 'Scanner',
            'email' => 'scanner@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SCANNER,
            'gate_name' => 'Main Gate',
        ]);

        $guest = Guest::query()->create([
            'name' => 'Asha Mohamed',
            'phone_number' => '+255 712 000 101',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 5,
        ]);

        $qrCode = QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => Guest::makeSecureToken(),
            'generated_at' => now(),
        ]);

        $checkin = Checkin::query()->create([
            'guest_id' => $guest->id,
            'qr_code_id' => $qrCode->id,
            'user_id' => $user->id,
            'gate_name' => 'Main Gate',
            'entries_added' => 2,
            'used_entries_after_scan' => 2,
            'remaining_entries_after_scan' => 3,
            'scan_result' => Checkin::RESULT_ADMITTED,
            'checked_in_at' => now(),
        ]);

        $this->assertTrue($guest->qrCode->is($qrCode));
        $this->assertTrue($guest->checkins->first()->is($checkin));
        $this->assertTrue($qrCode->guest->is($guest));
        $this->assertTrue($checkin->guest->is($guest));
        $this->assertTrue($checkin->qrCode->is($qrCode));
        $this->assertTrue($checkin->user->is($user));
    }

    public function test_pass_entry_rules_are_enforced_by_the_model(): void
    {
        $single = Guest::query()->create([
            'name' => 'Single',
            'phone_number' => '+255 700 000 001',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 10,
        ]);

        $double = Guest::query()->create([
            'name' => 'Double',
            'phone_number' => '+255 700 000 002',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 10,
        ]);

        $special = Guest::query()->create([
            'name' => 'Special',
            'phone_number' => '+255 700 000 003',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 12,
        ]);

        $this->assertSame(1, $single->allowed_entries);
        $this->assertSame(2, $double->allowed_entries);
        $this->assertSame(10, $special->allowed_entries);

        $this->expectException(InvalidArgumentException::class);

        Guest::query()->create([
            'name' => 'Invalid',
            'phone_number' => '+255 700 000 004',
            'pass_type' => 'vip',
            'allowed_entries' => 1,
        ]);
    }
}
