<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCustomerEvent;
use App\Services\Events\EventDashboardStats;
use App\Services\Invitations\InvitationProgress;
use App\Services\Invitations\InvitationStudio;
use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Working Flow 12 — Event Dashboard (reference UI/12_Event_Dashboard.png).
 *
 * Same authorization as Event Setup Progress; read-only, and it never touches the
 * legacy administrator `selected_event_id`.
 */
class CustomerEventDashboardController extends Controller
{
    use AuthorizesCustomerEvent;

    public function show(Request $request, string $event, WorkspaceAccess $access, EventDashboardStats $stats): Response
    {
        $user = $this->authorizedCustomer($request);
        $connection = DB::connection();
        $row = $this->authorizedEvent($connection, $user, $event, $access);

        $data = [
            'language' => CustomerHomeController::language($request, $user),
            'event' => $row,
            'stats' => null,
            'invitation' => null,
            'displayStatus' => CustomerHomeController::displayStatus($row, Carbon::today()->toDateString()),
        ];
        $status = 200;
        try {
            $data['stats'] = $stats->forEvent($connection, $row);
            // "Continue Invitation": the next invitation step, decided from saved data (read-only).
            $data['invitation'] = app(InvitationProgress::class)->forEvent(app(InvitationStudio::class)->event((int) $row->id), $data['language']);
        } catch (QueryException $exception) {
            report($exception);
            $status = 500;
        }

        return response()->view('reference_ui.12_event_dashboard', $data, $status)->withHeaders($this->privateEventHeaders());
    }
}
