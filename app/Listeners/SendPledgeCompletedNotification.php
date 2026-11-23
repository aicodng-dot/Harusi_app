<?php

namespace App\Listeners;

use App\Events\PledgeCompleted;
use App\Listeners\Concerns\HandlesNotificationFailures;
use App\Services\Notifications\NotificationService;

class SendPledgeCompletedNotification
{
    use HandlesNotificationFailures;

    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(PledgeCompleted $event): void
    {
        $this->safely(fn () => $this->notifications->sendPledgeCompletedNotification(
            $event->guest,
            $event->pledge ?? $event->context
        ));
    }
}
