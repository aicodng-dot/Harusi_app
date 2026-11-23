<?php

namespace App\Services\Notifications;

use App\Models\NotificationLog;

class NullWhatsappProvider implements WhatsappProviderInterface
{
    private string $lastStatus = NotificationLog::STATUS_SKIPPED;

    private string $lastError = 'WhatsApp credentials are not configured.';

    public function sendTemplate(string $phoneNumber, string $templateName, array $parameters = [], array $context = []): bool
    {
        return false;
    }

    public function sendText(string $phoneNumber, string $message, array $context = []): bool
    {
        return false;
    }

    public function sendMedia(string $phoneNumber, string $mediaUrl, string $caption = '', array $context = []): bool
    {
        return false;
    }

    public function lastStatus(): string
    {
        return $this->lastStatus;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }
}
