<?php

namespace Tests\Feature;

use App\Http\Controllers\PasswordRecoveryController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class PasswordRecoveryMailRenderingTest extends TestCase
{
    use RefreshDatabase;

    private ArrayTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercise the real notification, Markdown, MIME and envelope pipeline.
        // Every configured transport is in memory; no notification/mail fake or
        // external delivery service participates in these tests.
        config([
            'app.name' => 'Kadify',
            'app.url' => 'https://trusted.example/kadify',
            'mail.default' => 'array',
            'mail.driver' => null,
            'mail.mailers' => ['array' => ['transport' => 'array']],
            'mail.from' => ['address' => 'no-reply@trusted.example', 'name' => 'Kadify'],
        ]);
        $this->app->setLocale('en');
        Mail::purge('array');
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->transport = $transport;
    }

    #[DataProvider('recoveryIdentifiers')]
    public function test_registered_recipient_receives_a_rendered_valid_reset_link(string $identifier): void
    {
        $user = User::factory()->create([
            'name' => 'Recovery Fixture',
            'email' => 'reset+fixture@example.com',
            'phone_number' => '+255712345678',
        ]);

        // A conflicting email and request host must not change either the
        // registered recipient or the configured reset-link origin.
        $response = $this->postJson('https://untrusted.example/forgot-password', [
            'identifier' => $identifier,
            'email' => 'unregistered@example.com',
        ])->assertOk()->assertExactJson(['message' => PasswordRecoveryController::REQUEST_STATUS]);

        $this->assertCount(1, $this->transport->messages());
        $sent = $this->transport->messages()->sole();
        $this->assertInstanceOf(SentMessage::class, $sent);
        $message = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);
        $this->assertSame([$user->email], array_map(
            fn (Address $recipient): string => $recipient->getAddress(), $message->getTo(),
        ));
        $this->assertSame([$user->email], array_map(
            fn (Address $recipient): string => $recipient->getAddress(), $sent->getEnvelope()->getRecipients(),
        ));
        $this->assertSame([], $message->getCc());
        $this->assertSame([], $message->getBcc());
        $this->assertSame('Reset Password Notification', $message->getSubject());
        $this->assertSame('no-reply@trusted.example', $sent->getEnvelope()->getSender()->getAddress());

        $html = $message->getHtmlBody();
        $plain = $message->getTextBody();
        $this->assertIsString($html);
        $this->assertIsString($plain);
        $this->assertStringContainsString('Reset Password', $html);
        $this->assertStringContainsString('Reset Password', $plain);

        preg_match_all('/href="([^"]+)"/', $html, $matches);
        $links = array_map(fn (string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches[1]);
        $resetLinks = array_values(array_filter($links, fn (string $url): bool => str_starts_with(
            $url, 'https://trusted.example/kadify/reset-password/',
        )));
        $this->assertNotEmpty($resetLinks, 'The rendered HTML must contain an actionable reset link.');

        $url = $resetLinks[0];
        $token = rawurldecode(basename(parse_url($url, PHP_URL_PATH)));
        $this->assertNotEmpty($token);
        $this->assertSame(
            'https://trusted.example/kadify/reset-password/'.rawurlencode($token).'?email=reset%2Bfixture%40example.com',
            $url,
        );
        $this->assertStringContainsString($url, $plain);
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertNotSame($token, DB::table('password_reset_tokens')->where('email', $user->email)->value('token'));
        foreach ([$html, $plain] as $body) {
            $this->assertStringNotContainsString('untrusted.example', $body);
            $this->assertStringNotContainsString('unregistered@example.com', $body);
        }
        $this->assertStringNotContainsString($token, $response->getContent());

        // Force serialization too, so the check includes real multipart MIME
        // construction rather than inspecting only a MailMessage view model.
        $wireMessage = $sent->toString();
        $this->assertStringContainsString('Content-Type: multipart/alternative', $wireMessage);
        $this->assertStringContainsString('Content-Type: text/html', $wireMessage);
        $this->assertStringContainsString('Content-Type: text/plain', $wireMessage);
    }

    public static function recoveryIdentifiers(): array
    {
        return [
            'registered email' => ['reset+fixture@example.com'],
            'canonicalized phone' => ['0712345678'],
        ];
    }

    public function test_crlf_in_a_legacy_registered_recipient_is_rejected_without_delivery_or_sensitive_logging(): void
    {
        // Model creation deliberately represents a corrupt legacy/imported
        // address. Phone lookup reaches the real recipient pipeline, rather
        // than stopping at LoginIdentifier's email-input validation. Laravel's
        // mail message rejects line breaks before Symfony constructs MIME.
        $user = User::factory()->create([
            'email' => "\"recovery\r\nBcc: attacker@example.test\"@example.com",
            'phone_number' => '+255712345678',
        ]);
        Log::shouldReceive('warning')->once()->with(
            'Password recovery delivery unavailable.',
            ['exception_type' => InvalidArgumentException::class],
        );

        $this->postJson('https://localhost/forgot-password', ['identifier' => '0712345678'])
            ->assertOk()->assertExactJson(['message' => PasswordRecoveryController::REQUEST_STATUS]);

        $this->assertCount(0, $this->transport->messages());
        // Token creation proves that account lookup succeeded and mail rejected
        // the recipient afterward; delivery failures retain the broker token.
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }
}
