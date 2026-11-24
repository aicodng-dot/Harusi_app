<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CustomerWorkspaceCreationTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '11111111-1111-4111-8111-111111111111';

    private const NAME = 'Umoja 🌿';

    private ?string $originalDefault = null;

    private ?Connection $fixtureConnection = null;

    protected function tearDown(): void
    {
        $this->travelBack();
        if ($this->originalDefault !== null) {
            DB::setDefaultConnection($this->originalDefault);
        }
        if ($this->fixtureConnection !== null) {
            DB::purge($this->fixtureConnection->getName());
        }
        parent::tearDown();
    }

    #[DataProvider('languages')]
    public function test_private_localized_form_is_independent_escaped_and_read_only(string $language): void
    {
        [$db, $user] = $this->fixture($language);
        $before = $this->snapshot($db);
        $composed = false;
        View::composer('layouts.admin', static function () use (&$composed): void {
            $composed = true;
        });
        $writes = [];
        $response = $this->record($db, $writes, fn () => $this->actingAs($user)->get(route('customer.workspaces.create')));
        $response->assertOk()->assertViewIs('customer.workspaces.create')->assertViewHas('language', $language)
            ->assertSee(__('customer.create_intro', [], $language))->assertSee(e($user->name), false)
            ->assertDontSee($user->name, false)->assertDontSee('Private foreign workspace');
        $this->assertPrivate($response);
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($db));
        $this->assertFalse($composed);
        $key = $this->key($response);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $key);
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $form = $xpath->query('//form[@action="'.route('customer.workspaces.store').'"]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame('post', strtolower($form->getAttribute('method')));
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//label[@for="workspace-name"]', $form)->length);
        $this->assertSame('255', $xpath->query('.//input[@name="name"]', $form)->item(0)->getAttribute('maxlength'));
        $next = $this->get(route('customer.workspaces.create'));
        $this->assertNotSame($key, $this->key($next));
    }

    public static function languages(): array
    {
        return ['English' => ['en'], 'Swahili' => ['sw']];
    }

    public function test_exact_routes_have_session_role_csrf_and_post_only_limiter(): void
    {
        foreach (['create' => ['GET', 'HEAD'], 'store' => ['POST']] as $action => $methods) {
            $route = Route::getRoutes()->getByName('customer.workspaces.'.$action);
            $this->assertNotNull($route);
            $this->assertSame($methods, $route->methods());
            foreach (['web', 'auth', 'role:customer'] as $middleware) {
                $this->assertContains($middleware, $route->gatherMiddleware());
            }
            $this->assertSame($action === 'store', in_array('throttle:customer-workspace-create', $route->gatherMiddleware(), true));
        }
        // Customer surface: home, workspace create/store, the Working Flow 09 event-type step and
        // the Working Flow 10 details step (GET + throttled POSTs), all behind auth + role:customer.
        $customer = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'customer'));
        $invitation = ['customer.events.invitation', 'customer.events.invitation.continue', 'customer.events.invitation.save', 'customer.events.invitation.preview', 'customer.events.invitation.photo.store',
            'customer.events.invitation.photo', 'customer.events.invitation.thumb', 'customer.events.invitation.guests', 'customer.events.invitation.generate',
            'customer.events.invitation.send', 'customer.events.invitation.download', 'customer.events.invitation.guest', 'customer.events.invitation.card',
            'customer.events.invitation.status'];
        // Add Guest (reference 15) and Import Guests (reference 16): GET pages plus throttled POSTs.
        $guests = ['customer.events.guests.create' => null, 'customer.events.guests.store' => 'throttle:60,1',
            'customer.events.guests.import' => null, 'customer.events.guests.import.preview' => 'throttle:20,1',
            'customer.events.guests.import.confirm' => 'throttle:20,1', 'customer.events.guests.import.sample' => null,
            // Add Guests without a spreadsheet: parse-and-review, bulk save and the CSV template.
            'customer.events.guests.review' => 'throttle:60,1', 'customer.events.guests.bulk' => 'throttle:30,1', 'customer.events.guests.import.sample_csv' => null,
            // Guests page bulk delete (JSON, confirmed).
            'customer.events.guests.bulk_destroy' => 'throttle:10,1'];
        // Self-service: own profile, edit/delete own event, edit/delete own guest (method, throttle, numeric ids).
        $selfService = [
            'customer.profile' => [['GET', 'HEAD'], null, []], 'customer.profile.update' => [['PUT'], 'throttle:20,1', []],
            'customer.profile.password' => [['PUT'], 'throttle:10,1', []], 'customer.help' => [['GET', 'HEAD'], null, []],
            'customer.events.edit' => [['GET', 'HEAD'], null, ['event']], 'customer.events.update' => [['PUT'], 'throttle:30,1', ['event']],
            'customer.events.destroy' => [['DELETE'], 'throttle:10,1', ['event']],
            'customer.events.guests.edit' => [['GET', 'HEAD'], null, ['event', 'guest']], 'customer.events.guests.update' => [['PUT'], 'throttle:60,1', ['event', 'guest']],
            'customer.events.guests.destroy' => [['DELETE'], 'throttle:30,1', ['event', 'guest']],
            // Invitation Sharing Center (manual WhatsApp / SMS / share sheet; no messaging API).
            'customer.events.share' => [['GET', 'HEAD'], null, ['event']], 'customer.events.share.create' => [['GET', 'HEAD'], null, ['event']],
            'customer.events.share.select' => [['POST'], 'throttle:30,1', ['event']], 'customer.events.share.sessions.store' => [['POST'], 'throttle:20,1', ['event']],
            'customer.events.share.sessions.show' => [['GET', 'HEAD'], null, ['event', 'session']],
            'customer.events.share.sessions.progress' => [['POST'], 'throttle:240,1', ['event', 'session']],
            'customer.events.share.guest' => [['GET', 'HEAD'], null, ['event', 'guest']], 'customer.events.share.record' => [['POST'], 'throttle:240,1', ['event']],
            'customer.events.share.history' => [['GET', 'HEAD'], null, ['event']], 'customer.events.share.templates' => [['GET', 'HEAD'], null, ['event']],
            'customer.events.share.templates.store' => [['POST'], 'throttle:30,1', ['event']],
            'customer.events.share.templates.update' => [['PUT'], 'throttle:30,1', ['event', 'template']],
            'customer.events.share.templates.destroy' => [['DELETE'], 'throttle:30,1', ['event', 'template']],
            // RSVP (owner side).
            'customer.events.rsvp' => [['GET', 'HEAD'], null, ['event']], 'customer.events.rsvp.settings' => [['PUT'], 'throttle:30,1', ['event']],
            'customer.events.rsvp.record' => [['POST'], 'throttle:60,1', ['event']], 'customer.events.rsvp.export' => [['GET', 'HEAD'], 'throttle:20,1', ['event']],
        ];
        foreach ($selfService as $name => [$methods, $throttle, $ids]) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertSame($methods, $route->methods(), $name);
            foreach ($ids as $id) {
                $this->assertSame('[0-9]+', $route->wheres[$id], $name);
            }
            if ($throttle !== null) {
                $this->assertContains($throttle, $route->gatherMiddleware(), $name);
            }
        }
        $this->assertEqualsCanonicalizing(array_merge(['customer.home', 'customer.workspaces.create', 'customer.workspaces.store', 'customer.events.create', 'customer.events.type', 'customer.events.details', 'customer.events.store', 'customer.events.setup', 'customer.events.dashboard', 'customer.events.template', 'customer.events.template.store'], $invitation, array_keys($guests), array_keys($selfService)), $customer->map->getName()->values()->all());
        foreach (array_merge($invitation, array_keys($guests), array_keys($selfService)) as $name) {
            foreach (['web', 'auth', 'role:customer'] as $middleware) {
                $this->assertContains($middleware, Route::getRoutes()->getByName($name)->gatherMiddleware());
            }
        }
        foreach ($guests as $name => $throttle) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertSame(['event' => '[0-9]+'], $route->wheres);
            $this->assertSame($throttle === null ? ['GET', 'HEAD'] : ['POST'], $route->methods());
            if ($throttle !== null) {
                $this->assertContains($throttle, $route->gatherMiddleware());
            }
        }
        // Working Flow 14: template page (GET) and its throttled save (POST), numeric event id only.
        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('customer.events.template')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('customer.events.template.store')->methods());
        $this->assertContains('throttle:30,1', Route::getRoutes()->getByName('customer.events.template.store')->gatherMiddleware());
        $this->assertSame(['event' => '[0-9]+'], Route::getRoutes()->getByName('customer.events.template.store')->wheres);
        // Working Flow 12: read-only dashboard, numeric event id only.
        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('customer.events.dashboard')->methods());
        $this->assertSame(['event' => '[0-9]+'], Route::getRoutes()->getByName('customer.events.dashboard')->wheres);
        // Working Flow 11: read-only setup progress, numeric event id only.
        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('customer.events.setup')->methods());
        $this->assertSame('customer/events/{event}/setup', Route::getRoutes()->getByName('customer.events.setup')->uri());
        $this->assertSame(['event' => '[0-9]+'], Route::getRoutes()->getByName('customer.events.setup')->wheres);
        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('customer.events.create')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('customer.events.type')->methods());
        $this->assertContains('throttle:30,1', Route::getRoutes()->getByName('customer.events.type')->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('customer.events.details')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('customer.events.store')->methods());
        $this->assertContains('throttle:20,1', Route::getRoutes()->getByName('customer.events.store')->gatherMiddleware());
        foreach ($customer as $route) {
            $this->assertEmpty(array_diff(['web', 'auth', 'role:customer'], $route->gatherMiddleware()));
        }
        // The 100-route inventory gained only the public onboarding pages (Working Flows 02-03);
        // they carry no role or authority middleware.
        $onboarding = ['reference_ui.10_welcome_how_it_works.show' => ['GET', 'HEAD'], 'register' => ['GET', 'HEAD'],
            'register.store' => ['POST'], 'terms' => ['GET', 'HEAD']];
        foreach ($onboarding as $name => $methods) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertSame($methods, $route->methods());
            $this->assertSame([], array_values(preg_grep('/^(auth|role:|admin|scanner|can:)/', $route->gatherMiddleware())));
        }
        $this->assertContains('throttle:5,1', Route::getRoutes()->getByName('register.store')->gatherMiddleware());
        // + 14 invitation-workflow customer routes, the signed invitation media route, the 6 Add/Import Guest routes,
        // the 9 customer self-service routes, the customer Help page, the 7 admin customer-management routes,
        // the 13 Sharing Center routes, the 2 guest-facing invitation-link routes, 4 owner RSVP routes and the guest RSVP reply.
        $this->assertCount(100 + count($onboarding) + 8 + 14 + 1 + count($guests) + 9 + 1 + 7 + 13 + 2 + 4 + 1, Route::getRoutes());
    }

    #[DataProvider('languages')]
    public function test_customer_creates_exact_owner_setup_and_retry_ignores_injected_authority(string $language): void
    {
        [$db, $user] = $this->fixture($language);
        $before = $this->snapshot($db);
        Log::spy();
        $writes = [];
        $payload = ['name' => " \u{2003}".self::NAME."\u{00a0} ", 'request_key' => self::KEY,
            'user_id' => 222, 'creator_user_id' => 222, 'workspace_id' => 101, 'event_id' => 301,
            'role' => 'admin', 'is_platform_admin' => true, 'owner_id' => 222, 'membership_role' => 'manager'];
        $response = $this->record($db, $writes, fn () => $this->actingAs($user)->post(route('customer.workspaces.store'), $payload));
        $response->assertRedirect(route('customer.home'))->assertSessionHas('customer_workspace_status', 'created');
        $this->assertPrivate($response);
        $this->assertSame(['insert:workspaces', 'insert:workspace_users', 'insert:workspace_provisionings'], $writes);
        $workspace = $db->table('workspaces')->useWritePdo()->where('id', '!=', 101)->sole();
        $this->assertSame(self::NAME, $workspace->name);
        $id = $workspace->id;
        $member = $db->table('workspace_users')->useWritePdo()->where('workspace_id', $id)->sole();
        $this->assertSame([221, 'owner', 1], [$member->user_id, $member->role, $member->is_active]);
        $receipt = $db->table('workspace_provisionings')->useWritePdo()->sole();
        $this->assertSame([221, $id], [$receipt->creator_user_id, $receipt->workspace_id]);
        $this->assertSame(hash('sha256', "harusi.workspace.request.v1\0".self::KEY), $receipt->request_hash);
        $this->assertSame(hash('sha256', "harusi.workspace.name.v1\0".self::NAME), $receipt->name_hash);
        $after = $this->snapshot($db);
        $conserved = $after;
        foreach (['workspaces', 'workspace_users', 'workspace_provisionings'] as $table) {
            foreach ($before[$table] as $existingRow) {
                $this->assertContains($existingRow, $after[$table]);
            }
            $conserved[$table] = $before[$table];
            $this->assertCount(count($before[$table]) + 1, $after[$table]);
        }
        $this->assertSame($before, $conserved);
        $this->get(route('customer.home'))->assertOk()->assertSee(self::NAME)
            ->assertSee(__('customer.created', [], $language));
        $writes = [];
        $retry = $this->record($db, $writes, fn () => $this->postJson(route('customer.workspaces.store'), ['name' => self::NAME, 'request_key' => self::KEY]));
        $retry->assertRedirect(route('customer.home'))->assertSessionHas('customer_workspace_status', 'existing');
        $this->assertPrivate($retry);
        $this->assertSame([], $writes);
        $this->assertSame($after, $this->snapshot($db));
        $this->assertSame(0, $db->transactionLevel());
        Log::shouldNotHaveReceived('error');
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    #[DataProvider('conflicts')]
    public function test_conflict_never_repairs_owner_and_uses_a_new_form_key(string $variant): void
    {
        [$db, $user] = $this->fixture();
        $this->actingAs($user)->post(route('customer.workspaces.store'), $this->payload())->assertRedirect(route('customer.home'));
        if ($variant === 'revoked') {
            $db->table('workspace_users')->where('user_id', 221)->update(['is_active' => false]);
        }
        $before = $this->snapshot($db);
        $writes = [];
        $name = $variant === 'changed' ? '<script>changed</script>' : self::NAME;
        $response = $this->record($db, $writes, fn () => $this->postJson(route('customer.workspaces.store'), $this->payload($name)));
        $response->assertStatus(409)->assertViewIs('customer.workspaces.create')->assertViewHas('formError', 'conflict')
            ->assertSee(__('customer.conflict', [], 'en'))->assertDontSee('<script>changed</script>', false)
            ->assertDontSee('request_conflict')->assertDontSee('request_hash');
        $this->assertNotSame(self::KEY, $this->key($response));
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($db));
        $this->assertPrivate($response);
    }

    public static function conflicts(): array
    {
        return ['changed name' => ['changed'], 'revoked owner' => ['revoked']];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_private_html_without_database_mutation(array $payload, string $field, bool $keepKey): void
    {
        [$db, $user] = $this->fixture();
        $before = $this->snapshot($db);
        $writes = [];
        $response = $this->record($db, $writes, fn () => $this->actingAs($user)->postJson(route('customer.workspaces.store'), $payload));
        $response->assertStatus(422)->assertViewIs('customer.workspaces.create')
            ->assertViewHas('errors', fn ($errors): bool => $errors->has($field));
        $this->assertSame($keepKey, $this->key($response) === self::KEY);
        $this->assertPrivate($response);
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshot($db));
    }

    public static function invalidInputs(): array
    {
        $good = ['name' => self::NAME, 'request_key' => self::KEY];

        return [
            'name missing' => [['request_key' => self::KEY], 'name', true],
            'name array' => [array_replace($good, ['name' => ['<script>']]), 'name', true],
            'name bool' => [array_replace($good, ['name' => true]), 'name', true],
            'name null' => [array_replace($good, ['name' => null]), 'name', true],
            'unicode blank' => [array_replace($good, ['name' => "\u{2003}\u{00a0}"]), 'name', true],
            'name too long' => [array_replace($good, ['name' => str_repeat('界', 256)]), 'name', true],
            'embedded control' => [array_replace($good, ['name' => "A\nB"]), 'name', true],
            'invisible format' => [array_replace($good, ['name' => "A\u{200b}B"]), 'name', true],
            'key missing' => [['name' => self::NAME], 'request_key', false],
            'key array' => [array_replace($good, ['request_key' => ['bad']]), 'request_key', false],
            'key null' => [array_replace($good, ['request_key' => null]), 'request_key', false],
            'key invalid' => [array_replace($good, ['request_key' => 'private-not-a-uuid']), 'request_key', false],
            'key wrong version' => [array_replace($good, ['request_key' => '11111111-1111-1111-8111-111111111111']), 'request_key', false],
        ];
    }

    #[DataProvider('accountRoles')]
    public function test_staff_scanner_and_unsupported_roles_cannot_use_setup(string $role): void
    {
        [$db, $user] = $this->fixture();
        $db->table('users')->where('id', 221)->update(['role' => $role]);
        $before = $this->snapshot($db);
        $user = User::query()->useWritePdo()->findOrFail(221);
        $get = $this->actingAs($user)->get(route('customer.workspaces.create'));
        $role === 'unsupported' ? $get->assertRedirect(route('login')) : $get->assertForbidden();
        $this->postJson(route('customer.workspaces.store'), $this->payload())->assertStatus($role === 'unsupported' ? 401 : 403);
        $this->assertSame($before['workspaces'], $this->snapshot($db)['workspaces']);
        $this->assertSame($before['workspace_users'], $this->snapshot($db)['workspace_users']);
        $this->assertSame([], $this->snapshot($db)['workspace_provisionings']);
    }

    public static function accountRoles(): array
    {
        return ['admin' => ['admin'], 'scanner' => ['scanner'], 'unsupported' => ['unsupported']];
    }

    #[DataProvider('lockedChanges')]
    public function test_creator_state_changed_after_gateway_is_refused_under_the_creation_lock(array $change): void
    {
        [$db, $user] = $this->fixture();
        $changed = false;
        $expected = null;
        $db->beforeStartingTransaction(function () use ($db, $change, &$changed, &$expected): void {
            if (! $changed) {
                $changed = true;
                $db->table('users')->where('id', 221)->update($change);
                $expected = $this->snapshot($db);
            }
        });
        $response = $this->actingAs($user)->post(route('customer.workspaces.store'), $this->payload());
        $response->assertForbidden()->assertSee(__('customer.forbidden', [], 'en'));
        $this->assertTrue($changed);
        $this->assertSame($expected, $this->snapshot($db));
        $this->assertPrivate($response);
        $this->assertSame(0, $db->transactionLevel());
    }

    public static function lockedChanges(): array
    {
        return ['admin after gateway' => [['role' => 'admin']], 'scanner after gateway' => [['role' => 'scanner']],
            'inactive after gateway' => [['is_active' => false]]];
    }

    #[DataProvider('insertStages')]
    public function test_failure_after_each_insert_rolls_back_and_preserves_form_for_retry(int $stage): void
    {
        [$db, $user] = $this->fixture();
        $before = $this->snapshot($db);
        Log::spy();
        $writes = [];
        $failed = $this->record($db, $writes, fn () => $this->actingAs($user)->post(route('customer.workspaces.store'), $this->payload()), $stage);
        $failed->assertStatus(503)->assertViewIs('customer.workspaces.create')->assertViewHas('formError', 'unavailable')
            ->assertViewHas('name', self::NAME)->assertSee(__('customer.unavailable', [], 'en'))
            ->assertDontSee('private-failed-sql')->assertDontSee('SQLSTATE')->assertDontSee('request_hash');
        $this->assertSame(self::KEY, $this->key($failed));
        $this->assertCount($stage, $writes);
        $this->assertSame($before, $this->snapshot($db));
        $this->assertSame(0, $db->transactionLevel());
        $this->assertPrivate($failed);
        $this->post(route('customer.workspaces.store'), $this->payload())->assertRedirect(route('customer.home'));
        $this->assertSame(1, $db->table('workspace_provisionings')->useWritePdo()->count());
        Log::shouldNotHaveReceived('error');
    }

    public static function insertStages(): array
    {
        return ['workspace inserted' => [1], 'membership inserted' => [2], 'receipt inserted' => [3]];
    }

    public function test_invalid_prerequisites_are_safe_503_and_do_not_create_a_partial_workspace(): void
    {
        [$db, $user] = $this->fixture();
        $db->getSchemaBuilder()->disableForeignKeyConstraints();
        $before = $this->snapshot($db);
        $writes = [];
        try {
            $response = $this->record($db, $writes, fn () => $this->actingAs($user)->post(route('customer.workspaces.store'), $this->payload()));
            $response->assertStatus(503)->assertViewHas('formError', 'unavailable');
            $this->assertSame(self::KEY, $this->key($response));
            $this->assertSame([], $writes);
            $this->assertSame($before, $this->snapshot($db));
            $this->assertPrivate($response);
        } finally {
            $db->getSchemaBuilder()->enableForeignKeyConstraints();
        }
    }

    public function test_customer_http_creation_and_retry_never_use_unavailable_read_pdo(): void
    {
        [$db, $user] = $this->fixture();
        $attempts = 0;
        $read = $db->getReadPdo();
        $db->setReadPdo(static function () use (&$attempts): never {
            $attempts++;
            throw new RuntimeException('private-replica-unavailable');
        });
        try {
            $this->actingAs($user)->post(route('customer.workspaces.store'), $this->payload())->assertRedirect(route('customer.home'));
            $this->post(route('customer.workspaces.store'), $this->payload())->assertRedirect(route('customer.home'))
                ->assertSessionHas('customer_workspace_status', 'existing');
        } finally {
            $db->setReadPdo($read);
        }
        $this->assertSame(0, $attempts);
        $this->assertSame(1, $db->table('workspace_provisionings')->useWritePdo()->count());
    }

    public function test_guest_disabled_and_unsafe_cached_customer_cannot_create(): void
    {
        [$db, $user] = $this->fixture();
        $before = $this->snapshot($db);
        $this->get(route('customer.workspaces.create'))->assertRedirect(route('login'));
        $this->postJson(route('customer.workspaces.store'), $this->payload())->assertUnauthorized();
        $db->table('users')->where('id', 221)->update(['is_active' => false]);
        $this->actingAs($user)->postJson(route('customer.workspaces.store'), $this->payload())->assertUnauthorized();
        $db->table('users')->where('id', 221)->update(['is_active' => true]);
        $user->setRawAttributes(array_replace($user->getAttributes(), ['id' => '9223372036854775808']), true);
        $this->actingAs($user)->postJson(route('customer.workspaces.store'), $this->payload())->assertUnauthorized();
        foreach (['workspaces', 'workspace_users', 'workspace_provisionings', 'events', 'guests', 'qr_codes', 'personal_access_tokens'] as $table) {
            $this->assertSame($before[$table], $this->snapshot($db)[$table]);
        }
    }

    public function test_real_csrf_mismatch_and_expired_session_cannot_provision(): void
    {
        [$db, $user] = $this->fixture();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $before = $this->snapshot($db);
        $this->actingAs($user)->withSession(['_token' => 'synthetic-session-token'])
            ->postJson(route('customer.workspaces.store'), $this->payload() + ['_token' => 'wrong'])
            ->assertStatus(419)->assertExactJson(['message' => 'Your session expired. Refresh the page and try that action again.']);
        $this->post(route('customer.workspaces.store'), $this->payload() + ['_token' => 'wrong'])->assertRedirect(route('login'));
        $this->assertSame($before, $this->snapshot($db));
        $this->post(route('customer.workspaces.store'), $this->payload() + ['_token' => 'synthetic-session-token'])
            ->assertRedirect(route('customer.home'));
        Auth::forgetGuards();
        session()->flush();
        $after = $this->snapshot($db);
        $this->withSession(['_token' => 'new-session-token'])->postJson(route('customer.workspaces.store'),
            $this->payload() + ['_token' => 'new-session-token'])->assertUnauthorized();
        $this->assertSame($after, $this->snapshot($db));
    }

    public function test_account_throttle_is_shared_across_ips_and_resets_after_one_minute(): void
    {
        [$db, $user] = $this->fixture();
        $this->travelTo(now()->setDate(2026, 10, 23)->startOfDay());
        $this->actingAs($user);
        for ($number = 1; $number <= 5; $number++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$number])
                ->post(route('customer.workspaces.store'), $this->payload())->assertRedirect(route('customer.home'));
        }
        $before = $this->snapshot($db);
        $limited = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->post(route('customer.workspaces.store'), $this->payload());
        $limited->assertStatus(429)->assertHeader('Retry-After')->assertSee(__('customer.throttled', [], 'en'));
        $this->assertPrivate($limited);
        $this->assertSame($before, $this->snapshot($db));
        $this->travel(61)->seconds();
        $this->post(route('customer.workspaces.store'), $this->payload())->assertRedirect(route('customer.home'))
            ->assertSessionHas('customer_workspace_status', 'existing');
    }

    public function test_ip_throttle_is_shared_across_accounts_without_caller_supplied_identity(): void
    {
        [$db] = $this->fixture('sw');
        $this->travelTo(now()->setDate(2026, 10, 23)->startOfDay());
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.200']);
        for ($id = 221; $id <= 224; $id++) {
            $this->actingAs(User::query()->useWritePdo()->findOrFail($id));
            for ($number = 0; $number < 5; $number++) {
                $this->post(route('customer.workspaces.store'), $this->payload())->assertRedirect(route('customer.home'));
            }
        }
        $before = $this->snapshot($db);
        $response = $this->actingAs(User::query()->useWritePdo()->findOrFail(225))->postJson(route('customer.workspaces.store'),
            $this->payload() + ['user_id' => 99999]);
        $response->assertStatus(429)->assertHeader('Retry-After')->assertSee(__('customer.throttled', [], 'sw'));
        $this->assertPrivate($response);
        $this->assertSame($before, $this->snapshot($db));
    }

    private function payload(string $name = self::NAME): array
    {
        return ['name' => $name, 'request_key' => self::KEY];
    }

    private function key(TestResponse $response): string
    {
        return $response->viewData('requestKey');
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    private function record(Connection $db, array &$writes, callable $callback, ?int $failAfter = null): mixed
    {
        $original = $db->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$writes, $failAfter): void {
            if (preg_match('/\A\s*(insert|update|delete)\s+(?:into\s+|from\s+)?["`]?([a-z_]+)/i', $event->sql, $match)) {
                $writes[] = strtolower($match[1]).':'.$match[2];
                if ($failAfter !== null && count($writes) === $failAfter) {
                    throw new RuntimeException('private-failed-sql-do-not-display');
                }
            }
        });
        $db->setEventDispatcher($dispatcher);
        try {
            return $callback();
        } finally {
            $db->setEventDispatcher($original);
        }
    }

    private function fixture(string $language = 'en'): array
    {
        $source = DB::connection();
        $this->originalDefault = DB::getDefaultConnection();
        $name = 'customer_workspace_creation';
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        $db = DB::connection($name);
        $this->fixtureConnection = $db;
        $objects = $source->selectFromWriteConnection("SELECT type, name, sql FROM sqlite_master WHERE type IN ('table','index') AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name");
        foreach ($objects as $object) {
            $db->statement($object->sql);
        }
        foreach ($objects as $object) {
            if ($object->type === 'table') {
                foreach ($source->table($object->name)->useWritePdo()->get() as $row) {
                    $db->table($object->name)->insert((array) $row);
                }
            }
        }
        $db->getSchemaBuilder()->enableForeignKeyConstraints();
        DB::setDefaultConnection($name);
        for ($id = 221; $id <= 225; $id++) {
            $db->table('users')->insert(['id' => $id, 'name' => '<script>Account '.$id.'</script>',
                'email' => 'synthetic-'.$id.'@example.invalid', 'role' => 'customer', 'is_active' => true,
                'preferred_language' => $language, 'password' => bcrypt('synthetic-password'),
                'remember_token' => 'synthetic-remember-'.$id, 'is_platform_admin' => false]);
        }
        $db->table('workspaces')->insert(['id' => 101, 'name' => 'Private foreign workspace']);
        $db->table('workspace_users')->insert(['workspace_id' => 101, 'user_id' => 222, 'role' => 'owner', 'is_active' => true]);
        $db->table('events')->insert(['id' => 301, 'workspace_id' => 101, 'event_name' => 'Private foreign event', 'status' => 'active']);
        $db->table('guests')->insert(['id' => 401, 'event_id' => 301, 'name' => 'Private foreign guest', 'phone_number' => '+255712345678',
            'manual_code' => '0042', 'pass_type' => 'special', 'allowed_entries' => 3, 'used_entries' => 1, 'status' => 'partially_used']);
        $db->table('qr_codes')->insert(['id' => 501, 'guest_id' => 401, 'qr_token' => str_repeat('a', 64), 'qr_image_path' => 'private/never-open.png']);
        $user = User::query()->useWritePdo()->findOrFail(221);
        $user->createToken('synthetic-customer-device-token');
        $db->table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'synthetic-hash', 'created_at' => now()]);
        Mail::fake();
        Notification::fake();
        Http::fake();
        $this->assertSame(0, $db->transactionLevel());

        return [$db, $user];
    }

    private function snapshot(Connection $db): array
    {
        $result = [];
        foreach ($db->selectFromWriteConnection("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $rows = array_map(static fn (object $row): array => (array) $row, $db->table($table->name)->useWritePdo()->get()->all());
            usort($rows, static fn ($left, $right): int => strcmp(json_encode($left), json_encode($right)));
            $result[$table->name] = $rows;
        }

        return $result;
    }
}
