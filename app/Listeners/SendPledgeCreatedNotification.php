<?php

namespace App\Listeners;

use App\Events\PledgeCreated;
use App\Listeners\Concerns\HandlesNotificationFailures;
use App\Services\Notifications\NotificationService;

class SendPledgeCreatedNotification
{
    use HandlesNotificationFailures;

    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(PledgeCreated $event): void
    {
        $this->safely(fn () => $this->notifications->sendPledgeCreatedNotification(
            $event->guest,
            $event->pledge ?? $event->context
        ));
    }
}
