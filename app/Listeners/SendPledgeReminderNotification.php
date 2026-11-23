<?php

namespace App\Listeners;

use App\Events\PledgeReminderDue;
use App\Listeners\Concerns\HandlesNotificationFailures;
use App\Services\Notifications\NotificationService;

class SendPledgeReminderNotification
{
    use HandlesNotificationFailures;

    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(PledgeReminderDue $event): void
    {
        $this->safely(fn () => $this->notifications->sendPledgeReminderNotification(
            $event->guest,
            $event->pledge ?? $event->context
        ));
    }
}
