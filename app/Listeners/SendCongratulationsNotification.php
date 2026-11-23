<?php

namespace App\Listeners;

use App\Events\CongratulationsMessageRequested;
use App\Listeners\Concerns\HandlesNotificationFailures;
use App\Services\Notifications\NotificationService;

class SendCongratulationsNotification
{
    use HandlesNotificationFailures;

    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(CongratulationsMessageRequested $event): void
    {
        $this->safely(fn () => $this->notifications->sendCongratulationsNotification(
            $event->guest,
            $event->context
        ));
    }
}
