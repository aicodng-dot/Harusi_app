<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Checkin;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\ScanLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ScannerController extends Controller
{
    public function dashboard(Request $request): View
    {
        $todayQuery = $this->scannerCheckinsQuery($request)
            ->whereDate('checked_in_at', today());

        return view('scanner.dashboard', [
            'scannerUser' => $request->user(),
            'todayEntries' => (clone $todayQuery)
                ->where('scan_result', Checkin::RESULT_ADMITTED)
                ->sum('entries_added'),
            'todayScans' => (clone $todayQuery)->count(),
            'todayInvalidAttempts' => (clone $todayQuery)
                ->whereIn('scan_result', [
                    Checkin::RESULT_INVALID,
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
        ]);
    }

    public function ticket(string $token): View
    {
        return view('scanner.index', [
            'initialToken' => $token,
        ]);
    }

    public function recentScans(Request $request): View
    {
        return view('scanner.recent-scans', [
            'checkins' => $this->scannerCheckinsQuery($request)
                ->with('guest')
                ->latest('checked_in_at')
                ->latest()
                ->paginate(15),
        ]);
    }

    public function validateQr(Request $request): JsonResponse
    {
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

        if (! $qrCode->is_active || $qrCode->revoked_at) {
            $message = 'This QR code has been revoked.';
            $this->recordScan($qrCode->guest, $qrCode, null, Checkin::RESULT_REVOKED, $message, $request);

            return response()->json([
                'status' => Checkin::RESULT_REVOKED,
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

    public function admit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'guest_id' => ['nullable', 'integer', 'min:1'],
            'qr_token' => ['nullable', 'string', 'max:2048', 'required_without:token'],
            'token' => ['nullable', 'string', 'max:2048', 'required_without:qr_token'],
            'entries_to_admit' => ['nullable', 'integer', 'min:1', 'max:10', 'required_without:quantity'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10', 'required_without:entries_to_admit'],
            'admitted_by' => ['nullable', 'string', 'max:80'],
            'device_label' => ['nullable', 'string', 'max:80'],
        ]);

        $token = $this->extractToken($validated['qr_token'] ?? $validated['token'] ?? '');
        $quantity = (int) ($validated['entries_to_admit'] ?? $validated['quantity'] ?? 0);
        $guestId = isset($validated['guest_id']) ? (int) $validated['guest_id'] : null;

        if ($token === '') {
            $this->recordScan(null, null, $quantity, Checkin::RESULT_INVALID, 'QR token was empty.', $request);

            return response()->json([
                'ok' => false,
                'status' => Checkin::RESULT_INVALID,
                'result' => Checkin::RESULT_INVALID,
                'message' => 'Invalid QR code. Scan again.',
            ], 422);
        }

        $result = DB::transaction(function () use ($request, $validated, $token, $quantity, $guestId) {
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

            if (! $qrCode->is_active || $qrCode->revoked_at) {
                $guest = Guest::query()->whereKey($qrCode->guest_id)->lockForUpdate()->first();
                $qrCode->setRelation('guest', $guest);
                $message = 'This QR code has been revoked.';
                $this->recordScan($guest, $qrCode, $quantity, Checkin::RESULT_REVOKED, $message, $request);

                return [
                    'status' => 409,
                    'payload' => [
                        'ok' => false,
                        'status' => Checkin::RESULT_REVOKED,
                        'result' => Checkin::RESULT_REVOKED,
                        'message' => $message,
                        'guest' => $guest ? $this->guestPayload($guest) : null,
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

            if ($guest->allowed_entries > 10) {
                $message = 'Allowed entries may not exceed 10.';
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

            $guest->used_entries += $quantity;
            $guest->save();
            $guest = $guest->fresh();

            Admission::query()->create([
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
        });

        return response()->json($result['payload'], $result['status']);
    }

    private function findQrCode(string $token): ?QrCode
    {
        if ($token === '') {
            return null;
        }

        return QrCode::query()
            ->with('guest')
            ->where('qr_token', $token)
            ->first();
    }

    private function extractToken(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $query = parse_url($value, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            if (! empty($params['token']) && is_string($params['token'])) {
                return trim($params['token']);
            }
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (is_string($path) && str_contains($path, '/')) {
            $segments = array_values(array_filter(explode('/', $path)));
            return trim((string) end($segments));
        }

        return $value;
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

    private function recordScan(
        ?Guest $guest,
        ?QrCode $qrCode,
        ?int $quantity,
        string $result,
        string $message,
        Request $request,
    ): void {
        Checkin::query()->create([
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

        return Checkin::query()->where('user_id', $user?->id);
    }
}
