<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\LoginIdentifier;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

class SendPasswordResetLink
{
    public function __construct(private readonly ?string $identifier) {}

    public function handle(): void
    {
        try {
            $reason = config('mail.driver')
                ? 'legacy_mail_configuration'
                : $this->unsafeMailerReason((string) config('mail.default'));

            if ($reason !== null) {
                $this->recordFailure(['reason' => $reason]);

                return;
            }

            $identity = app(LoginIdentifier::class)->resolve($this->identifier);

            // The broker retains its timebox, token expiry and issuance throttle.
            // It sends only to the matched user's registered email, even when
            // the lookup used a phone. Invalid input still follows this path.
            Password::broker('users')->sendResetLink(($identity ?? ['email' => '', 'id' => -1]) + [
                'is_active' => true,
                'role' => [User::ROLE_ADMIN, User::ROLE_SCANNER, User::ROLE_CUSTOMER],
            ]);
        } catch (Throwable $exception) {
            // Never pass the exception object, message or trace to the logger:
            // transport errors can contain recipients and the complete reset URL.
            $this->recordFailure(['exception_type' => $exception::class]);
        }
    }

    private function unsafeMailerReason(string $name, array $ancestors = []): ?string
    {
        if (in_array($name, $ancestors, true)) {
            return 'cyclic_mail_configuration';
        }

        $configuration = config('mail.mailers.'.$name);

        if (! is_array($configuration)) {
            return 'missing_mail_configuration';
        }

        // Match Laravel MailManager's URL override precedence, including the
        // driver query parameter. Checking only the transport key is insufficient.
        if (isset($configuration['url'])) {
            $configuration = array_merge($configuration, (new ConfigurationUrlParser)->parseConfiguration($configuration));
            $configuration['transport'] = $configuration['driver'] ?? null;
        }

        $transport = $configuration['transport'] ?? null;

        if ($transport === 'log') {
            return 'log_mail_transport';
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach ($configuration['mailers'] ?? [] as $child) {
                $reason = is_string($child)
                    ? $this->unsafeMailerReason($child, [...$ancestors, $name])
                    : 'invalid_mail_configuration';

                if ($reason !== null) {
                    return $reason;
                }
            }

            // Symfony's composite transports log caught exceptions internally.
            return 'composite_mail_transport';
        }

        return in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'array'], true)
            ? null
            : 'unsupported_mail_transport';
    }

    private function recordFailure(array $context): void
    {
        try {
            Log::warning('Password recovery delivery unavailable.', $context);
        } catch (Throwable) {
            // A failing logger must not expose the original failure through the
            // deferred callback's automatic exception reporting.
        }
    }
}
