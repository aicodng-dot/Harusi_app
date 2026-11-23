<?php

namespace App\Listeners;

use App\Events\NextMeetingScheduled;
use App\Listeners\Concerns\HandlesNotificationFailures;
use App\Services\Notifications\NotificationService;

class SendNextMeetingNotification
{
    use HandlesNotificationFailures;

    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(NextMeetingScheduled $event): void
    {
        $this->safely(fn () => $this->notifications->sendNextMeetingNotification(
            $event->guest,
            $event->meeting ?? $event->context
        ));
    }
}
