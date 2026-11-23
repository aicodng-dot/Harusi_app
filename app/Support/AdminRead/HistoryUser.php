<?php

namespace App\Support\AdminRead;

/** Scanner label on a history row, present only for an eligible selected-Event operator. */
final readonly class HistoryUser
{
    public function __construct(public string $name) {}
}
