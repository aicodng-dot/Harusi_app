<?php

namespace Tests\Feature\Invitations;

use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Two workspaces: the owner's (event 101 with Single, Double and Special guests) and a
 * foreign one (event 201 with its own guest). Guests are created through the Guest
 * model so pass rules and manual codes follow the application's own logic.
 */
trait InvitationFixtures
{
    /** @return array{owner: User, foreign: User, admin: User, scanner: User, guests: array<string, Guest>, foreignGuest: Guest} */
    protected function invitationFixture(): array
    {
        $users = [];
        foreach (['owner' => 'customer', 'foreign' => 'customer', 'admin' => 'admin'] as $key => $role) {
            $users[$key] = User::query()->create([
                'name' => ucfirst($key).' Synthetic', 'email' => $key.'@example.invalid', 'phone_number' => '+25578800007'.count($users),
                'password' => 'synthetic-pass-123', 'role' => $role, 'is_active' => true, 'preferred_language' => 'sw',
            ]);
        }
        foreach ([10 => 'Owner Studio', 13 => 'Foreign Studio'] as $id => $name) {
            DB::table('workspaces')->insert(['id' => $id, 'name' => $name]);
        }
        DB::table('workspace_users')->insert(['workspace_id' => 10, 'user_id' => $users['owner']->id, 'role' => 'owner', 'is_active' => 1]);
        DB::table('workspace_users')->insert(['workspace_id' => 13, 'user_id' => $users['foreign']->id, 'role' => 'owner', 'is_active' => 1]);
        foreach ([[101, 'Neema & Baraka', 10], [201, 'Foreign Wedding', 13]] as [$id, $name, $workspace]) {
            DB::table('events')->insert(['id' => $id, 'event_name' => $name, 'venue_name' => 'Grand Hall, Dar es Salaam', 'event_date' => '2026-12-14',
                'event_time' => '18:00:00', 'event_type' => 'wedding', 'status' => 'active', 'workspace_id' => $workspace]);
        }
        $users['scanner'] = User::query()->create([
            'name' => 'Gate Scanner', 'email' => 'gate@example.invalid', 'phone_number' => '+255788000079', 'password' => 'synthetic-pass-123',
            'role' => User::ROLE_SCANNER, 'is_active' => true, 'gate_name' => 'Main Gate', 'event_id' => 101,
        ]);

        $guests = [];
        foreach ([
            'single' => ['Asha Mrema', '+255712000214', Guest::PASS_SINGLE, 1],
            'double' => ['John Komba', '+255755000251', Guest::PASS_DOUBLE, 2],
            'special' => ['Familia ya Mzee Nuru', '+255754000288', Guest::PASS_SPECIAL, 5],
        ] as $key => [$name, $phone, $pass, $allowed]) {
            $guests[$key] = Guest::query()->create(['event_id' => 101, 'name' => $name, 'phone_number' => $phone, 'pass_type' => $pass, 'allowed_entries' => $allowed]);
        }
        $foreignGuest = Guest::query()->create(['event_id' => 201, 'name' => 'Foreign Guest', 'phone_number' => '+255713000999', 'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1]);

        return $users + ['guests' => $guests, 'foreignGuest' => $foreignGuest];
    }

    protected function giveToken(Guest $guest): string
    {
        $token = Guest::makeSecureToken();
        QrCode::query()->create(['guest_id' => $guest->id, 'qr_token' => $token, 'is_active' => true, 'generated_at' => now()]);

        return $token;
    }
}
