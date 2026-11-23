<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Working Flow 08 — My Events List (customer landing page).
 *
 * Authorization lives in SQL: every read re-checks the current account row and an
 * active owner/manager membership, so nothing depends on frontend filtering.
 */
class CustomerHomeController extends Controller
{
    public const PER_PAGE = 10;

    /** Display filters derived from the stored status and the event date. */
    public const FILTERS = ['all', 'upcoming', 'ongoing', 'completed', 'archived', 'cancelled'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isCustomer() && $user->canAuthenticate(), 403);

        $language = self::language($request, $user);
        $search = $this->search($request);
        $filter = in_array($request->query('status'), self::FILTERS, true) ? $request->query('status') : 'all';
        $connection = DB::connection();
        $today = Carbon::today()->toDateString();

        $data = [
            'language' => $language,
            'accountName' => $user->name,
            'search' => $search,
            'filter' => $filter,
            'workspaces' => collect(),
            'events' => null,
            'counts' => array_fill_keys(self::FILTERS, 0),
            'loadError' => false,
        ];
        $status = 200;

        try {
            $data['workspaces'] = $this->authorizedWorkspaces($connection, $user)->get(['w.id', 'w.name', 'm.role as membership_role']);

            // A new customer without a workspace never triggers an event read.
            if ($data['workspaces']->isNotEmpty()) {
                $base = $this->authorizedEvents($connection, $user);
                $this->applySearch($base, $search);
                $data['counts'] = $this->counts(clone $base, $today);

                $list = clone $base;
                $this->applyFilter($list, $filter, $today);
                $data['events'] = $list
                    ->leftJoin('workspaces as ew', 'ew.id', '=', 'e.workspace_id')
                    ->orderByRaw($this->rankSql(), [$today, $today])
                    ->orderByRaw('case when e.event_date is null then 1 else 0 end')
                    ->orderBy('e.event_date')->orderBy('e.id')
                    ->select(['e.id', 'e.event_name', 'e.venue_name', 'e.event_date', 'e.event_time', 'e.event_type', 'e.status', 'e.workspace_id', 'ew.name as workspace_name'])
                    ->selectSub(fn (Builder $q) => $q->from('guests as g')->whereColumn('g.event_id', 'e.id')->selectRaw('count(*)'), 'guests_count')
                    ->paginate(self::PER_PAGE)
                    ->withQueryString();
            }
        } catch (QueryException $exception) {
            // Never show SQL, bindings or account details; keep the page usable.
            report($exception);
            $data['loadError'] = true;
            $data['events'] = null;
            $status = 500;
        }

        $data['today'] = $today;

