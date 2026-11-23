<?php

namespace App\Services\Notifications;

use App\Models\Guest;
use App\Models\NotificationLog;
use App\Services\PhoneNumberService;

class NotificationService
{
    private WhatsappProviderInterface $provider;

    public function __construct(
        private PhoneNumberService $phoneNumbers,
        ?WhatsappProviderInterface $provider = null,
    ) {
        $this->provider = $provider ?? $this->makeProvider();
    }

    public function provider(): WhatsappProviderInterface
    {
        return $this->provider;
    }

    public function renderTemplate(string $templateKey, array $values = []): string
    {
        $template = (string) config('notification_templates.messages.'.$templateKey, '');
        $supportedPlaceholders = config('notification_templates.supported_placeholders', []);
        $replacements = [];

        foreach ($supportedPlaceholders as $placeholder) {
            $replacements['{'.$placeholder.'}'] = $this->stringValue($values[$placeholder] ?? '');
        }

        return strtr($template, $replacements);
    }

    public function sendWhatsappTemplate($phoneNumber, $templateName, $parameters = [], $context = []): NotificationLog
    {
        return $this->sendWithProvider(
            method: 'template',
            phoneNumber: (string) $phoneNumber,
            templateName: (string) $templateName,
            parameters: is_array($parameters) ? $parameters : [$parameters],
            message: null,
            mediaUrl: null,
            caption: '',
            context: is_array($context) ? $context : []
        );
    }

    public function sendWhatsappText($phoneNumber, $message, $context = []): NotificationLog
    {
        return $this->sendWithProvider(
            method: 'text',
            phoneNumber: (string) $phoneNumber,
            templateName: null,
            parameters: [],
            message: (string) $message,
            mediaUrl: null,
            caption: '',
            context: is_array($context) ? $context : []
        );
    }

    public function sendWhatsappMedia($phoneNumber, $mediaUrl, $caption = '', $context = []): NotificationLog
    {
        return $this->sendWithProvider(
            method: 'media',
            phoneNumber: (string) $phoneNumber,
            templateName: null,
            parameters: [],
            message: (string) $caption,
            mediaUrl: (string) $mediaUrl,
            caption: (string) $caption,
            context: array_merge(is_array($context) ? $context : [], ['media_url' => (string) $mediaUrl])
        );
    }

    public function sendGuestCodeNotification(Guest $guest): NotificationLog
    {
        return $this->sendConfiguredWhatsappNotification(
            guest: $guest,
            messageType: NotificationLog::TYPE_GUEST_CODE,
            messageTemplateKey: 'guest_code_whatsapp',
            whatsappTemplateKey: 'guest_code'
        );
    }

    public function sendPledgeCreatedNotification(Guest $guest, $pledge = null): NotificationLog
    {
        return $this->sendConfiguredWhatsappNotification(
            guest: $guest,
            messageType: NotificationLog::TYPE_PLEDGE_CREATED,
            messageTemplateKey: 'pledge_created_whatsapp',
            whatsappTemplateKey: 'pledge_created',
            context: $this->relatedContext('pledge', $pledge)
        );
    }

    public function sendPledgeReminderNotification(Guest $guest, $pledge = null): NotificationLog
    {
        return $this->sendConfiguredWhatsappNotification(
            guest: $guest,
            messageType: NotificationLog::TYPE_PLEDGE_REMINDER,
            messageTemplateKey: 'pledge_reminder_whatsapp',
            whatsappTemplateKey: 'pledge_reminder',
            context: $this->relatedContext('pledge', $pledge)
        );
    }

    public function sendPledgeCompletedNotification(Guest $guest, $pledge = null): NotificationLog
    {
        return $this->sendConfiguredWhatsappNotification(
            guest: $guest,
            messageType: NotificationLog::TYPE_PLEDGE_COMPLETED,
            messageTemplateKey: 'pledge_completed_whatsapp',
            whatsappTemplateKey: 'pledge_completed',
            context: $this->relatedContext('pledge', $pledge)
        );
    }

    public function sendNextMeetingNotification(Guest $guest, $meeting = null): NotificationLog
    {
        return $this->sendConfiguredWhatsappNotification(
            guest: $guest,
            messageType: NotificationLog::TYPE_NEXT_MEETING,
            messageTemplateKey: 'next_meeting_whatsapp',
            whatsappTemplateKey: 'next_meeting',
            context: $this->relatedContext('meeting', $meeting)
        );
    }

    public function sendDigitalCardNotification(Guest $guest, $card = null): NotificationLog
    {
        return $this->sendConfiguredWhatsappNotification(
            guest: $guest,
            messageType: NotificationLog::TYPE_DIGITAL_CARD,
            messageTemplateKey: 'digital_card_whatsapp',
            whatsappTemplateKey: 'digital_card_ready',
            context: $this->relatedContext('card', $card)
        );
    }

