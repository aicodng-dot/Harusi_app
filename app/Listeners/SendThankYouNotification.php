<?php

namespace App\Listeners;

use App\Events\ThankYouMessageRequested;
use App\Listeners\Concerns\HandlesNotificationFailures;
use App\Services\Notifications\NotificationService;

class SendThankYouNotification
{
    use HandlesNotificationFailures;

    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(ThankYouMessageRequested $event): void
    {
        $this->safely(fn () => $this->notifications->sendThankYouNotification(
            $event->guest,
            $event->context
        ));
    }
}
