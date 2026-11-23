<?php

namespace App\Events;

use App\Models\Guest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DigitalCardReady
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Guest $guest,
        public mixed $card = null,
        public array $context = [],
    ) {
    }
}
