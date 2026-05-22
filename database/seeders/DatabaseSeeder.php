<?php

namespace Database\Seeders;

use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\ScanLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();

        ScanLog::query()->delete();
        Admission::query()->delete();
        Checkin::query()->delete();
        QrCode::query()->delete();
        Guest::query()->delete();
        User::query()->delete();

        Schema::enableForeignKeyConstraints();

        User::query()->create([
            'name' => 'Wedding Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'gate_name' => null,
        ]);

        User::query()->create([
            'name' => 'Main Gate Scanner',
            'email' => 'scanner@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SCANNER,
            'gate_name' => 'Main Gate',
        ]);

        collect([
            ['Asha Mohamed', '+255 712 000 101', Guest::PASS_SINGLE, 1, 0],
            ['Baraka Family', '+255 713 000 202', Guest::PASS_SPECIAL, 6, 2],
            ['Neema & Amani', '+255 714 000 303', Guest::PASS_DOUBLE, 2, 2],
            ['Joseph Kileo', '+255 715 000 404', Guest::PASS_SINGLE, 1, 0],
            ['Mariam Group', '+255 716 000 505', Guest::PASS_SPECIAL, 10, 0],
            ['Cancelled Sample', '+255 717 000 606', Guest::PASS_DOUBLE, 2, 0, Guest::STATUS_CANCELLED],
        ])->each(function (array $row): void {
            [$name, $phone, $passType, $allowed, $admitted] = $row;
            $status = $row[5] ?? Guest::STATUS_ACTIVE;

            $guest = new Guest([
                'name' => $name,
                'phone_number' => $phone,
                'pass_type' => $passType,
                'allowed_entries' => $allowed,
                'used_entries' => $admitted,
                'status' => $status,
            ]);
            $guest->save();

            $qrCode = QrCode::query()->create([
                'guest_id' => $guest->id,
                'qr_token' => Guest::makeSecureToken(),
                'is_active' => $status !== Guest::STATUS_CANCELLED,
                'generated_at' => now(),
                'revoked_at' => $status === Guest::STATUS_CANCELLED ? now() : null,
            ]);

            if ($admitted > 0) {
                Checkin::query()->create([
                    'guest_id' => $guest->id,
                    'qr_code_id' => $qrCode->id,
                    'user_id' => null,
                    'gate_name' => 'Main Gate',
                    'entries_added' => $admitted,
                    'used_entries_after_scan' => $guest->used_entries,
                    'remaining_entries_after_scan' => $guest->remainingEntries(),
                    'scan_result' => Checkin::RESULT_ADMITTED,
                    'device_info' => 'Seeder',
                    'ip_address' => '127.0.0.1',
                    'checked_in_at' => now()->subMinutes(12),
                ]);

                Admission::query()->create([
                    'guest_id' => $guest->id,
                    'quantity' => $admitted,
                    'admitted_by' => 'Seed gate',
                    'device_label' => 'Seeder',
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'Seeder',
                    'admitted_at' => now()->subMinutes(12),
                ]);

                ScanLog::query()->create([
                    'guest_id' => $guest->id,
                    'token_hash' => Guest::hashToken($qrCode->qr_token),
                    'action' => 'admit',
                    'result' => 'admitted',
                    'quantity' => $admitted,
                    'message' => 'Seed admission recorded.',
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'Seeder',
                    'metadata' => ['remaining' => $guest->remainingAdmissions()],
                    'scanned_at' => now()->subMinutes(12),
                ]);
            }
        });
    }
}
