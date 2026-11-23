<?php

namespace App\Listeners;

use App\Events\DigitalCardReady;
use App\Listeners\Concerns\HandlesNotificationFailures;
use App\Services\Notifications\NotificationService;

class SendDigitalCardNotification
{
    use HandlesNotificationFailures;

    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(DigitalCardReady $event): void
    {
        $this->safely(fn () => $this->notifications->sendDigitalCardNotification(
            $event->guest,
            $event->card ?? $event->context
        ));
    }
}
