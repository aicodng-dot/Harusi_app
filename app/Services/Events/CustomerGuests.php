<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\Guest;
use App\Services\GuestImportExcelReader;
use App\Services\Invitations\InvitationCredentials;
use App\Services\OfflineEventLockService;
use App\Services\PhoneNumberService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Guests added by an event owner/manager (Working Flows 20 and 21).
 *
 * Reuses the application's own rules: PhoneNumberService normalisation, the Guest model's
 * pass rules and manual-code generation, and the existing admission credential issuance
 * (InvitationCredentials: one random 64-character token per guest, never rotated).
 * The caller authorizes the account and the event; this class never trusts an event id,
 * allowance, usage count or status from the request.
 */
class CustomerGuests
{
    public const IMPORT_MAX_ROWS = 1000;

    /** Header aliases accepted in an import file, mapped to the canonical column. */
    private const HEADERS = [
        'name' => 'name', 'jina' => 'name', 'guest' => 'name', 'guest_name' => 'name', 'mgeni' => 'name',
        'phone_number' => 'phone_number', 'phone' => 'phone_number', 'simu' => 'phone_number', 'namba_ya_simu' => 'phone_number',
        'pass_type' => 'pass_type', 'pass' => 'pass_type', 'aina' => 'pass_type', 'aina_ya_tiketi' => 'pass_type', 'ticket' => 'pass_type',
        'allowed_entries' => 'allowed_entries', 'entries' => 'allowed_entries', 'idadi' => 'allowed_entries', 'idadi_ya_wageni' => 'allowed_entries',
    ];

    private const PASS_ALIASES = [
        'single' => Guest::PASS_SINGLE, 'mmoja' => Guest::PASS_SINGLE,
        'double' => Guest::PASS_DOUBLE, 'wawili' => Guest::PASS_DOUBLE,
        'special' => Guest::PASS_SPECIAL, 'family' => Guest::PASS_SPECIAL, 'familia' => Guest::PASS_SPECIAL, 'maalum' => Guest::PASS_SPECIAL,
        'special / family' => Guest::PASS_SPECIAL, 'special/family' => Guest::PASS_SPECIAL,
    ];

    public function __construct(
        private PhoneNumberService $phones,
        private InvitationCredentials $credentials,
        private OfflineEventLockService $locks,
    ) {}

