<?php

namespace Tests\Feature;

use App\Exceptions\OfflineEventLockedException;
use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\OfflineScannerSession;
use App\Models\OfflineScannerSessionAudit;
use App\Models\QrCode;
use App\Models\ScanLog;
use App\Models\User;
use App\Services\OfflineEventLockService;
use App\Services\OfflineScannerSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class OfflineEventLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_offline_session_blocks_laravel_qr_and_manual_admissions(): void
    {
        [$event, $admin, $scanner, $guest, $token] = $this->eventFixture();
        $this->activateOfflineLock($event, $admin);

        $this->actingAs($scanner)
            ->postJson(route('scanner.admit'), [
                'guest_id' => $guest->id,
                'qr_token' => $token,
                'entries_to_admit' => 1,
            ])
            ->assertStatus(423)
            ->assertJsonPath('message', OfflineEventLockedException::MESSAGE);

        $this->actingAs($scanner)
            ->from(route('scanner.manual-search', ['q' => $guest->manual_code]))
            ->post(route('scanner.manual-admit'), [
                'guest_id' => $guest->id,
                'entries_to_admit' => 1,
                'search' => $guest->manual_code,
            ])
            ->assertRedirect(route('scanner.dashboard'))
            ->assertSessionHas('error', OfflineEventLockedException::MESSAGE);

        $this->assertSame(0, $guest->fresh()->used_entries);
        $this->assertDatabaseMissing('admissions', ['guest_id' => $guest->id]);
        $this->assertDatabaseMissing('checkins', ['guest_id' => $guest->id]);
        $this->assertSame(0, Admission::query()->count());
        $this->assertSame(0, Checkin::query()->count());
    }

    public function test_active_offline_session_blocks_scanner_validation_routes_without_logs(): void
    {
        [$event, $admin, $scanner, , $token] = $this->eventFixture();
        $this->activateOfflineLock($event, $admin);

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => $token])
            ->assertStatus(423)
            ->assertJsonPath('message', OfflineEventLockedException::MESSAGE);

        $this->actingAs($scanner)
            ->postJson(route('scanner.verify'), ['token' => $token])
            ->assertStatus(423)
            ->assertJsonPath('message', OfflineEventLockedException::MESSAGE);

        $scanner->forceFill(['event_id' => null])->save();

        $this->actingAs($scanner)
            ->withSession(['selected_event_id' => $event->id])
            ->postJson(route('scanner.validate'), ['qr_token' => $token])
            ->assertStatus(423)
            ->assertJsonPath('message', OfflineEventLockedException::MESSAGE);

        $this->assertSame(0, Admission::query()->count());
        $this->assertSame(0, Checkin::query()->count());
        $this->assertSame(0, ScanLog::query()->count());
    }

    public function test_active_offline_session_blocks_admin_guest_updates_and_shows_session_banner(): void
    {
        [$event, $admin, , $guest] = $this->eventFixture();
        $session = $this->activateOfflineLock($event, $admin);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->get(route('admin.guests.index'))
            ->assertOk()
            ->assertSee('Offline Android scanner session is active')
            ->assertSee($session->session_uuid)
            ->assertSee(route('admin.offline-scanner-sessions.show', $session), false);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->from(route('admin.guests.edit', $guest))
            ->put(route('admin.guests.update', $guest), [
                'name' => 'Changed While Locked',
                'phone_number' => '+255700000099',
                'pass_type' => Guest::PASS_DOUBLE,
                'allowed_entries' => 2,
            ])
            ->assertSessionHas('error', OfflineEventLockedException::MESSAGE);

        $this->assertSame('Offline Lock Guest', $guest->fresh()->name);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.store'), [
                'name' => 'New Locked Guest',
                'phone_number' => '+255700000050',
                'pass_type' => Guest::PASS_SINGLE,
                'allowed_entries' => 1,
            ])
            ->assertSessionHas('error', OfflineEventLockedException::MESSAGE);

        $this->assertDatabaseMissing('guests', ['event_id' => $event->id, 'name' => 'New Locked Guest']);

        $oldToken = $guest->qrCode->qr_token;

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.qr.generate', $guest))
            ->assertSessionHas('error', OfflineEventLockedException::MESSAGE);

        $this->assertSame($oldToken, $guest->fresh('qrCode')->qrCode->qr_token);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->patch(route('admin.guests.cancel', $guest))
            ->assertSessionHas('error', OfflineEventLockedException::MESSAGE);

        $this->assertSame(Guest::STATUS_ACTIVE, $guest->fresh()->status);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->from(route('admin.guests.index'))
            ->delete(route('admin.guests.bulk-destroy'), ['guest_ids' => [$guest->id]])
            ->assertSessionHas('error', OfflineEventLockedException::MESSAGE);

        $this->assertDatabaseHas('guests', ['id' => $guest->id]);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'))
            ->assertSessionHas('error', OfflineEventLockedException::MESSAGE);
    }

    public function test_safe_cosmetic_event_updates_remain_allowed_while_offline_session_is_active(): void
    {
        [$event, $admin] = $this->eventFixture();
        $this->activateOfflineLock($event, $admin);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->put(route('admin.events.update', $event), [
                'event_name' => 'Offline Lock Guest List Event',
                'bride_name' => 'Asha',
                'groom_name' => 'Baraka',
                'venue_name' => 'Updated Reception Hall',
                'event_date' => now()->addMonth()->toDateString(),
                'status' => Event::STATUS_ACTIVE,
            ])
            ->assertRedirect(route('admin.events.index'))
            ->assertSessionHas('success', 'Event updated.');

        $event->refresh();
        $this->assertSame('Updated Reception Hall', $event->venue_name);
        $this->assertSame(Event::STATUS_ACTIVE, $event->status);
    }

    public function test_active_offline_session_blocks_single_guest_deletion_and_manual_code_regeneration(): void
    {
        [$event, $admin, , $guest] = $this->eventFixture();
        $this->activateOfflineLock($event, $admin);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->from(route('admin.guests.index'))
            ->delete(route('admin.guests.destroy', $guest))
            ->assertSessionHas('error', OfflineEventLockedException::MESSAGE);

        $this->assertDatabaseHas('guests', ['id' => $guest->id]);

        Guest::query()->whereKey($guest->id)->update(['manual_code' => null]);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.qr.generate', $guest))
            ->assertSessionHas('error', OfflineEventLockedException::MESSAGE);

        $this->assertNull($guest->fresh()->manual_code);
    }

    public function test_offline_session_cancellation_requires_authorization_confirmation_and_releases_lock(): void
    {
        [$event, $admin, $scanner] = $this->eventFixture();
        $session = $this->activateOfflineLock($event, $admin);

        $this->actingAs($scanner)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.offline-scanner-sessions.cancel', $session), [
                'confirm_unused' => '1',
                'cancellation_reason' => 'Scanner session was never started.',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.offline-scanner-sessions.cancel', $session))
            ->assertSessionHasErrors(['confirm_unused', 'cancellation_reason']);

        $this->assertSame(OfflineScannerSession::STATUS_ACTIVE, $session->fresh()->status);
        $this->assertNotNull(app(OfflineEventLockService::class)->getActiveSessionForEvent($event->fresh()));

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.offline-scanner-sessions.cancel', $session), [
                'confirm_unused' => '1',
                'cancellation_reason' => 'Administrator confirmed the Android session was not used.',
            ])
            ->assertRedirect(route('admin.offline-scanner-sessions.show', $session))
            ->assertSessionHas('success', 'Offline scanner session cancelled and event lock released.');

        $this->assertSame(OfflineScannerSession::STATUS_CANCELLED, $session->fresh()->status);
        $this->assertNull(app(OfflineEventLockService::class)->getActiveSessionForEvent($event->fresh()));
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $session->id,
            'action' => OfflineScannerSessionAudit::ACTION_SESSION_CANCELLED,
        ]);
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $session->id,
            'action' => OfflineScannerSessionAudit::ACTION_EVENT_UNLOCKED,
        ]);
    }
    public function test_lifecycle_and_cancellation_actions_create_required_audit_records(): void
    {
        [$event, $admin] = $this->eventFixture();
        $sessions = app(OfflineScannerSessionService::class);
        $locks = app(OfflineEventLockService::class);

        $session = $sessions->createDraft($event, $admin);
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $session->id,
            'action' => OfflineScannerSessionAudit::ACTION_SESSION_CREATED,
        ]);

        $session = $sessions->transition($session, OfflineScannerSession::STATUS_PACKAGE_GENERATED, $admin);
        $session = $locks->activateLock($session, $admin);
        $session = $locks->markResultsImported($session, $admin);
        $session = $locks->releaseLock($session, $admin);

        $actions = OfflineScannerSessionAudit::query()
            ->where('offline_scanner_session_id', $session->id)
            ->pluck('action')
            ->all();

        $this->assertContains(OfflineScannerSessionAudit::ACTION_PACKAGE_GENERATED, $actions);
        $this->assertContains(OfflineScannerSessionAudit::ACTION_EVENT_LOCKED, $actions);
        $this->assertContains(OfflineScannerSessionAudit::ACTION_SESSION_ACTIVATED, $actions);
        $this->assertContains(OfflineScannerSessionAudit::ACTION_RESULTS_IMPORTED, $actions);
        $this->assertContains(OfflineScannerSessionAudit::ACTION_EVENT_UNLOCKED, $actions);
        $this->assertContains(OfflineScannerSessionAudit::ACTION_SESSION_CLOSED, $actions);

        $cancelledSession = $sessions->createDraft($event, $admin);
        $cancelledSession = $sessions->transition($cancelledSession, OfflineScannerSession::STATUS_PACKAGE_GENERATED, $admin);
        $cancelledSession = $locks->cancelLock($cancelledSession, $admin, true, 'Package was generated for the wrong event date.');

        $this->assertSame(OfflineScannerSession::STATUS_CANCELLED, $cancelledSession->status);
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $cancelledSession->id,
            'action' => OfflineScannerSessionAudit::ACTION_SESSION_CANCELLED,
        ]);
        $this->assertDatabaseHas('offline_scanner_session_audits', [
            'offline_scanner_session_id' => $cancelledSession->id,
            'action' => OfflineScannerSessionAudit::ACTION_EVENT_UNLOCKED,
        ]);

        $this->expectException(LogicException::class);
        $locks->cancelLock($session, $admin, true, 'This must not be accepted after import.');
    }

    /**
     * @return array{Event, User, User, Guest, string}
     */
    private function eventFixture(): array
    {
        $event = Event::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $scanner = User::factory()->create([
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
            'gate_name' => 'Main Gate',
        ]);
        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => 'Offline Lock Guest',
            'phone_number' => '+255700000001',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);
        $token = Guest::makeSecureToken();

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => $token,
            'is_active' => true,
            'generated_at' => now(),
        ]);

        return [$event, $admin, $scanner, $guest->fresh('qrCode'), $token];
    }

    private function activateOfflineLock(Event $event, User $admin): OfflineScannerSession
    {
        $sessions = app(OfflineScannerSessionService::class);
        $session = $sessions->createDraft($event, $admin);
        $session = $sessions->transition($session, OfflineScannerSession::STATUS_PACKAGE_GENERATED, $admin);

        return app(OfflineEventLockService::class)->activateLock($session, $admin);
    }
}