    public function sendThankYouNotification(Guest $guest, $context = []): NotificationLog
    {
        return $this->sendConfiguredWhatsappNotification(
            guest: $guest,
            messageType: NotificationLog::TYPE_THANK_YOU,
            messageTemplateKey: 'thank_you_whatsapp',
            whatsappTemplateKey: 'thank_you',
            context: is_array($context) ? $context : []
        );
    }

    public function sendCongratulationsNotification(Guest $guest, $context = []): NotificationLog
    {
        return $this->sendConfiguredWhatsappNotification(
            guest: $guest,
            messageType: NotificationLog::TYPE_CONGRATULATIONS,
            messageTemplateKey: 'congratulations_whatsapp',
            whatsappTemplateKey: 'congratulations',
            context: is_array($context) ? $context : []
        );
    }

    private function sendConfiguredWhatsappNotification(
        Guest $guest,
        string $messageType,
        string $messageTemplateKey,
        string $whatsappTemplateKey,
        array $context = [],
    ): NotificationLog {
        $context = $this->guestContext($guest, $messageType, array_merge($context, [
            'template_key' => $messageTemplateKey,
        ]));
        $values = $this->placeholderValues($guest, $context);

        return $this->sendWithProvider(
            method: 'template',
            phoneNumber: $guest->phone_number,
            templateName: $this->whatsappTemplateName($whatsappTemplateKey),
            parameters: $this->parametersForTemplate($messageTemplateKey, $values),
            message: $this->renderTemplate($messageTemplateKey, $values),
            mediaUrl: null,
            caption: '',
            context: $context
        );
    }

    private function sendWithProvider(
        string $method,
        string $phoneNumber,
        ?string $templateName,
        array $parameters,
        ?string $message,
        ?string $mediaUrl,
        string $caption,
        array $context,
    ): NotificationLog {
        $context = $this->sanitizeContext($context);
        $normalizedPhoneNumber = $this->phoneNumbers->normalize($phoneNumber);

        if ($normalizedPhoneNumber === null) {
            return $this->createLog(
                phoneNumber: trim($phoneNumber),
                templateName: $templateName,
                message: $message,
                status: NotificationLog::STATUS_FAILED,
                context: $context,
                errorMessage: PhoneNumberService::INVALID_MESSAGE
            );
        }

        $log = $this->createLog(
            phoneNumber: $normalizedPhoneNumber,
            templateName: $templateName,
            message: $message,
            status: NotificationLog::STATUS_PENDING,
            context: $context
        );

        $sent = match ($method) {
            'template' => $this->provider->sendTemplate($normalizedPhoneNumber, (string) $templateName, $parameters, $context),
            'media' => $this->provider->sendMedia($normalizedPhoneNumber, (string) $mediaUrl, $caption, $context),
            default => $this->provider->sendText($normalizedPhoneNumber, (string) $message, $context),
        };

        if ($this->provider instanceof NullWhatsappProvider) {
            $log->update([
                'status' => $this->provider->lastStatus(),
                'error_message' => $this->provider->lastError(),
            ]);

            return $log->fresh();
        }

        $log->update([
            'status' => $sent ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED,
            'provider_message_id' => $this->provider instanceof MetaWhatsappProvider
                ? $this->provider->lastMessageId()
                : null,
            'error_message' => $sent
                ? null
                : ($this->provider instanceof MetaWhatsappProvider ? $this->provider->lastError() : 'WhatsApp provider send failed.'),
            'sent_at' => $sent ? now() : null,
        ]);

        return $log->fresh();
    }

    private function createLog(
        string $phoneNumber,
        ?string $templateName,
        ?string $message,
        string $status,
        array $context,
        ?string $errorMessage = null,
    ): NotificationLog {
        return NotificationLog::query()->create([
            'guest_id' => $context['guest_id'] ?? null,
            'event_id' => $context['event_id'] ?? null,
            'channel' => NotificationLog::CHANNEL_WHATSAPP,
            'message_type' => $this->messageType($context),
            'template_name' => $templateName,
            'recipient_phone' => $this->recipientPhoneForLog($phoneNumber),
            'message' => $message,
            'status' => $status,
            'provider' => NotificationLog::PROVIDER_META,
            'provider_message_id' => null,
            'error_message' => $errorMessage,
            'context' => $context,
            'sent_at' => null,
        ]);
    }

    private function makeProvider(): WhatsappProviderInterface
    {
        $provider = strtolower((string) config('services.whatsapp.provider', 'meta'));
        $config = config('services.whatsapp.meta', []);

        if ($provider === NotificationLog::PROVIDER_META && $this->metaCredentialsAreConfigured($config)) {
            return new MetaWhatsappProvider($config);
        }

        return new NullWhatsappProvider();
    }