    /**
     * Validates one guest. Single and Double always get their fixed allowance; Special
     * needs 3..100. With $strict (imports) a Single/Double row stating another allowance
     * is an error instead of being corrected silently.
     *
     * @return array{0: ?array{name: string, phone_number: string, pass_type: string, allowed_entries: int}, 1: list<string>}
     */
    public function validate(array $input, bool $strict = false): array
    {
        $errors = [];
        $name = is_scalar($input['name'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', (string) $input['name'])) : '';
        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'name';
        }
        $phone = is_scalar($input['phone_number'] ?? null) && mb_strlen((string) $input['phone_number']) <= 40
            ? $this->phones->normalize((string) $input['phone_number']) : null;
        if ($phone === null) {
            $errors[] = 'phone_number';
        }
        $pass = is_scalar($input['pass_type'] ?? null) ? (self::PASS_ALIASES[mb_strtolower(trim((string) $input['pass_type']))] ?? null) : null;
        $allowed = null;
        if ($pass === null) {
            $errors[] = 'pass_type';
        } else {
            $raw = is_scalar($input['allowed_entries'] ?? null) ? trim((string) $input['allowed_entries']) : '';
            $requested = $raw === '' ? null : filter_var($raw, FILTER_VALIDATE_INT);
            $fixed = [Guest::PASS_SINGLE => 1, Guest::PASS_DOUBLE => 2][$pass] ?? null;
            if ($fixed !== null) {
                $allowed = $fixed;
                if ($strict && $requested !== null && $requested !== $fixed) {
                    $errors[] = 'allowed_entries';
                }
            } elseif ($requested === null || $requested === false || $requested < Guest::MIN_SPECIAL_ENTRIES || $requested > Guest::MAX_ALLOWED_ENTRIES) {
                $errors[] = 'allowed_entries';
            } else {
                $allowed = $requested;
            }
        }
        if ($errors !== []) {
            return [null, $errors];
        }

        return [['name' => $name, 'phone_number' => $phone, 'pass_type' => $pass, 'allowed_entries' => $allowed], []];
    }

    /**
     * Which of the given guests already exist in the event (same phone, or same name).
     *
     * @param  array<int|string, array{name: string, phone_number: string}>  $payloads
     * @return array<int|string, 'phone'|'name'>
     */
    public function duplicates(int $eventId, array $payloads): array
    {
        $phones = [];
        $names = [];
        foreach (DB::table('guests')->where('event_id', $eventId)->get(['name', 'phone_number']) as $row) {
            $phones[(string) $row->phone_number] = true;
            $names[self::nameKey((string) $row->name)] = true;
        }
        $found = [];
        foreach ($payloads as $key => $payload) {
            if (isset($phones[$payload['phone_number']])) {
                $found[$key] = 'phone';
            } elseif (isset($names[self::nameKey($payload['name'])])) {
                $found[$key] = 'name';
            }
        }

        return $found;
    }

    /**
     * Creates the guests in one transaction, each with a manual code (Guest model) and an
     * admission credential (existing issuance). Nothing is created if any row fails.
     *
     * @param  list<array{name: string, phone_number: string, pass_type: string, allowed_entries: int}>  $payloads
     * @return list<Guest>
     */
    public function create(int $eventId, array $payloads): array
    {
        return DB::transaction(function () use ($eventId, $payloads): array {
            $event = Event::query()->useWritePdo()->whereKey($eventId)->whereNotNull('workspace_id')->lockForUpdate()->first();
            abort_if($event === null, 404);
            abort_unless($event->status === Event::STATUS_ACTIVE, 409);
            // Guests cannot change while an offline scanner session holds the event.
            $this->locks->assertEventAdmissionDataEditable($event);
            $guests = [];
            foreach ($payloads as $payload) {
                $guest = Guest::query()->create([
                    'event_id' => $eventId, 'name' => $payload['name'], 'phone_number' => $payload['phone_number'],
                    'pass_type' => $payload['pass_type'], 'allowed_entries' => $payload['allowed_entries'],
                    'used_entries' => 0, 'status' => Guest::STATUS_ACTIVE,
                ]);
                $this->credentials->ensure($eventId, (int) $guest->id);
                $guests[] = $guest;
            }

            return $guests;
        });
    }

    /** 'phone' or 'name' when another guest of the event already matches, else null. */
    public function duplicateOfOther(int $eventId, int $guestId, array $payload): ?string
    {
        $others = DB::table('guests')->where('event_id', $eventId)->where('id', '!=', $guestId)->get(['name', 'phone_number']);
        if ($others->contains(fn ($row) => (string) $row->phone_number === $payload['phone_number'])) {
            return 'phone';
        }

        return $others->contains(fn ($row) => self::nameKey((string) $row->name) === self::nameKey($payload['name'])) ? 'name' : null;
    }

    /**
     * Updates one guest of the event. The allowance can never drop below entries already
     * used; the model keeps the usage status in step (cancelled guests stay cancelled). A change to
     * anything printed on the invitation (name, pass, allowance) removes that guest's card so
     * it must be generated again. Returns 'used' when the allowance would undercut usage.
     *
     * @param  array{name: string, phone_number: string, pass_type: string, allowed_entries: int}  $payload
     */
    public function update(int $eventId, int $guestId, array $payload): ?string
    {
        $cardPaths = [];
        $result = DB::transaction(function () use ($eventId, $guestId, $payload, &$cardPaths): ?string {
            $event = $this->lockActiveEvent($eventId);
            $this->locks->assertEventAdmissionDataEditable($event);
            $guest = Guest::query()->useWritePdo()->where('event_id', $eventId)->whereKey($guestId)->lockForUpdate()->firstOrFail();
            if ($payload['allowed_entries'] < (int) $guest->used_entries) {
                return 'used';
            }
            $printed = $guest->name !== $payload['name'] || $guest->pass_type !== $payload['pass_type']
                || (int) $guest->allowed_entries !== $payload['allowed_entries'];
            // The Guest model re-derives pass rules and usage status (active/partially/fully used) on save.
            $guest->fill($payload)->save();
            if ($printed) {
                $cardPaths = $this->forgetCards($eventId, [$guestId]);
            }

            return null;
        });
        $this->deleteFiles($cardPaths);

        return $result;
    }

    /** Deletes a guest who was never admitted. Returns 'admitted' when refused. */
    public function delete(int $eventId, int $guestId): ?string
    {
        $cardPaths = [];
        $result = DB::transaction(function () use ($eventId, $guestId, &$cardPaths): ?string {
            $event = $this->lockActiveEvent($eventId);
            $this->locks->assertEventAdmissionDataEditable($event);
            $guest = Guest::query()->useWritePdo()->where('event_id', $eventId)->whereKey($guestId)->lockForUpdate()->firstOrFail();
            if ((int) $guest->used_entries > 0
                || DB::table('admissions')->where('guest_id', $guestId)->exists()
                || DB::table('checkins')->where('guest_id', $guestId)->where('scan_result', 'admitted')->exists()) {
                return 'admitted';
            }
            $cardPaths = $this->forgetCards($eventId, [$guestId]);
            // Cascades the QR credential, admissions and card rows; scan history keeps a null guest.
            $guest->delete();

            return null;
        });
        $this->deleteFiles($cardPaths);

        return $result;
    }

    private function lockActiveEvent(int $eventId): Event
    {
        $event = Event::query()->useWritePdo()->whereKey($eventId)->whereNotNull('workspace_id')->lockForUpdate()->first();
        abort_if($event === null, 404);
        abort_unless($event->status === Event::STATUS_ACTIVE, 409);

        return $event;
    }

    /** Removes card rows and returns their file paths (deleted after commit). */
    private function forgetCards(int $eventId, array $guestIds): array
    {
        $cards = DB::table('invitation_cards')->where('event_id', $eventId)->whereIn('guest_id', $guestIds)->get(['png_path', 'pdf_path']);
        DB::table('invitation_cards')->where('event_id', $eventId)->whereIn('guest_id', $guestIds)->delete();

        return $cards->flatMap(fn ($card) => [$card->png_path, $card->pdf_path])->filter()->values()->all();
    }

    private function deleteFiles(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk('invitations')->delete($paths);
        }
    }

