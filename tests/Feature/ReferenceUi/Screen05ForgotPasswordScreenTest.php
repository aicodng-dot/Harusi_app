<?php

namespace Tests\Feature\ReferenceUi;

use App\Http\Controllers\PasswordRecoveryController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Screen05ForgotPasswordScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Same isolated transport as PasswordRecoveryTest: nothing leaves the process.
        config(['mail.default' => 'array', 'mail.driver' => null]);
        Notification::fake();
    }

    public function test_forgot_password_screen_renders_the_reference_form_in_swahili_by_default(): void
    {
        $response = $this->get(route('password.request'))
            ->assertOk()
            ->assertViewIs('reference_ui.11_forgot_password')
            ->assertViewHas('language', 'sw')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('<html lang="sw">', false)
            ->assertSeeInOrder([
                'PangaEvents',
                'Umesahau Nenosiri?',
                'Weka barua pepe au namba ya simu yako kupata maelekezo ya kurejesha nenosiri.',
                'Barua Pepe au Namba ya Simu',
                'Maelekezo hutumwa kwenye barua pepe iliyosajiliwa kwenye akaunti pekee, si kwa SMS.',
                'Tuma Maelekezo',
                'Rudi kwenye ukurasa wa',
                'kuingia',
            ])
            ->assertSee('method="POST" action="'.route('password.email').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="identifier" type="text"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('class="fp-back" href="'.e(route('login', ['lang' => 'sw'])).'"', false)
            ->assertSee('href="'.e(route('login', ['lang' => 'sw'])).'"><span>Rudi kwenye ukurasa wa</span>', false)
            ->assertDontSee('OTP')
            ->assertDontSee('WhatsApp');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_login_links_here_and_english_selection_persists(): void
    {
        $this->get(route('login', ['lang' => 'en']))
            ->assertSee('href="'.e(route('password.request', ['lang' => 'en'])).'"', false);

        $this->get(route('password.request', ['lang' => 'en']))
            ->assertOk()
            ->assertSessionHas('login_language', 'en')
            ->assertSeeInOrder(['Forgot Password?', 'Enter your email or phone number to get password reset instructions.', 'Email or Phone Number', 'never by SMS', 'Send Instructions', 'Back to', 'Login']);

        $english = $this->get(route('password.request'))->assertViewHas('language', 'en')->getContent();
        $this->assertMatchesRegularExpression('/class="fp-language is-active"\s+href="[^"]*lang=en"\s+lang="en"[^>]*aria-current="true"/', $english);
        $this->get(route('login'))->assertViewHas('language', 'en');
    }

    #[DataProvider('eligibleIdentifiers')]
    public function test_email_and_equivalent_phone_formats_send_one_reset_link_to_the_registered_email(string $identifier): void
    {
        $user = $this->customer();

        $this->from(route('password.request'))
            ->post(route('password.email'), ['identifier' => $identifier])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', PasswordRecoveryController::REQUEST_STATUS);

        Notification::assertCount(1);
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertStringStartsWith(route('password.reset', ['token' => $notification->token]), $url);
            $this->assertStringContainsString('email=neema.recovery%40example.invalid', $url);

            // The public page never carries the reset token.
            $this->assertStringNotContainsString($notification->token, $this->get(route('password.request'))->getContent());

            return true;
        });
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $user->email)->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function eligibleIdentifiers(): array
    {
        return [
            'email' => ['neema.recovery@example.invalid'],
            'local phone' => ['0788000001'],
            'country code phone' => ['255788000001'],
            'international phone' => ['+255788000001'],
            'spaced phone' => ['0788 000 001'],
        ];
    }

    public function test_known_unknown_and_ineligible_accounts_share_one_public_response(): void
    {
        $this->customer();
        $this->customer(['email' => 'inactive@example.invalid', 'phone_number' => '+255788000002', 'is_active' => false]);

        $pages = [];
        foreach (['nobody@example.invalid', '0799999999', 'inactive@example.invalid', 'neema.recovery@example.invalid'] as $identifier) {
            $this->from(route('password.request'))
                ->post(route('password.email'), ['identifier' => $identifier])
                ->assertRedirect(route('password.request'))
                ->assertSessionHas('status', PasswordRecoveryController::REQUEST_STATUS);
            $page = $this->get(route('password.request'))->assertOk()->getContent();
            $pages[] = str_replace(e($identifier), '', preg_replace('/name="_token" value="[^"]+"/', '', $page));
        }

        $this->assertCount(1, array_unique($pages), 'Known, unknown and ineligible requests must render identically.');
        $this->assertStringContainsString(e(__('password_recovery.accepted', [], 'sw')), $pages[0]);
        $this->assertStringContainsString('Tuma Tena', $pages[0]);
        $this->assertStringNotContainsString(PasswordRecoveryController::REQUEST_STATUS, $pages[0]);
        Notification::assertCount(1);
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
    }

    public function test_missing_and_malformed_browser_input_gets_localized_feedback_without_side_effects(): void
    {
        $this->customer();

        $this->from(route('password.request'))->post(route('password.email'), ['identifier' => '   '])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors(['identifier' => __('password_recovery.identifier_required', [], 'sw')])
            ->assertSessionMissing('status');

        $this->from(route('password.request'))->post(route('password.email'), ['identifier' => 'not-an-identifier'])
            ->assertSessionHasErrors(['identifier' => __('password_recovery.identifier_invalid', [], 'sw')]);

        $this->get(route('password.request'))
            ->assertSee(e(__('password_recovery.identifier_invalid', [], 'sw')), false)
            ->assertSee('value="not-an-identifier"', false)
            ->assertSee('aria-invalid="true"', false);

        // JSON clients keep the uniform response (PasswordRecoveryTest covers the full set).
        $this->postJson(route('password.email'), ['identifier' => 'not-an-identifier'])
            ->assertOk()->assertExactJson(['message' => PasswordRecoveryController::REQUEST_STATUS]);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_repeated_requests_stay_within_existing_broker_and_route_limits(): void
    {
        $this->freezeTime();
        $user = $this->customer();

        $this->post(route('password.email'), ['identifier' => $user->email]);
        $this->post(route('password.email'), ['identifier' => '0788000001']);
        Notification::assertCount(1);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->from(route('password.request'))->post(route('password.email'), ['identifier' => "burst$attempt@example.invalid"]);
        }

        $this->from(route('password.request'))
            ->post(route('password.email'), ['identifier' => 'burst-final@example.invalid'])
            ->assertStatus(429)
            ->assertViewIs('reference_ui.11_forgot_password')
            ->assertSee('Maombi mengi mno. Jaribu tena baada ya sekunde', false)
            ->assertSee('value="burst-final@example.invalid"', false)
            ->assertSee('action="'.route('password.email').'"', false);

        $json = $this->postJson(route('password.email'), ['identifier' => 'burst-final@example.invalid'])->assertStatus(429);
        $this->assertStringNotContainsString('data-fp-form', $json->getContent());
        Notification::assertCount(1);
    }

    public function test_delivery_failure_keeps_the_public_response_and_logs_no_sensitive_data(): void
    {
        $user = $this->customer();
        config(['mail.default' => 'log']);
        Log::shouldReceive('warning')->once()->with('Password recovery delivery unavailable.', ['reason' => 'log_mail_transport']);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['identifier' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', PasswordRecoveryController::REQUEST_STATUS);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_authenticated_visitors_keep_existing_open_access_to_recovery(): void
    {
        $this->actingAs($this->customer())->get(route('password.request'))->assertOk();
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_replace([
            'name' => 'Neema Recovery',
            'email' => 'neema.recovery@example.invalid',
            'phone_number' => '+255788000001',
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
        ], $attributes));
    }
}
