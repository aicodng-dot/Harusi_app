<?php

namespace App\Events;

use App\Models\Guest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Guest $guest,
        public mixed $payment = null,
        public mixed $card = null,
        public array $context = [],
    ) {
    }
}
