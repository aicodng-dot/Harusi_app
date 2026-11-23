<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DeviceTokenService;
use App\Services\Tenancy\LegacyDataInventory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkspaceFoundationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::fake();
        config(['mail.default' => 'array', 'mail.driver' => null]);
    }

    public function test_fresh_legacy_accounts_and_events_receive_no_workspace_or_platform_grants(): void
    {
        $admin = User::factory()->create();
        $event = Event::factory()->create();
        $scanner = $this->scanner($event);

        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('workspace_users', 0);
        $this->assertSame(0, DB::table('events')->whereNotNull('workspace_id')->count());
        $this->assertSame(0, DB::table('users')->where('is_platform_admin', true)->count());
        foreach ([$admin, $scanner] as $user) {
            $this->assertFalse($user->fresh()->is_platform_admin);
            $this->assertTrue($user->workspaces->isEmpty());
        }
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($scanner->isScanner());
        $this->assertSame($event->id, $scanner->fresh()->event_id);
        $this->assertNull($event->fresh()->workspace);
        // A new model must remain saveable before the additive migration is installed.
        $this->assertArrayNotHasKey('is_platform_admin', (new User)->getAttributes());
    }

    public function test_relations_preserve_active_and_inactive_memberships_without_assigning_authority(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Private workspace relationship marker']);
        $other = Workspace::query()->create(['name' => 'Second workspace']);
        $event = Event::factory()->create();
        $scanner = $this->scanner($event);
        $admin = User::factory()->create();
        $workspace->members()->attach($scanner, ['role' => 'owner', 'is_active' => true]);
        $workspace->members()->attach($admin, ['role' => 'manager', 'is_active' => false]);
        $other->members()->attach($scanner, ['role' => 'manager', 'is_active' => false]);
        $before = $this->foundationContents();

        $members = $workspace->members()->orderBy('users.id')->get()->keyBy('id');
        $this->assertCount(2, $members);
        foreach ([[$scanner, 'owner', true], [$admin, 'manager', false]] as [$user, $role, $active]) {
            $pivot = $members[$user->id]->pivot;
            $row = DB::table('workspace_users')->where('workspace_id', $workspace->id)->where('user_id', $user->id)->first();
            $this->assertSame($row->id, $pivot->id);
            $this->assertSame($workspace->id, $pivot->workspace_id);
            $this->assertSame($user->id, $pivot->user_id);
            $this->assertSame($role, $pivot->role);
            $this->assertSame($active, (bool) $pivot->is_active);
            $this->assertNotNull($pivot->created_at);
            $this->assertNotNull($pivot->updated_at);
        }
        $memberships = $scanner->workspaces()->orderBy('workspaces.id')->get();
        $this->assertSame([$workspace->id, $other->id], $memberships->modelKeys());
        $this->assertSame(['owner', 'manager'], $memberships->pluck('pivot.role')->all());
        $this->assertSame([true, false], $memberships->pluck('pivot.is_active')->map(fn ($value): bool => (bool) $value)->all());
        $this->assertFalse($scanner->fresh()->isAdmin());
        $this->assertFalse($scanner->fresh()->is_platform_admin);
        $this->assertTrue($admin->fresh()->isAdmin());
        $this->assertFalse($admin->fresh()->is_platform_admin);
        $this->assertSame($event->id, $scanner->fresh()->event_id);
        $this->assertNull($event->fresh()->workspace_id);
        $this->assertSame($before, $this->foundationContents());
    }

    public function test_event_relations_require_explicit_assignment_and_never_choose_an_owner(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Explicit ownership fixture']);
        $unassigned = Event::factory()->create();
        $assigned = Event::factory()->create();
        $assigned->workspace()->associate($workspace);
        $assigned->save();

        $this->assertSame($workspace->id, $assigned->fresh()->workspace->id);
        $this->assertSame([$assigned->id], $workspace->events()->pluck('id')->all());
        $this->assertNull($unassigned->fresh()->workspace);
        $this->assertDatabaseCount('workspace_users', 0);
        $this->assertSame(0, DB::table('users')->where('is_platform_admin', true)->count());
    }

    public function test_mass_assignment_cannot_set_platform_capability_or_event_ownership(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Mass assignment fixture']);
        $user = User::query()->create([
            'name' => 'Ordinary legacy administrator', 'email' => 'protected-fields@example.invalid',
            'password' => 'password', 'role' => User::ROLE_ADMIN, 'is_platform_admin' => true,
        ]);
        $this->assertFalse($user->fresh()->is_platform_admin);
        $user->fill(['is_platform_admin' => true])->save();
        $this->assertFalse($user->fresh()->is_platform_admin);

        $event = Event::query()->create([
            'event_name' => 'Unassigned mass assignment fixture', 'status' => Event::STATUS_ACTIVE,
            'workspace_id' => $workspace->id,
        ]);
        $this->assertNull($event->fresh()->workspace_id);
        $event->fill(['workspace_id' => $workspace->id])->save();
        $this->assertNull($event->fresh()->workspace_id);

        // Ignoring an untrusted downgrade must also preserve an explicitly provisioned value.
        DB::table('users')->where('id', $user->id)->update(['is_platform_admin' => true]);
        $user->refresh()->fill(['is_platform_admin' => false])->save();
        $this->assertTrue($user->fresh()->is_platform_admin);
    }

    public function test_global_admin_without_membership_retains_existing_admin_routes(): void
    {
        $admin = User::factory()->create();
        $this->assertFalse($admin->fresh()->is_platform_admin);
        $this->assertTrue($admin->workspaces->isEmpty());
        $this->actingAs($admin)->get(route('admin.events.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('workspace_users', 0);
    }

    public function test_workspace_owner_scanner_remains_scanner_only_and_device_profile_is_allowlisted(): void
    {
        $event = Event::factory()->create();
        $scanner = $this->scanner($event);
        $workspace = Workspace::query()->create(['name' => 'Scanner owner fixture']);
        $workspace->members()->attach($scanner, ['role' => 'owner', 'is_active' => true]);
        $this->actingAs($scanner)->get(route('admin.events.index'))->assertForbidden();
        $this->actingAs($scanner)->get(route('scanner.dashboard'))->assertOk();

        $this->freshGuards();
        $response = $this->postJson('https://localhost/api/v1/auth/login', [
            'identifier' => $scanner->email, 'password' => 'password', 'device_name' => 'Owner scanner fixture',
            'is_platform_admin' => true, 'workspace_id' => $workspace->id,
        ])->assertCreated()->assertJsonPath('data.user.role', User::ROLE_SCANNER)
            ->assertJsonPath('data.user.event_id', $event->id);
        $this->assertProfileFields($response->json('data.user'));
        $this->assertFalse($scanner->fresh()->is_platform_admin);
        $this->freshGuards();
        $me = $this->getJson('https://localhost/api/v1/auth/me', [
            'Authorization' => 'Bearer '.$response->json('data.access_token'),
        ])->assertOk()->assertJsonPath('data.user.role', User::ROLE_SCANNER);
        $this->assertProfileFields($me->json('data.user'));
    }

    public function test_explicit_platform_flag_is_hidden_and_does_not_reclassify_legacy_roles(): void
    {
        $event = Event::factory()->create();
        $scanner = $this->scanner($event);
        $admin = User::factory()->create();
        DB::table('users')->whereIn('id', [$scanner->id, $admin->id])->update(['is_platform_admin' => true]);

        foreach ([$scanner, $admin] as $user) {
            $user->refresh();
            $this->assertTrue($user->is_platform_admin);
            $this->assertArrayNotHasKey('is_platform_admin', $user->toArray());
            $this->assertStringNotContainsString('is_platform_admin', $user->toJson());
            $this->assertProfileFields(app(DeviceTokenService::class)->profile($user));
        }
        $this->assertTrue($scanner->isScanner());
        $this->assertFalse($scanner->isAdmin());
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isScanner());
        $this->actingAs($scanner)->get(route('admin.events.index'))->assertForbidden();
        $this->freshGuards();
        $this->actingAs($admin)->get(route('scanner.dashboard'))->assertForbidden();
    }

    public function test_membership_and_platform_flag_do_not_supply_missing_scanner_event_assignment(): void
    {
        $scanner = $this->scanner(Event::factory()->create());
        $workspace = Workspace::query()->create(['name' => 'No inferred event fixture']);
        $workspace->members()->attach($scanner, ['role' => 'owner', 'is_active' => true]);
        DB::table('users')->where('id', $scanner->id)->update(['event_id' => null, 'is_platform_admin' => true]);
        $eventsBefore = Event::count();

        $this->freshGuards();
        $this->postJson('https://localhost/api/v1/auth/login', [
            'identifier' => $scanner->email, 'password' => 'password', 'device_name' => 'Unassigned owner',
        ])->assertUnauthorized()->assertJsonPath('success', false);
        $this->assertNull($scanner->fresh()->event_id);
        $this->assertSame($eventsBefore, Event::count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertFalse(app(DeviceTokenService::class)->hasActiveAssignment($scanner->fresh()));
    }

    public function test_admin_user_create_and_update_inputs_cannot_grant_or_remove_platform_capability(): void
    {
        $admin = User::factory()->create();
        $fields = [
            'name' => 'Managed protected capability', 'email' => 'http-protected-fields@example.invalid',
            'password' => 'password', 'password_confirmation' => 'password', 'role' => User::ROLE_ADMIN,
        ];
        $this->actingAs($admin)->post(route('admin.users.store'), $fields + ['is_platform_admin' => true])
            ->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $managed = User::query()->where('email', $fields['email'])->firstOrFail();
        $this->assertFalse($managed->is_platform_admin);

        $this->actingAs($admin)->put(route('admin.users.update', $managed), $fields + ['is_platform_admin' => true])
            ->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $this->assertFalse($managed->fresh()->is_platform_admin);
        DB::table('users')->where('id', $managed->id)->update(['is_platform_admin' => true]);
        $protectedBefore = $managed->fresh()->getRawOriginal();
        $this->actingAs($admin)->put(route('admin.users.update', $managed), $fields + ['is_platform_admin' => false])
            ->assertNotFound();
        $this->assertSame($protectedBefore, $managed->fresh()->getRawOriginal());
        $this->assertTrue($managed->fresh()->is_platform_admin);
        $this->assertDatabaseCount('workspace_users', 0);
    }

    public function test_event_http_create_and_update_inputs_cannot_assign_or_transfer_workspace(): void
    {
        $admin = User::factory()->create();
        $first = Workspace::query()->create(['name' => 'First event owner fixture']);
        $second = Workspace::query()->create(['name' => 'Second event owner fixture']);
        $fields = ['event_name' => 'HTTP unassigned event fixture', 'status' => Event::STATUS_ACTIVE];
        $this->actingAs($admin)->post(route('admin.events.store'), $fields + ['workspace_id' => $first->id])
            ->assertRedirect(route('admin.dashboard'))->assertSessionHasNoErrors();
        $event = Event::query()->where('event_name', $fields['event_name'])->firstOrFail();
        $this->assertNull($event->workspace_id);
        $this->actingAs($admin)->put(route('admin.events.update', $event), $fields + ['workspace_id' => $first->id])
            ->assertRedirect(route('admin.events.index'))->assertSessionHasNoErrors();
        $this->assertNull($event->fresh()->workspace_id);

        $event->workspace()->associate($first);
        $event->save();
        $ownedEventBefore = $event->fresh()->getRawOriginal();
        foreach ([$second->id, null] as $untrustedOwner) {
            $this->actingAs($admin)->put(route('admin.events.update', $event), array_replace($fields, [
                'event_name' => 'Denied workspace metadata change', 'workspace_id' => $untrustedOwner,
            ]))->assertNotFound();
            $this->assertSame($first->id, $event->fresh()->workspace_id);
            $this->assertSame($ownedEventBefore, $event->fresh()->getRawOriginal());
        }
        $this->assertDatabaseCount('workspace_users', 0);
    }

    public function test_inventory_counts_expanded_foundation_without_inference_or_rollout_authorization(): void
    {
        $event = Event::factory()->create();
        $scanner = $this->scanner($event);
        $admin = User::factory()->create();
        $first = Workspace::query()->create(['name' => 'Private inventory workspace marker']);
        $second = Workspace::query()->create(['name' => 'Private second workspace marker']);
        $first->members()->attach($scanner, ['role' => 'owner', 'is_active' => true]);
        $second->members()->attach($admin, ['role' => 'manager', 'is_active' => false]);
        $before = $this->foundationContents();

        $this->assertSame(0, Artisan::call('tenancy:preflight', ['--connection' => 'sqlite']));
        $output = Artisan::output();
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('complete', $report['status']);
        $this->assertCount(32, $report['tables']);
        $this->assertSame(['present' => true, 'rows' => 2, 'missing_columns' => []], $report['tables']['workspaces']);
        $this->assertSame(['present' => true, 'rows' => 2, 'missing_columns' => []], $report['tables']['workspace_users']);
        $this->assertSame([], $report['tables']['events']['missing_columns']);
        $this->assertSame([], $report['tables']['users']['missing_columns']);
        $this->assertFalse($report['rollout_ready']);
        $this->assertSame([
            'ownership_mapping' => 'unverified', 'backup_restore' => 'unverified', 'mysql_rehearsal' => 'unverified',
        ], $report['gates']);
        $this->assertStringNotContainsString('Private inventory workspace marker', $output);
        $this->assertStringNotContainsString('Private second workspace marker', $output);
        $this->assertSame($before, $this->foundationContents());
        $this->assertNull($event->fresh()->workspace_id);
        $this->assertFalse($admin->fresh()->is_platform_admin);
        $this->assertFalse($scanner->fresh()->is_platform_admin);
    }

    public function test_inventory_requires_new_ownership_and_capability_columns_in_partial_schema(): void
    {
        $name = 'workspace_foundation_partial';
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $connection = DB::connection($name);
        try {
            $schema = $connection->getSchemaBuilder();
            $schema->create('workspaces', function (Blueprint $table): void {
                $table->id();
            });
            $schema->create('workspace_users', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('user_id');
                $table->string('role');
            });
            $schema->create('users', function (Blueprint $table): void {
                $table->id();
            });
            $schema->create('events', function (Blueprint $table): void {
                $table->id();
                $table->string('status');
            });
            $before = $schema->getColumnListing('events');
            $report = app(LegacyDataInventory::class)->inspect($connection);
            $this->assertSame('incomplete', $report['status']);
            $this->assertCount(32, $report['tables']);
            $this->assertSame(['name'], $report['tables']['workspaces']['missing_columns']);
            $this->assertSame(['is_active'], $report['tables']['workspace_users']['missing_columns']);
            $this->assertContains('is_platform_admin', $report['tables']['users']['missing_columns']);
            $this->assertSame(['workspace_id'], $report['tables']['events']['missing_columns']);
            $this->assertFalse($report['rollout_ready']);
            $this->assertSame($before, $schema->getColumnListing('events'));
            $this->assertSame(0, $connection->table('workspaces')->count());
            $this->assertSame(0, $connection->table('workspace_users')->count());
        } finally {
            DB::purge($name);
        }
    }

    private function scanner(Event $event): User
    {
        return User::factory()->create([
            'role' => User::ROLE_SCANNER, 'event_id' => $event->id, 'gate_name' => 'Main gate',
        ]);
    }

    private function freshGuards(): void
    {
        Auth::shouldUse('web');
        Auth::forgetGuards();
    }

    private function assertProfileFields(array $profile): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'name', 'email', 'phone_number', 'preferred_language', 'role', 'event_id', 'gate_name',
        ], array_keys($profile));
    }

    private function foundationContents(): array
    {
        $contents = [];
        foreach (['workspaces', 'workspace_users', 'users', 'events'] as $table) {
            $contents[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $contents;
    }
}
