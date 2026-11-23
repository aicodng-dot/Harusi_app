<?php

namespace App\Listeners\Concerns;

use Throwable;

trait HandlesNotificationFailures
{
    protected function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
