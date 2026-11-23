<?php

namespace App\Http\Controllers;

use App\Exceptions\LegacyGuestDeletionFailed;
use App\Exceptions\LegacyGuestIssuanceFailed;
use App\Exceptions\LegacyGuestIssuanceInterrupted;
use App\Exceptions\LegacyQrMediaUnavailable;
use App\Exceptions\OfflineEventLockedException;
use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use App\Services\ExcelExportService;
use App\Services\GuestImportExcelReader;
use App\Services\GuestQrImageService;
use App\Services\OfflineEventLockService;
use App\Services\PhoneNumberService;
use App\Services\Tenancy\LegacyAdminReadProjection;
use App\Services\Tenancy\LegacyEventResolver;
use App\Services\Tenancy\LegacyGuestDeletion;
use App\Services\Tenancy\LegacyGuestIssuance;
use App\Services\Tenancy\LegacyGuestWrites;
use App\Services\Tenancy\LegacyQrMediaDelivery;
use App\Support\AdminRead\CheckinView;
use App\Support\AdminRead\GuestView;
use App\Support\PositiveIntegerId;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AdminController extends Controller
{
    public function index(Request $request): View
    {
        return $this->dashboard();
    }

    public function dashboard(): View
    {
        return view('admin.dashboard', $this->adminReads()->dashboardPage(auth()->user(), session('selected_event_id')));
    }

    public function qrCodes(Request $request): View
    {
        $filter = (string) $request->query('filter', '');
        $search = trim((string) $request->query('search', ''));
        $allowedFilters = ['has_qr', 'missing_qr', 'active_qr', 'revoked_qr', 'fully_used', 'unused'];

        if (! in_array($filter, $allowedFilters, true)) {
            $filter = '';
        }

        return view('admin.qr-codes', $this->adminReads()->qrListPage($request->user(), session('selected_event_id'), $filter, $search));
    }

    public function generateMissingQrCodes(GuestQrImageService $qrImages): RedirectResponse
    {
        $actor = clone request()->user();
        $selectedEventId = session('selected_event_id');
        $this->assertCurrentEventAdmissionDataEditable();
        $generated = 0;

        try {
            foreach ($this->eventGuestQuery()->whereDoesntHave('qrCode')->orderBy('id')->get() as $guest) {
                $result = app(LegacyGuestIssuance::class)->generate($guest, $actor, $selectedEventId, true);
                $generated += $result['generated'] ? 1 : 0;
            }
        } catch (LegacyGuestIssuanceFailed|LegacyGuestIssuanceInterrupted $exception) {
            return $this->guestIssuanceFailure($actor, $exception instanceof LegacyGuestIssuanceInterrupted);
        }

        return back()->with('success', $generated.' missing QR '.($generated === 1 ? 'code was' : 'codes were').' generated.');
    }

    public function downloadAllQrCodes(LegacyQrMediaDelivery $media): Response|RedirectResponse
    {
        $actor = clone request()->user();
        $selectedEventId = session('selected_event_id');
        $this->currentEvent();

        try {
            // Membership is frozen before repairs; the whole archive is refused rather than reduced.
            $delivery = $media->archive($media->eligibleGuestIds($actor, $selectedEventId), $actor, $selectedEventId);
            $files = [];
            foreach ($delivery['items'] as $item) {
                $files[$this->qrZipDownloadFilename($item['guest'])] = $item['bytes'];
            }
            $zip = $this->createZip($files);
        } catch (LegacyGuestIssuanceFailed|LegacyGuestIssuanceInterrupted $exception) {
            return $this->guestIssuanceFailure($actor, $exception instanceof LegacyGuestIssuanceInterrupted);
        } catch (LegacyQrMediaUnavailable) {
            return $this->qrMediaFailure($actor);
        }

        return response($zip, 200, $this->privateMediaHeaders('application/zip',
            'attachment; filename="'.$delivery['event']->safeSlug().'-qr-codes-'.now()->format('Y-m-d-His').'.zip"'));
    }

    public function downloadSelectedQrCodes(Request $request, LegacyQrMediaDelivery $media): Response|RedirectResponse
    {
        $actor = clone $request->user();
        $selectedEventId = session('selected_event_id');
        $guestIds = $request->input('guest_ids', []);

        if (! is_array($guestIds)) {
            return back()->with('error', 'Select at least one guest with a QR code to export.');
        }
        $guestIds = collect($guestIds)
            ->filter(fn ($guestId): bool => filter_var($guestId, FILTER_VALIDATE_INT) !== false)
            ->map(fn ($guestId): int => (int) $guestId)->unique()->values();
        if ($guestIds->isEmpty()) {
            return back()->with('error', 'Select at least one guest with a QR code to export.');
        }
        $guests = $this->eventGuestQuery()->with('qrCode')->whereIn('id', $guestIds)
            ->whereHas('qrCode')->orderBy('name')->get();
        if ($guests->count() !== $guestIds->count()) {
            return back()->with('error', 'One or more selected guests cannot be exported. Refresh the list and try again.');
        }
        try {
            // The normalized requested set is frozen; it is refused rather than silently reduced.
            $delivery = $media->archive($guests->map(fn (Guest $guest): int => (int) $guest->getKey())->all(), $actor, $selectedEventId);
            $files = [];
            foreach ($delivery['items'] as $item) {
                $files[$this->qrDownloadFilename($item['guest'])] = $item['bytes'];
            }
            $zip = $this->createZip($files);
        } catch (LegacyGuestIssuanceFailed|LegacyGuestIssuanceInterrupted $exception) {
            return $this->guestIssuanceFailure($actor, $exception instanceof LegacyGuestIssuanceInterrupted);
        } catch (LegacyQrMediaUnavailable) {
            return $this->qrMediaFailure($actor);
        }

        return response($zip, 200, $this->privateMediaHeaders('application/zip',
            'attachment; filename="'.$delivery['event']->safeSlug().'-selected-qr-codes-'.now()->format('Y-m-d-His').'.zip"'));
    }

    public function exportQrList(Request $request, ExcelExportService $excelExports): StreamedResponse
    {
        return $this->adminReads()->export($request->user(), session('selected_event_id'),
            function (int $eventId, Event $event, LegacyAdminReadProjection $reads) use ($excelExports): StreamedResponse {
                $slug = $event->safeSlug();

                return $excelExports->downloadQrList($slug.'-qr-list-'.now()->format('Y-m-d').'.xlsx',
                    $reads->guestExportRows($eventId)->map(fn (GuestView $guest): array => [
                        $guest->id,
                        $guest->name,
                        $guest->phone_number,
                        $guest->passTypeLabel(),
                        $guest->allowed_entries,
                        $guest->used_entries,
                        $guest->status,
                        $this->qrStatusLabel($guest),
                        $guest->qrCode?->generated_at?->toDateTimeString() ?? '',
                        $guest->qrCode?->revoked_at?->toDateTimeString() ?? '',
                        $guest->qrCode ? $this->qrFilename($slug, $guest->name, $guest->id, $guest->manual_code) : '',
                    ]));
            });
    }

    public function activateQr(Guest $guest): RedirectResponse
    {
        $this->ensureGuestBelongsToSelectedEvent($guest);
        $this->assertCurrentEventAdmissionDataEditable();

        app(LegacyGuestWrites::class)->mutateWithQr(
            $guest,
            request()->user(),
            session('selected_event_id'),
            function (Guest $lockedGuest, ?QrCode $qrCode): void {
                if (! $qrCode) {
                    abort(404, 'QR code has not been generated.');
                }

                $qrCode->update([
                    'is_active' => true,
                    'revoked_at' => null,
                ]);
            },
        );

        return back()->with('success', 'QR code activated.');
    }

    public function deactivateQr(Guest $guest): RedirectResponse
    {
        $this->ensureGuestBelongsToSelectedEvent($guest);
        $this->assertCurrentEventAdmissionDataEditable();

        app(LegacyGuestWrites::class)->mutateWithQr(
            $guest,
            request()->user(),
            session('selected_event_id'),
            function (Guest $lockedGuest, ?QrCode $qrCode): void {
                if (! $qrCode) {
                    abort(404, 'QR code has not been generated.');
                }

                $qrCode->update([
                    'is_active' => false,
                ]);
            },
        );

        return back()->with('success', 'QR code deactivated.');
    }

    public function guests(Request $request): View
    {
        $nameSort = (string) $request->query('name_sort', '');

        return view('admin.guests', $this->adminReads()->guestListPage($request->user(), session('selected_event_id'), [
            'search' => trim((string) $request->query('search', '')),
            'pass_type' => (string) $request->query('pass_type', ''),
            'status' => (string) $request->query('status', ''),
            'name_sort' => in_array($nameSort, ['asc', 'desc'], true) ? $nameSort : '',
        ]));
    }

    public function create(): View
    {
        return view('admin.guests.create', [
            'guest' => new Guest([
                'pass_type' => Guest::PASS_SINGLE,
                'allowed_entries' => 1,
                'used_entries' => 0,
            ]),
        ]);
    }

    public function import(): View
    {
        // An unusable selection refuses and clears before anything else is considered.
        // This Event and actor stay frozen for the read attempt and the saved-result check.
        $event = $this->currentEvent();
        $eventId = PositiveIntegerId::parse($event->getRawOriginal($event->getKeyName()));
        $actor = auth()->user();

        return view('admin.guests.import', [
            ...$this->adminReads()->importPage($actor, $eventId),
            'rows' => [],
            'summary' => null,
            'headerErrors' => [],
            'generateQr' => true,
            'importToken' => session('guest_import.token'),
            'importResult' => $this->boundGuestImportResult($actor, $eventId),
        ]);
    }

    public function processGuestImport(
        Request $request,
        GuestQrImageService $qrImages,
        GuestImportExcelReader $excelReader,
    ): View|RedirectResponse {
        $this->assertCurrentEventAdmissionDataEditable();

        if ($request->input('import_action') === 'confirm') {
            return $this->confirmGuestImport($request, $qrImages);
        }

        $event = $this->currentEvent();
        $eventId = PositiveIntegerId::parse($event->getRawOriginal($event->getKeyName()));
        $actor = clone $request->user();
        $actorId = PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName()));
        abort_unless($eventId !== null, 404);
        abort_unless($actor->hasSafeIdentityId() && $actorId !== null
            && PositiveIntegerId::parse($actor->getAttributes()[$actor->getKeyName()] ?? null) === $actorId, 403);

        $validated = $request->validate([
            'excel_file' => [
                'required',
                'file',
                'max:2048',
                function (string $attribute, mixed $value, $fail): void {
                    if (! $value instanceof UploadedFile || strtolower($value->getClientOriginalExtension()) !== 'xlsx') {
                        $fail('Upload a Microsoft Excel .xlsx file.');
                    }
                },
            ],
            'generate_qr' => ['nullable', 'boolean'],
        ], [
            'excel_file.required' => 'Choose an Excel .xlsx file to import.',
            'excel_file.file' => 'Upload a valid Excel .xlsx file.',
            'excel_file.max' => 'The Excel workbook may not be larger than 2 MB.',
        ]);

        [$rows, $headerErrors] = $excelReader->read($validated['excel_file']->getRealPath());
        $rows = $this->validateParsedGuestImportRows($rows);
        // Duplicates and the layout come from one locked read attempt; the view looks nothing up.
        $page = $this->adminReads()->importPreviewPage($actor, $eventId, $rows);
        $rows = $page['rows'];
        $importableRows = collect($rows)
            ->filter(fn (array $row): bool => in_array($row['status'], ['new', 'duplicate'], true))
            ->values()
            ->all();

        $importToken = null;
        if ($importableRows === []) {
            $this->forgetGuestImportPreview($request);
        } else {
            $importToken = (string) Str::uuid();
            $request->session()->put('guest_import.rows', $rows);
            $request->session()->put('guest_import.token', $importToken);
            $request->session()->put('guest_import.event_id', $eventId);
            $request->session()->put('guest_import.actor_id', $actorId);
        }

        return view('admin.guests.import', [
            'adminLayout' => $page['adminLayout'],
            'rows' => $rows,
            'summary' => $this->guestImportPreviewSummary($rows),
            'headerErrors' => $headerErrors,
            'generateQr' => $request->boolean('generate_qr'),
            'importToken' => $importToken,
            'importResult' => null,
        ]);
    }

    public function sampleGuestImport(ExcelExportService $excelExports): StreamedResponse
    {
        return $excelExports->downloadGuestImportTemplate();
    }

    public function show(Request $request, Guest $guest): View
    {
        return view('admin.guests.show', $this->adminReads()->guestDetailPage($request->user(), session('selected_event_id'),
            $guest->getRawOriginal($guest->getKeyName()), true));
    }

    public function checkins(Request $request): View
    {
        return view('admin.checkins', $this->adminReads()->checkinPage($request->user(), session('selected_event_id'), $this->checkinFilters($request)));
    }

    public function notificationLogs(Request $request): View
    {
        return view('admin.notification-logs', $this->adminReads()->notificationLogPage($request->user(), session('selected_event_id')));
    }

    public function exportCheckins(Request $request): StreamedResponse
    {
        $filters = $this->checkinFilters($request);
        $filename = 'checkins-'.now()->format('Y-m-d-His').'.csv';
        $headings = [
            'Time',
            'Guest name',
            'Phone number',
            'Pass type',
            'Entries added',
            'Used entries after scan',
            'Remaining entries after scan',
            'Scan result',
            'Gate name',
            'Scanner user',
            'IP address',
        ];

        return $this->adminReads()->export($request->user(), session('selected_event_id'),
            fn (int $eventId, Event $event, LegacyAdminReadProjection $reads): StreamedResponse => $this->streamCsv($filename, $headings,
                $reads->checkinExportRows($eventId, $filters)->map(static fn (CheckinView $checkin): array => [
                    $checkin->checked_in_at?->toDateTimeString() ?? $checkin->created_at->toDateTimeString(),
                    $checkin->guest?->name ?? 'Unknown QR',
                    $checkin->guest?->phone_number ?? '',
                    $checkin->guest?->passTypeLabel() ?? '',
                    $checkin->entries_added,
                    $checkin->used_entries_after_scan,
                    $checkin->remaining_entries_after_scan,
                    $checkin->scan_result,
                    $checkin->gate_name ?? '',
                    $checkin->user?->name ?? '',
                    $checkin->ip_address ?? '',
                ])));
    }

    public function reports(Request $request): View
    {
        return view('admin.reports', $this->adminReads()->reportsPage($request->user(), session('selected_event_id')));
    }

    public function exportGuestList(Request $request): StreamedResponse
    {
        return $this->guestCsvExport($request, 'guest-list-');
    }

    public function exportCheckedInGuests(Request $request): StreamedResponse
    {
        return $this->guestCsvExport($request, 'checked-in-guests-', static function (Builder $query): void {
            $query->where('used_entries', '>', 0);
        });
    }

    public function exportRemainingGuests(Request $request): StreamedResponse
    {
        return $this->guestCsvExport($request, 'remaining-guests-', static function (Builder $query): void {
            $query->where('status', '<>', Guest::STATUS_CANCELLED)->whereColumn('used_entries', '<', 'allowed_entries');
        });
    }

    public function exportInvalidScans(Request $request): StreamedResponse
    {
        $filename = 'invalid-scan-attempts-'.now()->format('Y-m-d-His').'.csv';
        $headings = [
            'Time',
            'Guest name',
            'Phone number',
            'Pass type',
            'Scan result',
            'Gate name',
            'Scanner user',
            'IP address',
        ];

        return $this->adminReads()->export($request->user(), session('selected_event_id'),
            fn (int $eventId, Event $event, LegacyAdminReadProjection $reads): StreamedResponse => $this->streamCsv($filename, $headings,
                $reads->invalidScanExportRows($eventId)->map(static fn (CheckinView $checkin): array => [
                    $checkin->checked_in_at?->toDateTimeString() ?? $checkin->created_at->toDateTimeString(),
                    $checkin->guest?->name ?? 'Unknown QR',
                    $checkin->guest?->phone_number ?? '',
                    $checkin->guest?->passTypeLabel() ?? '',
                    $checkin->scan_result,
                    $checkin->gate_name ?? '',
                    $checkin->user?->name ?? '',
                    $checkin->ip_address ?? '',
                ])));
    }

    public function scannerUsers(): View
    {
        return view('admin.scanner-users', [
            'scannerUsers' => User::query()
                ->where('role', User::ROLE_SCANNER)
                ->latest()
                ->get(),
        ]);
    }

    public function settings(): View
    {
        return view('admin.settings', [
            'whatsappReadiness' => $this->whatsappReadiness(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $actor = clone $request->user();
        $selectedEventId = session('selected_event_id');
        $this->assertCurrentEventAdmissionDataEditable();
        $validated = $this->validateGuest($request);

        try {
            $guest = app(LegacyGuestIssuance::class)->create($validated, $actor, $selectedEventId);
        } catch (LegacyGuestIssuanceFailed|LegacyGuestIssuanceInterrupted $exception) {
            return $this->guestIssuanceFailure($actor, $exception instanceof LegacyGuestIssuanceInterrupted);
        }

        return redirect()->route('admin.guests.show', $guest)
            ->with('success', 'Guest pass created and QR code generated.');
    }

    public function edit(Request $request, Guest $guest): View
    {
        return view('admin.guests.edit', $this->adminReads()->guestDetailPage($request->user(), session('selected_event_id'),
            $guest->getRawOriginal($guest->getKeyName()), false));
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request, Guest $guest): RedirectResponse
    {
        $this->ensureGuestBelongsToSelectedEvent($guest);
        $this->assertCurrentEventAdmissionDataEditable();

        $validated = $this->validateGuest($request, $guest);

        app(LegacyGuestWrites::class)->mutate(
            $guest,
            $request->user(),
            session('selected_event_id'),
            function (Guest $lockedGuest) use ($validated): void {
                if ($validated['allowed_entries'] < $lockedGuest->used_entries) {
                    throw ValidationException::withMessages([
                        'allowed_entries' => 'Allowed entries cannot be less than the '.$lockedGuest->used_entries.' entries already used.',
                    ]);
                }

                $lockedGuest->update([
                    'name' => $validated['name'],
                    'phone_number' => $validated['phone_number'],
                    'pass_type' => $validated['pass_type'],
                    'allowed_entries' => $validated['allowed_entries'],
                ]);
            },
        );

        return redirect()
            ->route('admin.guests.show', $guest)
            ->with('success', 'Guest pass updated.');
    }

    public function destroy(Guest $guest): RedirectResponse
    {
        $this->ensureGuestBelongsToSelectedEvent($guest);
        $this->assertCurrentEventAdmissionDataEditable();

        $actor = request()->user();
        try {
            app(LegacyGuestDeletion::class)->delete($guest, $actor, session('selected_event_id'));
        } catch (LegacyGuestDeletionFailed) {
            return $this->guestDeletionFailure($actor);
        }

        return redirect()
            ->route('admin.guests.index')
            ->with('success', 'Guest pass deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->assertCurrentEventAdmissionDataEditable();

        $validated = $request->validate([
            'guest_ids' => ['required', 'array', 'min:1', 'max:50'],
            'guest_ids.*' => ['required', 'integer', 'distinct'],
        ], [
            'guest_ids.required' => 'Select at least one guest to delete.',
            'guest_ids.max' => 'You can delete up to 50 guests at a time.',
        ]);

        $actor = $request->user();
        try {
            $deleted = app(LegacyGuestDeletion::class)->deleteMany($validated['guest_ids'], $actor, session('selected_event_id'));
        } catch (LegacyGuestDeletionFailed) {
            return $this->guestDeletionFailure($actor);
        }

        return back()->with('success', $deleted.' '.($deleted === 1 ? 'guest pass deleted.' : 'guest passes deleted.'));
    }

    public function generateQr(Guest $guest, GuestQrImageService $qrImages): RedirectResponse
    {
        $actor = clone request()->user();
        $selectedEventId = session('selected_event_id');
        $this->ensureGuestBelongsToSelectedEvent($guest);
        $this->assertCurrentEventAdmissionDataEditable();

        try {
            $result = app(LegacyGuestIssuance::class)->generate($guest, $actor, $selectedEventId);
        } catch (LegacyGuestIssuanceFailed|LegacyGuestIssuanceInterrupted $exception) {
            return $this->guestIssuanceFailure($actor, $exception instanceof LegacyGuestIssuanceInterrupted);
        }

        return back()->with('success', $result['replaced'] ? 'QR code regenerated.' : 'QR code generated.');
    }

    public function qr(Guest $guest, LegacyQrMediaDelivery $media): Response|RedirectResponse
    {
        return $this->deliverQrImage($guest, $media, 'inline');
    }

    public function downloadQr(Guest $guest, LegacyQrMediaDelivery $media): Response|RedirectResponse
    {
        return $this->deliverQrImage($guest, $media, 'attachment');
    }

    /** Guarded repair, then a fresh complete private delivery; no public URL or cache shortcut. */
    private function deliverQrImage(Guest $guest, LegacyQrMediaDelivery $media, string $disposition): Response|RedirectResponse
    {
        $actor = clone request()->user();
        $selectedEventId = session('selected_event_id');
        $this->ensureGuestBelongsToSelectedEvent($guest);

        try {
            $delivery = $media->single($guest, $actor, $selectedEventId);
        } catch (LegacyGuestIssuanceFailed|LegacyGuestIssuanceInterrupted $exception) {
            return $this->guestIssuanceFailure($actor, $exception instanceof LegacyGuestIssuanceInterrupted);
        } catch (LegacyQrMediaUnavailable) {
            return $this->qrMediaFailure($actor);
        }

        return response($delivery['bytes'], 200, $this->privateMediaHeaders('image/png',
            $disposition.'; filename="'.$this->qrDownloadFilename($delivery['guest']).'"'));
    }

    /** Private, uncached, non-sniffed media; full body only (no ranges, validators or 304). */
    private function privateMediaHeaders(string $contentType, string $disposition): array
    {
        return [
            'Content-Type' => $contentType,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private function qrMediaFailure(User $actor): RedirectResponse
    {
        $language = in_array($actor->preferred_language, User::SUPPORTED_LANGUAGES, true) ? $actor->preferred_language : 'sw';

        return back()->with('error', __('guest_qr_media.failure', [], $language));
    }

    public function cancel(Guest $guest): RedirectResponse
    {
        $this->ensureGuestBelongsToSelectedEvent($guest);
        $this->assertCurrentEventAdmissionDataEditable();

        app(LegacyGuestWrites::class)->mutate(
            $guest,
            request()->user(),
            session('selected_event_id'),
            function (Guest $lockedGuest): void {
                $lockedGuest->update([
                    'status' => Guest::STATUS_CANCELLED,
                ]);
            },
        );

        return back()->with('success', 'Pass cancelled.');
    }

    public function revoke(Guest $guest): RedirectResponse
    {
        $this->ensureGuestBelongsToSelectedEvent($guest);
        $this->assertCurrentEventAdmissionDataEditable();

        app(LegacyGuestWrites::class)->mutateWithQr(
            $guest,
            request()->user(),
            session('selected_event_id'),
            function (Guest $lockedGuest, ?QrCode $qrCode): void {
                $qrCode?->update([
                    'is_active' => false,
                    'revoked_at' => now(),
                ]);
            },
        );

        return back()->with('success', 'QR code revoked.');
    }

    public function restore(Guest $guest): RedirectResponse
    {
        $this->ensureGuestBelongsToSelectedEvent($guest);
        $this->assertCurrentEventAdmissionDataEditable();

        app(LegacyGuestWrites::class)->mutateWithQr(
            $guest,
            request()->user(),
            session('selected_event_id'),
            function (Guest $lockedGuest, ?QrCode $qrCode): void {
                $lockedGuest->update([
                    'status' => Guest::STATUS_ACTIVE,
                ]);

                $qrCode?->update([
                    'is_active' => true,
                    'revoked_at' => null,
                ]);
            },
        );

        return back()->with('success', 'Pass restored.');
    }

    private function currentEvent(): Event
    {
        $event = app(LegacyEventResolver::class)->find(session('selected_event_id'));
        if ($event === null) {
            session()->forget(['selected_event_id', 'guest_import']);
            abort(404);
        }

        return $event;
    }

    private function eventGuestQuery(): Builder
    {
        return Guest::query()->where('event_id', $this->currentEvent()->id);
    }

    private function assertCurrentEventAdmissionDataEditable(bool $lockForUpdate = false): void
    {
        app(OfflineEventLockService::class)
            ->assertEventAdmissionDataEditable($this->currentEvent(), $lockForUpdate);
    }

    private function ensureGuestBelongsToSelectedEvent(Guest $guest): Guest
    {
        abort_unless((int) $guest->event_id === $this->currentEvent()->id, 404);

        return $guest;
    }

    private function guestDeletionFailure(User $actor): RedirectResponse
    {
        $language = in_array($actor->preferred_language, User::SUPPORTED_LANGUAGES, true) ? $actor->preferred_language : 'sw';

        return back()->with('error', __('guest_deletion.failure', [], $language));
    }

    private function checkinFilters(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));
        $scanResult = (string) $request->query('scan_result', '');
        $gateName = trim((string) $request->query('gate_name', ''));
        $date = (string) $request->query('date', '');

        if (! in_array($scanResult, LegacyAdminReadProjection::SCAN_RESULT_OPTIONS, true)) {
            $scanResult = '';
        }

        if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = '';
        }

        return [
            'search' => $search,
            'scan_result' => $scanResult,
            'gate_name' => $gateName,
            'date' => $date,
        ];
    }

    private function whatsappReadiness(): array
    {
        $meta = config('services.whatsapp.meta', []);
        $hasAccessToken = $this->configValueIsFilled($meta['access_token'] ?? null);
        $hasPhoneNumberId = $this->configValueIsFilled($meta['phone_number_id'] ?? null);
        $hasBusinessAccountId = $this->configValueIsFilled($meta['business_account_id'] ?? null);
        $hasWebhookVerifyToken = $this->configValueIsFilled($meta['webhook_verify_token'] ?? null);
        $hasAppSecret = $this->configValueIsFilled($meta['app_secret'] ?? null);

        return [
            'provider_label' => 'Meta WhatsApp Cloud API',
            'credentials_configured' => $hasAccessToken && $hasPhoneNumberId,
            'phone_number_id_configured' => $hasPhoneNumberId,
            'business_account_id_configured' => $hasBusinessAccountId,
            'webhook_status' => $hasWebhookVerifyToken
                ? 'Verification token configured; delivery status processing pending'
                : 'Pending webhook verify token',
            'template_status' => 'Pending Meta template approval review',
            'app_secret_configured' => $hasAppSecret,
        ];
    }

    private function configValueIsFilled(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }

    private function guestCsvHeadings(): array
    {
        return [
            'Guest name',
            'Phone number',
            'Pass type',
            'Allowed entries',
            'Used entries',
            'Remaining entries',
            'Status',
            'QR status',
        ];
    }

    /** One locked attempt for the Guest rows; the complete CSV bytes exist before it ends. */
    private function guestCsvExport(Request $request, string $prefix, ?Closure $constraint = null): StreamedResponse
    {
        $filename = $prefix.now()->format('Y-m-d-His').'.csv';

        return $this->adminReads()->export($request->user(), session('selected_event_id'),
            fn (int $eventId, Event $event, LegacyAdminReadProjection $reads): StreamedResponse => $this->streamCsv($filename, $this->guestCsvHeadings(),
                $reads->guestExportRows($eventId, $constraint)->map(fn (GuestView $guest): array => $this->guestCsvRow($guest))));
    }

    private function adminReads(): LegacyAdminReadProjection
    {
        return app(LegacyAdminReadProjection::class);
    }

    private function guestCsvRow(GuestView $guest): array
    {
        return [
            $guest->name,
            $guest->phone_number,
            $guest->passTypeLabel(),
            $guest->allowed_entries,
            $guest->used_entries,
            $guest->remainingEntries(),
            $guest->status,
            $this->qrStatusLabel($guest),
        ];
    }

    private function confirmGuestImport(Request $request, GuestQrImageService $qrImages): RedirectResponse
    {
        $actor = clone $request->user();
        $eventId = PositiveIntegerId::parse(session('selected_event_id'));
        $actorId = PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName()));
        abort_unless($actor->hasSafeIdentityId() && $actorId !== null
            && PositiveIntegerId::parse($actor->getAttributes()[$actor->getKeyName()] ?? null) === $actorId, 403);
        abort_unless($eventId !== null, 404);
        $validated = $request->validate([
            'generate_qr' => ['nullable', 'boolean'],
            'import_token' => ['required', 'string'],
            'duplicate_actions' => ['nullable', 'array'],
            'duplicate_actions.*' => ['required', 'in:skip,add_anyway'],
        ], [
            'import_token.required' => 'Upload and preview an Excel file before importing.',
            'duplicate_actions.*.in' => 'Choose a valid duplicate action.',
        ]);
        $rows = $request->session()->get('guest_import.rows', []);
        $sessionToken = $request->session()->get('guest_import.token');
        $boundEvent = PositiveIntegerId::parse($request->session()->get('guest_import.event_id'));
        $boundActor = PositiveIntegerId::parse($request->session()->get('guest_import.actor_id'));
        if (! is_array($rows) || $rows === [] || ! is_string($sessionToken)
            || ! hash_equals($sessionToken, $validated['import_token'])
            || $boundEvent !== $eventId || $boundActor !== $actorId) {
            $this->forgetGuestImportPreview($request);

            return redirect()->route('admin.guests.import')
                ->withErrors(['excel_file' => 'Upload and preview an Excel file before importing.']);
        }
        $result = $this->emptyGuestImportResult(count($rows));
        $duplicateActions = $validated['duplicate_actions'] ?? [];
        $generateQr = $request->boolean('generate_qr');
        $this->forgetGuestImportPreview($request);
        $request->session()->forget(['guest_import.result', 'guest_import.result_context']);

        try {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $this->processGuestImportRow($row, $duplicateActions, $eventId, $generateQr, $actor, $result);
                }
            }
        } catch (LegacyGuestIssuanceInterrupted) {
            $this->assertGuestIssuancePresentation($actor, $eventId);
            $result['interrupted'] = true;
            $result['summary']['imported_successfully'] = $result['summary']['imported_new_contacts'] + $result['summary']['duplicates_added'];

            return $this->guestIssuanceFailure($actor, true, redirect()->route('admin.guests.import'))
                ->with('guest_import.result', $result)
                ->with('guest_import.result_context', ['actor_id' => $actorId, 'event_id' => $eventId]);
        } catch (HttpExceptionInterface|OfflineEventLockedException $exception) {
            $request->session()->forget(['guest_import.result', 'guest_import.result_context']);
            throw $exception;
        }
        $result['summary']['imported_successfully'] = $result['summary']['imported_new_contacts'] + $result['summary']['duplicates_added'];
        // Private results are published only under current actor/Event authority (as on interruption).
        $this->assertGuestIssuancePresentation($actor, $eventId);

        return redirect()->route('admin.guests.import')->with('guest_import.result', $result)
            ->with('guest_import.result_context', ['actor_id' => $actorId, 'event_id' => $eventId])
            ->with('success', 'Import completed. Imported successfully: '.$result['summary']['imported_successfully'].'.');
    }

    private function guestImportPreviewSummary(array $rows): array
    {
        $new = collect($rows)->where('status', 'new')->count();
        $duplicates = collect($rows)->where('status', 'duplicate')->count();
        $invalid = collect($rows)->where('status', 'invalid')->count();

        return [
            'total' => count($rows),
            'new' => $new,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
            'valid' => $new + $duplicates,
        ];
    }

    private function emptyGuestImportResult(int $totalRows): array
    {
        return [
            'summary' => [
                'total_rows' => $totalRows,
                'imported_successfully' => 0,
                'imported_new_contacts' => 0,
                'duplicates_added' => 0,
                'duplicates_skipped' => 0,
                'invalid_rows' => 0,
                'failed_rows' => 0,
                'generated_qr_codes' => 0,
            ],
            'imported' => [],
            'added_duplicates' => [],
            'skipped_duplicates' => [],
            'invalid' => [],
            'failed' => [],
        ];
    }

    private function processGuestImportRow(
        array $row,
        array $duplicateActions,
        int $eventId,
        bool $generateQr,
        User $actor,
        array &$result,
    ): void {
        $rowNumber = (int) ($row['row_number'] ?? 0);
        $previewStatus = (string) ($row['status'] ?? 'invalid');
        if ($previewStatus === 'invalid' || ! ($row['is_valid'] ?? false)) {
            $result['summary']['invalid_rows']++;
            $result['invalid'][] = $this->guestImportResultRow($row, implode(' ', $row['errors'] ?? ['Invalid row.']));

            return;
        }
        $validated = $this->validateGuestImportRow($row['data'] ?? []);
        if ($validated['errors'] !== []) {
            $row['data'] = $validated['data'];
            $result['summary']['invalid_rows']++;
            $result['invalid'][] = $this->guestImportResultRow($row, implode(' ', $validated['errors']));

            return;
        }
        $row['data'] = $validated['data'];
        $policy = $previewStatus === 'duplicate'
            ? ($this->duplicateActionForRow($rowNumber, $duplicateActions) === 'skip' ? 'skip' : 'add_anyway')
            : 'reject_if_match';
        try {
            $outcome = app(LegacyGuestIssuance::class)->importRow($validated['data'], $policy, $actor, $eventId, $generateQr);
        } catch (LegacyGuestIssuanceFailed $exception) {
            $result['summary']['failed_rows']++;
            $result['failed'][] = $this->guestImportResultRow($row, $this->guestImportFailureMessage($exception));

            return;
        }
        if ($outcome['outcome'] === 'skipped') {
            $result['summary']['duplicates_skipped']++;
            $result['skipped_duplicates'][] = $this->guestImportResultRow($row, 'Duplicate skipped.');

            return;
        }
        if ($outcome['outcome'] === 'conflict') {
            $result['summary']['failed_rows']++;
            $result['failed'][] = $this->guestImportResultRow($row,
                'Duplicate conflict. This name or phone number now matches a guest in the selected event; review it again before adding anyway.');

            return;
        }
        $guest = $outcome['guest'];
        if ($previewStatus === 'duplicate') {
            $result['summary']['duplicates_added']++;
            $result['added_duplicates'][] = $this->guestImportResultRow($row, 'Duplicate added anyway.', $guest);
        } else {
            $result['summary']['imported_new_contacts']++;
            $result['imported'][] = $this->guestImportResultRow($row, 'Imported.', $guest);
        }
        if ($outcome['generated_qr']) {
            $result['summary']['generated_qr_codes']++;
        }
    }

    private function duplicateActionForRow(int $rowNumber, array $duplicateActions): string
    {
        $action = $duplicateActions[(string) $rowNumber] ?? $duplicateActions[$rowNumber] ?? 'skip';

        return in_array($action, ['skip', 'add_anyway'], true) ? $action : 'skip';
    }

    private function guestImportResultRow(array $row, string $reason, ?Guest $guest = null): array
    {
        $data = $row['data'] ?? [];

        return [
            'row_number' => (int) ($row['row_number'] ?? 0),
            'name' => (string) ($data['name'] ?? ''),
            'phone_number' => (string) ($data['phone_number'] ?? ''),
            'pass_type' => (string) ($data['pass_type'] ?? ''),
            'allowed_entries' => (string) ($data['allowed_entries'] ?? ''),
            'reason' => $reason,
            'guest_id' => $guest?->id,
            'manual_code' => $guest?->manual_code,
        ];
    }

    private function guestImportFailureMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'manual_code') || str_contains($message, 'guests_event_manual_code_unique')) {
            return 'Unable to generate unique manual code.';
        }

        if (str_contains($message, 'qr_token')) {
            return 'Unable to generate unique QR token.';
        }

        return 'Database error. This row was not imported.';
    }

    private function validateParsedGuestImportRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $rawRow = is_array($row['data'] ?? null) ? $row['data'] : [];
            $validated = $this->validateGuestImportRow($rawRow);

            return [
                'row_number' => (int) ($row['row_number'] ?? 0),
                'data' => $validated['errors'] === [] ? $validated['data'] : $rawRow,
                'errors' => $validated['errors'],
                'is_valid' => $validated['errors'] === [],
            ];
        }, $rows);
    }

    private function validateGuestImportRow(array $row): array
    {
        $errors = [];
        $name = trim((string) ($row['name'] ?? ''));
        $phoneNumber = trim((string) ($row['phone_number'] ?? ''));
        $normalizedPhoneNumber = null;
        $passType = strtolower(trim((string) ($row['pass_type'] ?? '')));
        $allowedEntriesRaw = trim((string) ($row['allowed_entries'] ?? ''));
        $allowedEntries = null;

        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        if ($phoneNumber === '') {
            $errors[] = 'Phone number is required.';
        } else {
            $normalizedPhoneNumber = app(PhoneNumberService::class)->normalize($phoneNumber);

            if ($normalizedPhoneNumber === null) {
                $errors[] = PhoneNumberService::INVALID_MESSAGE;
            }
        }

        if (! in_array($passType, [Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL], true)) {
            $errors[] = 'Pass type must be single, double, or special.';
        }

        if ($allowedEntriesRaw === '') {
            $errors[] = 'Allowed entries is required.';
        } else {
            $allowedEntries = filter_var($allowedEntriesRaw, FILTER_VALIDATE_INT);

            if ($allowedEntries === false) {
                $errors[] = 'Allowed entries must be a whole number.';
            } else {
                $allowedEntries = (int) $allowedEntries;

                if ($allowedEntries < 1) {
                    $errors[] = 'Allowed entries cannot be less than 1.';
                }

                if ($allowedEntries > Guest::MAX_ALLOWED_ENTRIES) {
                    $errors[] = 'Allowed entries cannot be more than '.Guest::MAX_ALLOWED_ENTRIES.'.';
                }
            }
        }

        if ($allowedEntries !== null && in_array($passType, [Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL], true)) {
            if ($passType === Guest::PASS_SINGLE && $allowedEntries !== 1) {
                $errors[] = 'Single passes must have allowed_entries = 1.';
            }

            if ($passType === Guest::PASS_DOUBLE && $allowedEntries !== 2) {
                $errors[] = 'Double passes must have allowed_entries = 2.';
            }

            if ($passType === Guest::PASS_SPECIAL && ($allowedEntries < Guest::MIN_SPECIAL_ENTRIES || $allowedEntries > Guest::MAX_ALLOWED_ENTRIES)) {
                $errors[] = 'Special / Family passes must have allowed_entries from '.Guest::MIN_SPECIAL_ENTRIES.' to '.Guest::MAX_ALLOWED_ENTRIES.'.';
            }
        }

        return [
            'data' => [
                'name' => $name,
                'phone_number' => $normalizedPhoneNumber ?? $phoneNumber,
                'pass_type' => $passType,
                'allowed_entries' => $allowedEntries ?? $allowedEntriesRaw,
            ],
            'errors' => $errors,
        ];
    }

    private function qrStatusLabel(GuestView $guest): string
    {
        if (! $guest->qrCode) {
            return 'missing';
        }

        if ($guest->qrCode->is_active && ! $guest->qrCode->revoked_at) {
            return 'active';
        }

        return $guest->qrCode->revoked_at ? 'revoked' : 'inactive';
    }

    private function qrDownloadFilename(Guest $guest): string
    {
        $guest->loadMissing('event');

        return $this->qrFilename($guest->event?->safeSlug() ?? 'event', $guest->name, $guest->id, $guest->manual_code);
    }

    /** The QR PNG file name from scalars only: no model, relation or session read. */
    private function qrFilename(string $safeEvent, ?string $name, int $id, mixed $code): string
    {
        $safeName = (string) Str::of($name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(80, '');

        if ($safeName === '') {
            $safeName = 'guest';
        }

        $manualCode = preg_match('/^\d{4}$/', (string) $code)
            ? (string) $code
            : 'code';

        return $safeEvent.'_'.$safeName.'_'.str_pad((string) $id, 3, '0', STR_PAD_LEFT).'_qr_'.$manualCode.'.png';
    }

    private function qrZipDownloadFilename(Guest $guest): string
    {
        return Str::beforeLast($this->qrDownloadFilename($guest), '.png')
            .'_'.(int) $guest->allowed_entries.'.png';
    }

    /**
     * Stored (uncompressed) ZIP32. Unsupported entry-count, name-length, size or
     * offset bounds refuse the whole archive before packing; nothing is truncated
     * or wrapped.
     */
    private function createZip(array $files): string
    {
        $offset = 0;
        $directorySize = 0;
        foreach ($files as $filename => $contents) {
            if (! is_string($contents)) {
                throw new LegacyQrMediaUnavailable;
            }
            $nameLength = strlen(str_replace('\\', '/', (string) $filename));
            // 0xFFFF and 0xFFFFFFFF are ZIP64 sentinels, so ZIP32 count/size/offset fields stay below them.
            if ($nameLength > 0xFFFF || strlen($contents) >= 0xFFFFFFFF) {
                throw new LegacyQrMediaUnavailable;
            }
            $offset += 30 + $nameLength + strlen($contents);
            $directorySize += 46 + $nameLength;
        }
        if (count($files) >= 0xFFFF || $offset >= 0xFFFFFFFF || $directorySize >= 0xFFFFFFFF
            || $offset + $directorySize + 22 > 0xFFFFFFFF) {
            throw new LegacyQrMediaUnavailable;
        }

        $zip = '';
        $centralDirectory = '';
        $offset = 0;
        [$dosTime, $dosDate] = $this->dosDateTime();

        foreach ($files as $filename => $contents) {
            $filename = str_replace('\\', '/', $filename);
            $crc = crc32($contents);
            $size = strlen($contents);

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034B50,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                strlen($filename),
                0
            ).$filename.$contents;

            $zip .= $localHeader;

            $centralDirectory .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50,
                20,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                strlen($filename),
                0,
                0,
                0,
                0,
                0,
                $offset
            ).$filename;

            $offset += strlen($localHeader);
        }

        return $zip.$centralDirectory.pack(
            'VvvvvVVv',
            0x06054B50,
            0,
            0,
            count($files),
            count($files),
            strlen($centralDirectory),
            $offset,
            0
        );
    }

    private function dosDateTime(): array
    {
        $now = now();

        return [
            ($now->second >> 1) | ($now->minute << 5) | ($now->hour << 11),
            $now->day | ($now->month << 5) | (($now->year - 1980) << 9),
        ];
    }

    /**
     * @param  resource  $handle
     */
    private function writeCsvRow($handle, array $row): void
    {
        $cells = array_map(static function (mixed $value): mixed {
            if (! is_string($value) || $value === '') {
                return $value;
            }

            // These downloads target spreadsheets. The tab stays in raw CSV
            // data; never alter the stored guest or history value.
            if (ord($value[0]) < 32
                || preg_match('/\A[\x00-\x20]*[=+\-@]/', $value) === 1
                || preg_match('/\A[\p{Z}\p{C}\s]*[=+\-@＝＋－＠]/u', $value) === 1) {
                return "\t".$value;
            }

            return $value;
        }, $row);

        // Disable the proprietary backslash escape so quoted fields round-trip.
        fputcsv($handle, $cells, ',', '"', '');
    }

    /**
     * Rows are read and serialized before the response is returned; the
     * deferred callback only emits frozen bytes, never late private SQL.
     */
    private function streamCsv(string $filename, array $headings, callable|iterable $rows): StreamedResponse
    {
        // In memory, not php://temp: private rows never spill to an OS temp file.
        $handle = fopen('php://memory', 'w+');
        try {
            $this->writeCsvRow($handle, $headings);
            foreach (is_callable($rows) ? $rows() : $rows as $row) {
                $this->writeCsvRow($handle, $row);
            }
            rewind($handle);
            $bytes = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function verificationUrl(Guest $guest): string
    {
        $guest->loadMissing('qrCode');

        if (! $guest->qrCode) {
            abort(404, 'QR code has not been generated.');
        }

        return $this->verificationUrlForToken($guest->revealQrToken());
    }

    /**
     * @return array{name: string, phone_number: string, pass_type: string, allowed_entries: int}
     *
     * @throws ValidationException
     */
    private function validateGuest(Request $request, ?Guest $guest = null): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'phone_number' => ['required', 'string', 'max:40'],
            'pass_type' => ['required', 'in:single,double,special'],
            'allowed_entries' => ['required', 'integer', 'min:1', 'max:'.Guest::MAX_ALLOWED_ENTRIES],
        ], [
            'name.required' => 'Enter the guest name.',
            'phone_number.required' => 'Enter the guest phone number.',
            'pass_type.in' => 'Choose a valid pass type.',
            'allowed_entries.required' => 'Enter the number of guests allowed.',
            'allowed_entries.min' => 'Allowed entries cannot be less than 1.',
            'allowed_entries.max' => 'Allowed entries cannot be more than '.Guest::MAX_ALLOWED_ENTRIES.'.',
        ]);

        $validator->after(function ($validator) use ($request, $guest): void {
            $passType = (string) $request->input('pass_type');
            $requestedEntries = (int) $request->input('allowed_entries');
            $phoneNumber = (string) $request->input('phone_number');

            if ($phoneNumber !== '' && app(PhoneNumberService::class)->normalize($phoneNumber) === null) {
                $validator->errors()->add('phone_number', PhoneNumberService::INVALID_MESSAGE);
            }

            if (! in_array($passType, [Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL], true)) {
                return;
            }

            if ($passType === Guest::PASS_SPECIAL && $requestedEntries < Guest::MIN_SPECIAL_ENTRIES) {
                $validator->errors()->add('allowed_entries', 'Special / Family passes must allow between '.Guest::MIN_SPECIAL_ENTRIES.' and '.Guest::MAX_ALLOWED_ENTRIES.' guests.');

                return;
            }

            $allowedEntries = Guest::allowedEntriesForPassType($passType, $requestedEntries);

            // Never let an edit lower the allowance below entries already admitted.
            if ($guest && $allowedEntries < $guest->used_entries) {
                $validator->errors()->add(
                    'allowed_entries',
                    'Allowed entries cannot be less than the '.$guest->used_entries.' entries already used.'
                );
            }
        });

        $validated = $validator->validate();
        $validated['phone_number'] = app(PhoneNumberService::class)->normalizeOrFail($validated['phone_number']);
        $validated['allowed_entries'] = Guest::allowedEntriesForPassType(
            $validated['pass_type'],
            (int) $validated['allowed_entries']
        );

        return $validated;
    }

    private function verificationUrlForToken(string $token): string
    {
        return route('scanner.verify-token', ['token' => $token]);
    }

    /**
     * A saved result is shown only to the actor and selected Event it was
     * produced for, as frozen by the caller before its read attempt.
     * Unbound or mismatched state is discarded, never shown.
     */
    private function boundGuestImportResult(User $actor, ?int $eventId): ?array
    {
        $result = session('guest_import.result');
        if ($result === null) {
            return null;
        }
        $context = session('guest_import.result_context');
        $actorId = PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName()));
        if (! is_array($result) || ! is_array($context) || $actorId === null || $eventId === null
            || PositiveIntegerId::parse($context['actor_id'] ?? null) !== $actorId
            || PositiveIntegerId::parse($context['event_id'] ?? null) !== $eventId) {
            session()->forget(['guest_import.result', 'guest_import.result_context']);
            $language = in_array($actor->preferred_language, User::SUPPORTED_LANGUAGES, true) ? $actor->preferred_language : 'sw';
            // Generic re-preview guidance; nothing about the withheld rows is revealed.
            session()->now('error', __('guest_issuance.result_withheld', [], $language));

            return null;
        }

        return $result;
    }

    private function forgetGuestImportPreview(Request $request): void
    {
        $request->session()->forget(['guest_import.rows', 'guest_import.token', 'guest_import.event_id', 'guest_import.actor_id']);
    }

    private function assertGuestIssuancePresentation(User $actor, int $eventId): void
    {
        abort_unless(app(LegacyEventResolver::class)->find($eventId) instanceof Event, 404);
        $actorId = PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName()));
        abort_unless($actor->hasSafeIdentityId() && $actorId !== null
            && PositiveIntegerId::parse($actor->getAttributes()[$actor->getKeyName()] ?? null) === $actorId, 403);
        try {
            $current = User::query()->useWritePdo()->whereKey($actorId)->first();
        } catch (Throwable) {
            abort(403);
        }
        abort_unless($current instanceof User
            && PositiveIntegerId::parse($current->getRawOriginal($current->getKeyName())) === $actorId
            && $current->getRawOriginal('role') === User::ROLE_ADMIN
            && in_array($current->getRawOriginal('is_active'), [true, 1, '1'], true), 403);
    }

    private function guestIssuanceFailure(User $actor, bool $interrupted = false, ?RedirectResponse $response = null): RedirectResponse
    {
        $language = in_array($actor->preferred_language, User::SUPPORTED_LANGUAGES, true) ? $actor->preferred_language : 'sw';

        return ($response ?? back())->with('error', __('guest_issuance.'.($interrupted ? 'interrupted' : 'failure'), [], $language));
    }
}
