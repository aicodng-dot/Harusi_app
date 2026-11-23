<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class MetaWhatsappProvider implements WhatsappProviderInterface
{
    private ?string $lastMessageId = null;

    private ?string $lastError = null;

    /**
     * @param array{
     *     api_base_url?: string|null,
     *     api_version?: string|null,
     *     access_token?: string|null,
     *     phone_number_id?: string|null
     * }|null $config
     */
    public function __construct(private ?array $config = null)
    {
        $this->config ??= config('services.whatsapp.meta', []);
    }

    public function sendTemplate(string $phoneNumber, string $templateName, array $parameters = [], array $context = []): bool
    {
        $template = [
            'name' => $templateName,
            'language' => [
                'code' => (string) ($context['language_code'] ?? 'en'),
            ],
        ];

        $components = [];
        // Approved templates with an image header (e.g. the invitation card).
        if (is_string($context['header_image_url'] ?? null) && $context['header_image_url'] !== '') {
            $components[] = ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => $context['header_image_url']]]]];
        }
        if ($parameters !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($parameter): array => ['type' => 'text', 'text' => (string) $parameter],
                    array_values($parameters)
                ),
            ];
        }
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->sendRequest([
            'messaging_product' => 'whatsapp',
            'to' => $this->recipient($phoneNumber),
            'type' => 'template',
            'template' => $template,
        ]);
    }

    public function sendText(string $phoneNumber, string $message, array $context = []): bool
    {
        return $this->sendRequest([
            'messaging_product' => 'whatsapp',
            'to' => $this->recipient($phoneNumber),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message,
            ],
        ]);
    }

    public function sendMedia(string $phoneNumber, string $mediaUrl, string $caption = '', array $context = []): bool
    {
        $mediaType = (string) ($context['media_type'] ?? 'image');
        $media = ['link' => $mediaUrl];

        if ($caption !== '') {
            $media['caption'] = $caption;
        }

        return $this->sendRequest([
            'messaging_product' => 'whatsapp',
            'to' => $this->recipient($phoneNumber),
            'type' => $mediaType,
            $mediaType => $media,
        ]);
    }

    public function lastMessageId(): ?string
    {
        return $this->lastMessageId;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function sendRequest(array $payload): bool
    {
        $this->lastMessageId = null;
        $this->lastError = null;

        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post($this->endpoint(), $payload);

            if (! $response->successful()) {
                $this->lastError = $this->errorFromResponse($response);

                return false;
            }

            $messageId = $response->json('messages.0.id');
            $this->lastMessageId = is_string($messageId) ? $messageId : null;

            return true;
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();

            return false;
        }
    }

    private function endpoint(): string
    {
        return rtrim($this->apiBaseUrl(), '/').'/'.$this->apiVersion().'/'.$this->phoneNumberId().'/messages';
    }

    private function recipient(string $phoneNumber): string
    {
        return ltrim($phoneNumber, '+');
    }

    private function errorFromResponse(Response $response): string
    {
        $error = $response->json('error.message');
        if (is_string($error) && $error !== '') {
            return $error;
        }

        $message = $response->json('message');
        if (is_string($message) && $message !== '') {
            return $message;
        }

        return 'Meta WhatsApp API request failed with status '.$response->status().'.';
    }

    private function apiBaseUrl(): string
    {
        return (string) ($this->config['api_base_url'] ?? 'https://graph.facebook.com');
    }

    private function apiVersion(): string
    {
        return (string) ($this->config['api_version'] ?? 'v21.0');
    }

    private function accessToken(): string
    {
        return (string) ($this->config['access_token'] ?? '');
    }

    private function phoneNumberId(): string
    {
        return (string) ($this->config['phone_number_id'] ?? '');
    }
}
