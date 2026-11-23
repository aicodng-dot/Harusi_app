<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesCustomerEventSetup;
use App\Models\User;
use App\Services\Tenancy\CustomerEventCreationException;
use App\Services\Tenancy\CustomerEventCreator;
use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Working Flow 10 — Event Details (reference UI/05_Event_Details.png).
 *
 * Reads the Working Flow 09 setup state (account + authorized workspace + type),
 * validates the details and creates exactly one Event through CustomerEventCreator.
 */
class CustomerEventDetailsController extends Controller
{
    use ResolvesCustomerEventSetup;

    private const LAST_KEY = 'customer_event_last';

    public function create(Request $request, WorkspaceAccess $access): Response|RedirectResponse
    {
        $user = $this->customer($request);
        $workspaces = $this->eligibleWorkspaces(DB::connection(), $user, $access);
        if ($workspaces->isEmpty()) {
            $request->session()->forget(self::$setupKey);

            return redirect()->route('customer.workspaces.create');
        }
        $setup = $this->setupState($request, $user, $workspaces);
        if ($setup === []) {
            return $this->backToType();
        }
        if (! $this->validKey($setup['request_key'] ?? null)) {
            // One request key per setup: every retry of this form maps to one event.
            $setup['request_key'] = (string) Str::uuid();
            $request->session()->put(self::$setupKey, $setup);
        }

        return $this->form($request, $user, $workspaces, $setup, [
            'event_name' => '', 'event_datetime' => '', 'venue_name' => '',
        ]);
    }

    public function store(Request $request, WorkspaceAccess $access, CustomerEventCreator $creator): Response|RedirectResponse
    {
        $user = $this->customer($request);
        $workspaces = $this->eligibleWorkspaces(DB::connection(), $user, $access);
        $setup = $workspaces->isEmpty() ? [] : $this->setupState($request, $user, $workspaces);
        $postedKey = $request->input('request_key');

        if ($setup === [] || ! $this->validKey($setup['request_key'] ?? null)) {
            // A repeated tap after success resolves to the same completed event.
            $last = $request->session()->get(self::LAST_KEY);
            if (is_array($last) && ($last['user_id'] ?? null) === (int) $user->getKey()
                && is_string($postedKey) && hash_equals((string) ($last['request_key'] ?? ''), $postedKey)) {
                return $this->success((int) ($last['event_id'] ?? 0), $last['event_name'] ?? '');
            }
            if ($workspaces->isEmpty()) {
                return redirect()->route('customer.workspaces.create');
            }

            return $this->backToType();
        }

        $language = CustomerHomeController::language($request, $user);
        $input = [
            'event_name' => $this->text($request->input('event_name')),
            'event_datetime' => is_string($request->input('event_datetime')) ? trim($request->input('event_datetime')) : '',
            'venue_name' => $this->text($request->input('venue_name')),
        ];
        [$details, $errors] = $this->validateDetails($input, $setup['event_type'], $language);
        if ($errors !== []) {
            return $this->form($request, $user, $workspaces, $setup, $input, $errors, 422);
        }

        try {
            $result = $creator->create(DB::connection(), (int) $user->getKey(), $setup['workspace_id'], $setup['request_key'], $details);
        } catch (CustomerEventCreationException $exception) {
            if ($exception->reason() === 'creator_unavailable') {
                abort(403);
            }
            if ($exception->reason() === 'workspace_unavailable') {
                $request->session()->forget(self::$setupKey);

                return $this->backToType();
            }
            report($exception);

            return $this->form($request, $user, $workspaces, $setup, $input, ['form' => __('event_details.failed', [], $language)], 503);
        }

        $request->session()->forget(self::$setupKey);
        $request->session()->put(self::LAST_KEY, [
            'user_id' => (int) $user->getKey(),
            'request_key' => $setup['request_key'],
            'event_id' => $result['event_id'],
            'event_name' => $details['event_name'],
        ]);

        return $this->success($result['event_id'], $details['event_name']);
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} */
    private function validateDetails(array $input, string $type, string $language): array
    {
        $t = fn (string $key) => __('event_details.'.$key, [], $language);
        $errors = [];

        if ($input['event_name'] === null || $input['event_name'] === '') {
            $errors['event_name'] = $t($input['event_name'] === null ? 'name_invalid' : 'name_required.'.$type);
        } elseif (mb_strlen($input['event_name']) > 160) {
            $errors['event_name'] = $t('name_invalid');
        }

        $date = null;
        $time = null;
        if ($input['event_datetime'] === '') {
            $errors['event_datetime'] = $t('datetime_required');
        } elseif (preg_match('/\A(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?\z/', $input['event_datetime'], $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $errors['event_datetime'] = $t('datetime_invalid');
        } else {
            $date = "{$m[1]}-{$m[2]}-{$m[3]}";
            $time = "{$m[4]}:{$m[5]}";
            // Dates are wall-clock values compared in the application timezone.
            $today = Carbon::today(config('app.timezone'));
            if ($date < $today->toDateString()) {
                $errors['event_datetime'] = $t('datetime_past');
            } elseif ($date > $today->copy()->addYears(10)->toDateString()) {
                $errors['event_datetime'] = $t('datetime_far');
            }
        }

        if ($input['venue_name'] === null || $input['venue_name'] === '') {
            $errors['venue_name'] = $t($input['venue_name'] === null ? 'venue_invalid' : 'venue_required');
        } elseif (mb_strlen($input['venue_name']) > 160) {
            $errors['venue_name'] = $t('venue_invalid');
        }

        return [$errors === [] ? [
            'event_type' => $type,
            'event_name' => $input['event_name'],
            'event_date' => $date,
            'event_time' => $time,
            'venue_name' => $input['venue_name'],
        ] : [], $errors];
    }

    /** Trimmed single-line text; null when the value is not a clean UTF-8 string. */
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

    private function validKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $key) === 1;
    }

    private function backToType(): RedirectResponse
    {
        return redirect()->route('customer.events.create')->with('event_setup_notice', 'setup_missing');
    }

    /** Working Flow 11: success (and any successful retry) opens the event's setup progress. */
    private function success(int $eventId, string $name): RedirectResponse
    {
        if ($eventId < 1) {
            return redirect()->route('customer.home')->with('customer_event_created', $name)->withHeaders($this->privateHeaders());
        }

        return redirect()->route('customer.events.setup', ['event' => $eventId])
            ->with('customer_event_created', $name)->withHeaders($this->privateHeaders());
    }

    private function form(Request $request, User $user, Collection $workspaces, array $setup, array $input, array $errors = [], int $status = 200): Response
    {
        $workspace = $workspaces->firstWhere('id', $setup['workspace_id']);

        return response()->view('reference_ui.05_event_details', [
            'language' => CustomerHomeController::language($request, $user),
            'eventType' => $setup['event_type'],
            'workspaceName' => $workspaces->count() > 1 ? $workspace?->name : null,
            'requestKey' => $setup['request_key'],
            'values' => array_map(fn ($value) => is_string($value) ? $value : '', $input),
            'formErrors' => $errors,
            'minDate' => Carbon::today(config('app.timezone'))->format('Y-m-d\T00:00'),
        ], $status)->withHeaders($this->privateHeaders());
    }
}
