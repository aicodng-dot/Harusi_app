<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\ScanLog;
use App\Models\User;
use App\Services\OfflineEventLockService;
use App\Services\PhoneNumberService;
use App\Services\QrTokenCanonicalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ScannerController extends Controller
{
    public function dashboard(Request $request): View
    {
        $assignedEvent = $this->scannerEvent($request);
        $todayQuery = $this->scannerCheckinsQuery($request)
            ->whereDate('checked_in_at', today());

        return view('scanner.dashboard', [
            'scannerUser' => $request->user(),
            'assignedEvent' => $assignedEvent,
            'todayEntries' => (clone $todayQuery)
                ->where('scan_result', Checkin::RESULT_ADMITTED)
                ->sum('entries_added'),
            'todayScans' => (clone $todayQuery)->count(),
            'todayInvalidAttempts' => (clone $todayQuery)
                ->whereIn('scan_result', [
                    Checkin::RESULT_INVALID,
                    Checkin::RESULT_WRONG_EVENT,
                    Checkin::RESULT_ALREADY_USED,
                    Checkin::RESULT_CANCELLED,
                    Checkin::RESULT_REVOKED,
                    Checkin::RESULT_ERROR,
                ])
                ->count(),
            'recentCheckins' => $this->scannerCheckinsQuery($request)
                ->with('guest')
                ->latest('checked_in_at')
                ->latest()
                ->take(3)
                ->get(),
        ]);
    }

    public function index(Request $request): View
    {
        return view('scanner.index', [
            'initialToken' => $request->query('token', ''),
            'assignedEvent' => $this->scannerEvent($request),
        ]);
    }

    public function ticket(Request $request, string $token): View
    {
        return view('scanner.index', [
            'initialToken' => $token,
            'assignedEvent' => $this->scannerEvent($request),
        ]);
    }

    public function recentScans(Request $request): View
    {
        return view('scanner.recent-scans', [
            'assignedEvent' => $this->scannerEvent($request),
            'checkins' => $this->scannerCheckinsQuery($request)
                ->with('guest')
                ->latest('checked_in_at')
                ->latest()
                ->paginate(15),
        ]);
    }

    public function manualSearch(Request $request): View
    {
        // Same Event rule as admit(): an explicit assigned/selected Event must
        // exist and be active now; it never falls back to the default Event.
        $eventId = ($request->user()->event_id === null ? null : (int) $request->user()->event_id)
            ?? $request->session()->get('selected_event_id')
            ?? Event::defaultEvent()->getKey();
        abort_unless((is_int($eventId) || is_string($eventId))
            && filter_var($eventId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 403);
        $event = Event::query()->useWritePdo()->whereKey($eventId)->first();
        abort_unless($event && $event->status === Event::STATUS_ACTIVE, 403);

        $search = trim((string) $request->query('q', ''));
        $guests = collect();
        $manualSearchError = null;

        if ($search !== '') {
            $guestsQuery = Guest::query()
                ->with('qrCode')
                ->where('event_id', $event->id);

            if (preg_match('/^\d{4}$/', $search)) {
                $guestsQuery->where('manual_code', $search);
            } else {
                $phoneNumber = app(PhoneNumberService::class)->normalize($search);

                if ($phoneNumber === null) {
                    $manualSearchError = PhoneNumberService::INVALID_MESSAGE;
                } else {
                    $guestsQuery->where('phone_number', $phoneNumber);
                }
            }

            if ($manualSearchError === null) {
                $guests = $guestsQuery
                    ->orderBy('name')
                    ->take(12)
                    ->get();
            }
        }

        return view('scanner.manual-search', [
            'search' => $search,
            'guests' => $guests,
            'manualSearchError' => $manualSearchError,
            'assignedEvent' => $event,
        ]);
    }

    public function manualAdmit(Request $request, OfflineEventLockService $locks): RedirectResponse
    {
        $validated = $request->validate([
            'guest_id' => ['required', 'integer', 'min:1'],
            'entries_to_admit' => ['required', 'integer', 'min:1', 'max:'.Guest::MAX_ALLOWED_ENTRIES],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $quantity = (int) $validated['entries_to_admit'];
        $search = trim((string) ($validated['search'] ?? ''));

        $result = DB::transaction(function () use ($request, $validated, $quantity, $locks) {
            $locks->assertLaravelAdmissionAllowed($this->scannerEvent($request), true);

            $guest = Guest::query()
                ->with('qrCode')
                ->whereKey((int) $validated['guest_id'])
                ->where('event_id', $this->scannerEvent($request)->id)
                ->lockForUpdate()
                ->first();

            if (! $guest) {
                return [
                    'ok' => false,
                    'message' => 'Guest was not found.',
                ];
            }

            if ($guest->qrCode && (! $guest->qrCode->is_active || $guest->qrCode->revoked_at)) {
                return [
                    'ok' => false,
                    'message' => 'This QR code has been revoked. Admission was not recorded.',
                ];
            }

            if ($guest->status === Guest::STATUS_CANCELLED) {
                return [
                    'ok' => false,
                    'message' => 'Cancelled pass. Admission was not recorded.',
                ];
            }

            $remainingEntries = $guest->remainingEntries();
            if ($guest->status === Guest::STATUS_FULLY_USED || $remainingEntries <= 0) {
                return [
                    'ok' => false,
                    'message' => 'Already fully used. Admission was not recorded.',
                ];
            }

            if ($guest->allowed_entries > Guest::MAX_ALLOWED_ENTRIES) {
                return [
                    'ok' => false,
                    'message' => 'Allowed entries may not exceed '.Guest::MAX_ALLOWED_ENTRIES.'.',
                ];
            }

            if ($quantity > $remainingEntries) {
                return [
                    'ok' => false,
                    'message' => 'Requested entries exceed the remaining allowance.',
                ];
            }

            $guest = $this->applyAdmissionToLockedGuest($guest, $quantity);
            if (! $guest) {
                return [
                    'ok' => false,
                    'message' => 'Requested entries exceed the remaining allowance.',
                ];
            }

            Admission::query()->create([
                'event_id' => $guest->event_id,
                'guest_id' => $guest->id,
                'quantity' => $quantity,
                'admitted_by' => $request->user()?->name,
                'device_label' => 'Manual search',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'admitted_at' => now(),
            ]);

            $this->recordScan($guest, $guest->qrCode, $quantity, Checkin::RESULT_ADMITTED, 'Manual admission recorded.', $request);

            return [
                'ok' => true,
                'message' => $quantity.' entr'.($quantity === 1 ? 'y' : 'ies').' recorded for '.$guest->name.'.',
            ];
        }, 3);

        $redirect = redirect()->route('scanner.manual-search', $search !== '' ? ['q' => $search] : []);

        return $result['ok']
            ? $redirect->with('success', $result['message'])
            : $redirect->withErrors(['entries_to_admit' => $result['message']]);
    }

    public function validateQr(Request $request): JsonResponse
    {
        // The offline middleware may already have cached the Event relation.
        // Check its current status before reading a QR or recording a scan,
        // while retaining the existing scanner assignment/fallback resolution.
        $eventStatus = Event::query()->useWritePdo()
            ->whereKey($this->scannerEvent($request)->getKey())
            ->value('status');
        abort_unless($eventStatus === Event::STATUS_ACTIVE, 403);

        $validated = $request->validate([
            'qr_token' => ['nullable', 'string', 'max:2048', 'required_without:scanned_url'],
            'scanned_url' => ['nullable', 'string', 'max:2048', 'required_without:qr_token'],
        ]);

        $rawValue = $validated['qr_token'] ?? $validated['scanned_url'] ?? '';
        $token = $this->extractToken($rawValue);

        if ($token === '') {
            $message = 'This QR code is not registered.';
            $this->recordScan(null, null, null, Checkin::RESULT_INVALID, $message, $request);

            return response()->json([
                'status' => Checkin::RESULT_INVALID,
                'message' => $message,
            ]);
        }

        $qrCode = $this->findQrCode($token);
        if (! $qrCode) {
            $message = 'This QR code is not registered.';
            $this->recordScan(null, null, null, Checkin::RESULT_INVALID, $message, $request);

            return response()->json([
                'status' => Checkin::RESULT_INVALID,
                'message' => $message,
            ]);
        }

        if (! $qrCode->guest) {
            $message = 'This QR code is not registered.';
            $this->recordScan(null, $qrCode, null, Checkin::RESULT_INVALID, $message, $request);

            return response()->json([
                'status' => Checkin::RESULT_INVALID,
                'message' => $message,
            ]);
        }

        if (! $this->qrBelongsToScannerEvent($qrCode, $request)) {
            $message = 'This QR code belongs to a different event.';
            $this->recordScan(null, null, null, Checkin::RESULT_WRONG_EVENT, $message, $request, $this->scannerEvent($request));

            return response()->json([
                'status' => Checkin::RESULT_WRONG_EVENT,
                'message' => $message,
            ]);
        }

        if (! $qrCode->is_active || $qrCode->revoked_at) {
            $message = 'This QR code has been revoked.';
            $this->recordScan($qrCode->guest, $qrCode, null, Checkin::RESULT_REVOKED, $message, $request);

            return response()->json([
                'status' => Checkin::RESULT_REVOKED,
                'message' => $message,
            ]);
        }

        $guest = $qrCode->guest;
        $remainingEntries = $guest->remainingEntries();

        if ($guest->status === Guest::STATUS_CANCELLED) {
            $message = 'This pass has been cancelled.';
            $this->recordScan($guest, $qrCode, null, Checkin::RESULT_CANCELLED, $message, $request);

            return response()->json([
                'status' => Checkin::RESULT_CANCELLED,
                'message' => $message,
            ]);
        }

        if ($guest->status === Guest::STATUS_FULLY_USED || $remainingEntries <= 0) {
            $message = 'This pass has already been fully used.';
            $this->recordScan($guest, $qrCode, null, Checkin::RESULT_ALREADY_USED, $message, $request);

            return response()->json([
                'status' => Checkin::RESULT_ALREADY_USED,
                'message' => $message,
            ]);
        }

        if (! in_array($guest->status, [Guest::STATUS_ACTIVE, Guest::STATUS_PARTIALLY_USED], true)) {
            $message = 'This QR code is not registered.';
            $this->recordScan($guest, $qrCode, null, Checkin::RESULT_INVALID, $message, $request);

            return response()->json([
                'status' => Checkin::RESULT_INVALID,
                'message' => $message,
            ]);
        }

        $this->recordScan($guest, $qrCode, null, Checkin::RESULT_VALID, 'Valid pass.', $request);

        return response()->json([
            'status' => Checkin::RESULT_VALID,
            'guest_id' => $guest->id,
            'guest_name' => $guest->name,
            'phone_number' => $guest->phone_number,
            'pass_type' => $guest->pass_type,
            'allowed_entries' => $guest->allowed_entries,
            'used_entries' => $guest->used_entries,
            'remaining_entries' => $remainingEntries,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        // Recheck the current status even when offline middleware cached the Event.
        $eventStatus = Event::query()->useWritePdo()
            ->whereKey($this->scannerEvent($request)->getKey())
            ->value('status');
        abort_unless($eventStatus === Event::STATUS_ACTIVE, 403);

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:2048'],
        ]);

        $token = $this->extractToken($validated['token']);
        $qrCode = $this->findQrCode($token);

        if (! $qrCode) {
            $this->recordScan(null, null, null, Checkin::RESULT_INVALID, 'QR token was not found.', $request);

            return response()->json([
                'ok' => false,
                'result' => Checkin::RESULT_INVALID,
                'message' => 'Invalid QR code. This pass was not found.',
            ], 404);
        }

        if (! $qrCode->guest) {
            $message = 'This QR code is not registered.';
            $this->recordScan(null, $qrCode, null, Checkin::RESULT_INVALID, $message, $request);

            return response()->json([
                'ok' => false,
                'result' => Checkin::RESULT_INVALID,
                'status' => Checkin::RESULT_INVALID,
                'message' => $message,
            ], 404);
        }

        if (! $this->qrBelongsToScannerEvent($qrCode, $request)) {
            $message = 'This QR code belongs to a different event.';
            $this->recordScan(null, null, null, Checkin::RESULT_WRONG_EVENT, $message, $request, $this->scannerEvent($request));

            return response()->json([
                'ok' => false,
                'result' => Checkin::RESULT_WRONG_EVENT,
                'status' => Checkin::RESULT_WRONG_EVENT,
                'message' => $message,
            ], 409);
        }

        $result = $this->resultFor($qrCode);
        $this->recordScan($qrCode->guest, $qrCode, null, $result['result'], $result['message'], $request);

        return response()->json([
            'ok' => $result['can_admit'],
            'result' => $result['result'],
            'message' => $result['message'],
            'guest' => $this->guestPayload($qrCode->guest),
            'token' => $token,
        ]);
    }

    public function admit(Request $request, OfflineEventLockService $locks): JsonResponse
    {
        $validated = $request->validate([
            'guest_id' => ['nullable', 'integer', 'min:1'],
            'qr_token' => ['nullable', 'string', 'max:2048', 'required_without:token'],
            'token' => ['nullable', 'string', 'max:2048', 'required_without:qr_token'],
            'entries_to_admit' => ['nullable', 'integer', 'min:1', 'max:'.Guest::MAX_ALLOWED_ENTRIES, 'required_without:quantity'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.Guest::MAX_ALLOWED_ENTRIES, 'required_without:entries_to_admit'],
            'admitted_by' => ['nullable', 'string', 'max:80'],
            'device_label' => ['nullable', 'string', 'max:80'],
        ]);

        $token = $this->extractToken($validated['qr_token'] ?? $validated['token'] ?? '');
        $quantity = (int) ($validated['entries_to_admit'] ?? $validated['quantity'] ?? 0);
        $guestId = isset($validated['guest_id']) ? (int) $validated['guest_id'] : null;

        $scannerId = $request->user()->getAuthIdentifier();
        $assignedEventId = $request->user()->event_id === null ? null : (int) $request->user()->event_id;
        $eventId = $assignedEventId
            ?? $request->session()->get('selected_event_id')
            ?? Event::defaultEvent()->getKey();

        $result = DB::transaction(function () use ($request, $validated, $token, $quantity, $guestId, $locks, $scannerId, $assignedEventId, $eventId) {
            // Keep authority locked through admission, ahead of Session/QR/Guest.
            // A missing explicit Event must never resolve to the default Event.
            abort_unless((is_int($eventId) || is_string($eventId))
                && filter_var($eventId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 403);
            $event = Event::query()->useWritePdo()->whereKey($eventId)->lockForUpdate()->first();
            abort_unless($event && $event->status === Event::STATUS_ACTIVE, 403);

            $scanner = User::query()->useWritePdo()->whereKey($scannerId)->lockForUpdate()->first();
            abort_unless($scanner && $scanner->canAuthenticate() && $scanner->role === User::ROLE_SCANNER
                && ($scanner->event_id === null ? null : (int) $scanner->event_id) === $assignedEventId, 403);

            $locks->assertLaravelAdmissionAllowed($event, true);

            if ($token === '') {
                $this->recordScan(null, null, $quantity, Checkin::RESULT_INVALID, 'QR token was empty.', $request);

                return [
                    'status' => 422,
                    'payload' => [
                        'ok' => false,
                        'status' => Checkin::RESULT_INVALID,
                        'result' => Checkin::RESULT_INVALID,
                        'message' => 'Invalid QR code. Scan again.',
                    ],
                ];
            }

            $qrCode = QrCode::query()
                ->where('qr_token', $token)
                ->lockForUpdate()
                ->first();

            if (! $qrCode) {
                $this->recordScan(null, null, $quantity, Checkin::RESULT_INVALID, 'QR token was not found.', $request);

                return [
                    'status' => 404,
                    'payload' => [
                        'ok' => false,
                        'status' => Checkin::RESULT_INVALID,
                        'result' => Checkin::RESULT_INVALID,
                        'message' => 'Invalid QR code. This pass was not found.',
                    ],
                ];
            }

            $guest = Guest::query()->whereKey($qrCode->guest_id)->lockForUpdate()->first();
            if (! $guest) {
                $message = 'This QR code is not registered.';
                $this->recordScan(null, $qrCode, $quantity, Checkin::RESULT_INVALID, $message, $request);

                return [
                    'status' => 404,
                    'payload' => [
                        'ok' => false,
                        'status' => Checkin::RESULT_INVALID,
                        'result' => Checkin::RESULT_INVALID,
                        'message' => $message,
                    ],
                ];
            }

            $qrCode->setRelation('guest', $guest);

            if ((int) $guest->event_id !== (int) $event->id) {
                $message = 'This QR code belongs to a different event.';
                $this->recordScan(null, null, $quantity, Checkin::RESULT_WRONG_EVENT, $message, $request, $event);

                return [
                    'status' => 409,
                    'payload' => [
                        'ok' => false,
                        'status' => Checkin::RESULT_WRONG_EVENT,
                        'result' => Checkin::RESULT_WRONG_EVENT,
                        'message' => $message,
                    ],
                ];
            }

            if (! $qrCode->is_active || $qrCode->revoked_at) {
                $message = 'This QR code has been revoked.';
                $this->recordScan($guest, $qrCode, $quantity, Checkin::RESULT_REVOKED, $message, $request);

                return [
                    'status' => 409,
                    'payload' => [
                        'ok' => false,
                        'status' => Checkin::RESULT_REVOKED,
                        'result' => Checkin::RESULT_REVOKED,
                        'message' => $message,
                        'guest' => $this->guestPayload($guest),
                    ],
                ];
            }

            if ($guestId !== null && $guestId !== $guest->id) {
                $message = 'QR code does not match this guest.';
                $this->recordScan($guest, $qrCode, $quantity, Checkin::RESULT_INVALID, $message, $request);

                return [
                    'status' => 422,
                    'payload' => [
                        'ok' => false,
                        'status' => Checkin::RESULT_INVALID,
                        'result' => Checkin::RESULT_INVALID,
                        'message' => $message,
                        'guest' => $this->guestPayload($guest),
                    ],
                ];
            }

            if ($guest->status === Guest::STATUS_CANCELLED) {
                $message = 'This pass has been cancelled.';
                $this->recordScan($guest, $qrCode, $quantity, Checkin::RESULT_CANCELLED, $message, $request);

                return [
                    'status' => 409,
                    'payload' => [
                        'ok' => false,
                        'status' => Checkin::RESULT_CANCELLED,
                        'result' => Checkin::RESULT_CANCELLED,
                        'message' => $message,
                        'guest' => $this->guestPayload($guest),
                    ],
                ];
            }

            $remainingEntries = $guest->remainingEntries();
            if ($guest->status === Guest::STATUS_FULLY_USED || $remainingEntries <= 0) {
                $message = 'This pass has already been fully used.';
                $this->recordScan($guest, $qrCode, $quantity, Checkin::RESULT_ALREADY_USED, $message, $request);

                return [
                    'status' => 409,
                    'payload' => [
                        'ok' => false,
                        'status' => Checkin::RESULT_ALREADY_USED,
                        'result' => Checkin::RESULT_ALREADY_USED,
                        'message' => $message,
                        'guest' => $this->guestPayload($guest),
                    ],
                ];
            }

            if ($guest->allowed_entries > Guest::MAX_ALLOWED_ENTRIES) {
                $message = 'Allowed entries may not exceed '.Guest::MAX_ALLOWED_ENTRIES.'.';
                $this->recordScan($guest, $qrCode, $quantity, Checkin::RESULT_ERROR, $message, $request);

                return [
                    'status' => 422,
                    'payload' => [
                        'ok' => false,
                        'status' => Checkin::RESULT_ERROR,
                        'result' => Checkin::RESULT_ERROR,
                        'message' => $message,
                        'guest' => $this->guestPayload($guest),
                    ],
                ];
            }

            if ($quantity > $remainingEntries) {
                $message = 'Requested entries exceed the remaining allowance.';
                $this->recordScan($guest, $qrCode, $quantity, Checkin::RESULT_ERROR, $message, $request);

                return [
                    'status' => 422,
                    'payload' => [
                        'ok' => false,
                        'status' => Checkin::RESULT_ERROR,
                        'result' => Checkin::RESULT_ERROR,
                        'message' => $message,
                        'guest' => $this->guestPayload($guest),
                    ],
                ];
            }

            $guest = $this->applyAdmissionToLockedGuest($guest, $quantity);
            if (! $guest) {
                $message = 'Requested entries exceed the remaining allowance.';
                $freshGuest = Guest::query()->whereKey($qrCode->guest_id)->first();
                $qrCode->setRelation('guest', $freshGuest);
                $this->recordScan($freshGuest, $qrCode, $quantity, Checkin::RESULT_ERROR, $message, $request);

                return [
                    'status' => 422,
                    'payload' => [
                        'ok' => false,
                        'status' => Checkin::RESULT_ERROR,
                        'result' => Checkin::RESULT_ERROR,
                        'message' => $message,
                        'guest' => $freshGuest ? $this->guestPayload($freshGuest) : null,
                    ],
                ];
            }

            $qrCode->setRelation('guest', $guest);

            Admission::query()->create([
                'event_id' => $guest->event_id,
                'guest_id' => $guest->id,
                'quantity' => $quantity,
                'admitted_by' => $validated['admitted_by'] ?? null,
                'device_label' => $validated['device_label'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'admitted_at' => now(),
            ]);

            $this->recordScan($guest, $qrCode, $quantity, Checkin::RESULT_ADMITTED, 'Guest admission recorded.', $request);

            return [
                'status' => 200,
                'payload' => [
                    'ok' => true,
                    'status' => Checkin::RESULT_ADMITTED,
                    'result' => Checkin::RESULT_ADMITTED,
                    'message' => $quantity.' entr'.($quantity === 1 ? 'y' : 'ies').' recorded.',
                    'guest' => $this->guestPayload($guest),
                ],
            ];
        }, 3);

        return response()->json($result['payload'], $result['status']);
    }

    private function findQrCode(string $token): ?QrCode
    {
        if ($token === '' || ! $this->looksLikeQrToken($token)) {
            return null;
        }

        return QrCode::query()
            ->with('guest.event')
            ->where('qr_token', $token)
            ->first();
    }

    private function scannerEvent(Request $request): Event
    {
        $user = $request->user();

        if ($user?->event) {
            return $user->event;
        }

        if ($user?->event_id) {
            return Event::query()->find($user->event_id) ?? Event::defaultEvent();
        }

        $selectedEventId = $request->session()->get('selected_event_id');
        $selectedEvent = $selectedEventId ? Event::query()->find($selectedEventId) : null;

        if ($selectedEvent) {
            return $selectedEvent;
        }

        return Event::defaultEvent();
    }

    private function guestBelongsToScannerEvent(Guest $guest, Request $request): bool
    {
        return (int) $guest->event_id === $this->scannerEvent($request)->id;
    }

    private function qrBelongsToScannerEvent(QrCode $qrCode, Request $request): bool
    {
        return $qrCode->guest && $this->guestBelongsToScannerEvent($qrCode->guest, $request);
    }

    private function looksLikeQrToken(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{40,128}$/', $token);
    }

    private function extractToken(string $value): string
    {
        return app(QrTokenCanonicalizer::class)->canonicalize($value);
    }

    private function resultFor(QrCode $qrCode): array
    {
        if (! $qrCode->is_active || $qrCode->revoked_at) {
            return ['can_admit' => false, 'result' => Checkin::RESULT_REVOKED, 'message' => 'This QR code has been revoked.'];
        }

        if ($qrCode->guest->status === Guest::STATUS_CANCELLED) {
            return ['can_admit' => false, 'result' => Checkin::RESULT_CANCELLED, 'message' => 'This pass has been cancelled.'];
        }

        if ($qrCode->guest->remainingEntries() <= 0) {
            return ['can_admit' => false, 'result' => Checkin::RESULT_ALREADY_USED, 'message' => 'This pass has already been fully used.'];
        }

        return ['can_admit' => true, 'result' => Checkin::RESULT_VALID, 'message' => 'Valid pass. Admit up to '.$qrCode->guest->remainingEntries().' more.'];
    }

    private function guestPayload(Guest $guest): array
    {
        return [
            'id' => $guest->id,
            'guest_name' => $guest->name,
            'phone_number' => $guest->phone_number,
            'pass_type' => $guest->passTypeLabel(),
            'pass_type_key' => $guest->pass_type,
            'allowed_admissions' => $guest->allowed_entries,
            'admitted_count' => $guest->used_entries,
            'remaining_admissions' => $guest->remainingEntries(),
            'allowed_entries' => $guest->allowed_entries,
            'used_entries' => $guest->used_entries,
            'remaining_entries' => $guest->remainingEntries(),
            'usage_status' => $guest->usageStatus(),
            'system_status' => $guest->status,
            'token_preview' => substr((string) $guest->qrCode?->qr_token, -8),
        ];
    }

    private function applyAdmissionToLockedGuest(Guest $guest, int $quantity): ?Guest
    {
        if ($quantity < 1 || $quantity > Guest::MAX_ALLOWED_ENTRIES) {
            return null;
        }

        $allowedEntries = (int) $guest->allowed_entries;
        $usedEntries = (int) $guest->used_entries;

        if ($allowedEntries > Guest::MAX_ALLOWED_ENTRIES || $quantity > max(0, $allowedEntries - $usedEntries)) {
            return null;
        }

        $newUsedEntries = $usedEntries + $quantity;
        $updated = Guest::query()
            ->whereKey($guest->id)
            ->where('event_id', $guest->event_id)
            ->where('status', '<>', Guest::STATUS_CANCELLED)
            ->where('allowed_entries', '<=', Guest::MAX_ALLOWED_ENTRIES)
            ->whereRaw('(used_entries + ?) <= allowed_entries', [$quantity])
            ->update([
                'used_entries' => DB::raw('used_entries + '.(int) $quantity),
                'status' => $this->statusForUsedEntries($newUsedEntries, $allowedEntries),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return null;
        }

        return Guest::query()
            ->with('qrCode')
            ->whereKey($guest->id)
            ->where('event_id', $guest->event_id)
            ->first();
    }

    private function statusForUsedEntries(int $usedEntries, int $allowedEntries): string
    {
        if ($usedEntries <= 0) {
            return Guest::STATUS_ACTIVE;
        }

        return $usedEntries >= $allowedEntries
            ? Guest::STATUS_FULLY_USED
            : Guest::STATUS_PARTIALLY_USED;
    }

    private function recordScan(
        ?Guest $guest,
        ?QrCode $qrCode,
        ?int $quantity,
        string $result,
        string $message,
        Request $request,
        ?Event $event = null,
    ): void {
        $eventId = $event?->id
            ?? $guest?->event_id
            ?? $request->user()?->event_id
            ?? Event::defaultEvent()->id;

        Checkin::query()->create([
            'event_id' => $eventId,
            'guest_id' => $guest?->id,
            'qr_code_id' => $qrCode?->id,
            'user_id' => $request->user()?->id,
            'gate_name' => $request->user()?->gate_name,
            'entries_added' => $result === Checkin::RESULT_ADMITTED ? (int) $quantity : 0,
            'used_entries_after_scan' => $guest?->used_entries ?? 0,
            'remaining_entries_after_scan' => $guest?->remainingEntries() ?? 0,
            'scan_result' => $result,
            'device_info' => substr((string) $request->userAgent(), 0, 1000),
            'ip_address' => $request->ip(),
            'checked_in_at' => now(),
        ]);

        ScanLog::query()->create([
            'event_id' => $eventId,
            'guest_id' => $guest?->id,
            'token_hash' => $qrCode ? Guest::hashToken($qrCode->qr_token) : null,
            'action' => $result === Checkin::RESULT_ADMITTED ? 'admit' : 'verify',
            'result' => $result,
            'quantity' => $quantity,
            'message' => $message,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'metadata' => [
                'remaining' => $guest?->remainingEntries(),
                'status' => $guest?->status,
            ],
            'scanned_at' => now(),
        ]);
    }

    private function scannerCheckinsQuery(Request $request): Builder
    {
        $user = $request->user();

        return Checkin::query()
            ->where('user_id', $user?->id)
            ->where('event_id', $this->scannerEvent($request)->id);
    }
}
