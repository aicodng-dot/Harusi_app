<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCustomerEvent;
use App\Services\Events\EventSetupProgress;
use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Working Flow 11 — Event Setup Progress (reference UI/30_Event_Setup_Progress.png).
 * Authorization is shared with the Event Dashboard; viewing is read-only.
 */
class CustomerEventSetupController extends Controller
{
    use AuthorizesCustomerEvent;

    public function show(Request $request, string $event, WorkspaceAccess $access, EventSetupProgress $progress): Response
    {
        $user = $this->authorizedCustomer($request);
        $connection = DB::connection();
        $row = $this->authorizedEvent($connection, $user, $event, $access);

        $data = [
            'language' => CustomerHomeController::language($request, $user),
            'event' => $row,
            'progress' => null,
            'created' => session('customer_event_created'),
        ];
        $status = 200;
        try {
            $data['progress'] = $progress->forEvent($connection, $row);
        } catch (QueryException $exception) {
            // Never show SQL or private details; the page offers a retry.
            report($exception);
            $status = 500;
        }

        return response()->view('reference_ui.30_event_setup_progress', $data, $status)->withHeaders($this->privateEventHeaders());
    }
}
