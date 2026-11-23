<?php

namespace App\Http\Controllers;

use App\Exceptions\OfflineEventLockedException;
use App\Http\Controllers\Concerns\AuthorizesCustomerEvent;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Services\Events\CustomerGuests;
use App\Services\ExcelExportService;
use App\Services\Invitations\InvitationProgress;
use App\Services\Invitations\InvitationStudio;
use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Add Guest (reference 15) and Import Guests (reference 16) for an event owner/manager.
 *
 * Every request authorizes the account and the event through WorkspaceAccess; writes need
 * `event.update` and an active event. The event comes only from the authorized URL; the
 * form can never set an event id, usage count or status. No card artwork is generated
 * here: a new guest simply appears on the guest-cards screen as "not ready".
 */
class CustomerGuestController extends Controller
{
    use AuthorizesCustomerEvent;

    private const SESSION_KEY = 'customer_guest_import';

    public function __construct(private CustomerGuests $guests, private InvitationStudio $studio) {}

    public function create(Request $request, string $event, WorkspaceAccess $access): Response
    {
        [, $row, $language] = $this->context($request, $event, $access);

        return $this->form($row, $language, [], [], null);
    }

    public function store(Request $request, string $event, WorkspaceAccess $access): Response|RedirectResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $input = $request->only(['name', 'phone_number', 'pass_type', 'allowed_entries']);
        [$payload, $errors] = $this->guests->validate($input);
        if ($payload === null) {
            return $this->form($row, $language, $input, $errors, null, 422);
        }
        $duplicate = $this->guests->duplicates((int) $row->id, [$payload])[0] ?? null;
        if ($duplicate !== null && ! $request->boolean('allow_duplicate')) {
            return $this->form($row, $language, $input, [], $duplicate, 422);
        }
        try {
            [$guest] = $this->guests->create((int) $row->id, [$payload]);
        } catch (OfflineEventLockedException) {
            return $this->form($row, $language, $input, ['locked'], null, 409);
        }
        $this->studio->activity((int) $row->id, (int) $user->getKey(), 'guest_added', (int) $guest->id);
        $target = $request->boolean('again') ? 'customer.events.guests.create' : 'customer.events.invitation.guests';

