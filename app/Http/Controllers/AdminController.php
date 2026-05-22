<?php

namespace App\Http\Controllers;

use App\Models\Checkin;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use App\Services\QrCodeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    public function index(Request $request): View
    {
        return $this->dashboard();
    }

    public function dashboard(): View
    {
        return view('admin.dashboard', [
            'stats' => $this->dashboardStats(),
            'recentGuests' => Guest::query()
                ->with('qrCode')
                ->latest()
                ->take(6)
                ->get(),
            'recentCheckins' => Checkin::query()
                ->with(['guest', 'user'])
                ->latest('checked_in_at')
                ->latest()
                ->take(8)
                ->get(),
        ]);
    }

    public function qrCodes(Request $request): View
    {
        $filter = (string) $request->query('filter', '');
        $allowedFilters = ['has_qr', 'missing_qr', 'active_qr', 'revoked_qr', 'fully_used', 'unused'];

        if (! in_array($filter, $allowedFilters, true)) {
            $filter = '';
        }

        $guestsQuery = Guest::query()
            ->with('qrCode')
            ->latest();

        match ($filter) {
            'has_qr' => $guestsQuery->whereHas('qrCode'),
            'missing_qr' => $guestsQuery->whereDoesntHave('qrCode'),
            'active_qr' => $guestsQuery->whereHas('qrCode', function (Builder $query): void {
                $query->where('is_active', true)->whereNull('revoked_at');
            }),
            'revoked_qr' => $guestsQuery->whereHas('qrCode', function (Builder $query): void {
                $query->where('is_active', false)->orWhereNotNull('revoked_at');
            }),
            'fully_used' => $guestsQuery->where('status', Guest::STATUS_FULLY_USED),
            'unused' => $guestsQuery->where('status', Guest::STATUS_ACTIVE)->where('used_entries', 0),
            default => null,
        };

        return view('admin.qr-codes', [
            'guests' => $guestsQuery->paginate(15)->withQueryString(),
            'activeFilter' => $filter,
            'stats' => [
                'total_guests' => Guest::query()->count(),
                'has_qr' => Guest::query()->whereHas('qrCode')->count(),
                'missing_qr' => Guest::query()->whereDoesntHave('qrCode')->count(),
                'active' => QrCode::query()->where('is_active', true)->whereNull('revoked_at')->count(),
                'revoked' => QrCode::query()
                    ->where(function ($query): void {
                        $query->where('is_active', false)->orWhereNotNull('revoked_at');
                    })
                ->count(),
                'fully_used' => Guest::query()->where('status', Guest::STATUS_FULLY_USED)->count(),
                'unused' => Guest::query()->where('status', Guest::STATUS_ACTIVE)->where('used_entries', 0)->count(),
            ],
        ]);
    }

    public function generateMissingQrCodes(QrCodeService $qrCodes): RedirectResponse
    {
        $generated = 0;

        Guest::query()
            ->whereDoesntHave('qrCode')
            ->orderBy('id')
            ->get()
            ->each(function (Guest $guest) use ($qrCodes, &$generated): void {
                $this->generateQrForGuest($guest, $qrCodes);
                $generated++;
            });

        return back()->with('success', $generated.' missing QR '.($generated === 1 ? 'code was' : 'codes were').' generated.');
    }

    public function downloadAllQrCodes(QrCodeService $qrCodes): Response
    {
        $files = [];

        Guest::query()
            ->with('qrCode')
            ->whereHas('qrCode')
            ->orderBy('name')
            ->get()
            ->each(function (Guest $guest) use ($qrCodes, &$files): void {
                $qrCode = $this->ensureQrImage($guest, $qrCodes);
                $files[$this->qrDownloadFilename($guest)] = Storage::disk('public')->get($qrCode->qr_image_path);
            });

        return response($this->createZip($files), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="wedding-qr-codes-'.now()->format('Y-m-d-His').'.zip"',
        ]);
    }

    public function exportQrList(): StreamedResponse
    {
        return $this->streamCsv('qr-list-'.now()->format('Y-m-d-His').'.csv', [
            'Guest ID',
            'Guest name',
            'Phone number',
            'Pass type',
            'Allowed entries',
            'Used entries',
            'Guest status',
            'QR status',
            'Generated at',
            'Revoked at',
            'Download file name',
        ], function () {
            return Guest::query()
                ->with('qrCode')
                ->orderBy('name')
                ->get()
                ->map(function (Guest $guest): array {
                    return [
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
                        $guest->qrCode ? $this->qrDownloadFilename($guest) : '',
                    ];
                });
        });
    }

    public function activateQr(Guest $guest): RedirectResponse
    {
        if (! $guest->qrCode) {
            abort(404, 'QR code has not been generated.');
        }

        $guest->qrCode->update([
            'is_active' => true,
            'revoked_at' => null,
        ]);

        return back()->with('success', 'QR code activated.');
    }

    public function deactivateQr(Guest $guest): RedirectResponse
    {
        if (! $guest->qrCode) {
            abort(404, 'QR code has not been generated.');
        }

        $guest->qrCode->update([
            'is_active' => false,
        ]);

        return back()->with('success', 'QR code deactivated.');
    }

    public function guests(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $passType = (string) $request->query('pass_type', '');
        $status = (string) $request->query('status', '');

        $guestsQuery = Guest::query()
            ->with('qrCode')
            ->withCount('checkins')
            ->latest();

        if ($search !== '') {
            $guestsQuery->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('phone_number', 'like', '%'.$search.'%');
            });
        }

        if (in_array($passType, [Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL], true)) {
            $guestsQuery->where('pass_type', $passType);
        }

        match ($status) {
            'unused' => $guestsQuery->where('status', Guest::STATUS_ACTIVE)->where('used_entries', 0),
            Guest::STATUS_PARTIALLY_USED => $guestsQuery->where('status', Guest::STATUS_PARTIALLY_USED),
            Guest::STATUS_FULLY_USED => $guestsQuery->where('status', Guest::STATUS_FULLY_USED),
            Guest::STATUS_CANCELLED => $guestsQuery->where('status', Guest::STATUS_CANCELLED),
            default => null,
        };

        return view('admin.guests', [
            'guests' => $guestsQuery->paginate(12)->withQueryString(),
            'filters' => [
                'search' => $search,
                'pass_type' => $passType,
                'status' => $status,
            ],
            'counts' => $this->guestFilterCounts(),
        ]);
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

    public function show(Guest $guest): View
    {
        return view('admin.guests.show', [
            'guest' => $guest->load(['qrCode', 'checkins.user']),
        ]);
    }

    public function checkins(Request $request): View
    {
        $filters = $this->checkinFilters($request);

        return view('admin.checkins', [
            'checkins' => $this->filteredCheckinsQuery($filters)
                ->with(['guest', 'qrCode', 'user'])
                ->paginate(20)
                ->withQueryString(),
            'summary' => $this->checkinSummary(),
            'filters' => $filters,
            'scanResults' => $this->scanResultOptions(),
            'gateNames' => $this->checkinGateNames(),
        ]);
    }

    public function exportCheckins(Request $request): StreamedResponse
    {
        $filters = $this->checkinFilters($request);
        $filename = 'checkins-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($filters): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
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
            ]);

            $this->filteredCheckinsQuery($filters)
                ->with(['guest', 'user'])
                ->get()
                ->each(function (Checkin $checkin) use ($handle): void {
                    fputcsv($handle, [
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
                    ]);
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function reports(): View
    {
        return view('admin.reports', [
            'stats' => $this->dashboardStats(),
            'admissionSummary' => $this->guestAdmissionSummary(),
            'passTypeReport' => $this->passTypeReport(),
            'statusReport' => $this->statusReport(),
            'gateReport' => $this->gateReport(),
            'timeReport' => $this->timeReport(),
        ]);
    }

    public function exportGuestList(): StreamedResponse
    {
        return $this->streamCsv('guest-list-'.now()->format('Y-m-d-His').'.csv', $this->guestCsvHeadings(), function () {
            return Guest::query()
                ->with('qrCode')
                ->orderBy('name')
                ->get()
                ->map(fn (Guest $guest): array => $this->guestCsvRow($guest));
        });
    }

    public function exportCheckedInGuests(): StreamedResponse
    {
        return $this->streamCsv('checked-in-guests-'.now()->format('Y-m-d-His').'.csv', $this->guestCsvHeadings(), function () {
            return Guest::query()
                ->with('qrCode')
                ->where('used_entries', '>', 0)
                ->orderBy('name')
                ->get()
                ->map(fn (Guest $guest): array => $this->guestCsvRow($guest));
        });
    }

    public function exportRemainingGuests(): StreamedResponse
    {
        return $this->streamCsv('remaining-guests-'.now()->format('Y-m-d-His').'.csv', $this->guestCsvHeadings(), function () {
            return Guest::query()
                ->with('qrCode')
                ->where('status', '<>', Guest::STATUS_CANCELLED)
                ->whereColumn('used_entries', '<', 'allowed_entries')
                ->orderBy('name')
                ->get()
                ->map(fn (Guest $guest): array => $this->guestCsvRow($guest));
        });
    }

    public function exportInvalidScans(): StreamedResponse
    {
        return $this->streamCsv('invalid-scan-attempts-'.now()->format('Y-m-d-His').'.csv', [
            'Time',
            'Guest name',
            'Phone number',
            'Pass type',
            'Scan result',
            'Gate name',
            'Scanner user',
            'IP address',
        ], function () {
            return Checkin::query()
                ->with(['guest', 'user'])
                ->whereIn('scan_result', $this->invalidScanResults())
                ->latest('checked_in_at')
                ->latest()
                ->get()
                ->map(function (Checkin $checkin): array {
                    return [
                        $checkin->checked_in_at?->toDateTimeString() ?? $checkin->created_at->toDateTimeString(),
                        $checkin->guest?->name ?? 'Unknown QR',
                        $checkin->guest?->phone_number ?? '',
                        $checkin->guest?->passTypeLabel() ?? '',
                        $checkin->scan_result,
                        $checkin->gate_name ?? '',
                        $checkin->user?->name ?? '',
                        $checkin->ip_address ?? '',
                    ];
                });
        });
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
        return view('admin.settings');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateGuest($request);

        $guest = Guest::query()->create([
            'name' => $validated['name'],
            'phone_number' => $validated['phone_number'],
            'pass_type' => $validated['pass_type'],
            'allowed_entries' => $validated['allowed_entries'],
            'status' => Guest::STATUS_ACTIVE,
        ]);

        $this->generateQrForGuest($guest, app(QrCodeService::class));

        return redirect()
            ->route('admin.guests.show', $guest)
            ->with('success', 'Guest pass created and QR code generated.');
    }

    public function edit(Guest $guest): View
    {
        return view('admin.guests.edit', [
            'guest' => $guest->load('qrCode'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request, Guest $guest): RedirectResponse
    {
        $validated = $this->validateGuest($request, $guest);

        $guest->update([
            'name' => $validated['name'],
            'phone_number' => $validated['phone_number'],
            'pass_type' => $validated['pass_type'],
            'allowed_entries' => $validated['allowed_entries'],
        ]);

        return redirect()
            ->route('admin.guests.show', $guest)
            ->with('success', 'Guest pass updated.');
    }

    public function destroy(Guest $guest): RedirectResponse
    {
        if ($guest->qrCode?->qr_image_path) {
            Storage::disk('public')->delete($guest->qrCode->qr_image_path);
        }

        $guest->delete();

        return redirect()
            ->route('admin.guests.index')
            ->with('success', 'Guest pass deleted.');
    }

    public function generateQr(Guest $guest, QrCodeService $qrCodes): RedirectResponse
    {
        $hadQrCode = $guest->qrCode()->exists();
        $this->generateQrForGuest($guest, $qrCodes);

        return back()->with('success', $hadQrCode ? 'QR code regenerated.' : 'QR code generated.');
    }

    public function qr(Guest $guest, QrCodeService $qrCodes): Response
    {
        $qrCode = $this->ensureQrImage($guest, $qrCodes);

        return response(Storage::disk('public')->get($qrCode->qr_image_path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function downloadQr(Guest $guest, QrCodeService $qrCodes): Response
    {
        $qrCode = $this->ensureQrImage($guest, $qrCodes);
        $filename = $this->qrDownloadFilename($guest);

        return response(Storage::disk('public')->get($qrCode->qr_image_path), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function cancel(Guest $guest): RedirectResponse
    {
        $guest->update([
            'status' => Guest::STATUS_CANCELLED,
        ]);

        return back()->with('success', 'Pass cancelled.');
    }

    public function revoke(Guest $guest): RedirectResponse
    {
        $guest->qrCode?->update([
            'is_active' => false,
            'revoked_at' => now(),
        ]);

        return back()->with('success', 'QR code revoked.');
    }

    public function restore(Guest $guest): RedirectResponse
    {
        $guest->update([
            'status' => Guest::STATUS_ACTIVE,
        ]);

        $guest->qrCode?->update([
            'is_active' => true,
            'revoked_at' => null,
        ]);

        return back()->with('success', 'Pass restored.');
    }

    private function dashboardStats(): array
    {
        $totalAllowed = (int) Guest::query()
            ->where('status', '<>', Guest::STATUS_CANCELLED)
            ->sum('allowed_entries');
        $totalUsed = (int) Guest::query()->sum('used_entries');
        $activeUsed = (int) Guest::query()
            ->where('status', '<>', Guest::STATUS_CANCELLED)
            ->sum('used_entries');

        return [
            'total_guests' => Guest::query()->count(),
            'single_passes' => Guest::query()->where('pass_type', Guest::PASS_SINGLE)->count(),
            'double_passes' => Guest::query()->where('pass_type', Guest::PASS_DOUBLE)->count(),
            'special_passes' => Guest::query()->where('pass_type', Guest::PASS_SPECIAL)->count(),
            'unused_passes' => Guest::query()->where('status', Guest::STATUS_ACTIVE)->where('used_entries', 0)->count(),
            'partially_used' => Guest::query()->where('status', Guest::STATUS_PARTIALLY_USED)->count(),
            'fully_used' => Guest::query()->where('status', Guest::STATUS_FULLY_USED)->count(),
            'cancelled' => Guest::query()->where('status', Guest::STATUS_CANCELLED)->count(),
            'qr_codes' => QrCode::query()->count(),
            'revoked_qr_codes' => QrCode::query()
                ->where(function ($query): void {
                    $query->where('is_active', false)->orWhereNotNull('revoked_at');
                })
                ->count(),
            'admitted_entries' => $totalUsed,
            'remaining_entries' => max(0, $totalAllowed - $activeUsed),
            'total_allowed_entries' => $totalAllowed,
            'today_scans' => Checkin::query()->whereDate('checked_in_at', today())->count(),
            'today_admitted' => Checkin::query()
                ->whereDate('checked_in_at', today())
                ->where('scan_result', Checkin::RESULT_ADMITTED)
                ->sum('entries_added'),
        ];
    }

    private function guestAdmissionSummary(): array
    {
        $totalExpected = (int) Guest::query()
            ->where('status', '<>', Guest::STATUS_CANCELLED)
            ->sum('allowed_entries');
        $activeUsed = (int) Guest::query()
            ->where('status', '<>', Guest::STATUS_CANCELLED)
            ->sum('used_entries');

        return [
            'total_invites' => Guest::query()->count(),
            'total_expected_admissions' => $totalExpected,
            'total_admitted_people' => (int) Guest::query()->sum('used_entries'),
            'total_remaining_admissions' => max(0, $totalExpected - $activeUsed),
        ];
    }

    private function passTypeReport(): array
    {
        return [
            Guest::PASS_SINGLE => Guest::query()->where('pass_type', Guest::PASS_SINGLE)->count(),
            Guest::PASS_DOUBLE => Guest::query()->where('pass_type', Guest::PASS_DOUBLE)->count(),
            Guest::PASS_SPECIAL => Guest::query()->where('pass_type', Guest::PASS_SPECIAL)->count(),
        ];
    }

    private function statusReport(): array
    {
        return [
            'unused' => Guest::query()->where('status', Guest::STATUS_ACTIVE)->where('used_entries', 0)->count(),
            Guest::STATUS_PARTIALLY_USED => Guest::query()->where('status', Guest::STATUS_PARTIALLY_USED)->count(),
            Guest::STATUS_FULLY_USED => Guest::query()->where('status', Guest::STATUS_FULLY_USED)->count(),
            Guest::STATUS_CANCELLED => Guest::query()->where('status', Guest::STATUS_CANCELLED)->count(),
        ];
    }

    private function gateReport(): array
    {
        $rows = [];
        $ensureGate = function (?string $gateName) use (&$rows): string {
            $key = filled($gateName) ? (string) $gateName : 'No gate';

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'gate_name' => $key,
                    'admissions' => 0,
                    'invalid_attempts' => 0,
                ];
            }

            return $key;
        };

        Checkin::query()
            ->where('scan_result', Checkin::RESULT_ADMITTED)
            ->get(['gate_name', 'entries_added'])
            ->each(function (Checkin $checkin) use (&$rows, $ensureGate): void {
                $gateName = $ensureGate($checkin->gate_name);
                $rows[$gateName]['admissions'] += (int) $checkin->entries_added;
            });

        Checkin::query()
            ->whereIn('scan_result', $this->invalidScanResults())
            ->get(['gate_name'])
            ->each(function (Checkin $checkin) use (&$rows, $ensureGate): void {
                $gateName = $ensureGate($checkin->gate_name);
                $rows[$gateName]['invalid_attempts']++;
            });

        ksort($rows);

        return array_values($rows);
    }

    private function timeReport(): array
    {
        $hours = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $hours[sprintf('%02d:00', $hour)] = [
                'hour' => sprintf('%02d:00', $hour),
                'admissions' => 0,
            ];
        }

        Checkin::query()
            ->where('scan_result', Checkin::RESULT_ADMITTED)
            ->get(['checked_in_at', 'created_at', 'entries_added'])
            ->each(function (Checkin $checkin) use (&$hours): void {
                $time = $checkin->checked_in_at ?? $checkin->created_at;
                $hour = $time->format('H:00');
                $hours[$hour]['admissions'] += (int) $checkin->entries_added;
            });

        return array_values($hours);
    }

    private function guestFilterCounts(): array
    {
        return [
            'all' => Guest::query()->count(),
            'unused' => Guest::query()->where('status', Guest::STATUS_ACTIVE)->where('used_entries', 0)->count(),
            Guest::STATUS_PARTIALLY_USED => Guest::query()->where('status', Guest::STATUS_PARTIALLY_USED)->count(),
            Guest::STATUS_FULLY_USED => Guest::query()->where('status', Guest::STATUS_FULLY_USED)->count(),
            Guest::STATUS_CANCELLED => Guest::query()->where('status', Guest::STATUS_CANCELLED)->count(),
            'revoked' => QrCode::query()
                ->where(function ($query): void {
                    $query->where('is_active', false)->orWhereNotNull('revoked_at');
                })
                ->count(),
        ];
    }

    private function checkinFilters(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));
        $scanResult = (string) $request->query('scan_result', '');
        $gateName = trim((string) $request->query('gate_name', ''));
        $date = (string) $request->query('date', '');

        if (! in_array($scanResult, $this->scanResultOptions(), true)) {
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

    private function filteredCheckinsQuery(array $filters): Builder
    {
        $query = Checkin::query();

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->whereHas('guest', function (Builder $guestQuery) use ($search): void {
                $guestQuery->where('name', 'like', '%'.$search.'%')
                    ->orWhere('phone_number', 'like', '%'.$search.'%');
            });
        }

        if ($filters['scan_result'] !== '') {
            $query->where('scan_result', $filters['scan_result']);
        }

        if ($filters['gate_name'] !== '') {
            $query->where('gate_name', $filters['gate_name']);
        }

        if ($filters['date'] !== '') {
            $query->whereDate('checked_in_at', $filters['date']);
        }

        return $query
            ->latest('checked_in_at')
            ->latest();
    }

    private function checkinSummary(): array
    {
        $todayQuery = Checkin::query()->whereDate('checked_in_at', today());

        return [
            'total_scans_today' => (clone $todayQuery)->count(),
            'successful_admissions_today' => (int) (clone $todayQuery)
                ->where('scan_result', Checkin::RESULT_ADMITTED)
                ->sum('entries_added'),
            'invalid_attempts_today' => (clone $todayQuery)
                ->whereIn('scan_result', [Checkin::RESULT_INVALID, Checkin::RESULT_ERROR])
                ->count(),
            'already_used_attempts_today' => (clone $todayQuery)
                ->where('scan_result', Checkin::RESULT_ALREADY_USED)
                ->count(),
            'cancelled_revoked_attempts_today' => (clone $todayQuery)
                ->whereIn('scan_result', [Checkin::RESULT_CANCELLED, Checkin::RESULT_REVOKED])
                ->count(),
        ];
    }

    private function checkinGateNames()
    {
        return Checkin::query()
            ->whereNotNull('gate_name')
            ->where('gate_name', '<>', '')
            ->distinct()
            ->orderBy('gate_name')
            ->pluck('gate_name');
    }

    private function scanResultOptions(): array
    {
        return [
            Checkin::RESULT_ADMITTED,
            Checkin::RESULT_VALID,
            Checkin::RESULT_INVALID,
            Checkin::RESULT_ALREADY_USED,
            Checkin::RESULT_CANCELLED,
            Checkin::RESULT_REVOKED,
            Checkin::RESULT_ERROR,
        ];
    }

    private function invalidScanResults(): array
    {
        return [
            Checkin::RESULT_INVALID,
            Checkin::RESULT_ALREADY_USED,
            Checkin::RESULT_CANCELLED,
            Checkin::RESULT_REVOKED,
            Checkin::RESULT_ERROR,
        ];
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

    private function guestCsvRow(Guest $guest): array
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

    private function qrStatusLabel(Guest $guest): string
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
        $safeName = (string) Str::of($guest->name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(80, '');

        if ($safeName === '') {
            $safeName = 'guest';
        }

        return $safeName.'_'.str_pad((string) $guest->id, 3, '0', STR_PAD_LEFT).'_qr.png';
    }

    private function createZip(array $files): string
    {
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
                0x04034b50,
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
                0x02014b50,
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
            0x06054b50,
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

    private function streamCsv(string $filename, array $headings, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $headings);

            foreach ($rows() as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function uniqueToken(): string
    {
        do {
            $token = Guest::makeSecureToken();
        } while (QrCode::query()->where('qr_token', $token)->exists());

        return $token;
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
            'allowed_entries' => ['required', 'integer', 'min:1', 'max:10'],
        ], [
            'name.required' => 'Enter the guest name.',
            'phone_number.required' => 'Enter the guest phone number.',
            'pass_type.in' => 'Choose a valid pass type.',
            'allowed_entries.required' => 'Enter the number of guests allowed.',
            'allowed_entries.min' => 'Allowed entries cannot be less than 1.',
            'allowed_entries.max' => 'Allowed entries cannot be more than 10.',
        ]);

        $validator->after(function ($validator) use ($request, $guest): void {
            $passType = (string) $request->input('pass_type');
            $requestedEntries = (int) $request->input('allowed_entries');

            if (! in_array($passType, [Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL], true)) {
                return;
            }

            if ($passType === Guest::PASS_SPECIAL && $requestedEntries < 3) {
                $validator->errors()->add('allowed_entries', 'Special / Family passes must allow between 3 and 10 guests.');
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
        $validated['allowed_entries'] = Guest::allowedEntriesForPassType(
            $validated['pass_type'],
            (int) $validated['allowed_entries']
        );

        return $validated;
    }

    private function generateQrForGuest(Guest $guest, QrCodeService $qrCodes): QrCode
    {
        $guest->loadMissing('qrCode');
        $oldImagePath = $guest->qrCode?->qr_image_path;
        $token = $this->uniqueToken();
        $imagePath = $this->qrImagePath($guest, $token);

        $this->storeQrImage($imagePath, $qrCodes->png($this->verificationUrlForToken($token)));

        if ($oldImagePath && $oldImagePath !== $imagePath) {
            Storage::disk('public')->delete($oldImagePath);
        }

        $qrCode = $guest->qrCode()->updateOrCreate(
            ['guest_id' => $guest->id],
            [
                'qr_token' => $token,
                'qr_image_path' => $imagePath,
                'is_active' => true,
                'generated_at' => now(),
                'revoked_at' => null,
            ]
        );

        $guest->setRelation('qrCode', $qrCode);

        return $qrCode;
    }

    private function ensureQrImage(Guest $guest, QrCodeService $qrCodes): QrCode
    {
        $guest->loadMissing('qrCode');

        if (! $guest->qrCode) {
            return $this->generateQrForGuest($guest, $qrCodes);
        }

        $qrCode = $guest->qrCode;
        if ($qrCode->qr_image_path && Storage::disk('public')->exists($qrCode->qr_image_path)) {
            return $qrCode;
        }

        $imagePath = $this->qrImagePath($guest, $qrCode->qr_token);
        $this->storeQrImage($imagePath, $qrCodes->png($this->verificationUrlForToken($qrCode->qr_token)));

        $qrCode->update([
            'qr_image_path' => $imagePath,
            'generated_at' => $qrCode->generated_at ?? now(),
        ]);

        return $qrCode->fresh();
    }

    private function verificationUrlForToken(string $token): string
    {
        return route('scanner.verify-token', ['token' => $token]);
    }

    private function qrImagePath(Guest $guest, string $token): string
    {
        $safeToken = preg_replace('/[^A-Za-z0-9]/', '', $token) ?: hash('sha256', $token);

        return 'qr-codes/guest-'.$guest->id.'-'.$safeToken.'.png';
    }

    private function storeQrImage(string $path, string $contents): void
    {
        $stored = Storage::disk('public')->put($path, $contents);

        if (! $stored) {
            throw new RuntimeException('Unable to save QR code image.');
        }
    }
}