    /**
     * Reads an uploaded .xlsx or .csv guest list.
     *
     * @return array{rows: list<array{row: int, data: array<string, string>}>, error: ?string}
     *                                                                                         error is one of: unreadable, columns, empty, too_many
     */
    public function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'xlsx') {
            [$rows, $errors] = app(GuestImportExcelReader::class)->read($file->getRealPath());
            if ($errors !== []) {
                return ['rows' => [], 'error' => str_contains($errors[0], 'Missing required columns') ? 'columns' : (str_contains($errors[0], 'empty') ? 'empty' : 'unreadable')];
            }
            $rows = array_map(fn (array $row) => ['row' => (int) $row['row_number'], 'data' => $row['data']], array_values($rows));
        } else {
            $rows = $this->readCsv($file->getRealPath());
            if (is_string($rows)) {
                return ['rows' => [], 'error' => $rows];
            }
        }
        if ($rows === []) {
            return ['rows' => [], 'error' => 'empty'];
        }
        if (count($rows) > self::IMPORT_MAX_ROWS) {
            return ['rows' => [], 'error' => 'too_many'];
        }

        return ['rows' => $rows, 'error' => null];
    }

    /** @return list<array{row: int, data: array<string, string>}>|string */
    private function readCsv(string $path): array|string
    {
        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '') {
            return 'empty';
        }
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $first = $lines[0] ?? '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $columns = [];
        foreach (str_getcsv($first, $delimiter, '"', '') as $index => $header) {
            $key = self::HEADERS[preg_replace('/[\s\-]+/', '_', mb_strtolower(trim((string) $header)))] ?? null;
            if ($key !== null && ! in_array($key, $columns, true)) {
                $columns[$index] = $key;
            }
        }
        if (array_diff(['name', 'phone_number', 'pass_type'], $columns) !== []) {
            return 'columns';
        }
        $rows = [];
        foreach (array_slice($lines, 1, null, true) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter, '"', '');
            $data = ['name' => '', 'phone_number' => '', 'pass_type' => '', 'allowed_entries' => ''];
            foreach ($columns as $index => $key) {
                $data[$key] = trim((string) ($cells[$index] ?? ''));
            }
            if (implode('', $data) === '') {
                continue;
            }
            $rows[] = ['row' => $number + 1, 'data' => $data];
            if (count($rows) > self::IMPORT_MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Checks every row and classifies it: ready, invalid (with the failing fields) or
     * duplicate (already in the event, or repeated earlier in the same file).
     *
     * @param  list<array{row: int, data: array<string, string>}>  $rows
     * @return list<array{row: int, name: string, phone: string, status: 'ready'|'invalid'|'duplicate', errors: list<string>, payload: ?array}>
     */
    public function review(int $eventId, array $rows): array
    {
        $reviewed = [];
        $valid = [];
        foreach ($rows as $index => $row) {
            [$payload, $errors] = $this->validate($row['data'], true);
            $reviewed[$index] = ['row' => $row['row'], 'name' => mb_substr((string) ($row['data']['name'] ?? ''), 0, 120), 'phone' => mb_substr((string) ($row['data']['phone_number'] ?? ''), 0, 40),
                'status' => $payload === null ? 'invalid' : 'ready', 'errors' => $errors, 'payload' => $payload];
            if ($payload !== null) {
                $valid[$index] = $payload;
            }
        }
        $existing = $this->duplicates($eventId, $valid);
        $seenPhones = [];
        $seenNames = [];
        foreach ($valid as $index => $payload) {
            $name = self::nameKey($payload['name']);
            if (isset($existing[$index]) || isset($seenPhones[$payload['phone_number']]) || isset($seenNames[$name])) {
                $reviewed[$index]['status'] = 'duplicate';
                $reviewed[$index]['payload'] = null;

                continue;
            }
            $seenPhones[$payload['phone_number']] = true;
            $seenNames[$name] = true;
        }

        return array_values($reviewed);
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
