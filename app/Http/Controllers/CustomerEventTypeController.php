<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesCustomerEventSetup;
use App\Models\User;
use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Working Flow 09 — Choose Event Type (reference UI/04_Choose_Event_Type.png).
 *
 * The choice is kept in a narrowly scoped session setup state bound to this account
 * and one authorized workspace. No Event row is created here; Event Details
 * (Working Flow 10) creates the event.
 */
class CustomerEventTypeController extends Controller
{
    use ResolvesCustomerEventSetup;

    /** Stable identifiers for the four categories shown in the approved reference. */
    public const TYPES = ['wedding', 'send_off', 'kitchen_party', 'other'];

    public const SESSION_KEY = 'customer_event_setup';

    public function create(Request $request, WorkspaceAccess $access): Response|RedirectResponse
    {
        $user = $this->customer($request);
        $workspaces = $this->eligibleWorkspaces(DB::connection(), $user, $access);
        if ($workspaces->isEmpty()) {
            // Explicit provisioning stays first; nothing is created on page load.
            return redirect()->route('customer.workspaces.create');
        }

        $setup = $this->setupState($request, $user, $workspaces);
        $type = $this->validType($request->query('type')) ?? $setup['event_type'] ?? null;
        $workspaceId = $this->validWorkspace($request->query('workspace'), $workspaces)
            ?? $setup['workspace_id'] ?? ($workspaces->count() === 1 ? $workspaces->first()->id : null);

        return $this->form($request, $user, $workspaces, $type, $workspaceId);
    }

    public function store(Request $request, WorkspaceAccess $access): Response|RedirectResponse
    {
        $user = $this->customer($request);
        // Authority is recomputed from current rows on every submission.
        $workspaces = $this->eligibleWorkspaces(DB::connection(), $user, $access);
        if ($workspaces->isEmpty()) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('customer.workspaces.create');
        }

        $language = CustomerHomeController::language($request, $user);
        $type = $this->validType($request->input('event_type'));
        $submittedWorkspace = $request->input('workspace_id');
        $workspaceId = $this->validWorkspace($submittedWorkspace, $workspaces);
        if ($workspaceId === null && $workspaces->count() === 1 && ($submittedWorkspace === null || $submittedWorkspace === '')) {
            $workspaceId = $workspaces->first()->id;
        }

        $errors = [];
        if ($type === null) {
            $errors['event_type'] = __('event_type.type_required', [], $language);
        }
        if ($workspaceId === null) {
            $errors['workspace_id'] = __('event_type.workspace_required', [], $language);
        }
        if ($errors !== []) {
            return $this->form($request, $user, $workspaces, $type, $workspaceId, $errors, 422);
        }

        // Entered details survive a change of type for the same workspace.
        $previous = $this->setupState($request, $user, $workspaces);
        $request->session()->put(self::SESSION_KEY, array_filter([
            'user_id' => (int) $user->getKey(),
            'workspace_id' => $workspaceId,
            'event_type' => $type,
            'request_key' => ($previous['workspace_id'] ?? null) === $workspaceId ? ($previous['request_key'] ?? null) : null,
        ], fn ($value) => $value !== null));

        return redirect()->route('customer.events.details')->withHeaders($this->privateHeaders());
    }

    private function form(Request $request, User $user, Collection $workspaces, ?string $type, ?int $workspaceId, array $errors = [], int $status = 200): Response
    {
        return response()->view('reference_ui.04_choose_event_type', [
            'language' => CustomerHomeController::language($request, $user),
            'types' => self::TYPES,
            'selectedType' => $type,
            'workspaces' => $workspaces,
            'selectedWorkspace' => $workspaceId,
            'formErrors' => $errors,
            'notice' => session('event_setup_notice'),
        ], $status)->withHeaders($this->privateHeaders());
    }
}
