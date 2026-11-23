<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Guest;
use App\Models\User;
use App\Services\Customers\CustomerAccountManager;
use App\Services\PhoneNumberService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin > Customers: customer (self-registered) accounts, their workspaces, events and
 * guests. Only role=customer, non-platform accounts are reachable here; staff accounts
 * stay under Scanner Users. Every change is transactional and audited.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerAccountManager $accounts) {}

    public function index(Request $request): View
    {
        $search = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 100) : '';
        $status = in_array($request->query('status'), ['active', 'suspended'], true) ? $request->query('status') : 'all';
        $events = in_array($request->query('events'), ['with', 'without'], true) ? $request->query('events') : 'all';

        $workspaceIds = fn ($query) => $query->select('workspace_id')->from('workspace_users')->whereColumn('workspace_users.user_id', 'users.id');
        $query = $this->customers()
            ->select(['users.id', 'users.name', 'users.email', 'users.phone_number', 'users.is_active', 'users.created_at'])
            ->selectSub(fn ($q) => $q->from('workspace_users')->selectRaw('count(*)')->whereColumn('workspace_users.user_id', 'users.id'), 'workspaces_count')
            ->selectSub(fn ($q) => $q->from('events')->selectRaw('count(*)')->whereIn('events.workspace_id', $workspaceIds), 'events_count')
            ->selectSub(fn ($q) => $q->from('guests')->join('events', 'events.id', '=', 'guests.event_id')
                ->selectRaw('count(*)')->whereIn('events.workspace_id', $workspaceIds), 'guests_count');

        if ($search !== '') {
            $like = '%'.addcslashes(mb_strtolower($search), '\\%_').'%';
            $digits = preg_replace('/\D+/', '', $search);
            $query->where(function (Builder $q) use ($like, $digits): void {
                $q->whereRaw('lower(users.name) like ?', [$like])->orWhereRaw('lower(users.email) like ?', [$like]);
                if (strlen($digits) >= 3) {
                    $q->orWhere('users.phone_number', 'like', '%'.$digits.'%');
                }
            });
        }
        if ($status !== 'all') {
            $query->where('users.is_active', $status === 'active');
        }
        if ($events !== 'all') {
            $has = fn ($q) => $q->selectRaw('1')->from('events')->whereIn('events.workspace_id', $workspaceIds);
            $events === 'with' ? $query->whereExists($has) : $query->whereNotExists($has);
        }

        return view('admin.customers.index', [
            'customers' => $query->orderByDesc('users.id')->paginate(20)->withQueryString(),
            'search' => $search,
            'status' => $status,
            'eventsFilter' => $events,
            'totals' => [
                'all' => $this->customers()->count(),
                'suspended' => $this->customers()->where('is_active', false)->count(),
            ],
        ]);
    }

    public function show(string $customer): View
    {
        $user = $this->customer($customer);
        $memberships = DB::table('workspace_users as m')->join('workspaces as w', 'w.id', '=', 'm.workspace_id')
            ->where('m.user_id', $user->id)->orderBy('w.name')->get(['w.id', 'w.name', 'm.role', 'm.is_active']);
        $workspaceIds = $memberships->pluck('id')->all();

        $events = DB::table('events as e')->whereIn('e.workspace_id', $workspaceIds === [] ? [0] : $workspaceIds)
            ->leftJoin('workspaces as w', 'w.id', '=', 'e.workspace_id')
            ->select(['e.id', 'e.event_name', 'e.event_date', 'e.event_type', 'e.status', 'w.name as workspace_name'])
            ->selectSub(fn ($q) => $q->from('guests')->selectRaw('count(*)')->whereColumn('guests.event_id', 'e.id'), 'guests_count')
            ->selectSub(fn ($q) => $q->from('guests')->selectRaw('coalesce(sum(used_entries), 0)')->whereColumn('guests.event_id', 'e.id'), 'entries_used')
            ->selectSub(fn ($q) => $q->from('guests')->selectRaw('coalesce(sum(allowed_entries), 0)')->whereColumn('guests.event_id', 'e.id'), 'entries_allowed')
            ->orderByDesc('e.event_date')->orderByDesc('e.id')->get();
        $eventIds = $events->pluck('id')->all();

        $guestTotals = DB::table('guests')->whereIn('event_id', $eventIds === [] ? [0] : $eventIds)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as active', [Guest::STATUS_ACTIVE])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as fully_used', [Guest::STATUS_FULLY_USED])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as cancelled', [Guest::STATUS_CANCELLED])
            ->selectRaw('sum(case when used_entries > 0 then 1 else 0 end) as arrived')
            ->first();
        $recentGuests = DB::table('guests as g')->join('events as e', 'e.id', '=', 'g.event_id')
            ->whereIn('g.event_id', $eventIds === [] ? [0] : $eventIds)
            ->orderByDesc('g.id')->limit(25)
            ->get(['g.id', 'g.name', 'g.phone_number', 'g.pass_type', 'g.allowed_entries', 'g.used_entries', 'g.status', 'e.event_name']);

        return view('admin.customers.show', [
            'customer' => $user,
            'memberships' => $memberships,
            'events' => $events,
            'guestTotals' => $guestTotals,
            'recentGuests' => $recentGuests,
            'deletion' => $this->accounts->deletionPlan($user->id),
            'audits' => AdminAuditLog::query()->with('actor:id,name')->where('subject_type', 'customer')
                ->where('subject_id', $user->id)->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function edit(string $customer): View
    {
        return view('admin.customers.edit', ['customer' => $this->customer($customer)]);
    }

    public function update(Request $request, string $customer, PhoneNumberService $phones): RedirectResponse
    {
        $user = $this->customer($customer);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        if (is_string($request->input('phone_number'))) {
            $phone = trim($request->input('phone_number'));
            $request->merge(['phone_number' => $phone === '' ? null : ($phones->normalize($phone) ?? $phone)]);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'email' => ['required', 'string', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'phone_number' => ['nullable', 'string', 'max:40',
                function (string $attribute, mixed $value, \Closure $fail) use ($phones): void {
                    if ($value !== null && $phones->normalize($value) === null) {
                        $fail(PhoneNumberService::INVALID_MESSAGE);
                    }
                },
                Rule::unique('users', 'phone_number')->ignore($user->id)],
            'preferred_language' => ['required', Rule::in(User::SUPPORTED_LANGUAGES)],
        ]);

        $this->accounts->update($request->user(), $user->id, $validated);

        return redirect()->route('admin.customers.show', $user->id)->with('success', 'Customer details updated.');
    }

    public function suspend(Request $request, string $customer): RedirectResponse
    {
        $user = $this->customer($customer);
        $validated = $request->validate([
            'confirm' => ['accepted'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], ['confirm.accepted' => 'Confirm that this customer should be suspended.']);

        $this->accounts->suspend($request->user(), $user->id, $validated['reason'] ?? null);

        return redirect()->route('admin.customers.show', $user->id)->with('success', 'Customer suspended. They are signed out and cannot sign in.');
    }

    public function reactivate(Request $request, string $customer): RedirectResponse
    {
        $user = $this->customer($customer);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm that this customer should be reactivated.']);

        $this->accounts->reactivate($request->user(), $user->id);

        return redirect()->route('admin.customers.show', $user->id)->with('success', 'Customer reactivated.');
    }

    public function destroy(Request $request, string $customer): RedirectResponse
    {
        $user = $this->customer($customer);
        $request->validate(['confirm_email' => ['required', 'string']], ['confirm_email.required' => 'Type the customer email to confirm deletion.']);
        if (! hash_equals(Str::lower((string) $user->email), Str::lower(trim((string) $request->input('confirm_email'))))) {
            return back()->withErrors(['confirm_email' => 'The email you typed does not match this customer.']);
        }

        $plan = $this->accounts->delete($request->user(), $user->id);
        if (! $plan['allowed']) {
            return back()->withErrors(['customer' => self::refusal($plan)]);
        }

        return redirect()->route('admin.customers.index')->with('success', 'Customer '.$user->email.' deleted.');
    }

    public static function refusal(array $plan): string
    {
        return match ($plan['reason']) {
            'events' => 'This customer has '.$plan['events'].' event(s). Their events and guests are kept: suspend the account instead, or have the events removed first.',
            'shared_workspace' => 'This customer created a workspace that other members still use. Remove them from it first or suspend the account.',
            'legacy_mapping' => 'This customer\'s workspace is linked to migrated admin data and cannot be removed. Suspend the account instead.',
            default => 'This customer cannot be deleted.',
        };
    }

    private function customers(): Builder
    {
        return User::query()->useWritePdo()
            ->where('users.role', User::ROLE_CUSTOMER)->where('users.is_platform_admin', false);
    }

    private function customer(string $id): User
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,18}\z/', $id) === 1, 404);

        return $this->customers()->whereKey((int) $id)->firstOrFail();
    }
}
