<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Invitations\InvitationFixtures;
use Tests\TestCase;

class AdminCustomerManagementTest extends TestCase
{
    use InvitationFixtures;
    use RefreshDatabase;

    private function customer(string $key, array $extra = []): User
    {
        static $n = 0;
        $n++;

        return User::query()->create($extra + [
            'name' => 'Customer '.$key, 'email' => $key.'@example.invalid', 'phone_number' => '+2557660000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'password' => 'customer-pass-123', 'role' => User::ROLE_CUSTOMER, 'is_active' => true, 'preferred_language' => 'en',
        ]);
    }

    private function workspace(int $id, User $owner, bool $provisioned = true): void
    {
        DB::table('workspaces')->insert(['id' => $id, 'name' => 'Workspace '.$id]);
        DB::table('workspace_users')->insert(['workspace_id' => $id, 'user_id' => $owner->id, 'role' => 'owner', 'is_active' => 1]);
        if ($provisioned) {
            DB::table('workspace_provisionings')->insert(['creator_user_id' => $owner->id, 'workspace_id' => $id,
                'request_hash' => hash('sha256', 'req'.$id), 'name_hash' => hash('sha256', 'name'.$id)]);
        }
    }

    public function test_only_administrators_can_reach_customer_management(): void
    {
        $f = $this->invitationFixture();
        $this->get(route('admin.customers.index'))->assertRedirect(route('login'));
        foreach ([$f['owner'], $f['scanner']] as $user) {
            $this->actingAs($user)->get(route('admin.customers.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.customers.show', $f['owner']))->assertForbidden();
            $this->actingAs($user)->post(route('admin.customers.suspend', $f['foreign']), ['confirm' => '1'])->assertForbidden();
            $this->actingAs($user)->delete(route('admin.customers.destroy', $f['foreign']), ['confirm_email' => 'foreign@example.invalid'])->assertForbidden();
        }
        $this->assertTrue($f['foreign']->fresh()->is_active);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_index_lists_only_customers_with_counts_search_filters_and_pagination(): void
    {
        $f = $this->invitationFixture();
        $response = $this->actingAs($f['admin'])->get(route('admin.customers.index'))->assertOk()
            ->assertSee('owner@example.invalid')->assertSee('foreign@example.invalid')
            ->assertDontSee('admin@example.invalid')->assertDontSee('gate@example.invalid');
        $owner = $response->viewData('customers')->firstWhere('id', $f['owner']->id);
        $this->assertSame([1, 1, 3], [(int) $owner->workspaces_count, (int) $owner->events_count, (int) $owner->guests_count]);

        $this->actingAs($f['admin'])->get(route('admin.customers.index', ['q' => 'FOREIGN']))->assertSee('foreign@example.invalid')->assertDontSee('owner@example.invalid');
        $this->actingAs($f['admin'])->get(route('admin.customers.index', ['q' => '788000070']))->assertSee('owner@example.invalid')->assertDontSee('foreign@example.invalid');
        $this->actingAs($f['admin'])->get(route('admin.customers.index', ['q' => '%']))->assertOk()->assertSee('No customers match');

        $lonely = $this->customer('lonely', ['is_active' => false]);
        $this->actingAs($f['admin'])->get(route('admin.customers.index', ['status' => 'suspended']))->assertSee($lonely->email)->assertDontSee('owner@example.invalid');
        $this->actingAs($f['admin'])->get(route('admin.customers.index', ['events' => 'without']))->assertSee($lonely->email)->assertDontSee('owner@example.invalid');
        $this->actingAs($f['admin'])->get(route('admin.customers.index', ['events' => 'with']))->assertSee('owner@example.invalid')->assertDontSee($lonely->email);

        for ($i = 0; $i < 20; $i++) {
            $this->customer('bulk'.$i);
        }
        $page1 = $this->actingAs($f['admin'])->get(route('admin.customers.index', ['status' => 'active']))->viewData('customers');
        $this->assertSame(22, $page1->total());
        $this->assertCount(20, $page1->items());
        $this->assertStringContainsString('status=active', (string) $page1->nextPageUrl());
        $this->assertCount(2, $this->actingAs($f['admin'])->get(route('admin.customers.index', ['status' => 'active', 'page' => 2]))->viewData('customers')->items());
    }

    public function test_profile_shows_only_that_customers_events_and_guests(): void
    {
        $f = $this->invitationFixture();
        $this->actingAs($f['admin'])->get(route('admin.customers.show', $f['owner']))->assertOk()
            ->assertSee('Neema &amp; Baraka', false)->assertSee('Owner Studio')->assertSee('Asha Mrema')->assertSee('Familia ya Mzee Nuru')
            ->assertDontSee('Foreign Wedding')->assertDontSee('Foreign Guest')
            ->assertSee('This customer has 1 event(s)');

        foreach ([$f['admin'], $f['scanner']] as $staff) {
            $this->actingAs($f['admin'])->get(route('admin.customers.show', $staff))->assertNotFound();
            $this->actingAs($f['admin'])->get(route('admin.customers.edit', $staff))->assertNotFound();
        }
        $this->actingAs($f['admin'])->get('/admin/customers/0')->assertNotFound();
    }

    public function test_edit_validates_updates_and_audits_without_touching_role_or_password(): void
    {
        $f = $this->invitationFixture();
        $owner = $f['owner'];
        $hash = $owner->password;
        DB::table('password_reset_tokens')->insert(['email' => $owner->email, 'token' => 'hashed', 'created_at' => now()]);

        $this->actingAs($f['admin'])->get(route('admin.customers.edit', $owner))->assertOk()->assertSee('Owner Synthetic');
        $this->actingAs($f['admin'])->from(route('admin.customers.edit', $owner))->put(route('admin.customers.update', $owner), [
            'name' => 'X', 'email' => 'foreign@example.invalid', 'phone_number' => '123', 'preferred_language' => 'en',
        ])->assertSessionHasErrors(['email', 'phone_number']);

        $this->actingAs($f['admin'])->put(route('admin.customers.update', $owner), [
            'name' => 'Owner Renamed', 'email' => ' New.Owner@Example.Invalid ', 'phone_number' => '0712 999 111', 'preferred_language' => 'en',
            'role' => 'admin', 'is_active' => '0', 'password' => 'hijack-pass',
        ])->assertRedirect(route('admin.customers.show', $owner));

        $owner->refresh();
        $this->assertSame(['Owner Renamed', 'new.owner@example.invalid', '+255712999111', 'en', User::ROLE_CUSTOMER, true],
            [$owner->name, $owner->email, $owner->phone_number, $owner->preferred_language, $owner->role, $owner->is_active]);
        $this->assertSame($hash, $owner->password);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'owner@example.invalid']);
        $audit = AdminAuditLog::query()->sole();
        $this->assertSame(['customer.updated', $f['admin']->id, $owner->id], [$audit->action, $audit->actor_user_id, $audit->subject_id]);
        $this->assertEqualsCanonicalizing(['name', 'email', 'phone_number', 'preferred_language'], $audit->details['fields']);
        $this->assertStringNotContainsString('hijack', json_encode($audit->details));
    }

    public function test_suspend_signs_the_customer_out_blocks_login_and_reactivate_restores(): void
    {
        $f = $this->invitationFixture();
        $owner = $f['owner'];
        $owner->createToken('device');

        $this->actingAs($f['admin'])->post(route('admin.customers.suspend', $owner), ['reason' => 'Chargeback'])->assertSessionHasErrors('confirm');
        $this->assertTrue($owner->fresh()->is_active);

        $this->actingAs($f['admin'])->post(route('admin.customers.suspend', $owner), ['confirm' => '1', 'reason' => 'Chargeback'])
            ->assertRedirect(route('admin.customers.show', $owner));
        $this->assertFalse($owner->fresh()->is_active);
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertSame('Chargeback', AdminAuditLog::query()->where('action', 'customer.suspended')->sole()->details['reason']);

        // An existing session is ended on its next request; a new sign-in is refused.
        $this->actingAs($owner)->get(route('customer.home'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login.attempt'), ['identifier' => $owner->email, 'password' => 'synthetic-pass-123']);
        $this->assertGuest();

        $this->actingAs($f['admin'])->post(route('admin.customers.reactivate', $owner), ['confirm' => '1'])->assertRedirect();
        $this->assertTrue($owner->fresh()->is_active);
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'customer.reactivated')->count());
        $this->actingAs($owner->fresh())->get(route('customer.home'))->assertOk();
    }

    public function test_customer_with_events_cannot_be_deleted_and_nothing_changes(): void
    {
        $f = $this->invitationFixture();
        $before = [DB::table('users')->count(), DB::table('events')->count(), DB::table('guests')->count(), DB::table('workspaces')->count()];

        $this->actingAs($f['admin'])->from(route('admin.customers.show', $f['owner']))
            ->delete(route('admin.customers.destroy', $f['owner']), ['confirm_email' => 'owner@example.invalid'])
            ->assertRedirect(route('admin.customers.show', $f['owner']))->assertSessionHasErrors('customer');

        $this->assertSame($before, [DB::table('users')->count(), DB::table('events')->count(), DB::table('guests')->count(), DB::table('workspaces')->count()]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_delete_requires_the_typed_email(): void
    {
        $f = $this->invitationFixture();
        $lonely = $this->customer('lonely');

        $this->actingAs($f['admin'])->delete(route('admin.customers.destroy', $lonely), ['confirm_email' => 'someone@else.invalid'])->assertSessionHasErrors('confirm_email');
        $this->actingAs($f['admin'])->delete(route('admin.customers.destroy', $lonely), [])->assertSessionHasErrors('confirm_email');
        $this->assertDatabaseHas('users', ['id' => $lonely->id]);
    }

    public function test_delete_removes_account_and_its_empty_workspace_and_keeps_an_audit_record(): void
    {
        $f = $this->invitationFixture();
        $empty = $this->customer('empty');
        $this->workspace(50, $empty);
        $empty->createToken('device');
        DB::table('password_reset_tokens')->insert(['email' => $empty->email, 'token' => 'hashed', 'created_at' => now()]);

        $this->actingAs($f['admin'])->delete(route('admin.customers.destroy', $empty), ['confirm_email' => 'EMPTY@example.invalid'])
            ->assertRedirect(route('admin.customers.index'));

        $this->assertDatabaseMissing('users', ['id' => $empty->id]);
        $this->assertDatabaseMissing('workspaces', ['id' => 50]);
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $empty->id]);
        $this->assertDatabaseMissing('workspace_provisionings', ['workspace_id' => 50]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $empty->email]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $empty->id]);
        $audit = AdminAuditLog::query()->sole();
        $this->assertSame(['customer.deleted', 'empty@example.invalid', [50]], [$audit->action, $audit->subject_label, $audit->details['workspaces_removed']]);
        // Other customers' data is untouched.
        $this->assertDatabaseHas('workspaces', ['id' => 10]);
        $this->assertSame(3, DB::table('guests')->where('event_id', 101)->count());
    }

    public function test_shared_workspace_member_loses_only_membership_but_its_creator_cannot_be_deleted(): void
    {
        $f = $this->invitationFixture();
        $creator = $this->customer('creator');
        $member = $this->customer('member');
        $this->workspace(60, $creator);
        DB::table('workspace_users')->insert(['workspace_id' => 60, 'user_id' => $member->id, 'role' => 'manager', 'is_active' => 1]);

        $this->actingAs($f['admin'])->delete(route('admin.customers.destroy', $creator), ['confirm_email' => $creator->email])->assertSessionHasErrors('customer');
        $this->assertDatabaseHas('users', ['id' => $creator->id]);

        $this->actingAs($f['admin'])->delete(route('admin.customers.destroy', $member), ['confirm_email' => $member->email])->assertRedirect(route('admin.customers.index'));
        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        $this->assertDatabaseHas('workspaces', ['id' => 60]);
        $this->assertDatabaseHas('workspace_users', ['workspace_id' => 60, 'user_id' => $creator->id]);
    }

    public function test_staff_accounts_are_never_reachable_through_customer_actions(): void
    {
        $f = $this->invitationFixture();
        foreach ([$f['admin'], $f['scanner']] as $staff) {
            $this->actingAs($f['admin'])->post(route('admin.customers.suspend', $staff), ['confirm' => '1'])->assertNotFound();
            $this->actingAs($f['admin'])->delete(route('admin.customers.destroy', $staff), ['confirm_email' => $staff->email])->assertNotFound();
            $this->actingAs($f['admin'])->put(route('admin.customers.update', $staff), ['name' => 'x', 'email' => $staff->email, 'preferred_language' => 'en'])->assertNotFound();
            $this->assertTrue($staff->fresh()->is_active);
        }
    }
}