        return response()->view('reference_ui.20_my_events_list', $data, $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** Display state for one event row; never a stored status. */
    public static function displayStatus(object $event, string $today): string
    {
        if ($event->status === Event::STATUS_ARCHIVED) {
            return 'archived';
        }
        if ($event->status === Event::STATUS_CANCELLED) {
            return 'cancelled';
        }
        $date = $event->event_date === null ? null : substr((string) $event->event_date, 0, 10);

        return match (true) {
            $date === null || $date > $today => 'upcoming',
            $date === $today => 'ongoing',
            default => 'completed',
        };
    }

    private function authorizedWorkspaces(Connection $connection, User $user): Builder
    {
        // Same membership rule as before: this account, still an active customer,
        // with an active owner or manager membership. Reads use the primary PDO.
        return $connection->table('workspaces as w')->useWritePdo()
            ->join('workspace_users as m', 'm.workspace_id', '=', 'w.id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('u.id', $user->getKey())->where('u.role', User::ROLE_CUSTOMER)->where('u.is_active', true)
            ->where('m.user_id', $user->getKey())->where('m.is_active', true)->whereIn('m.role', ['owner', 'manager'])
            ->orderBy('w.name')->orderBy('w.id');
    }

    private function authorizedEvents(Connection $connection, User $user): Builder
    {
        return $connection->table('events as e')->useWritePdo()
            ->whereNotNull('e.workspace_id')
            ->whereExists(fn (Builder $q) => $q->from('workspace_users as m')
                ->join('workspaces as w', 'w.id', '=', 'm.workspace_id')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->whereColumn('m.workspace_id', 'e.workspace_id')
                ->where('m.user_id', $user->getKey())->where('m.is_active', true)->whereIn('m.role', ['owner', 'manager'])
                ->where('u.role', User::ROLE_CUSTOMER)->where('u.is_active', true)
                ->selectRaw('1'));
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }
        $like = '%'.addcslashes(mb_strtolower($search), '\\%_').'%';
        $query->where(function (Builder $q) use ($like): void {
            foreach (['e.event_name', 'e.venue_name', 'e.bride_name', 'e.groom_name', 'e.event_date'] as $column) {
                $q->orWhereRaw("lower(coalesce({$column}, '')) like ? escape '\\'", [$like]);
            }
        });
    }

    private function applyFilter(Builder $query, string $filter, string $today): void
    {
        match ($filter) {
            'archived' => $query->where('e.status', Event::STATUS_ARCHIVED),
            'cancelled' => $query->where('e.status', Event::STATUS_CANCELLED),
            'upcoming' => $query->where('e.status', Event::STATUS_ACTIVE)
                ->where(fn (Builder $q) => $q->whereNull('e.event_date')->orWhereDate('e.event_date', '>', $today)),
            'ongoing' => $query->where('e.status', Event::STATUS_ACTIVE)->whereDate('e.event_date', '=', $today),
            'completed' => $query->where('e.status', Event::STATUS_ACTIVE)->whereDate('e.event_date', '<', $today),
            default => null,
        };
    }

    /** One aggregate query for every filter count. */
    private function counts(Builder $query, string $today): array
    {
        $active = Event::STATUS_ACTIVE;
        $row = $query->selectRaw(
            'count(*) as c_all,'
            .' sum(case when e.status = ? and (e.event_date is null or date(e.event_date) > ?) then 1 else 0 end) as c_upcoming,'
            .' sum(case when e.status = ? and date(e.event_date) = ? then 1 else 0 end) as c_ongoing,'
            .' sum(case when e.status = ? and date(e.event_date) < ? then 1 else 0 end) as c_completed,'
            .' sum(case when e.status = ? then 1 else 0 end) as c_archived,'
            .' sum(case when e.status = ? then 1 else 0 end) as c_cancelled',
            [$active, $today, $active, $today, $active, $today, Event::STATUS_ARCHIVED, Event::STATUS_CANCELLED],
        )->first();

        $counts = [];
        foreach (self::FILTERS as $filter) {
            $counts[$filter] = (int) ($row->{'c_'.$filter} ?? 0);
        }

        return $counts;
    }

    private function rankSql(): string
    {
        // Ongoing, upcoming, completed, archived, cancelled.
        return "case when e.status = 'active' and date(e.event_date) = ? then 0"
            ." when e.status = 'active' and (e.event_date is null or date(e.event_date) > ?) then 1"
            ." when e.status = 'active' then 2 when e.status = 'archived' then 3 else 4 end";
    }

    private function search(Request $request): string
    {
        $value = $request->query('q');
        if (! is_string($value)) {
            return '';
        }
        $value = trim(preg_replace('/[\p{Cc}\s]+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, 100);
    }

    /**
     * Shared language mechanism: ?lang= is remembered in `login_language`; otherwise
     * the visitor's earlier choice, then the account preference, then Kiswahili.
     */
    public static function language(Request $request, User $user): string
    {
        $requested = $request->query('lang');
        if (in_array($requested, User::SUPPORTED_LANGUAGES, true)) {
            $request->session()->put('login_language', $requested);
        }
        $language = $request->session()->get('login_language', $user->preferred_language);

        return in_array($language, User::SUPPORTED_LANGUAGES, true) ? $language : 'sw';
    }
}
