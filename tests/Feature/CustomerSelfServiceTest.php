<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Invitations\InvitationFixtures;
use Tests\TestCase;

/**
 * Customer self-service: own profile, edit/delete own events and guests. Every route
 * re-authorizes the account and event; other customers, staff and guests get nothing.
 */
class CustomerSelfServiceTest extends TestCase
{
    use InvitationFixtures;
    use RefreshDatabase;

    private function manager(): User
    {
        $manager = User::query()->create(['name' => 'Manager Synthetic', 'email' => 'manager@example.invalid', 'phone_number' => '+255788000090',
            'password' => 'synthetic-pass-123', 'role' => User::ROLE_CUSTOMER, 'is_active' => true, 'preferred_language' => 'en']);
        DB::table('workspace_users')->insert(['workspace_id' => 10, 'user_id' => $manager->id, 'role' => 'manager', 'is_active' => 1]);

        return $manager;
    }

    private function generateCards(array $f, array $guests): void
    {
        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.generate', ['event' => 101]), [
            'guests' => array_map(fn (Guest $g) => $g->id, $guests),
        ])->assertOk();
    }

    private function eventPayload(array $extra = []): array
    {
        return $extra + ['event_name' => 'Neema & Baraka Renewed', 'event_datetime' => now()->addMonths(2)->format('Y-m-d').'T17:30', 'venue_name' => 'Serena Hotel'];
    }

    // ----- Profile -------------------------------------------------------------------

    public function test_profile_is_only_for_the_signed_in_customer(): void
    {
        $f = $this->invitationFixture();
        $this->get(route('customer.profile'))->assertRedirect(route('login'));
        $this->actingAs($f['admin'])->get(route('customer.profile'))->assertForbidden();
        $this->actingAs($f['scanner'])->get(route('customer.profile'))->assertForbidden();
        $this->actingAs($f['owner'])->get(route('customer.profile'))->assertOk()
            ->assertSee('owner@example.invalid')->assertDontSee('foreign@example.invalid')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_profile_update_changes_details_and_ignores_role_and_status(): void
    {
        $f = $this->invitationFixture();
        $this->actingAs($f['owner'])->put(route('customer.profile.update'), [
            'name' => 'Owner New Name', 'email' => 'owner@example.invalid', 'phone_number' => '0713 222 333', 'preferred_language' => 'en',
            'role' => 'admin', 'is_active' => '0', 'is_platform_admin' => '1',
        ])->assertRedirect();
        $owner = $f['owner']->fresh();
        $this->assertSame(['Owner New Name', '+255713222333', 'en', User::ROLE_CUSTOMER, true],
            [$owner->name, $owner->phone_number, $owner->preferred_language, $owner->role, $owner->is_active]);
        $this->assertFalse((bool) $owner->getRawOriginal('is_platform_admin'));
    }

    public function test_email_change_needs_the_current_password_and_must_be_unique(): void
    {
        $f = $this->invitationFixture();
        $base = ['name' => 'Owner Synthetic', 'phone_number' => '+255788000070', 'preferred_language' => 'sw'];

        $this->actingAs($f['owner'])->put(route('customer.profile.update'), $base + ['email' => 'new@example.invalid'])->assertSessionHasErrorsIn('profile', 'current_password');
        $this->actingAs($f['owner'])->put(route('customer.profile.update'), $base + ['email' => 'new@example.invalid', 'current_password' => 'wrong'])->assertSessionHasErrorsIn('profile', 'current_password');
        $this->actingAs($f['owner'])->put(route('customer.profile.update'), $base + ['email' => 'FOREIGN@example.invalid', 'current_password' => 'synthetic-pass-123'])->assertSessionHasErrorsIn('profile', 'email');
        $this->assertSame('owner@example.invalid', $f['owner']->fresh()->email);

        $this->actingAs($f['owner'])->put(route('customer.profile.update'), $base + ['email' => 'New@Example.invalid', 'current_password' => 'synthetic-pass-123'])->assertSessionHasNoErrors();
        $this->assertSame('new@example.invalid', $f['owner']->fresh()->email);
    }

    public function test_password_change_checks_current_password_and_keeps_this_session(): void
    {
        $f = $this->invitationFixture();
        $token = $f['owner']->fresh()->getRememberToken();

        $this->actingAs($f['owner'])->put(route('customer.profile.password'), ['current_password' => 'wrong', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertSessionHasErrorsIn('password', 'current_password');
        $this->actingAs($f['owner'])->put(route('customer.profile.password'), ['current_password' => 'synthetic-pass-123', 'password' => 'brand-new-pass', 'password_confirmation' => 'different'])
            ->assertSessionHasErrorsIn('password', 'password');
        $this->assertTrue(Hash::check('synthetic-pass-123', $f['owner']->fresh()->password));

        $this->actingAs($f['owner'])->put(route('customer.profile.password'), ['current_password' => 'synthetic-pass-123', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertRedirect(route('customer.profile'))->assertSessionHasNoErrors();
        $fresh = $f['owner']->fresh();
        $this->assertTrue(Hash::check('brand-new-pass', $fresh->password));
        $this->assertNotSame($token, $fresh->getRememberToken());
        $this->get(route('customer.profile'))->assertOk();
    }

    // ----- Events --------------------------------------------------------------------

    public function test_owner_and_manager_can_edit_their_event_but_nobody_else(): void
    {
        $f = $this->invitationFixture();
        $manager = $this->manager();

        $this->actingAs($f['owner'])->get(route('customer.events.edit', 101))->assertOk()->assertSee('Neema &amp; Baraka', false);
        $this->actingAs($f['owner'])->put(route('customer.events.update', 101), $this->eventPayload())->assertRedirect(route('customer.events.dashboard', 101));
        $event = DB::table('events')->where('id', 101)->first();
        $this->assertSame(['Neema & Baraka Renewed', 'Serena Hotel', '17:30'], [$event->event_name, $event->venue_name, substr((string) $event->event_time, 0, 5)]);

        $this->actingAs($manager)->put(route('customer.events.update', 101), $this->eventPayload(['venue_name' => 'Manager Venue']))->assertRedirect();
        $this->assertSame('Manager Venue', DB::table('events')->where('id', 101)->value('venue_name'));

        $this->actingAs($f['foreign'])->get(route('customer.events.edit', 101))->assertNotFound();
        $this->actingAs($f['foreign'])->put(route('customer.events.update', 101), $this->eventPayload(['venue_name' => 'Hijack']))->assertNotFound();
        $this->actingAs($f['admin'])->put(route('customer.events.update', 101), $this->eventPayload(['venue_name' => 'Hijack']))->assertForbidden();
        $this->actingAs($f['scanner'])->get(route('customer.events.edit', 101))->assertForbidden();
        $this->assertSame('Manager Venue', DB::table('events')->where('id', 101)->value('venue_name'));
    }

    public function test_event_edit_validates_dates_and_refuses_inactive_events(): void
    {
        $f = $this->invitationFixture();
        $this->actingAs($f['owner'])->put(route('customer.events.update', 101), $this->eventPayload(['event_datetime' => now()->subDays(3)->format('Y-m-d').'T10:00']))->assertStatus(422);
        $this->actingAs($f['owner'])->put(route('customer.events.update', 101), $this->eventPayload(['event_name' => '', 'venue_name' => '']))->assertStatus(422);

        // A date already in the past can be kept while other details change.
        DB::table('events')->where('id', 101)->update(['event_date' => now()->subDays(2)->toDateString()]);
        $this->actingAs($f['owner'])->put(route('customer.events.update', 101), $this->eventPayload(['event_datetime' => now()->subDays(2)->format('Y-m-d').'T18:00']))->assertRedirect();

        DB::table('events')->where('id', 101)->update(['status' => 'archived']);
        $this->actingAs($f['owner'])->put(route('customer.events.update', 101), $this->eventPayload(['venue_name' => 'Late change']))->assertStatus(409);
        $this->assertNotSame('Late change', DB::table('events')->where('id', 101)->value('venue_name'));
    }

    public function test_only_the_owner_deletes_and_only_with_the_typed_name_and_no_blockers(): void
    {
        $f = $this->invitationFixture();
        Storage::fake('invitations');
        $manager = $this->manager();
        $this->generateCards($f, array_values($f['guests']));
        $guestIds = array_map(fn (Guest $g) => $g->id, $f['guests']);

        $this->actingAs($manager)->delete(route('customer.events.destroy', 101), ['confirm_name' => 'Neema & Baraka'])->assertNotFound();
        $this->actingAs($f['foreign'])->delete(route('customer.events.destroy', 101), ['confirm_name' => 'Neema & Baraka'])->assertNotFound();
        $this->actingAs($f['owner'])->delete(route('customer.events.destroy', 101), ['confirm_name' => 'wrong'])->assertStatus(422);
        // The fixture assigns a gate scanner to event 101.
        $this->actingAs($f['owner'])->delete(route('customer.events.destroy', 101), ['confirm_name' => 'neema & baraka'])->assertStatus(409)->assertSee(__('customer_manage.event.blocked.scanners', [], 'sw'));
        $this->assertDatabaseHas('events', ['id' => 101]);

        DB::table('users')->where('id', $f['scanner']->id)->update(['event_id' => 201]);
        $this->actingAs($f['owner'])->delete(route('customer.events.destroy', 101), ['confirm_name' => ' Neema & Baraka '])->assertRedirect(route('customer.home'));

        $this->assertDatabaseMissing('events', ['id' => 101]);
        $this->assertSame(0, Guest::query()->whereIn('id', $guestIds)->count());
        $this->assertSame(0, QrCode::query()->whereIn('guest_id', $guestIds)->count());
        $this->assertSame(0, DB::table('invitation_cards')->where('event_id', 101)->count());
        $this->assertSame(0, DB::table('invitation_designs')->where('event_id', 101)->count());
        $this->assertSame([], Storage::disk('invitations')->allFiles('events/101'));
        // The foreign customer's event and guest are untouched.
        $this->assertDatabaseHas('events', ['id' => 201]);
        $this->assertDatabaseHas('guests', ['id' => $f['foreignGuest']->id]);
    }

    public function test_event_with_admitted_guests_cannot_be_deleted(): void
    {
        $f = $this->invitationFixture();
        DB::table('users')->where('id', $f['scanner']->id)->update(['event_id' => 201]);
        DB::table('guests')->where('id', $f['guests']['double']->id)->update(['used_entries' => 1]);

        $this->actingAs($f['owner'])->get(route('customer.events.edit', 101))->assertOk()->assertSee(__('customer_manage.event.blocked.admitted', [], 'sw'));
        $this->actingAs($f['owner'])->delete(route('customer.events.destroy', 101), ['confirm_name' => 'Neema & Baraka'])->assertStatus(409);
        $this->assertDatabaseHas('events', ['id' => 101]);
        $this->assertSame(3, DB::table('guests')->where('event_id', 101)->count());
    }

    // ----- Guests --------------------------------------------------------------------

    public function test_guest_edit_updates_details_and_retires_a_now_inaccurate_card(): void
    {
        $f = $this->invitationFixture();
        Storage::fake('invitations');
        $this->generateCards($f, [$f['guests']['single'], $f['guests']['double']]);
        $single = $f['guests']['single'];
        $card = DB::table('invitation_cards')->where('guest_id', $single->id)->first();
        $this->assertNotNull($card);

        // Phone-only change keeps the card (the phone is not printed).
        $this->actingAs($f['owner'])->put(route('customer.events.guests.update', [101, $single->id]), [
            'name' => 'Asha Mrema', 'phone_number' => '0719 000 111', 'pass_type' => 'single', 'allowed_entries' => '1',
            'used_entries' => '1', 'status' => 'cancelled', 'event_id' => 201,
        ])->assertRedirect(route('customer.events.invitation.guests', 101));
        $single->refresh();
        $this->assertSame(['+255719000111', 0, Guest::STATUS_ACTIVE, 101], [$single->phone_number, (int) $single->used_entries, $single->status, (int) $single->event_id]);
        $this->assertSame(1, DB::table('invitation_cards')->where('guest_id', $single->id)->count());

        // A printed change removes the card row and its files.
        $this->actingAs($f['owner'])->put(route('customer.events.guests.update', [101, $single->id]), [
            'name' => 'Asha M. Mrema', 'phone_number' => '0719 000 111', 'pass_type' => 'special', 'allowed_entries' => '4',
        ])->assertRedirect();
        $this->assertSame(['Asha M. Mrema', Guest::PASS_SPECIAL, 4], [$single->fresh()->name, $single->fresh()->pass_type, (int) $single->fresh()->allowed_entries]);
        $this->assertSame(0, DB::table('invitation_cards')->where('guest_id', $single->id)->count());
        Storage::disk('invitations')->assertMissing($card->png_path);
        Storage::disk('invitations')->assertMissing($card->pdf_path);
        $this->assertSame(1, DB::table('invitation_cards')->where('guest_id', $f['guests']['double']->id)->count());
    }

    public function test_guest_edit_guards_allowance_duplicates_and_ownership(): void
    {
        $f = $this->invitationFixture();
        $special = $f['guests']['special']; // allowed 5
        DB::table('guests')->where('id', $special->id)->update(['used_entries' => 4]);
        $payload = ['name' => 'Familia ya Mzee Nuru', 'phone_number' => '+255754000288', 'pass_type' => 'special'];

        $this->actingAs($f['owner'])->put(route('customer.events.guests.update', [101, $special->id]), $payload + ['allowed_entries' => '3'])->assertStatus(422);
        $this->actingAs($f['owner'])->put(route('customer.events.guests.update', [101, $special->id]), $payload + ['allowed_entries' => '4'])->assertRedirect();
        $this->assertSame(Guest::STATUS_FULLY_USED, $special->fresh()->status);
        $this->actingAs($f['owner'])->put(route('customer.events.guests.update', [101, $special->id]), $payload + ['allowed_entries' => '6'])->assertRedirect();
        $this->assertSame(Guest::STATUS_PARTIALLY_USED, $special->fresh()->status);

        $duplicate = ['name' => 'Someone Else', 'phone_number' => '+255755000251', 'pass_type' => 'single', 'allowed_entries' => '1'];
        $this->actingAs($f['owner'])->put(route('customer.events.guests.update', [101, $f['guests']['single']->id]), $duplicate)->assertStatus(422);
        $this->actingAs($f['owner'])->put(route('customer.events.guests.update', [101, $f['guests']['single']->id]), $duplicate + ['allow_duplicate' => '1'])->assertRedirect();

        // Another event's guest through this event's URL, or another customer, gets 404.
        $this->actingAs($f['owner'])->get(route('customer.events.guests.edit', [101, $f['foreignGuest']->id]))->assertNotFound();
        $this->actingAs($f['owner'])->put(route('customer.events.guests.update', [101, $f['foreignGuest']->id]), $duplicate)->assertNotFound();
        $this->actingAs($f['foreign'])->get(route('customer.events.guests.edit', [101, $special->id]))->assertNotFound();
        $this->actingAs($f['foreign'])->delete(route('customer.events.guests.destroy', [101, $special->id]), ['confirm' => '1'])->assertNotFound();
        $this->assertSame('Foreign Guest', $f['foreignGuest']->fresh()->name);
    }

    public function test_guest_delete_needs_confirmation_and_never_removes_admitted_guests(): void
    {
        $f = $this->invitationFixture();
        Storage::fake('invitations');
        $manager = $this->manager();
        $this->generateCards($f, [$f['guests']['single']]);
        $single = $f['guests']['single'];
        $double = $f['guests']['double'];
        DB::table('guests')->where('id', $double->id)->update(['used_entries' => 1]);

        $this->actingAs($f['owner'])->delete(route('customer.events.guests.destroy', [101, $single->id]))->assertStatus(422);
        $this->actingAs($f['owner'])->delete(route('customer.events.guests.destroy', [101, $double->id]), ['confirm' => '1'])->assertStatus(409);
        $this->assertDatabaseHas('guests', ['id' => $double->id]);

        $this->actingAs($manager)->delete(route('customer.events.guests.destroy', [101, $single->id]), ['confirm' => '1'])->assertRedirect(route('customer.events.invitation.guests', 101));
        $this->assertDatabaseMissing('guests', ['id' => $single->id]);
        $this->assertSame(0, QrCode::query()->where('guest_id', $single->id)->count());
        $this->assertSame(0, DB::table('invitation_cards')->where('guest_id', $single->id)->count());
        $this->assertSame([], Storage::disk('invitations')->files('events/101/cards'));
    }

    public function test_offline_session_lock_blocks_guest_changes(): void
    {
        $f = $this->invitationFixture();
        $single = $f['guests']['single'];
        DB::table('offline_scanner_sessions')->insert([
            'session_uuid' => (string) Str::uuid(), 'event_id' => 101, 'status' => 'active',
            'created_by_user_id' => $f['admin']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($f['owner'])->put(route('customer.events.guests.update', [101, $single->id]), [
            'name' => 'Changed While Offline', 'phone_number' => '+255712000214', 'pass_type' => 'single', 'allowed_entries' => '1',
        ])->assertStatus(409);
        $this->actingAs($f['owner'])->delete(route('customer.events.guests.destroy', [101, $single->id]), ['confirm' => '1'])->assertStatus(409);
        $this->assertSame('Asha Mrema', $single->fresh()->name);
    }

    public function test_self_service_pages_render_in_both_languages(): void
    {
        $f = $this->invitationFixture();
        foreach (['en' => ['My profile', 'Edit event details', 'Edit guest'], 'sw' => ['Wasifu wangu', 'Hariri taarifa za tukio', 'Hariri mgeni']] as $lang => [$profile, $event, $guest]) {
            $this->actingAs($f['owner'])->get(route('customer.profile', ['lang' => $lang]))->assertOk()->assertSee($profile);
            $this->actingAs($f['owner'])->get(route('customer.events.edit', [101, 'lang' => $lang]))->assertOk()->assertSee($event);
            $this->actingAs($f['owner'])->get(route('customer.events.guests.edit', [101, $f['guests']['single']->id, 'lang' => $lang]))->assertOk()->assertSee($guest);
        }
        $this->actingAs($f['owner'])->get(route('customer.events.invitation.guests', 101))->assertOk()
            ->assertSee(route('customer.events.guests.edit', [101, $f['guests']['single']->id]), false);
        $this->actingAs($f['owner'])->get(route('customer.events.dashboard', 101))->assertOk()->assertSee(route('customer.events.edit', 101), false);
    }
}
