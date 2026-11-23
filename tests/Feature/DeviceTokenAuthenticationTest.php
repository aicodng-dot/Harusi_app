<?php

namespace Tests\Feature;

use App\Models\Event as WeddingEvent;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeviceTokenAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://localhost/api/v1/auth/';

    private const USER_FIELDS = [
        'id', 'name', 'email', 'phone_number', 'preferred_language', 'role', 'event_id', 'gate_name',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.driver' => null]);
        Notification::fake();
    }

    public function test_login_stores_only_a_hash_and_returns_the_exact_identity_and_fixed_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
        $user = $this->user(['remember_token' => 'existing-browser-remember-token']);
        $password = $user->password;
        $response = $this->login($user)->assertCreated()
            ->assertJsonPath('success', true)->assertJsonPath('message', 'Signed in.')
            ->assertJsonPath('data.token_type', 'Bearer');
        $this->assertPrivate($response);
        $this->assertSame([], $response->headers->getCookies());
        $this->assertEqualsCanonicalizing(['success', 'message', 'data'], array_keys($response->json()));
        $this->assertEqualsCanonicalizing(
            ['user', 'access_token', 'token_type', 'expires_at'], array_keys($response->json('data')),
        );
        $this->assertUser($response, $user);

        $plain = $response->json('data.access_token');
        $this->assertIsString($plain);
        [$id, $secret] = explode('|', $plain, 2);
        $stored = DB::table('personal_access_tokens')->where('id', $id)->first();
        $this->assertNotNull($stored);
        $this->assertSame(hash('sha256', $secret), $stored->token);
        $this->assertNotSame($secret, $stored->token);
        $this->assertSame('Gate handset', $stored->name);
        $this->assertEqualsCanonicalizing(['auth:read', 'auth:logout'], json_decode($stored->abilities, true));
        $this->assertTrue(now()->addHours(24)->equalTo(Carbon::parse($stored->expires_at)));
        $this->assertTrue(Carbon::parse($stored->expires_at)->equalTo(Carbon::parse($response->json('data.expires_at'))));
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame('existing-browser-remember-token', $user->fresh()->remember_token);

        $me = $this->bearer('GET', 'me', $plain)->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('message', 'Authenticated.');
        $this->assertSame(['user'], array_keys($me->json('data')));
        $this->assertUser($me, $user);
        $this->assertStringNotContainsString($plain, $me->getContent());
        $this->assertPrivate($me);
    }

    #[DataProvider('phoneIdentifiers')]
    public function test_canonical_phone_variants_authenticate_the_existing_shared_identity(string $identifier): void
    {
        $user = $this->user(['phone_number' => '+255712345678']);
        $response = $this->login($user, ['identifier' => $identifier])->assertCreated();
        $this->assertUser($response, $user);
        $this->assertDatabaseCount('users', 1);
    }

    public static function phoneIdentifiers(): array
    {
        return [['0712345678'], ['255712345678'], ['+255 712 345 678']];
    }

    public function test_legacy_email_input_works_and_explicit_identifier_takes_precedence(): void
    {
        $user = $this->user();
        $this->apiLogin(['email' => $user->email, 'password' => 'password', 'device_name' => 'Legacy client'])
            ->assertCreated()->assertJsonPath('data.user.id', $user->id);
        $this->assertApiError($this->apiLogin([
            'identifier' => 'unknown@example.com', 'email' => $user->email,
            'password' => 'password', 'device_name' => 'Untrusted client',
        ]), 401);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_bad_unknown_inactive_and_unsupported_credentials_share_one_generic_response(): void
    {
        $user = $this->user();
        $baseline = $this->login($user, ['password' => 'incorrect']);
        $this->assertApiError($baseline, 401);
        $expected = $baseline->json();
        foreach (['unknown@example.com', 'not-an-identifier', '0712345679'] as $identifier) {
            $this->login($user, ['identifier' => $identifier])->assertStatus(401)->assertExactJson($expected);
        }
        $user->update(['is_active' => false]);
        $this->login($user)->assertStatus(401)->assertExactJson($expected);
        $user->forceFill(['is_active' => true, 'role' => 'unsupported'])->save();
        $this->login($user)->assertStatus(401)->assertExactJson($expected);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('malformedFields')]
    public function test_malformed_or_missing_login_fields_return_private_json_validation(string $field, mixed $value): void
    {
        $response = $this->login($this->user(), [$field => $value])->assertUnprocessable()
            ->assertJsonPath('success', false)->assertJsonValidationErrors($field);
        $this->assertPrivate($response);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function malformedFields(): array
    {
        return [
            ['identifier', []], ['identifier', str_repeat('i', 256)], ['identifier', null],
            ['password', []], ['password', null],
            ['device_name', []], ['device_name', null], ['device_name', str_repeat('d', 101)],
        ];
    }

    public function test_all_three_api_routes_refuse_plain_http_without_redirecting(): void
    {
        $user = $this->user();
        $token = $this->token($user);
        foreach (['login', 'me', 'logout'] as $action) {
            $this->freshGuards();
            $url = 'http://localhost/api/v1/auth/'.$action;
            $headers = ['Authorization' => 'Bearer '.$token];
            $response = $action === 'me' ? $this->get($url, $headers)
                : $this->post($url, ['identifier' => $user->email, 'password' => 'password', 'device_name' => 'HTTP'], $headers);
            $this->assertApiError($response, 426);
            $this->assertFalse($response->headers->has('Location'));
        }
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->bearer('GET', 'me', $token)->assertOk();
    }

    public function test_api_errors_are_json_without_accept_and_do_not_create_browser_cookies(): void
    {
        $this->freshGuards();
        $response = $this->get(self::API.'me');
        $this->assertApiError($response, 401);
        $this->assertSame([], $response->headers->getCookies());
        $this->freshGuards();
        $validation = $this->post(self::API.'login', []);
        $validation->assertUnprocessable()->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['identifier', 'password', 'device_name']);
        $this->assertPrivate($validation);
        $this->assertSame([], $validation->headers->getCookies());
    }

    public function test_login_throttle_follows_canonical_identity_across_ips(): void
    {
        // These requests model one burst even when the test host pauses.
        $this->freezeTime();
        for ($index = 0; $index < 10; $index++) {
            $this->apiLogin([
                'identifier' => $index % 2 ? '+255712345678' : '0712345678',
                'password' => 'wrong-password', 'device_name' => 'Attempt',
            ], '192.0.2.'.($index + 1))->assertUnauthorized();
        }
        $response = $this->apiLogin([
            'identifier' => '255712345678', 'password' => 'wrong-password', 'device_name' => 'Attempt',
        ], '192.0.2.100');
        $this->assertApiError($response, 429);
        $response->assertHeader('Retry-After');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_login_throttle_follows_ip_across_identifiers(): void
    {
        // These requests model one burst even when the test host pauses.
        $this->freezeTime();
        for ($index = 0; $index < 10; $index++) {
            $this->apiLogin([
                'identifier' => "missing$index@example.com", 'password' => 'wrong-password', 'device_name' => 'Attempt',
            ])->assertUnauthorized();
        }
        $response = $this->apiLogin([
            'identifier' => 'last@example.com', 'password' => 'wrong-password', 'device_name' => 'Attempt',
        ]);
        $this->assertApiError($response, 429);
        $response->assertHeader('Retry-After');
    }

    public function test_token_expires_after_twenty_four_hours_without_sliding_on_use(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
        $token = $this->token($this->user());
        $id = $this->tokenId($token);
        $expiry = DB::table('personal_access_tokens')->where('id', $id)->value('expires_at');
        $this->travel(12)->hours();
        $this->bearer('GET', 'me', $token)->assertOk();
        $this->assertSame($expiry, DB::table('personal_access_tokens')->where('id', $id)->value('expires_at'));
        $this->travel(12)->hours();
        $this->assertApiError($this->bearer('GET', 'me', $token), 401);
        $this->assertApiError($this->bearer('POST', 'logout', $token), 401);
    }

    public function test_successful_login_prunes_expired_and_oldest_tokens_only_for_its_account(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
        $user = $this->user();
        $unrelated = $this->token($this->user());
        $expired = $this->token($user);
        DB::table('personal_access_tokens')->where('id', $this->tokenId($expired))
            ->update(['expires_at' => now()->subSecond()]);
        $tokens = [];
        for ($index = 0; $index < 11; $index++) {
            $this->travel(61)->seconds();
            $tokens[] = $this->token($user, ['device_name' => 'Handset '.$index]);
        }
        $this->assertSame(10, $user->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $this->tokenId($expired)]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $this->tokenId($tokens[0])]);
        $this->assertApiError($this->bearer('GET', 'me', $tokens[0]), 401);
        $this->bearer('GET', 'me', $tokens[1])->assertOk();
        $this->bearer('GET', 'me', $tokens[10])->assertOk();
        $this->bearer('GET', 'me', $unrelated)->assertOk();
    }

    public function test_logout_revokes_only_the_current_token_and_replay_fails(): void
    {
        $user = $this->user();
        $first = $this->token($user);
        $second = $this->token($user);
        $other = $this->token($this->user());
        $response = $this->bearer('POST', 'logout', $first)->assertNoContent();
        $this->assertSame('', $response->getContent());
        $this->assertPrivate($response);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $this->tokenId($first)]);
        $this->assertApiError($this->bearer('GET', 'me', $first), 401);
        $this->assertApiError($this->bearer('POST', 'logout', $first), 401);
        $this->bearer('GET', 'me', $second)->assertOk();
        $this->bearer('GET', 'me', $other)->assertOk();
    }

    public function test_tokens_are_accepted_only_in_the_bearer_header_and_must_match_storage(): void
    {
        $token = $this->token($this->user());
        foreach ([null, 'invalid', $this->tokenId($token).'|wrong-secret', '999999|wrong-secret'] as $candidate) {
            $this->assertApiError($this->bearer('GET', 'me', $candidate), 401);
        }
        $this->freshGuards();
        $this->assertApiError($this->getJson(self::API.'me?access_token='.rawurlencode($token)), 401);
        $this->freshGuards();
        $this->assertApiError($this->postJson(self::API.'logout', ['access_token' => $token]), 401);
        $this->bearer('GET', 'me', $token)->assertOk();
    }

    public function test_browser_session_and_remember_cookie_do_not_authenticate_token_routes(): void
    {
        $user = $this->user();
        $this->freshGuards();
        $cookieName = Auth::guard('web')->getRecallerName();
        $login = $this->post('/login', ['identifier' => $user->email, 'password' => 'password', 'remember' => '1'])
            ->assertRedirect();
        $cookie = $login->getCookie($cookieName)->getValue();
        $this->assertApiError($this->bearer('GET', 'me', null), 401);
        $this->assertApiError($this->bearer('POST', 'logout', null), 401);
        session()->flush();
        $this->withCookie($cookieName, $cookie);
        $this->assertApiError($this->bearer('GET', 'me', null), 401);
        $this->assertApiError($this->bearer('POST', 'logout', null), 401);
        $this->freshGuards();
        $this->get('https://localhost/sanctum/csrf-cookie')->assertNotFound();
    }

    public function test_api_login_and_bearer_token_do_not_establish_browser_authentication(): void
    {
        $token = $this->token($this->user());
        $this->freshGuards();
        $this->assertGuest('web');
        $this->get('https://localhost/admin/dashboard', ['Authorization' => 'Bearer '.$token])->assertRedirect();
        $this->freshGuards();
        $this->getJson('https://localhost/scanner/dashboard', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->freshGuards();
        $this->assertGuest('web');
    }

    public function test_bearer_identity_wins_over_browser_identity_and_api_logout_preserves_browser_session(): void
    {
        $browser = $this->user();
        $device = $this->user();
        $this->post('/login', ['email' => $browser->email, 'password' => 'password'])->assertRedirect();
        $this->withSession(['selected_event_id' => 321]);
        $token = $this->token($device);
        $this->bearer('GET', 'me', $token)->assertOk()->assertJsonPath('data.user.id', $device->id);
        $this->bearer('POST', 'logout', $token)->assertNoContent();
        $this->freshGuards();
        $this->get('https://localhost/admin/users')->assertOk();
        $this->assertAuthenticatedAs($browser, 'web');
        $this->assertSame(321, session('selected_event_id'));
    }

    public function test_api_token_routes_do_not_require_web_csrf_even_when_csrf_is_enforced(): void
    {
        $user = $this->user();
        $this->app->instance('env', 'csrf-contract');
        try {
            $token = $this->token($user);
            $this->bearer('GET', 'me', $token)->assertOk();
            $this->bearer('POST', 'logout', $token)->assertNoContent();
            $this->freshGuards();
            $this->postJson('https://localhost/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    #[DataProvider('unavailableAccounts')]
    public function test_protected_routes_recheck_persisted_account_availability(string $change): void
    {
        $user = $this->user();
        $token = $this->token($user);
        $this->bearer('GET', 'me', $token)->assertOk();
        match ($change) {
            'inactive' => $user->update(['is_active' => false]),
            'unsupported' => $user->forceFill(['role' => 'unsupported'])->save(),
            'deleted' => $user->delete(),
        };
        $this->assertApiError($this->bearer('GET', 'me', $token), 401);
        $this->assertApiError($this->bearer('POST', 'logout', $token), 401);
    }

    public static function unavailableAccounts(): array
    {
        return [['inactive'], ['unsupported'], ['deleted']];
    }

    public function test_scanner_login_uses_only_the_persisted_active_assignment(): void
    {
        $event = WeddingEvent::factory()->create();
        $other = WeddingEvent::factory()->create();
        $scanner = $this->scanner($event);
        $eventCount = WeddingEvent::query()->count();
        $response = $this->login($scanner, ['event_id' => $other->id, 'role' => User::ROLE_ADMIN])->assertCreated();
        $this->assertUser($response, $scanner);
        $response->assertJsonPath('data.user.event_id', $event->id)->assertJsonPath('data.user.role', User::ROLE_SCANNER);
        $this->assertDatabaseCount('events', $eventCount);
    }

    #[DataProvider('unavailableEvents')]
    public function test_scanner_login_requires_an_explicit_existing_active_event(string $change): void
    {
        $event = WeddingEvent::factory()->create();
        $scanner = $this->scanner($event);
        if ($change === 'unassigned') {
            $scanner->update(['event_id' => null]);
        } else {
            $event->update(['status' => $change]);
        }
        $before = WeddingEvent::query()->count();
        $this->assertApiError($this->login($scanner), 401);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame($before, WeddingEvent::query()->count());
    }

    public static function unavailableEvents(): array
    {
        return [['unassigned'], [WeddingEvent::STATUS_ARCHIVED], [WeddingEvent::STATUS_CANCELLED]];
    }

    public function test_unavailable_event_forbids_me_but_still_allows_current_token_logout(): void
    {
        $event = WeddingEvent::factory()->create();
        $token = $this->token($this->scanner($event));
        $event->update(['status' => WeddingEvent::STATUS_ARCHIVED]);
        $this->assertApiError($this->bearer('GET', 'me', $token), 403);
        $this->bearer('POST', 'logout', $token)->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $this->tokenId($token)]);
    }

    public function test_me_reloads_assignment_without_using_browser_selected_event(): void
    {
        $first = WeddingEvent::factory()->create();
        $second = WeddingEvent::factory()->create();
        $scanner = $this->scanner($first);
        $token = $this->token($scanner);
        $this->bearer('GET', 'me', $token)->assertOk()->assertJsonPath('data.user.event_id', $first->id);
        // Direct persisted state change tests per-request eligibility, not the admin revocation boundary.
        $scanner->update(['event_id' => $second->id, 'gate_name' => 'Second gate']);
        $first->update(['status' => WeddingEvent::STATUS_ARCHIVED]);
        $this->withSession(['selected_event_id' => $first->id]);
        $this->bearer('GET', 'me', $token)->assertOk()
            ->assertJsonPath('data.user.event_id', $second->id)->assertJsonPath('data.user.gate_name', 'Second gate');
        $scanner->update(['event_id' => null]);
        $this->assertApiError($this->bearer('GET', 'me', $token), 403);
    }

    public function test_protected_actions_require_their_own_token_abilities(): void
    {
        $token = $this->token($this->user());
        $row = DB::table('personal_access_tokens')->where('id', $this->tokenId($token));
        $row->update(['abilities' => json_encode([])]);
        $this->assertApiError($this->bearer('GET', 'me', $token), 403);
        $this->assertApiError($this->bearer('POST', 'logout', $token), 403);
        $row->update(['abilities' => json_encode(['auth:read'])]);
        $this->bearer('GET', 'me', $token)->assertOk();
        $this->assertApiError($this->bearer('POST', 'logout', $token), 403);
        $row->update(['abilities' => json_encode(['auth:logout'])]);
        $this->assertApiError($this->bearer('GET', 'me', $token), 403);
        $this->bearer('POST', 'logout', $token)->assertNoContent();
    }

    public function test_client_cannot_choose_token_abilities_owner_or_expiry_and_device_name_boundary_is_accepted(): void
    {
        $user = $this->user();
        $other = $this->user();
        $response = $this->login($user, [
            'device_name' => str_repeat('d', 100), 'abilities' => ['*'], 'user_id' => $other->id,
            'expires_at' => now()->addYears(10)->toISOString(), 'token_type' => 'untrusted',
        ])->assertCreated();
        $row = DB::table('personal_access_tokens')->where('id', $this->tokenId($response->json('data.access_token')))->first();
        $this->assertEquals($user->id, $row->tokenable_id);
        $this->assertSame(str_repeat('d', 100), $row->name);
        $this->assertEqualsCanonicalizing(['auth:read', 'auth:logout'], json_decode($row->abilities, true));
        $this->assertTrue(Carbon::parse($row->expires_at)->between(now()->addHours(24)->subMinute(), now()->addHours(24)->addMinute()));
        $response->assertJsonPath('data.token_type', 'Bearer');
    }

    public function test_password_reset_revokes_all_target_tokens_and_old_password_but_preserves_other_accounts(): void
    {
        $user = $this->user();
        $first = $this->token($user);
        $second = $this->token($user);
        $other = $this->token($this->user());
        $reset = Password::broker('users')->createToken($user);
        $this->freshGuards();
        $this->post('/reset-password', $this->resetData($user, $reset))->assertRedirect(route('login'));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertApiError($this->bearer('GET', 'me', $first), 401);
        $this->assertApiError($this->bearer('GET', 'me', $second), 401);
        $this->bearer('GET', 'me', $other)->assertOk();
        $this->assertApiError($this->login($user), 401);
        $this->login($user, ['password' => 'new-password'])->assertCreated();
    }

    public function test_failed_password_reset_rolls_back_token_revocation_and_credential_changes(): void
    {
        $user = $this->user(['remember_token' => 'before-reset']);
        $token = $this->token($user);
        $hash = $user->password;
        $reset = Password::broker('users')->createToken($user);
        Event::listen(PasswordReset::class, fn () => throw new \RuntimeException('Rollback reset fixture'));
        $this->withoutExceptionHandling();
        $this->freshGuards();
        try {
            $this->post('/reset-password', $this->resetData($user, $reset));
            $this->fail('The reset must abort when its transaction fails.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('Rollback reset fixture', $failure->getMessage());
        }
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame('before-reset', $user->fresh()->remember_token);
        $this->assertTrue(Password::broker('users')->tokenExists($user->fresh(), $reset));
        $this->bearer('GET', 'me', $token)->assertOk();
    }

    public function test_administrative_password_change_revokes_target_tokens_and_preserves_optional_fields(): void
    {
        $admin = $this->user();
        $managed = $this->user(['phone_number' => '+255712345678', 'preferred_language' => 'en']);
        $first = $this->token($managed);
        $second = $this->token($managed);
        $adminToken = $this->token($admin);
        $this->adminUpdate($admin, $managed, [
            'password' => 'replacement-password', 'password_confirmation' => 'replacement-password',
        ])->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $this->assertSame(0, $managed->tokens()->count());
        $this->assertSame('+255712345678', $managed->fresh()->phone_number);
        $this->assertSame('en', $managed->fresh()->preferred_language);
        $this->assertTrue($managed->fresh()->is_active);
        $this->assertApiError($this->bearer('GET', 'me', $first), 401);
        $this->assertApiError($this->bearer('GET', 'me', $second), 401);
        $this->bearer('GET', 'me', $adminToken)->assertOk();
        $this->assertApiError($this->login($managed), 401);
        $this->login($managed, ['password' => 'replacement-password'])->assertCreated();
    }

    #[DataProvider('administrativeAuthorizationChanges')]
    public function test_administrative_availability_role_and_assignment_changes_revoke_tokens(string $change): void
    {
        $admin = $this->user();
        $firstEvent = WeddingEvent::factory()->create();
        $secondEvent = WeddingEvent::factory()->create();
        $managed = in_array($change, ['assignment', 'gate'], true) ? $this->scanner($firstEvent) : $this->user();
        $token = $this->token($managed);
        $fields = match ($change) {
            'availability' => ['is_active' => '0'],
            'role' => ['role' => User::ROLE_SCANNER, 'event_id' => $firstEvent->id, 'gate_name' => 'Assigned gate'],
            'assignment' => ['event_id' => $secondEvent->id],
            'gate' => ['gate_name' => 'Replacement gate'],
        };
        $this->adminUpdate($admin, $managed, $fields)->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $this->assertSame(0, $managed->tokens()->count());
        $this->assertApiError($this->bearer('GET', 'me', $token), 401);
    }

    public static function administrativeAuthorizationChanges(): array
    {
        return [['availability'], ['role'], ['assignment'], ['gate']];
    }

    public function test_unrelated_administrative_profile_edit_preserves_tokens_and_omitted_optional_fields(): void
    {
        $admin = $this->user();
        $managed = $this->user(['phone_number' => '+255712345678', 'preferred_language' => 'en']);
        $token = $this->token($managed);
        $hash = $managed->password;
        $this->adminUpdate($admin, $managed, ['name' => 'Updated display name'])
            ->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $this->assertSame($hash, $managed->fresh()->password);
        $this->assertSame('+255712345678', $managed->fresh()->phone_number);
        $this->assertSame('en', $managed->fresh()->preferred_language);
        $this->bearer('GET', 'me', $token)->assertOk()->assertJsonPath('data.user.name', 'Updated display name');
    }

    public function test_administrative_update_and_token_deletion_roll_back_together(): void
    {
        $admin = $this->user();
        $managed = $this->user();
        $token = $this->token($managed);
        $hash = $managed->password;
        $interrupted = false;
        DB::listen(function (QueryExecuted $query) use (&$interrupted): void {
            $sql = strtolower(str_replace(['"', '`'], '', $query->sql));
            if (! $interrupted && str_starts_with($sql, 'delete from personal_access_tokens')) {
                $interrupted = true;
                throw new \RuntimeException('Rollback administrative revocation fixture');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->adminUpdate($admin, $managed, [
                'password' => 'uncommitted-password', 'password_confirmation' => 'uncommitted-password',
            ]);
            $this->fail('Token deletion must be reached and its failure must abort the update.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('Rollback administrative revocation fixture', $failure->getMessage());
        }
        $this->assertTrue($interrupted);
        $this->assertSame($hash, $managed->fresh()->password);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $this->tokenId($token)]);
        $this->bearer('GET', 'me', $token)->assertOk();
    }

    public function test_api_errors_keep_the_envelope_and_privacy_headers_for_all_failure_paths(): void
    {
        foreach ([403, 404, 405, 419, 500] as $status) {
            Route::get('api/v1/error-probe/'.$status, fn () => abort($status, 'Sensitive internal failure details'));
            $response = $this->get('https://localhost/api/v1/error-probe/'.$status);
            $this->assertApiError($response, $status);
            $response->assertDontSee('Sensitive internal failure details');
        }
    }

    public function test_explicit_earlier_expiry_is_enforced_before_the_global_lifetime(): void
    {
        $token = $this->token($this->user());
        DB::table('personal_access_tokens')->where('id', $this->tokenId($token))
            ->update(['expires_at' => now()->subMinute()]);
        $this->assertApiError($this->bearer('GET', 'me', $token), 401);
        $this->assertApiError($this->bearer('POST', 'logout', $token), 401);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    private function scanner(WeddingEvent $event): User
    {
        return $this->user(['role' => User::ROLE_SCANNER, 'event_id' => $event->id, 'gate_name' => 'Main gate']);
    }

    private function login(User $user, array $overrides = []): TestResponse
    {
        return $this->apiLogin(array_replace([
            'identifier' => $user->email, 'password' => 'password', 'device_name' => 'Gate handset',
        ], $overrides));
    }

    private function apiLogin(array $data, string $ip = '127.0.0.1'): TestResponse
    {
        $this->freshGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(self::API.'login', $data);
    }

    private function token(User $user, array $overrides = []): string
    {
        return $this->login($user, $overrides)->assertCreated()->json('data.access_token');
    }

    private function bearer(string $method, string $action, ?string $token): TestResponse
    {
        $this->freshGuards();
        $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

        return $method === 'GET' ? $this->getJson(self::API.$action, $headers)
            : $this->postJson(self::API.$action, [], $headers);
    }

    private function freshGuards(): void
    {
        // A PHPUnit process serves several simulated requests; do not reuse its cached guard/user.
        Auth::shouldUse('web');
        Auth::forgetGuards();
    }

    private function tokenId(string $token): int
    {
        return (int) explode('|', $token, 2)[0];
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $cache = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    private function assertApiError(TestResponse $response, int $status): void
    {
        $response->assertStatus($status)->assertJsonPath('success', false);
        $this->assertEqualsCanonicalizing(['success', 'message', 'errors'], array_keys($response->json()));
        $this->assertIsString($response->json('message'));
        $this->assertEquals(new \stdClass, json_decode($response->getContent())->errors);
        $this->assertPrivate($response);
    }

    private function assertUser(TestResponse $response, User $user): void
    {
        $actual = $response->json('data.user');
        $this->assertEqualsCanonicalizing(self::USER_FIELDS, array_keys($actual));
        foreach (self::USER_FIELDS as $field) {
            $this->assertSame($user->getAttribute($field), $actual[$field], $field);
        }
    }

    private function resetData(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
    }

    private function adminUpdate(User $admin, User $managed, array $overrides): TestResponse
    {
        $this->freshGuards();

        return $this->actingAs($admin, 'web')->put(route('admin.users.update', $managed), array_replace([
            'name' => $managed->name, 'email' => $managed->email, 'role' => $managed->role,
            'event_id' => $managed->event_id, 'gate_name' => $managed->gate_name,
        ], $overrides));
    }
}
