<?php

namespace Tests\Feature\Invitations;

use App\Models\Checkin;
use App\Models\Guest;
use App\Models\QrCode;
use App\Services\Invitations\InvitationCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The QR printed on a generated invitation is accepted by the EXISTING scanner endpoints
 * (no parallel validation), and admissions follow each guest's stored allowance.
 */
class InvitationScannerIntegrationTest extends TestCase
{
    use InvitationFixtures;
    use RefreshDatabase;

    private function issued(array $f): array
    {
        Storage::fake('invitations');
        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.generate', ['event' => 101]), [
            'guests' => array_map(fn ($g) => $g->id, $f['guests']),
        ])->assertOk();

        return array_map(fn (Guest $guest) => InvitationCredentials::payload(QrCode::query()->where('guest_id', $guest->id)->sole()), $f['guests']);
    }

    private function admit(array $f, string $payload, int $quantity)
    {
        return $this->actingAs($f['scanner'])->postJson(route('scanner.admit'), ['qr_token' => $payload, 'entries_to_admit' => $quantity]);
    }

    public function test_invitation_qr_payload_is_the_scanner_verification_url(): void
    {
        $f = $this->invitationFixture();
        $payloads = $this->issued($f);

        foreach ($f['guests'] as $key => $guest) {
            $token = QrCode::query()->where('guest_id', $guest->id)->value('qr_token');
            $this->assertSame(route('scanner.verify-token', ['token' => $token]), $payloads[$key]);
            $this->assertStringNotContainsString((string) $guest->phone_number, $payloads[$key]);
            $this->assertStringNotContainsString('Asha', $payloads[$key]);
        }
        $this->actingAs($f['scanner'])->postJson(route('scanner.validate'), ['qr_token' => $payloads['special']])
            ->assertOk()->assertJsonPath('pass_type', Guest::PASS_SPECIAL)->assertJsonPath('allowed_entries', 5)
            ->assertJsonPath('remaining_entries', 5)->assertJsonPath('guest_name', 'Familia ya Mzee Nuru');
    }

    public function test_single_double_and_special_passes_follow_their_stored_allowance(): void
    {
        $f = $this->invitationFixture();
        $payloads = $this->issued($f);

        $this->admit($f, $payloads['single'], 1)->assertOk()->assertJsonPath('guest.remaining_entries', 0);
        $this->admit($f, $payloads['single'], 1)->assertStatus(409)->assertJsonPath('result', Checkin::RESULT_ALREADY_USED);

        $this->admit($f, $payloads['double'], 1)->assertOk()->assertJsonPath('guest.remaining_entries', 1);
        $this->admit($f, $payloads['double'], 2)->assertStatus(422);
        $this->admit($f, $payloads['double'], 1)->assertOk()->assertJsonPath('guest.remaining_entries', 0);

        $this->admit($f, $payloads['special'], 6)->assertStatus(422);
        $this->admit($f, $payloads['special'], 3)->assertOk()->assertJsonPath('guest.used_entries', 3)->assertJsonPath('guest.remaining_entries', 2);
        $this->admit($f, $payloads['special'], 2)->assertOk()->assertJsonPath('guest.remaining_entries', 0);
        $this->admit($f, $payloads['special'], 1)->assertStatus(409);

        $this->assertSame([1, 2, 5], Guest::query()->whereIn('id', array_map(fn ($g) => $g->id, $f['guests']))->orderBy('id')->pluck('used_entries')->all());
        $this->assertSame(5, DB::table('admissions')->count());
        $this->assertSame(8, (int) DB::table('admissions')->sum('quantity'), 'Admitted people: 1 + 2 + 5.');
        // Each successful scan is recorded with its operator and timestamp.
        $admitted = DB::table('checkins')->where('scan_result', Checkin::RESULT_ADMITTED)->get();
        $this->assertCount(5, $admitted);
        $this->assertSame([$f['scanner']->id], $admitted->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $this->assertNotContains(null, $admitted->pluck('checked_in_at')->all());
    }

    public function test_foreign_event_revoked_and_invalid_codes_are_rejected(): void
    {
        $f = $this->invitationFixture();
        $payloads = $this->issued($f);
        $foreignToken = $this->giveToken($f['foreignGuest']);

        $this->admit($f, route('scanner.verify-token', ['token' => $foreignToken]), 1)->assertStatus(409)->assertJsonPath('result', Checkin::RESULT_WRONG_EVENT);
        $this->admit($f, route('scanner.verify-token', ['token' => str_repeat('x', 64)]), 1)->assertNotFound();
        QrCode::query()->where('guest_id', $f['guests']['double']->id)->update(['is_active' => false, 'revoked_at' => now()]);
        $this->admit($f, $payloads['double'], 1)->assertStatus(409)->assertJsonPath('result', Checkin::RESULT_REVOKED);
        $this->assertSame(0, (int) Guest::query()->whereKey($f['foreignGuest']->id)->value('used_entries'));
        $this->assertSame(0, (int) Guest::query()->whereKey($f['guests']['double']->id)->value('used_entries'));
    }

    public function test_regenerating_cards_after_admission_keeps_counts_and_credentials(): void
    {
        $f = $this->invitationFixture();
        $payloads = $this->issued($f);
        $this->admit($f, $payloads['special'], 2)->assertOk();

        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.generate', ['event' => 101]), ['guests' => [$f['guests']['special']->id], 'force' => true])->assertOk();

        $this->assertSame($payloads['special'], InvitationCredentials::payload(QrCode::query()->where('guest_id', $f['guests']['special']->id)->sole()));
        $this->assertSame(2, (int) Guest::query()->whereKey($f['guests']['special']->id)->value('used_entries'));
        $this->admit($f, $payloads['special'], 3)->assertOk()->assertJsonPath('guest.remaining_entries', 0);
    }
}
