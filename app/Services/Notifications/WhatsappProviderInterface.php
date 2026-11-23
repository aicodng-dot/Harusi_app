<?php

namespace App\Services\Notifications;

interface WhatsappProviderInterface
{
    public function sendTemplate(string $phoneNumber, string $templateName, array $parameters = [], array $context = []): bool;

    public function sendText(string $phoneNumber, string $message, array $context = []): bool;

    public function sendMedia(string $phoneNumber, string $mediaUrl, string $caption = '', array $context = []): bool;
}
