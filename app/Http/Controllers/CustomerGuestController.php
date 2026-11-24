<?php

namespace App\Http\Controllers;

use App\Exceptions\OfflineEventLockedException;
use App\Http\Controllers\Concerns\AuthorizesCustomerEvent;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Services\Events\CustomerGuests;
use App\Services\Events\GuestTextParser;
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

        return $this->addPage($row, $language, $request->query('method'));
    }

    /**
     * "Paste a list of names" / "Paste guest details" (and Excel rows sent for correction):
     * parse with the deterministic GuestTextParser and show the editable review. Nothing is saved.
     */
    public function review(Request $request, string $event, WorkspaceAccess $access): Response
    {
        [, $row, $language] = $this->context($request, $event, $access, true);
        $mode = $request->input('mode');
        $back = $mode === 'structured' ? 'details' : 'names';
        $text = is_string($request->input('text')) ? $request->input('text') : '';
        if (mb_strlen($text) > GuestTextParser::MAX_TEXT) {
            return $this->addPage($row, $language, $back, ['text' => 'too_long'], '', 422);
        }
        if ($mode === 'rows') {
            $parsed = ['rows' => $this->submittedRows($request), 'truncated' => false];
        } else {
            $parsed = $mode === 'structured' ? GuestTextParser::structured($text) : GuestTextParser::names($text, $request->boolean('split_words'));
            // "Add missing information": the defaults chosen on the page fill blank cells.
            $defaultPass = in_array($request->input('default_pass'), [Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL], true) ? $request->input('default_pass') : '';
            $defaultCount = is_scalar($request->input('default_count')) ? trim((string) $request->input('default_count')) : '';
            if ($defaultPass !== '') {
                foreach ($parsed['rows'] as &$parsedRow) {
                    if ($parsedRow['pass_type'] === '' && $parsedRow['allowed_entries'] === '') {
                        $parsedRow['pass_type'] = $defaultPass;
                        $parsedRow['allowed_entries'] = $defaultPass === Guest::PASS_SPECIAL ? $defaultCount : '';
                    }
                }
                unset($parsedRow);
            }
        }
        if ($parsed['rows'] === []) {
            return $this->addPage($row, $language, $mode === 'rows' ? 'single' : $back, ['text' => 'empty'], $text, 422);
        }

        return $this->reviewPage($row, $language, $this->guests->reviewEntries((int) $row->id, $parsed['rows']), $parsed['truncated'], null);
    }

    /**
     * Saves reviewed (or typed one-by-one) guests. Every row is validated again on the
     * server; while any row is invalid nothing is saved and the review is shown with the
     * problems. Duplicates (already in the event, or repeated in the list) are skipped.
     * The rows are created in one transaction by CustomerGuests::create().
     */
    public function bulk(Request $request, string $event, WorkspaceAccess $access): Response|RedirectResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $rows = $this->submittedRows($request);
        if ($rows === []) {
            return $this->addPage($row, $language, 'single', ['rows' => 'empty'], '', 422);
        }
        if (count($rows) > CustomerGuests::IMPORT_MAX_ROWS) {
            return $this->addPage($row, $language, 'single', ['rows' => 'too_many'], '', 422);
        }
        $reviewed = $this->guests->reviewEntries((int) $row->id, $rows);
        $invalid = count(array_filter($reviewed, fn ($r) => $r['status'] === 'invalid'));
        if ($request->input('action') === 'check' || $invalid > 0) {
            return $this->reviewPage($row, $language, $reviewed, false, $invalid > 0 ? 'fix' : null, $invalid > 0 ? 422 : 200);
        }
        $ready = array_values(array_map(fn ($r) => $r['payload'], array_filter($reviewed, fn ($r) => $r['status'] === 'ready')));
        try {
            $created = DB::transaction(function () use ($ready, $row): array {
                // Re-checked inside the transaction: another tab may have added the same guest.
                $duplicates = $this->guests->duplicates((int) $row->id, $ready);

                return $this->guests->create((int) $row->id, array_values(array_diff_key($ready, $duplicates)));
            });
        } catch (OfflineEventLockedException) {
            return $this->reviewPage($row, $language, $reviewed, false, 'locked', 409);
        }
        $skipped = count($reviewed) - count($created);
        $source = $request->input('source') === 'single' ? 'single' : 'list';
        $this->studio->activity((int) $row->id, (int) $user->getKey(), 'guests_added', null, ['guests' => count($created), 'skipped' => $skipped, 'source' => $source]);
        $notice = trans_choice('customer_guests.imported', count($created), ['count' => count($created)], $language);
        if ($skipped > 0) {
            $notice .= ' '.trans_choice('customer_guests.bulk.skipped', $skipped, ['count' => $skipped], $language);
        }
        $target = $source === 'single' ? 'customer.events.guests.create' : 'customer.events.invitation.guests';

        return redirect()->route($target, ['event' => $row->id])->with('guest_notice', $notice);
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

    /**
     * Bulk delete from the Guests page (JSON). Each guest goes through CustomerGuests::delete(),
     * so admitted guests are refused, cards are removed and the offline lock is respected.
     */
    public function bulkDestroy(Request $request, string $event, WorkspaceAccess $access): \Illuminate\Http\JsonResponse
    {
        [$user, $row] = $this->context($request, $event, $access, true);
        $input = $request->input('guests');
        abort_unless($request->boolean('confirm') && is_array($input) && $input !== [] && count($input) <= 500, 422);
        $ids = array_values(array_unique(array_map(fn ($v) => filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), $input)));
        abort_if(in_array(false, $ids, true), 422);
        abort_unless(DB::table('guests')->where('event_id', $row->id)->whereIn('id', $ids)->count() === count($ids), 404);
        $deleted = 0;
        $admitted = 0;
        try {
            foreach ($ids as $id) {
                $this->guests->delete((int) $row->id, (int) $id) === 'admitted' ? $admitted++ : $deleted++;
            }
        } catch (OfflineEventLockedException) {
            return response()->json(['ok' => false, 'locked' => true, 'deleted' => $deleted, 'admitted' => $admitted], 409)->withHeaders($this->privateEventHeaders());
        }
        if ($deleted > 0) {
            $this->studio->activity((int) $row->id, (int) $user->getKey(), 'guests_deleted', null, ['guests' => $deleted]);
        }

        return response()->json(['ok' => true, 'deleted' => $deleted, 'admitted' => $admitted])->withHeaders($this->privateEventHeaders());
    }

    public function sample(Request $request, string $event, WorkspaceAccess $access, ExcelExportService $exports): StreamedResponse
    {
        $this->context($request, $event, $access);

        return $exports->downloadGuestImportTemplate();
    }

    /** CSV version of the import template, with a UTF-8 BOM so Excel opens accents correctly. */
    public function sampleCsv(Request $request, string $event, WorkspaceAccess $access): Response
    {
        $this->context($request, $event, $access);
        $lines = [
            'name,phone_number,pass_type,allowed_entries',
            'Asha Mohamed,0712345678,single,1',
            'John and Mary Michael,0755123456,double,2',
            'Familia ya Mzee Juma,,special,5',
            'Peter James,,,',
        ];

        return response("\u{FEFF}".implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="guest-import-template.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
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

    /**
     * The rows the page sent: one JSON field (keeps large lists under PHP's max_input_vars)
     * or, without JavaScript, the rows[] fields. Rows ticked "remove" and rows left
     * completely blank are dropped.
     *
     * @return list<array{name: string, phone_number: string, pass_type: string, allowed_entries: string}>
     */
    private function submittedRows(Request $request): array
    {
        $json = $request->input('rows_json');
        $rows = is_string($json) && $json !== '' && strlen($json) <= 600_000 ? json_decode($json, true) : $request->input('rows');
        if (! is_array($rows)) {
            return [];
        }
        $clean = [];
        foreach (array_slice(array_values($rows), 0, CustomerGuests::IMPORT_MAX_ROWS + 1) as $entry) {
            if (! is_array($entry) || filter_var($entry['remove'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $value = fn (string $key, int $max) => is_scalar($entry[$key] ?? null) ? mb_substr(trim((string) $entry[$key]), 0, $max) : '';
            $data = ['name' => $value('name', 200), 'phone_number' => $value('phone_number', 40), 'pass_type' => $value('pass_type', 40), 'allowed_entries' => $value('allowed_entries', 10)];
            if ($data['name'] === '' && $data['phone_number'] === '') {
                continue;
            }
            $clean[] = $data;
        }

        return $clean;
    }

    private function addPage(object $row, string $language, mixed $method, array $errors = [], string $text = '', int $status = 200): Response
    {
        $methods = ['single', 'names', 'details', 'file', 'contacts'];

        return response()->view('reference_ui.customer_guests_add', [
            'language' => $language,
            'event' => $row,
            'progress' => $this->progress($row, $language),
            'method' => in_array($method, $methods, true) ? $method : 'single',
            'errors' => $errors,
            'text' => mb_substr($text, 0, GuestTextParser::MAX_TEXT),
            'guestCount' => (int) DB::table('guests')->where('event_id', $row->id)->count(),
            'maxRows' => CustomerGuests::IMPORT_MAX_ROWS,
            'readOnly' => $row->status !== Event::STATUS_ACTIVE,
        ], $status)->withHeaders($this->privateEventHeaders());
    }

    private function reviewPage(object $row, string $language, array $reviewed, bool $truncated, ?string $error, int $status = 200): Response
    {
        $statuses = array_count_values(array_column($reviewed, 'status'));

        return response()->view('reference_ui.customer_guest_review', [
            'language' => $language,
            'event' => $row,
            'rows' => $reviewed,
            'counts' => ['total' => count($reviewed), 'ready' => $statuses['ready'] ?? 0, 'invalid' => $statuses['invalid'] ?? 0, 'duplicate' => $statuses['duplicate'] ?? 0],
            'truncated' => $truncated,
            'error' => $error,
            'maxRows' => CustomerGuests::IMPORT_MAX_ROWS,
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
