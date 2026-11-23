<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCustomerEvent;
use App\Models\Event;
use App\Models\User;
use App\Services\Events\CustomerEventManager;
use App\Services\Invitations\InvitationStudio;
use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Edit Event / Delete Event for the event's workspace owner or manager.
 * Editing needs WorkspaceAccess `event.update`; deleting needs `event.delete` (owner only)
 * plus the typed event name, and is refused once the event has been used for admission.
 */
class CustomerEventManageController extends Controller
{
    use AuthorizesCustomerEvent;

    public function __construct(private readonly CustomerEventManager $events) {}

    public function edit(Request $request, string $event, WorkspaceAccess $access): Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access, 'event.update');

        return $this->form($row, $language, $this->values($row), [], $this->canDelete($user, $row, $access));
    }

    public function update(Request $request, string $event, WorkspaceAccess $access, InvitationStudio $studio): Response|RedirectResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, 'event.update');
        abort_unless($row->status === Event::STATUS_ACTIVE, 409);
        $input = [
            'event_name' => $this->text($request->input('event_name')),
            'event_datetime' => is_string($request->input('event_datetime')) ? trim($request->input('event_datetime')) : '',
            'venue_name' => $this->text($request->input('venue_name')),
        ];
        [$details, $errors] = $this->validate($input, $row, $language);
        if ($errors !== []) {
            return $this->form($row, $language, array_map(fn ($v) => is_string($v) ? $v : '', $input), $errors, $this->canDelete($user, $row, $access), 422);
        }
        $this->events->update((int) $row->id, $details);
        $studio->activity((int) $row->id, (int) $user->getKey(), 'event_updated');

        return redirect()->route('customer.events.dashboard', ['event' => $row->id])
            ->with('customer_event_notice', __('customer_manage.event.updated', [], $language));
    }

    public function destroy(Request $request, string $event, WorkspaceAccess $access): Response|RedirectResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, 'event.delete');
        $typed = is_string($request->input('confirm_name')) ? trim($request->input('confirm_name')) : '';
        if ($typed === '' || mb_strtolower($typed) !== mb_strtolower(trim((string) $row->event_name))) {
            return $this->form($row, $language, $this->values($row), ['confirm_name' => __('customer_manage.event.confirm_mismatch', [], $language)], true, 422);
        }
        $blocked = $this->events->delete((int) $row->id);
        if ($blocked !== null) {
            return $this->form($row, $language, $this->values($row), ['delete' => __('customer_manage.event.blocked.'.$blocked, [], $language)], true, 409);
        }

        return redirect()->route('customer.home')
            ->with('customer_event_deleted', __('customer_manage.event.deleted', ['name' => $row->event_name], $language));
    }

    /** @return array{0: User, 1: object, 2: string} */
    private function context(Request $request, string $event, WorkspaceAccess $access, string $permission): array
    {
        $user = $this->authorizedCustomer($request);
        $connection = DB::connection();
        $row = $this->authorizedEvent($connection, $user, $event, $access);
        abort_unless($access->allows($connection, (int) $user->getKey(), (int) $row->workspace_id, $permission, (int) $row->id), 404);

        return [$user, $row, CustomerHomeController::language($request, $user)];
    }

    private function canDelete(User $user, object $row, WorkspaceAccess $access): bool
    {
        return $access->allows(DB::connection(), (int) $user->getKey(), (int) $row->workspace_id, 'event.delete', (int) $row->id);
    }

    private function values(object $row): array
    {
        $time = $row->event_time ? substr((string) $row->event_time, 0, 5) : '00:00';

        return [
            'event_name' => (string) $row->event_name,
            'event_datetime' => $row->event_date ? Carbon::parse($row->event_date)->format('Y-m-d').'T'.$time : '',
            'venue_name' => (string) ($row->venue_name ?? ''),
        ];
    }

    /** @return array{0: array, 1: array<string, string>} */
    private function validate(array $input, object $row, string $language): array
    {
        $t = fn (string $key) => __('event_details.'.$key, [], $language);
        $errors = [];
        if ($input['event_name'] === null || $input['event_name'] === '' || mb_strlen($input['event_name']) > 160) {
            $errors['event_name'] = $t('name_invalid');
        }
        $date = null;
        $time = null;
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?\z/', $input['event_datetime'], $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $errors['event_datetime'] = $t($input['event_datetime'] === '' ? 'datetime_required' : 'datetime_invalid');
        } else {
            $date = "{$m[1]}-{$m[2]}-{$m[3]}";
            $time = "{$m[4]}:{$m[5]}";
            $today = Carbon::today(config('app.timezone'));
            $unchanged = $row->event_date !== null && Carbon::parse($row->event_date)->toDateString() === $date;
            if ($date < $today->toDateString() && ! $unchanged) {
                $errors['event_datetime'] = $t('datetime_past');
            } elseif ($date > $today->copy()->addYears(10)->toDateString()) {
                $errors['event_datetime'] = $t('datetime_far');
            }
        }
        if ($input['venue_name'] === null || $input['venue_name'] === '' || mb_strlen($input['venue_name']) > 160) {
            $errors['venue_name'] = $t($input['venue_name'] === '' ? 'venue_required' : 'venue_invalid');
        }

        return [$errors === [] ? ['event_name' => $input['event_name'], 'event_date' => $date, 'event_time' => $time, 'venue_name' => $input['venue_name']] : [], $errors];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 0) {
            return null;
        }

        return preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $value) ?? '';
    }

    private function form(object $row, string $language, array $values, array $errors, bool $canDelete, int $status = 200): Response
    {
        return response()->view('reference_ui.customer_event_edit', [
            'language' => $language,
            'event' => $row,
            'values' => $values,
            'formErrors' => $errors,
            'readOnly' => $row->status !== Event::STATUS_ACTIVE,
            'canDelete' => $canDelete,
            'deleteBlocker' => $canDelete ? $this->events->deletionBlocker((int) $row->id) : null,
        ], $status)->withHeaders($this->privateEventHeaders());
    }
}