    private function metaCredentialsAreConfigured(array $config): bool
    {
        return $this->filled($config['access_token'] ?? null)
            && $this->filled($config['phone_number_id'] ?? null)
            && $this->filled($config['api_base_url'] ?? null)
            && $this->filled($config['api_version'] ?? null);
    }

    private function filled(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }

    private function messageType(array $context): string
    {
        $messageType = (string) ($context['message_type'] ?? NotificationLog::TYPE_GENERAL_UPDATE);

        return in_array($messageType, NotificationLog::messageTypes(), true)
            ? $messageType
            : NotificationLog::TYPE_GENERAL_UPDATE;
    }

    private function recipientPhoneForLog(string $phoneNumber): string
    {
        $phoneNumber = trim($phoneNumber);

        return substr($phoneNumber !== '' ? $phoneNumber : 'invalid', 0, 20);
    }

    private function whatsappTemplateName(string $key): string
    {
        return (string) config('notification_templates.whatsapp_templates.'.$key, $key);
    }

    private function parametersForTemplate(string $templateKey, array $values): array
    {
        return array_map(
            fn (string $placeholder): string => $this->stringValue($values[$placeholder] ?? ''),
            $this->placeholdersForTemplate($templateKey)
        );
    }

    private function placeholdersForTemplate(string $templateKey): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $this->templateBody($templateKey), $matches);

        $supportedPlaceholders = config('notification_templates.supported_placeholders', []);

        return array_values(array_filter(
            $matches[1] ?? [],
            fn (string $placeholder): bool => in_array($placeholder, $supportedPlaceholders, true)
        ));
    }

    private function templateBody(string $templateKey): string
    {
        return (string) config('notification_templates.messages.'.$templateKey, '');
    }

    private function placeholderValues(Guest $guest, array $context): array
    {
        return [
            'manual_code' => $guest->manual_code,
            'phone_number' => $this->phoneNumbers->normalize($guest->phone_number) ?? $guest->phone_number,
            'event_name' => $this->eventName($guest),
            'allowed_entries' => $guest->allowed_entries,
            'pledge_amount' => data_get($context, 'pledge_amount', data_get($context, 'pledge.amount')),
            'pledge_balance' => data_get($context, 'pledge_balance', data_get($context, 'pledge.balance')),
            'meeting_date' => data_get($context, 'meeting_date', data_get($context, 'meeting.date')),
            'meeting_venue' => data_get($context, 'meeting_venue', data_get($context, 'meeting.venue')),
            'card_link' => data_get($context, 'card_link', data_get($context, 'card.link')),
        ];
    }

    private function eventName(Guest $guest): ?string
    {
        if ($guest->relationLoaded('event')) {
            return $guest->event?->event_name;
        }

        return $guest->event_id
            ? $guest->event()->value('event_name')
            : null;
    }

    private function stringValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) ($value ?? '');
    }

    private function guestContext(Guest $guest, string $messageType, array $context = []): array
    {
        return array_merge($context, [
            'guest_id' => $guest->id,
            'event_id' => $guest->event_id,
            'message_type' => $messageType,
            'manual_code' => $guest->manual_code,
        ]);
    }

    private function relatedContext(string $name, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return array_merge(
                [$name => $this->sanitizeContext($value)],
                $this->placeholderContextForRelated($name, $value)
            );
        }

        if (is_object($value)) {
            return array_merge([
                $name.'_type' => $value::class,
                $name.'_id' => data_get($value, 'id'),
            ], $this->placeholderContextForRelated($name, $value));
        }

        return [$name => $value];
    }

    private function placeholderContextForRelated(string $name, mixed $value): array
    {
        $context = match ($name) {
            'pledge' => [
                'pledge_amount' => data_get($value, 'pledge_amount', data_get($value, 'amount')),
                'pledge_balance' => data_get($value, 'pledge_balance', data_get($value, 'balance')),
            ],
            'meeting' => [
                'meeting_date' => data_get($value, 'meeting_date', data_get($value, 'date', data_get($value, 'scheduled_at'))),
                'meeting_venue' => data_get($value, 'meeting_venue', data_get($value, 'venue', data_get($value, 'venue_name'))),
            ],
            'card' => [
                'card_link' => data_get($value, 'card_link', data_get($value, 'link', data_get($value, 'url'))),
            ],
            default => [],
        };

        return array_filter($context, fn ($item): bool => $item !== null && $item !== '');
    }

    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if ($this->isSensitiveContextKey((string) $key)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
                continue;
            }

            if (is_object($value)) {
                $sanitized[$key] = [
                    'type' => $value::class,
                    'id' => data_get($value, 'id'),
                ];
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function isSensitiveContextKey(string $key): bool
    {
        $key = strtolower($key);

        return in_array($key, [
            'qr_token',
            'qr_code_token',
            'token',
            'token_hash',
            'access_token',
            'app_secret',
            'secret',
            'password',
        ], true);
    }
}
