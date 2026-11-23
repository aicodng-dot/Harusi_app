<?php

namespace App\Events;

use App\Models\Guest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CongratulationsMessageRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Guest $guest,
        public array $context = [],
    ) {
    }
}
