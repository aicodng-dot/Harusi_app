<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

class OfflineEventLockedException extends RuntimeException
{
    public const MESSAGE = 'This event is currently assigned to an active offline Android scanner session. Admission-sensitive guest records cannot be modified until the scanner results are imported or the session is cancelled.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 423);
        }

        return back()->with('error', $this->getMessage());
    }
}
