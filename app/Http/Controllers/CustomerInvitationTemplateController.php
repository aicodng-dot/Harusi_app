<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCustomerEvent;
use App\Models\Event;
use App\Models\User;
use App\Services\Events\InvitationTemplates;
use App\Services\Invitations\InvitationProgress;
use App\Services\Invitations\InvitationStudio;
use App\Services\Invitations\InvitationTemplateCatalog;
use App\Services\Tenancy\CustomerEventCreationException;
use App\Services\Tenancy\CustomerEventTemplateSelector;
use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Working Flow 14 — Choose Invitation Template (reference UI/13_Choose_Invitation_Template.png),
 * now browsing the full published library with category chips, search and "show more".
 *
 * Viewing uses the shared event authorization; saving re-checks account, membership,
 * ownership and lifecycle under row locks in CustomerEventTemplateSelector, then moves
 * an existing saved design to the new template (keeping the owner's wording and photo).
 */
class CustomerInvitationTemplateController extends Controller
{
    use AuthorizesCustomerEvent;

    public const PER_PAGE = 12;

    public function show(Request $request, string $event, WorkspaceAccess $access): Response
    {
        $user = $this->authorizedCustomer($request);
        $row = $this->authorizedEvent(DB::connection(), $user, $event, $access);
        $category = $request->has('category')
            ? InvitationTemplates::category($request->query('category'))
            : InvitationTemplates::defaultCategory($row->event_type);
        $selected = InvitationTemplates::isValid($request->query('template')) ? $request->query('template') : $row->invitation_template;

        return $this->page($request, $user, $row, $category, InvitationTemplates::isValid($selected) ? $selected : null);
    }

    public function store(Request $request, string $event, WorkspaceAccess $access, CustomerEventTemplateSelector $selector, InvitationStudio $studio): Response|RedirectResponse
    {
        $user = $this->authorizedCustomer($request);
        $connection = DB::connection();
        $row = $this->authorizedEvent($connection, $user, $event, $access);
        abort_unless($access->allows($connection, (int) $user->getKey(), (int) $row->workspace_id, 'event.update', (int) $row->id), 404);
        $language = CustomerHomeController::language($request, $user);
        $template = $request->input('template');
        $category = InvitationTemplates::category($request->input('category'));

        if (! InvitationTemplates::isValid($template)) {
            return $this->page($request, $user, $row, $category, null, ['template' => __('invitation_template.template_required', [], $language)], 422);
        }

        try {
            $selector->select($connection, (int) $user->getKey(), (int) $row->id, $template);
            $studio->switchTemplate($studio->event((int) $row->id), (int) $user->getKey(), $template, $language);
        } catch (CustomerEventCreationException $exception) {
            if ($exception->reason() === 'creator_unavailable') {
                abort(403);
            }
            if ($exception->reason() === 'workspace_unavailable') {
                abort(404);
            }
            if ($exception->reason() !== 'event_locked') {
                report($exception);
            }
            $message = $exception->reason() === 'event_locked'
                ? __('invitation_template.locked', [], $language)
                : __('invitation_template.failed', [], $language);

            return $this->page($request, $user, $row, $category, $template, ['form' => $message], $exception->reason() === 'event_locked' ? 409 : 503);
        }

        return redirect()->route('customer.events.invitation', ['event' => $row->id])
            ->with('invitation_template_saved', $template)
            ->withHeaders($this->privateEventHeaders());
    }

    private function page(Request $request, User $user, object $event, ?string $category, ?string $selected, array $errors = [], int $status = 200): Response
    {
        $search = $request->query('q');
        $search = is_string($search) ? mb_substr(trim($search), 0, 60) : '';
        $pages = filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]) ?: 1;
        $all = InvitationTemplates::forCategory($category, $search);
        // Keep the selected design visible even when it is beyond the shown pages.
        $visible = array_slice($all, 0, $pages * self::PER_PAGE, true);
        if ($selected !== null && isset($all[$selected]) && ! isset($visible[$selected])) {
            $visible = [$selected => $all[$selected]] + $visible;
        }

        $language = CustomerHomeController::language($request, $user);

        return response()->view('reference_ui.13_choose_invitation_template', [
            'progress' => app(InvitationProgress::class)->forEvent(app(InvitationStudio::class)->event((int) $event->id), $language),
            'language' => $language,
            'event' => $event,
            'category' => $category,
            'search' => $search,
            'pages' => $pages,
            'templates' => $visible,
            'total' => count($all),
            'libraryTotal' => InvitationTemplateCatalog::count(),
            'hasMore' => count($all) > $pages * self::PER_PAGE,
            'selected' => $selected,
            'saved' => InvitationTemplates::isValid(session('invitation_template_saved')) ? session('invitation_template_saved') : null,
            'readOnly' => $event->status !== Event::STATUS_ACTIVE,
            'formErrors' => $errors,
        ], $status)->withHeaders($this->privateEventHeaders());
    }
}
