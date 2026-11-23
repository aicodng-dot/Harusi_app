<?php

namespace App\Listeners;

use App\Events\PaymentCompleted;
use App\Listeners\Concerns\HandlesNotificationFailures;
use App\Services\Notifications\NotificationService;

class SendPaymentCompletedNotification
{
    use HandlesNotificationFailures;

    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(PaymentCompleted $event): void
    {
        $context = array_merge($event->context, $this->paymentContext($event->payment));

        $this->safely(fn () => $this->notifications->sendThankYouNotification($event->guest, $context));
        $this->safely(fn () => $this->notifications->sendCongratulationsNotification($event->guest, $context));

        if ($event->card !== null) {
            $this->safely(fn () => $this->notifications->sendDigitalCardNotification($event->guest, $event->card));
        }
    }

    private function paymentContext(mixed $payment): array
    {
        if ($payment === null) {
            return [];
        }

        if (is_array($payment)) {
            return ['payment' => $payment];
        }

        if (is_object($payment)) {
            return [
                'payment_type' => $payment::class,
                'payment_id' => data_get($payment, 'id'),
            ];
        }

        return ['payment' => $payment];
    }
}