        return redirect()->route($target, ['event' => $row->id])->with('guest_notice', __('customer_guests.added', ['name' => $guest->name], $language));
    }

    public function import(Request $request, string $event, WorkspaceAccess $access): Response
    {
        [, $row, $language] = $this->context($request, $event, $access);

        return $this->importPage($row, $language, null, null);
    }

    /** Step 1: read and check the file. Nothing is saved until the owner confirms. */
    public function preview(Request $request, string $event, WorkspaceAccess $access): Response
    {
        [, $row, $language] = $this->context($request, $event, $access, true);
        $request->session()->forget(self::SESSION_KEY);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return $this->importPage($row, $language, null, 'missing', 422);
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'csv'], true)) {
            return $this->importPage($row, $language, null, 'type', 422);
        }
        if ($file->getSize() > 2 * 1024 * 1024) {
            return $this->importPage($row, $language, null, 'size', 422);
        }
        $read = $this->guests->read($file);
        if ($read['error'] !== null) {
            return $this->importPage($row, $language, null, $read['error'], 422);
        }
        $rows = $this->guests->review((int) $row->id, $read['rows']);
        $ready = array_values(array_filter(array_column($rows, 'payload')));
        $token = null;
        if ($ready !== []) {
            $token = Str::random(40);
            $request->session()->put(self::SESSION_KEY, ['token' => $token, 'event' => (int) $row->id, 'rows' => $ready]);
        }

        return $this->importPage($row, $language, ['rows' => $rows, 'token' => $token, 'file' => mb_substr($file->getClientOriginalName(), 0, 80)], null);
    }

    /**
     * Step 2: the owner confirmed. The checked rows are taken from the session exactly once
     * (a repeated submit finds nothing to import) and saved in a single transaction; rows
     * that became duplicates in the meantime are skipped.
     */
    public function confirm(Request $request, string $event, WorkspaceAccess $access): Response|RedirectResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $pending = $request->session()->pull(self::SESSION_KEY);
        $token = $request->input('token');
        if (! is_array($pending) || ! is_string($token) || ! hash_equals((string) ($pending['token'] ?? ''), $token) || ($pending['event'] ?? null) !== (int) $row->id) {
            return $this->importPage($row, $language, null, 'expired', 409);
        }
        try {
            $created = DB::transaction(function () use ($pending, $row): array {
                $duplicates = $this->guests->duplicates((int) $row->id, $pending['rows']);

                return $this->guests->create((int) $row->id, array_values(array_diff_key($pending['rows'], $duplicates)));
            });
        } catch (OfflineEventLockedException) {
            return $this->importPage($row, $language, null, 'locked', 409);
        }
        $this->studio->activity((int) $row->id, (int) $user->getKey(), 'guests_imported', null, ['guests' => count($created)]);

        return redirect()->route('customer.events.invitation.guests', ['event' => $row->id])
            ->with('guest_notice', trans_choice('customer_guests.imported', count($created), ['count' => count($created)], $language));
    }

    public function edit(Request $request, string $event, string $guest, WorkspaceAccess $access): Response
    {
        [, $row, $language] = $this->context($request, $event, $access);
        $record = $this->eventGuest((int) $row->id, $guest);

        return $this->editForm($row, $record, $language, [], [], null);
    }

    public function update(Request $request, string $event, string $guest, WorkspaceAccess $access): Response|RedirectResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $record = $this->eventGuest((int) $row->id, $guest);
        $input = $request->only(['name', 'phone_number', 'pass_type', 'allowed_entries']);
        [$payload, $errors] = $this->guests->validate($input);
        if ($payload === null) {
            return $this->editForm($row, $record, $language, $input, $errors, null, 422);
        }
        $duplicate = $this->guests->duplicateOfOther((int) $row->id, (int) $record->id, $payload);
        if ($duplicate !== null && ! $request->boolean('allow_duplicate')) {
            return $this->editForm($row, $record, $language, $input, [], $duplicate, 422);
        }
        try {
            $refused = $this->guests->update((int) $row->id, (int) $record->id, $payload);
        } catch (OfflineEventLockedException) {
            return $this->editForm($row, $record, $language, $input, ['locked'], null, 409);
        }
        if ($refused === 'used') {
            return $this->editForm($row, $record, $language, $input, ['allowed_below_used'], null, 422);
        }
        $this->studio->activity((int) $row->id, (int) $user->getKey(), 'guest_updated', (int) $record->id);

        return redirect()->route('customer.events.invitation.guests', ['event' => $row->id])
            ->with('guest_notice', __('customer_manage.guest.updated', ['name' => $payload['name']], $language));
    }

    public function destroy(Request $request, string $event, string $guest, WorkspaceAccess $access): Response|RedirectResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $record = $this->eventGuest((int) $row->id, $guest);
        if (! $request->boolean('confirm')) {
            return $this->editForm($row, $record, $language, [], ['confirm_delete'], null, 422);
        }
        try {
            $refused = $this->guests->delete((int) $row->id, (int) $record->id);
        } catch (OfflineEventLockedException) {
            return $this->editForm($row, $record, $language, [], ['locked'], null, 409);
        }
        if ($refused === 'admitted') {
            return $this->editForm($row, $record, $language, [], ['admitted'], null, 409);
        }
        $this->studio->activity((int) $row->id, (int) $user->getKey(), 'guest_deleted', null, ['guest' => (int) $record->id]);

        return redirect()->route('customer.events.invitation.guests', ['event' => $row->id])
            ->with('guest_notice', __('customer_manage.guest.deleted', ['name' => $record->name], $language));
    }

    public function sample(Request $request, string $event, WorkspaceAccess $access, ExcelExportService $exports): StreamedResponse
    {
        $this->context($request, $event, $access);

        return $exports->downloadGuestImportTemplate();
    }

    // ----- Helpers -----------------------------------------------------------------

    private function form(object $row, string $language, array $input, array $errors, ?string $duplicate, int $status = 200): Response
    {
        $pass = in_array($input['pass_type'] ?? null, [Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL], true) ? $input['pass_type'] : Guest::PASS_SINGLE;

        return response()->view('reference_ui.15_add_guest', [
            'language' => $language,
            'event' => $row,
            'progress' => $this->progress($row, $language),
            'values' => [
                'name' => is_string($input['name'] ?? null) ? mb_substr($input['name'], 0, 120) : '',
                'phone_number' => is_string($input['phone_number'] ?? null) ? mb_substr($input['phone_number'], 0, 40) : '',
                'pass_type' => $pass,
                'allowed_entries' => max(1, min(Guest::MAX_ALLOWED_ENTRIES, (int) ($input['allowed_entries'] ?? 1))),
            ],
            'errors' => $errors,
            'duplicate' => $duplicate,
            'readOnly' => $row->status !== Event::STATUS_ACTIVE,
        ], $status)->withHeaders($this->privateEventHeaders());
    }

    private function editForm(object $row, Guest $guest, string $language, array $input, array $errors, ?string $duplicate, int $status = 200): Response
    {
        $value = fn (string $key, mixed $fallback) => is_string($input[$key] ?? null) ? mb_substr($input[$key], 0, 120) : $fallback;
        $pass = in_array($input['pass_type'] ?? null, [Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL], true) ? $input['pass_type'] : $guest->pass_type;

        return response()->view('reference_ui.customer_guest_edit', [
            'language' => $language,
            'event' => $row,
            'guest' => $guest,
            'values' => [
                'name' => $value('name', (string) $guest->name),
                'phone_number' => $value('phone_number', (string) $guest->phone_number),
                'pass_type' => $pass,
                'allowed_entries' => max(1, min(Guest::MAX_ALLOWED_ENTRIES, (int) ($input['allowed_entries'] ?? $guest->allowed_entries))),
            ],
            'errors' => $errors,
            'duplicate' => $duplicate,
            'readOnly' => $row->status !== Event::STATUS_ACTIVE,
            'deletable' => (int) $guest->used_entries === 0,
        ], $status)->withHeaders($this->privateEventHeaders());
    }

    private function eventGuest(int $eventId, string $guest): Guest
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,18}\z/', $guest) === 1, 404);

        return Guest::query()->useWritePdo()->where('event_id', $eventId)->whereKey((int) $guest)->firstOrFail();
    }

    private function importPage(object $row, string $language, ?array $review, ?string $error, int $status = 200): Response
    {
        $counts = null;
        if ($review !== null) {
            $statuses = array_count_values(array_column($review['rows'], 'status'));
            $counts = ['total' => count($review['rows']), 'ready' => $statuses['ready'] ?? 0, 'invalid' => $statuses['invalid'] ?? 0, 'duplicate' => $statuses['duplicate'] ?? 0];
        }

        return response()->view('reference_ui.16_import_guests', [
            'language' => $language,
            'event' => $row,
            'progress' => $this->progress($row, $language),
            'review' => $review,
            'counts' => $counts,
            'error' => $error,
            'maxRows' => CustomerGuests::IMPORT_MAX_ROWS,
            'readOnly' => $row->status !== Event::STATUS_ACTIVE,
        ], $status)->withHeaders($this->privateEventHeaders());
    }

    private function progress(object $row, string $language): array
    {
        return app(InvitationProgress::class)->forEvent($this->studio->event((int) $row->id), $language);
    }

    /** @return array{0: User, 1: object, 2: string} */
    private function context(Request $request, string $event, WorkspaceAccess $access, bool $write = false): array
    {
        $user = $this->authorizedCustomer($request);
        $connection = DB::connection();
        $row = $this->authorizedEvent($connection, $user, $event, $access);
        if ($write) {
            abort_unless($access->allows($connection, (int) $user->getKey(), (int) $row->workspace_id, 'event.update', (int) $row->id), 404);
            abort_unless($row->status === Event::STATUS_ACTIVE, 409);
        }

        return [$user, $row, CustomerHomeController::language($request, $user)];
    }
}
