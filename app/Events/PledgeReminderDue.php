<?php

namespace App\Events;

use App\Models\Guest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PledgeReminderDue
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Guest $guest,
        public mixed $pledge = null,
        public array $context = [],
    ) {
    }
}
