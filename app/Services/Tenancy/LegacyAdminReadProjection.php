<?php

namespace App\Services\Tenancy;

use App\Exceptions\LegacyAdminReadUnavailable;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\OfflineScannerPackage;
use App\Models\OfflineScannerSession;
use App\Models\QrCode;
use App\Models\User;
use App\Support\AdminLayout\LayoutCreator;
use App\Support\AdminLayout\LayoutEvent;
use App\Support\AdminLayout\LayoutOfflineSession;
use App\Support\AdminLayout\LayoutPackage;
use App\Support\AdminRead\CheckinView;
use App\Support\AdminRead\EventSummary;
use App\Support\AdminRead\GuestView;
use App\Support\AdminRead\HistoryGuest;
use App\Support\AdminRead\HistoryUser;
use App\Support\AdminRead\NotificationLogView;
use App\Support\AdminRead\QrView;
use App\Support\PositiveIntegerId;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Administrator read projections (P4-047, family 3).
 *
 * The shared admin layout is prepared before any rendering, in one read-only
 * transaction attempt with primary shared locking reads, in order:
 * - the exact unowned selected Event (optional for pages without a family-3 read bundle);
 * - then the exact active administrator;
 * - then that Event's blocking offline Session.
 *
 * Shared locks read the current committed rows and hold off writers until the
 * attempt ends, while concurrent page views no longer block each other.
 *
 * The package fallback time needs no lock of its own: package writers already take
 * the Event and Session locks held here. The creator label and the active-unowned
 * catalogue stay unlocked primary reads: they are labels and navigation, and
 * locking other users' or other Events' rows could invert those requests' own
 * Event -> actor lock order.
 *
 * Family-3 pages and exports run their own content inside that same attempt: the
 * selected Event is then required, private rows are share-locked in PK order before
 * any display-ordered read, and statistics are computed in PHP from the materialized
 * rows. Status and pass classifications stay SQL expressions on those rows, so the
 * database collation decides them exactly as it did for the former aggregates.
 *
 * Views receive immutable values with no model or lazy SQL. The layout composer
 * issues no SQL and rereads no session state.
 */
final class LegacyAdminReadProjection
{
    /** Every view that extends layouts.admin; each is prepared before it renders. */
    public const LAYOUT_PAGES = [
        'admin.checkins', 'admin.customers.edit', 'admin.customers.index', 'admin.customers.show', 'admin.dashboard', 'admin.events.create', 'admin.events.edit', 'admin.events.index',
        'admin.guests', 'admin.guests.create', 'admin.guests.edit', 'admin.guests.import', 'admin.guests.show',
        'admin.notification-logs', 'admin.offline-scanner-sessions.create', 'admin.offline-scanner-sessions.result-conflict',
        'admin.offline-scanner-sessions.results-preview', 'admin.offline-scanner-sessions.show', 'admin.qr-codes',
        'admin.reports', 'admin.scanner-users', 'admin.settings', 'admin.users.create', 'admin.users.edit', 'admin.users.index',
    ];

    public const SCAN_RESULT_OPTIONS = [
        Checkin::RESULT_ADMITTED, Checkin::RESULT_VALID, Checkin::RESULT_INVALID, Checkin::RESULT_WRONG_EVENT,
        Checkin::RESULT_ALREADY_USED, Checkin::RESULT_CANCELLED, Checkin::RESULT_REVOKED, Checkin::RESULT_ERROR,
    ];

    public const INVALID_SCAN_RESULTS = [
        Checkin::RESULT_INVALID, Checkin::RESULT_WRONG_EVENT, Checkin::RESULT_ALREADY_USED,
        Checkin::RESULT_CANCELLED, Checkin::RESULT_REVOKED, Checkin::RESULT_ERROR,
    ];

    /**
     * Event-scoped locking reads name their index. Without this, MySQL scans the whole
     * table once an Event holds a large share of its rows, and under REPEATABLE READ a
     * locking scan holds every row it passes, including other Events' rows. These names
     * come from the repository migrations; DEPLOYMENT_CPANEL.md checks them before rollout.
     */
    public const GUESTS_BY_EVENT = 'guests_event_id_index';

    private const CHECKINS_BY_EVENT = 'checkins_event_id_index';

    private const LOGS_BY_EVENT = 'notification_logs_event_id_channel_status_index';

    public const QR_BY_GUEST = 'qr_codes_guest_id_unique';

    /**
     * Layout values for a page without its own family-3 read bundle. The selected
     * Event is optional: an unsafe, missing or owned selection projects nothing
     * rather than refusing catalogue/settings pages. The actor must still be the
     * exact current active administrator.
     *
     * @return array{selectedEvent: ?LayoutEvent, activeOfflineScannerSession: ?LayoutOfflineSession, eventSwitcherEvents: Collection<int, LayoutEvent>}
     */
    public function layout(User $actor, mixed $selectedEventId): array
    {
        $actorId = $this->actorId($actor);
        $eventId = PositiveIntegerId::parse($selectedEventId);

        return $this->attempt(function () use ($actorId, $eventId): array {
            $event = $eventId === null ? null : $this->unownedEvent($eventId);
            $this->currentAdministrator($actorId);
            $selected = $event === null ? null : $this->layoutEvent($event, $eventId);

            return [
                'selectedEvent' => $selected,
                'activeOfflineScannerSession' => $selected === null ? null : $this->bannerSession($selected->id, $actorId),
                'eventSwitcherEvents' => $this->catalogue(),
            ];
        });
    }

    /** Values for a view rendered without an authenticated administrator; no SQL. */
    public static function emptyLayout(): array
    {
        return ['selectedEvent' => null, 'activeOfflineScannerSession' => null, 'eventSwitcherEvents' => new Collection];
    }

    public function dashboardPage(User $actor, mixed $selectedEventId): array
    {
        return $this->page($actor, $selectedEventId, function (int $eventId, Event $event): array {
            $guests = $this->guestFacts($eventId);
            $qrCodes = $this->qrFacts($guests);
            $this->lockCheckins($eventId);
            $stats = $this->dashboardStats($guests, $qrCodes, $this->todayCheckinFacts($eventId));

            return [
                'event' => EventSummary::fromModel($event, $eventId),
                'stats' => $stats,
                'recentGuests' => $this->guestViews($eventId, $this->ids(
                    $this->eventGuests($eventId)->latest()->take(6)->sharedLock()->pluck('id')
                )),
                'recentCheckins' => $this->checkinViews($eventId, $this->ids(
                    $this->eventCheckins($eventId)
                        ->latest('checked_in_at')->latest()->take(8)->sharedLock()->pluck('id')
                ), false),
            ];
        });
    }

    public function reportsPage(User $actor, mixed $selectedEventId): array
    {
        return $this->page($actor, $selectedEventId, function (int $eventId): array {
            $guests = $this->guestFacts($eventId);
            $qrCodes = $this->qrFacts($guests);
            $expected = $this->total($guests->where('not_cancelled', 1), 'allowed_entries');
            $activeUsed = $this->total($guests->where('not_cancelled', 1), 'used_entries');

            return [
                'stats' => $this->dashboardStats($guests, $qrCodes, $this->todayCheckinFacts($eventId)),
                'admissionSummary' => [
                    'total_invites' => $guests->count(),
                    'total_expected_admissions' => $expected,
                    'total_admitted_people' => $this->total($guests, 'used_entries'),
                    'total_remaining_admissions' => max(0, $expected - $activeUsed),
                ],
                'passTypeReport' => [
                    Guest::PASS_SINGLE => $guests->where('pass_single', 1)->count(),
                    Guest::PASS_DOUBLE => $guests->where('pass_double', 1)->count(),
                    Guest::PASS_SPECIAL => $guests->where('pass_special', 1)->count(),
                ],
                'statusReport' => [
                    'unused' => $guests->where('unused', 1)->count(),
                    Guest::STATUS_PARTIALLY_USED => $guests->where('partially_used', 1)->count(),
                    Guest::STATUS_FULLY_USED => $guests->where('fully_used', 1)->count(),
                    Guest::STATUS_CANCELLED => $guests->where('cancelled', 1)->count(),
                ],
                'gateReport' => $this->gateReport($eventId),
                'timeReport' => $this->timeReport($eventId),
            ];
        });
    }

    /** @param array{search: string, pass_type: string, status: string, name_sort: string} $filters */
    public function guestListPage(User $actor, mixed $selectedEventId, array $filters): array
    {
        $window = $this->pageWindow();

        return $this->page($actor, $selectedEventId, function (int $eventId) use ($filters, $window): array {
            $guests = $this->guestFacts($eventId);
            $qrCodes = $this->qrFacts($guests);
            $query = $this->eventGuests($eventId);
            if ($filters['search'] !== '') {
                $search = $filters['search'];
                $query->where(static function (Builder $match) use ($search): void {
                    $match->where('name', 'like', '%'.$search.'%')->orWhere('phone_number', 'like', '%'.$search.'%');
                });
            }
            if (in_array($filters['pass_type'], [Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL], true)) {
                $query->where('pass_type', $filters['pass_type']);
            }
            match ($filters['status']) {
                'unused' => $query->where('status', Guest::STATUS_ACTIVE)->where('used_entries', 0),
                Guest::STATUS_PARTIALLY_USED => $query->where('status', Guest::STATUS_PARTIALLY_USED),
                Guest::STATUS_FULLY_USED => $query->where('status', Guest::STATUS_FULLY_USED),
                Guest::STATUS_CANCELLED => $query->where('status', Guest::STATUS_CANCELLED),
                default => null,
            };
            if ($filters['name_sort'] !== '') {
                $query->orderBy('name', $filters['name_sort'])->orderBy('id');
            } else {
                $query->latest();
            }
            $identity = $this->ids($query->sharedLock()->pluck('id'));
            $history = $this->checkinCounts($eventId, $this->forPage($identity, 50, $window['page']));

            return [
                'guests' => $this->paginate($identity, 50, $window, fn (array $ids): Collection => $this->guestViews($eventId, $ids, $history))->appends($window['query']),
                'filters' => $filters,
                'counts' => [
                    'all' => $guests->count(),
                    'unused' => $guests->where('unused', 1)->count(),
                    Guest::STATUS_PARTIALLY_USED => $guests->where('partially_used', 1)->count(),
                    Guest::STATUS_FULLY_USED => $guests->where('fully_used', 1)->count(),
                    Guest::STATUS_CANCELLED => $guests->where('cancelled', 1)->count(),
                    'revoked' => $qrCodes->where('revoked', 1)->count(),
                ],
            ];
        });
    }

    public function qrListPage(User $actor, mixed $selectedEventId, string $filter, string $search): array
    {
        $window = $this->pageWindow();

        return $this->page($actor, $selectedEventId, function (int $eventId) use ($filter, $search, $window): array {
            $guests = $this->guestFacts($eventId);
            $qrCodes = $this->qrFacts($guests);
            $query = $this->eventGuests($eventId)->latest();
            if ($search !== '') {
                $phoneSearch = preg_replace('/[\s\-().]+/', '', $search) ?? $search;
                $query->where(static function (Builder $match) use ($search, $phoneSearch): void {
                    $match->where('name', 'like', '%'.$search.'%')->orWhere('phone_number', 'like', '%'.$search.'%');
                    if ($phoneSearch !== $search) {
                        $match->orWhere('phone_number', 'like', '%'.$phoneSearch.'%');
                    }
                });
            }
            match ($filter) {
                'fully_used' => $query->where('status', Guest::STATUS_FULLY_USED),
                'unused' => $query->where('status', Guest::STATUS_ACTIVE)->where('used_entries', 0),
                default => null,
            };
            $identity = array_values(array_filter($this->ids($query->sharedLock()->pluck('id')),
                static fn (int $id): bool => match ($filter) {
                    'has_qr' => $qrCodes->has($id),
                    'missing_qr' => ! $qrCodes->has($id),
                    'active_qr' => (int) ($qrCodes->get($id)?->active ?? 0) === 1,
                    'revoked_qr' => (int) ($qrCodes->get($id)?->revoked ?? 0) === 1,
                    default => true,
                }));

            return [
                'guests' => $this->paginate($identity, 15, $window, fn (array $ids): Collection => $this->guestViews($eventId, $ids))->appends($window['query']),
                'activeFilter' => $filter,
                'search' => $search,
                'stats' => [
                    'total_guests' => $guests->count(),
                    'has_qr' => $qrCodes->count(),
                    'missing_qr' => $guests->count() - $qrCodes->count(),
                    'active' => $qrCodes->where('active', 1)->count(),
                    'revoked' => $qrCodes->where('revoked', 1)->count(),
                    'fully_used' => $guests->where('fully_used', 1)->count(),
                    'unused' => $guests->where('unused', 1)->count(),
                ],
            ];
        });
    }

    /** The exact current Guest of the selected Event; a moved or missing Guest refuses 404. */
    public function guestDetailPage(User $actor, mixed $selectedEventId, mixed $guestId, bool $withHistory): array
    {
        $guestId = PositiveIntegerId::parse($guestId);
        abort_unless($guestId !== null, 404);

        return $this->page($actor, $selectedEventId, function (int $eventId) use ($guestId, $withHistory): array {
            $guest = Guest::query()->useWritePdo()->whereKey($guestId)->where('event_id', $eventId)->sharedLock()->first();
            abort_unless($guest instanceof Guest
                && PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName())) === $guestId
                && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId, 404);
            $qr = $this->currentQrViews([$guestId])->get($guestId);
            $history = new Collection;
            if ($withHistory) {
                foreach (Checkin::query()->useWritePdo()->where('guest_id', $guestId)->where('event_id', $eventId)
                    ->orderBy('id')->sharedLock()->get() as $checkin) {
                    if ($this->attributed($checkin, $eventId)) {
                        $history->push(CheckinView::fromModel($checkin));
                    }
                }
            }

            return ['guest' => GuestView::fromModel($guest, $guestId, $qr, $history->count(), $history)];
        });
    }

    public function checkinPage(User $actor, mixed $selectedEventId, array $filters): array
    {
        $window = $this->pageWindow();

        return $this->page($actor, $selectedEventId, function (int $eventId) use ($filters, $window): array {
            $this->lockCheckins($eventId);
            $identity = $this->ids($this->filteredCheckins($eventId, $filters)->sharedLock()->pluck('id'));
            $today = $this->todayCheckinFacts($eventId);

            return [
                'checkins' => $this->paginate($identity, 20, $window, fn (array $ids): Collection => $this->checkinViews($eventId, $ids, true))->appends($window['query']),
                'summary' => [
                    'total_scans_today' => $today->count(),
                    'successful_admissions_today' => $this->total($today->where('admitted', 1), 'entries_added'),
                    'invalid_attempts_today' => $today->where('invalid_group', 1)->count(),
                    'already_used_attempts_today' => $today->where('already_used', 1)->count(),
                    'cancelled_revoked_attempts_today' => $today->where('cancelled_revoked', 1)->count(),
                ],
                'filters' => $filters,
                'scanResults' => self::SCAN_RESULT_OPTIONS,
                'gateNames' => $this->eventCheckins($eventId)
                    ->whereNotNull('gate_name')->where('gate_name', '<>', '')
                    ->distinct()->orderBy('gate_name')->sharedLock()->pluck('gate_name'),
            ];
        });
    }

    public function notificationLogPage(User $actor, mixed $selectedEventId): array
    {
        $window = $this->pageWindow();

        return $this->page($actor, $selectedEventId, function (int $eventId) use ($window): array {
            $this->eventLogs($eventId)->orderBy('id')->sharedLock()->pluck('id');
            // Null-Event rows are not evidence of selected-Event ownership.
            $identity = $this->ids($this->eventLogs($eventId)->latest()->sharedLock()->pluck('id'));

            return ['logs' => $this->paginate($identity, 20, $window, fn (array $ids): Collection => $this->notificationViews($eventId, $ids))];
        });
    }

    /** Selected Event, actor and layout only: pages whose content is session or form state. */
    public function importPage(User $actor, mixed $selectedEventId): array
    {
        return $this->page($actor, $selectedEventId, static fn (): array => []);
    }

    /**
     * Workbook rows classified against current Guests inside the read bundle. The preview
     * and its stored rows keep only these frozen fields; Blade looks nothing up.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function importPreviewPage(User $actor, mixed $selectedEventId, array $rows): array
    {
        return $this->page($actor, $selectedEventId, function (int $eventId) use ($rows): array {
            foreach ($rows as $index => $row) {
                $row['duplicate'] = null;
                if (! ($row['is_valid'] ?? false)) {
                    $row['status'] = 'invalid';
                    $row['status_label'] = 'INVALID';
                    $row['action'] = null;
                    $rows[$index] = $row;

                    continue;
                }
                $name = (string) ($row['data']['name'] ?? '');
                $phoneNumber = (string) ($row['data']['phone_number'] ?? '');
                $duplicate = $this->duplicateGuest($eventId, $name, $phoneNumber);
                if ($duplicate !== null) {
                    $row['status'] = 'duplicate';
                    $row['status_label'] = 'DUPLICATE';
                    $row['action'] = 'skip';
                    $row['duplicate'] = $this->duplicatePayload($duplicate, $name, $phoneNumber);
                } else {
                    $row['status'] = 'new';
                    $row['status_label'] = 'NEW';
                    $row['action'] = 'import';
                }
                $rows[$index] = $row;
            }

            return ['rows' => $rows];
        });
    }

    /**
     * One locked attempt for a complete export: $prepare builds the frozen response
     * bytes before it returns. Nothing later reads private data or the session.
     *
     * @param  Closure(int, Event, self): mixed  $prepare
     */
    public function export(User $actor, mixed $selectedEventId, Closure $prepare): mixed
    {
        $actorId = $this->actorId($actor);
        $eventId = PositiveIntegerId::parse($selectedEventId);

        return $this->attempt(function () use ($actorId, $eventId, $prepare): mixed {
            $event = $this->requiredEvent($eventId);
            $this->currentAdministrator($actorId);

            return $prepare($eventId, $event, $this);
        });
    }

    /**
     * Complete Guest rows for an export, by the existing name order and predicate.
     *
     * @param  Closure(Builder): void|null  $constraint
     * @return Collection<int, GuestView>
     */
    public function guestExportRows(int $eventId, ?Closure $constraint = null): Collection
    {
        $this->guestFacts($eventId);
        $query = $this->eventGuests($eventId)->orderBy('name');
        if ($constraint !== null) {
            $constraint($query);
        }

        return $this->guestViews($eventId, $this->ids($query->sharedLock()->pluck('id')));
    }

    /** @return Collection<int, CheckinView> */
    public function checkinExportRows(int $eventId, array $filters): Collection
    {
        $this->lockCheckins($eventId);

        return $this->checkinViews($eventId, $this->ids($this->filteredCheckins($eventId, $filters)->sharedLock()->pluck('id')), true);
    }

    /** @return Collection<int, CheckinView> */
    public function invalidScanExportRows(int $eventId): Collection
    {
        $this->lockCheckins($eventId);

        return $this->checkinViews($eventId, $this->ids($this->eventCheckins($eventId)
            ->whereIn('scan_result', self::INVALID_SCAN_RESULTS)->latest('checked_in_at')->latest()->sharedLock()->pluck('id')), true);
    }

    /**
     * Required selected Event, actor, offline banner, then the page's own scoped
     * reads, then the layout's unlocked labels: one attempt, one frozen result.
     *
     * @param  Closure(int, Event): array<string, mixed>  $content
     */
    private function page(User $actor, mixed $selectedEventId, Closure $content): array
    {
        $actorId = $this->actorId($actor);
        $eventId = PositiveIntegerId::parse($selectedEventId);

        return $this->attempt(function () use ($actorId, $eventId, $content): array {
            $event = $this->requiredEvent($eventId);
            $this->currentAdministrator($actorId);
            $banner = $this->bannerSession($eventId, $actorId);
            $values = $content($eventId, $event);
            $values['adminLayout'] = [
                'selectedEvent' => $this->layoutEvent($event, $eventId),
                'activeOfflineScannerSession' => $banner,
                'eventSwitcherEvents' => $this->catalogue(),
            ];

            return $values;
        });
    }

    private function eventGuests(int $eventId): Builder
    {
        return Guest::query()->useWritePdo()->forceIndex(self::GUESTS_BY_EVENT)->where('guests.event_id', $eventId);
    }

    private function eventCheckins(int $eventId): Builder
    {
        return Checkin::query()->useWritePdo()->forceIndex(self::CHECKINS_BY_EVENT)->where('checkins.event_id', $eventId);
    }

    private function eventLogs(int $eventId): Builder
    {
        return NotificationLog::query()->useWritePdo()->forceIndex(self::LOGS_BY_EVENT)->where('notification_logs.event_id', $eventId);
    }

    private function requiredEvent(?int $eventId): Event
    {
        $event = $eventId === null ? null : $this->unownedEvent($eventId);
        if ($event === null) {
            session()->forget(['selected_event_id', 'guest_import']);
            abort(404);
        }

        return $event;
    }

    private function unownedEvent(int $eventId): ?Event
    {
        $event = Event::query()->useWritePdo()->whereKey($eventId)->whereNull('workspace_id')->sharedLock()->first();

        return $event instanceof Event
            && PositiveIntegerId::parse($event->getRawOriginal($event->getKeyName())) === $eventId
            && $event->getRawOriginal('workspace_id') === null ? $event : null;
    }

    private function currentAdministrator(int $actorId): void
    {
        $actor = User::query()->useWritePdo()->whereKey($actorId)->sharedLock()->first();
        abort_unless($actor instanceof User
            && PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName())) === $actorId
            && $actor->getRawOriginal('role') === User::ROLE_ADMIN
            && in_array($actor->getRawOriginal('is_active'), [true, 1, '1'], true), 403);
    }

    /**
     * The selected Event's blocking Session with frozen package and creator labels.
     * The package time needs the package to link back to this exact Session. The
     * creator label needs the current actor itself or an exact legacy administrator
     * (admin role bytes, not platform, no workspace membership; inactive is kept
     * as a historical label). Anything else keeps the existing fallback labels.
     */
    private function bannerSession(int $eventId, int $actorId): ?LayoutOfflineSession
    {
        $session = OfflineScannerSession::query()->useWritePdo()->where('event_id', $eventId)
            ->whereIn('status', OfflineScannerSession::blockingStatuses())->orderBy('id')->sharedLock()->first();
        if (! $session instanceof OfflineScannerSession) {
            return null;
        }
        $sessionId = PositiveIntegerId::parse($session->getRawOriginal($session->getKeyName()));
        $status = $session->getRawOriginal('status');
        if ($sessionId === null || PositiveIntegerId::parse($session->getRawOriginal('event_id')) !== $eventId
            || ! in_array($status, OfflineScannerSession::blockingStatuses(), true)) {
            return null;
        }

        $package = null;
        $packageId = PositiveIntegerId::parse($session->getRawOriginal('package_export_id'));
        if ($packageId !== null) {
            $candidate = OfflineScannerPackage::query()->useWritePdo()->whereKey($packageId)
                ->where('offline_scanner_session_id', $sessionId)->first();
            if ($candidate instanceof OfflineScannerPackage
                && PositiveIntegerId::parse($candidate->getRawOriginal($candidate->getKeyName())) === $packageId
                && PositiveIntegerId::parse($candidate->getRawOriginal('offline_scanner_session_id')) === $sessionId) {
                $package = new LayoutPackage($this->instant($candidate->generated_at));
            }
        }

        $creator = null;
        $creatorId = PositiveIntegerId::parse($session->getRawOriginal('created_by_user_id'));
        if ($creatorId !== null) {
            $query = User::query()->useWritePdo()->whereKey($creatorId);
            if ($creatorId !== $actorId) {
                $query->where('users.role', User::ROLE_ADMIN)
                    ->whereRaw('HEX(users.role) = ?', [strtoupper(bin2hex(User::ROLE_ADMIN))])
                    ->where('users.is_platform_admin', false)
                    ->whereNotExists(static function ($memberships): void {
                        $memberships->selectRaw('1')->from('workspace_users')->whereColumn('workspace_users.user_id', 'users.id');
                    });
            }
            $candidate = $query->first(['id', 'name', 'role', 'is_platform_admin']);
            if ($candidate instanceof User
                && PositiveIntegerId::parse($candidate->getRawOriginal($candidate->getKeyName())) === $creatorId
                && ($creatorId === $actorId || ($candidate->getRawOriginal('role') === User::ROLE_ADMIN
                    && in_array($candidate->getRawOriginal('is_platform_admin'), [false, 0, '0'], true)))) {
                $creator = new LayoutCreator((string) $candidate->getRawOriginal('name'));
            }
        }

        return new LayoutOfflineSession($sessionId, (string) $session->getRawOriginal('session_uuid'), $status,
            $this->instant($session->package_created_at), $this->instant($session->package_expires_at), $package, $creator);
    }

    /** The existing all-active-unowned navigation catalogue; selection is not ownership. */
    private function catalogue(): Collection
    {
        $entries = new Collection;
        foreach (Event::query()->useWritePdo()->whereNull('workspace_id')->whereBetween('id', [1, PHP_INT_MAX])
            ->where('status', Event::STATUS_ACTIVE)->orderBy('event_date')->orderBy('event_name')->get() as $event) {
            $eventId = PositiveIntegerId::parse($event->getRawOriginal($event->getKeyName()));
            if ($eventId !== null && $event->getRawOriginal('workspace_id') === null) {
                $entries->push($this->layoutEvent($event, $eventId));
            }
        }

        return $entries;
    }

    private function layoutEvent(Event $event, int $eventId): LayoutEvent
    {
        return new LayoutEvent($eventId, (string) $event->getRawOriginal('event_name'));
    }

    /**
     * Every current Guest of the Event, share-locked in PK order, with the existing
     * classifications decided by SQL on each row and the arithmetic left to PHP.
     *
     * @return Collection<int, object> keyed by Guest ID
     */
    private function guestFacts(int $eventId): Collection
    {
        $rows = $this->eventGuests($eventId)->orderBy('id')->sharedLock()->toBase()
            ->select(['id', 'event_id', 'allowed_entries', 'used_entries'])
            ->selectRaw('CASE WHEN status <> ? THEN 1 ELSE 0 END AS not_cancelled', [Guest::STATUS_CANCELLED])
            ->selectRaw('CASE WHEN pass_type = ? THEN 1 ELSE 0 END AS pass_single', [Guest::PASS_SINGLE])
            ->selectRaw('CASE WHEN pass_type = ? THEN 1 ELSE 0 END AS pass_double', [Guest::PASS_DOUBLE])
            ->selectRaw('CASE WHEN pass_type = ? THEN 1 ELSE 0 END AS pass_special', [Guest::PASS_SPECIAL])
            ->selectRaw('CASE WHEN status = ? AND used_entries = 0 THEN 1 ELSE 0 END AS unused', [Guest::STATUS_ACTIVE])
            ->selectRaw('CASE WHEN status = ? THEN 1 ELSE 0 END AS partially_used', [Guest::STATUS_PARTIALLY_USED])
            ->selectRaw('CASE WHEN status = ? THEN 1 ELSE 0 END AS fully_used', [Guest::STATUS_FULLY_USED])
            ->selectRaw('CASE WHEN status = ? THEN 1 ELSE 0 END AS cancelled', [Guest::STATUS_CANCELLED])
            ->get();

        $facts = new Collection;
        foreach ($rows as $row) {
            $id = PositiveIntegerId::parse($row->id);
            if ($id !== null && PositiveIntegerId::parse($row->event_id) === $eventId) {
                $facts->put($id, $this->integers($row));
            }
        }

        return $facts;
    }

    /**
     * QR state of those exact Guests, share-locked in QR PK order. It reads by Guest ID
     * rather than joining guests: on MySQL that join scanned from qr_codes and held
     * other Events' QR and Guest rows until the attempt ended.
     *
     * @param  Collection<int, object>  $guests  guestFacts() of the same Event
     * @return Collection<int, object> keyed by Guest ID
     */
    private function qrFacts(Collection $guests): Collection
    {
        $facts = new Collection;
        if ($guests->isEmpty()) {
            return $facts;
        }
        $rows = QrCode::query()->useWritePdo()->forceIndex(self::QR_BY_GUEST)->whereIntegerInRaw('guest_id', $guests->keys()->all())->orderBy('id')->sharedLock()->toBase()
            ->select(['guest_id'])
            ->selectRaw('CASE WHEN is_active = ? AND revoked_at IS NULL THEN 1 ELSE 0 END AS active', [true])
            ->selectRaw('CASE WHEN is_active = ? OR revoked_at IS NOT NULL THEN 1 ELSE 0 END AS revoked', [false])
            ->get();

        foreach ($rows as $row) {
            $guestId = PositiveIntegerId::parse($row->guest_id);
            if ($guestId !== null && $guests->has($guestId)) {
                $facts->put($guestId, $this->integers($row));
            }
        }

        return $facts;
    }

    /** @return Collection<int, object> today's attributed attempts, share-locked in PK order */
    private function todayCheckinFacts(int $eventId): Collection
    {
        $rows = $this->eventCheckins($eventId)->whereDate('checked_in_at', today())
            ->orderBy('id')->sharedLock()->toBase()
            ->select(['id', 'entries_added'])
            ->selectRaw('CASE WHEN scan_result = ? THEN 1 ELSE 0 END AS admitted', [Checkin::RESULT_ADMITTED])
            ->selectRaw('CASE WHEN scan_result IN (?, ?, ?) THEN 1 ELSE 0 END AS invalid_group',
                [Checkin::RESULT_INVALID, Checkin::RESULT_WRONG_EVENT, Checkin::RESULT_ERROR])
            ->selectRaw('CASE WHEN scan_result = ? THEN 1 ELSE 0 END AS already_used', [Checkin::RESULT_ALREADY_USED])
            ->selectRaw('CASE WHEN scan_result IN (?, ?) THEN 1 ELSE 0 END AS cancelled_revoked',
                [Checkin::RESULT_CANCELLED, Checkin::RESULT_REVOKED])
            ->get();

        return $rows->map(fn (object $row): object => $this->integers($row))->values();
    }

    /** Share-locks the Event's history in PK order before any display-ordered read. */
    private function lockCheckins(int $eventId): void
    {
        $this->eventCheckins($eventId)->orderBy('id')->sharedLock()->pluck('id');
    }

    private function dashboardStats(Collection $guests, Collection $qrCodes, Collection $today): array
    {
        $totalAllowed = $this->total($guests->where('not_cancelled', 1), 'allowed_entries');
        $activeUsed = $this->total($guests->where('not_cancelled', 1), 'used_entries');

        return [
            'total_guests' => $guests->count(),
            'single_passes' => $guests->where('pass_single', 1)->count(),
            'double_passes' => $guests->where('pass_double', 1)->count(),
            'special_passes' => $guests->where('pass_special', 1)->count(),
            'unused_passes' => $guests->where('unused', 1)->count(),
            'partially_used' => $guests->where('partially_used', 1)->count(),
            'fully_used' => $guests->where('fully_used', 1)->count(),
            'cancelled' => $guests->where('cancelled', 1)->count(),
            'qr_codes' => $qrCodes->count(),
            'revoked_qr_codes' => $qrCodes->where('revoked', 1)->count(),
            'admitted_entries' => $this->total($guests, 'used_entries'),
            'remaining_entries' => max(0, $totalAllowed - $activeUsed),
            'total_allowed_entries' => $totalAllowed,
            'today_scans' => $today->count(),
            'today_admitted' => $this->total($today->where('admitted', 1), 'entries_added'),
        ];
    }

    private function gateReport(int $eventId): array
    {
        $rows = [];
        $ensureGate = static function (mixed $gateName) use (&$rows): string {
            $key = filled($gateName) ? (string) $gateName : 'No gate';
            $rows[$key] ??= ['gate_name' => $key, 'admissions' => 0, 'invalid_attempts' => 0];

            return $key;
        };
        foreach ($this->eventCheckins($eventId)->where('scan_result', Checkin::RESULT_ADMITTED)
            ->orderBy('id')->sharedLock()->get(['id', 'gate_name', 'entries_added']) as $checkin) {
            $rows[$ensureGate($checkin->gate_name)]['admissions'] += (int) $checkin->entries_added;
        }
        foreach ($this->eventCheckins($eventId)->whereIn('scan_result', self::INVALID_SCAN_RESULTS)
            ->orderBy('id')->sharedLock()->get(['id', 'gate_name']) as $checkin) {
            $rows[$ensureGate($checkin->gate_name)]['invalid_attempts']++;
        }
        ksort($rows);

        return array_values($rows);
    }

    private function timeReport(int $eventId): array
    {
        $hours = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $hours[sprintf('%02d:00', $hour)] = ['hour' => sprintf('%02d:00', $hour), 'admissions' => 0];
        }
        foreach ($this->eventCheckins($eventId)->where('scan_result', Checkin::RESULT_ADMITTED)
            ->orderBy('id')->sharedLock()->get(['id', 'checked_in_at', 'created_at', 'entries_added']) as $checkin) {
            $time = $checkin->checked_in_at ?? $checkin->created_at;
            $hours[$time->format('H:00')]['admissions'] += (int) $checkin->entries_added;
        }

        return array_values($hours);
    }

    /** The Guest name/phone OR group stays inside the selected Event by materializing its matches first. */
    private function filteredCheckins(int $eventId, array $filters): Builder
    {
        $query = $this->eventCheckins($eventId);
        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->whereIntegerInRaw('checkins.guest_id', $this->ids($this->eventGuests($eventId)
                ->where(static function (Builder $match) use ($search): void {
                    $match->where('name', 'like', '%'.$search.'%')->orWhere('phone_number', 'like', '%'.$search.'%');
                })->orderBy('id')->sharedLock()->pluck('id')));
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

        return $query->latest('checked_in_at')->latest();
    }

    /**
     * Frozen Guest rows in the given identity order, with the current QR only for
     * that exact Guest. A row missing under the locks refuses the whole result.
     *
     * @param  list<int>  $ids
     * @param  array<int, int>  $checkinCounts
     * @return Collection<int, GuestView>
     */
    private function guestViews(int $eventId, array $ids, array $checkinCounts = []): Collection
    {
        if ($ids === []) {
            return new Collection;
        }
        $rows = [];
        foreach ($this->eventGuests($eventId)->whereIntegerInRaw('id', $ids)
            ->orderBy('id')->sharedLock()->get() as $guest) {
            $id = PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName()));
            if ($id !== null && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId) {
                $rows[$id] = $guest;
            }
        }
        $qrCodes = $this->currentQrViews($ids);
        $views = new Collection;
        foreach ($ids as $id) {
            if (! isset($rows[$id])) {
                throw new LegacyAdminReadUnavailable;
            }
            $views->push(GuestView::fromModel($rows[$id], $id, $qrCodes->get($id), $checkinCounts[$id] ?? 0));
        }

        return $views;
    }

    /**
     * @param  list<int>  $guestIds
     * @return Collection<int, QrView> keyed by exact Guest ID
     */
    private function currentQrViews(array $guestIds): Collection
    {
        $views = new Collection;
        foreach (QrCode::query()->useWritePdo()->forceIndex(self::QR_BY_GUEST)->whereIntegerInRaw('guest_id', $guestIds)->orderBy('id')->sharedLock()->get() as $qr) {
            $guestId = PositiveIntegerId::parse($qr->getRawOriginal('guest_id'));
            if ($guestId !== null && in_array($guestId, $guestIds, true)) {
                $views->put($guestId, QrView::fromModel($qr));
            }
        }

        return $views;
    }

    /**
     * History attributed to both the Guest and the selected Event, counted in PHP.
     *
     * @param  list<int>  $guestIds
     * @return array<int, int>
     */
    private function checkinCounts(int $eventId, array $guestIds): array
    {
        $counts = [];
        if ($guestIds === []) {
            return $counts;
        }
        foreach (Checkin::query()->useWritePdo()->whereIntegerInRaw('guest_id', $guestIds)->where('event_id', $eventId)
            ->orderBy('id')->sharedLock()->pluck('guest_id') as $guestId) {
            $guestId = PositiveIntegerId::parse($guestId);
            if ($guestId !== null) {
                $counts[$guestId] = ($counts[$guestId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Frozen history rows in identity order. Their own stored scalars always stay;
     * Guest and scanner decoration appear only for exact selected-Event lineage.
     *
     * @param  list<int>  $ids
     * @return Collection<int, CheckinView>
     */
    private function checkinViews(int $eventId, array $ids, bool $withOperators): Collection
    {
        if ($ids === []) {
            return new Collection;
        }
        $rows = [];
        foreach ($this->eventCheckins($eventId)->whereIntegerInRaw('id', $ids)
            ->orderBy('id')->sharedLock()->get() as $checkin) {
            $id = PositiveIntegerId::parse($checkin->getRawOriginal($checkin->getKeyName()));
            if ($id !== null && $this->attributed($checkin, $eventId)) {
                $rows[$id] = $checkin;
            }
        }
        $guests = $this->historyGuests($eventId, array_map(static fn (Checkin $row): mixed => $row->getRawOriginal('guest_id'), $rows));
        $users = $withOperators ? $this->historyUsers($eventId, array_map(static fn (Checkin $row): mixed => $row->getRawOriginal('user_id'), $rows)) : [];
        $views = new Collection;
        foreach ($ids as $id) {
            if (! isset($rows[$id])) {
                throw new LegacyAdminReadUnavailable;
            }
            $views->push(CheckinView::fromModel($rows[$id],
                $guests[PositiveIntegerId::parse($rows[$id]->getRawOriginal('guest_id')) ?? 0] ?? null,
                $users[PositiveIntegerId::parse($rows[$id]->getRawOriginal('user_id')) ?? 0] ?? null));
        }

        return $views;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, NotificationLogView>
     */
    private function notificationViews(int $eventId, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }
        $rows = [];
        foreach ($this->eventLogs($eventId)->whereIntegerInRaw('id', $ids)
            ->orderBy('id')->sharedLock()->get() as $log) {
            $id = PositiveIntegerId::parse($log->getRawOriginal($log->getKeyName()));
            if ($id !== null && PositiveIntegerId::parse($log->getRawOriginal('event_id')) === $eventId) {
                $rows[$id] = $log;
            }
        }
        $guests = $this->historyGuests($eventId, array_map(static fn (NotificationLog $row): mixed => $row->getRawOriginal('guest_id'), $rows));
        $views = new Collection;
        foreach ($ids as $id) {
            if (! isset($rows[$id])) {
                throw new LegacyAdminReadUnavailable;
            }
            $views->push(NotificationLogView::fromModel($rows[$id], $guests[PositiveIntegerId::parse($rows[$id]->getRawOriginal('guest_id')) ?? 0] ?? null));
        }

        return $views;
    }

    /**
     * Current Guest labels for exact selected-Event lineage only, share-locked in PK order.
     *
     * @param  array<int, mixed>  $rawIds
     * @return array<int, HistoryGuest>
     */
    private function historyGuests(int $eventId, array $rawIds): array
    {
        $ids = $this->uniqueIds($rawIds);
        $labels = [];
        if ($ids === []) {
            return $labels;
        }
        foreach ($this->eventGuests($eventId)->whereIntegerInRaw('id', $ids)
            ->orderBy('id')->sharedLock()->get() as $guest) {
            $id = PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName()));
            if ($id !== null && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId) {
                $labels[$id] = new HistoryGuest((string) $guest->getRawOriginal('name'), $guest->getRawOriginal('phone_number'), $guest->passTypeLabel());
            }
        }

        return $labels;
    }

    /**
     * Scanner labels, matching LegacyOfflineScannerReview: exact scanner role bytes, the
     * same Event, not platform and no workspace membership; inactive stays a label. These
     * are unlocked primary label reads: locking other accounts is not needed for a label
     * and could invert those accounts' own writers.
     *
     * @param  array<int, mixed>  $rawIds
     * @return array<int, HistoryUser>
     */
    private function historyUsers(int $eventId, array $rawIds): array
    {
        $ids = $this->uniqueIds($rawIds);
        $labels = [];
        if ($ids === []) {
            return $labels;
        }
        foreach (User::query()->useWritePdo()->whereIntegerInRaw('users.id', $ids)->where('users.event_id', $eventId)
            ->where('users.role', User::ROLE_SCANNER)
            ->whereRaw('HEX(users.role) = ?', [strtoupper(bin2hex(User::ROLE_SCANNER))])
            ->where('users.is_platform_admin', false)
            ->whereNotExists(static function ($memberships): void {
                $memberships->selectRaw('1')->from('workspace_users')->whereColumn('workspace_users.user_id', 'users.id');
            })->orderBy('users.id')->get(['id', 'name', 'event_id', 'role', 'is_platform_admin']) as $user) {
            $id = PositiveIntegerId::parse($user->getRawOriginal($user->getKeyName()));
            if ($id !== null && PositiveIntegerId::parse($user->getRawOriginal('event_id')) === $eventId
                && $user->getRawOriginal('role') === User::ROLE_SCANNER
                && in_array($user->getRawOriginal('is_platform_admin'), [false, 0, '0'], true)) {
                $labels[$id] = new HistoryUser((string) $user->getRawOriginal('name'));
            }
        }

        return $labels;
    }

    /** The existing phone OR LOWER(TRIM(name)) match with its first-PK tie-break, share-locked. */
    private function duplicateGuest(int $eventId, string $name, string $phoneNumber): ?Guest
    {
        $normalizedName = $this->duplicateName($name);
        if ($normalizedName === '' && $phoneNumber === '') {
            return null;
        }
        $guest = $this->eventGuests($eventId)
            ->where(static function (Builder $query) use ($normalizedName, $phoneNumber): void {
                if ($phoneNumber !== '') {
                    $query->where('phone_number', $phoneNumber);
                }
                if ($normalizedName !== '') {
                    $method = $phoneNumber !== '' ? 'orWhereRaw' : 'whereRaw';
                    $query->{$method}('LOWER(TRIM(name)) = ?', [$normalizedName]);
                }
            })->orderBy('id')->sharedLock()->first();

        return $guest instanceof Guest
            && PositiveIntegerId::parse($guest->getRawOriginal($guest->getKeyName())) !== null
            && PositiveIntegerId::parse($guest->getRawOriginal('event_id')) === $eventId ? $guest : null;
    }

    private function duplicateName(string $name): string
    {
        return Str::lower(preg_replace('/\s+/', ' ', trim($name)) ?? '');
    }

    private function duplicatePayload(Guest $guest, string $incomingName, string $incomingPhoneNumber): array
    {
        $matchReasons = [];
        if ($this->duplicateName($guest->name) === $this->duplicateName($incomingName)) {
            $matchReasons[] = 'Name (case-insensitive)';
        }
        if ($guest->phone_number === $incomingPhoneNumber) {
            $matchReasons[] = 'Phone number';
        }

        return [
            'id' => $guest->id,
            'name' => $guest->name,
            'phone_number' => $guest->phone_number,
            'manual_code' => $guest->manual_code,
            'pass_type' => $guest->pass_type,
            'pass_type_label' => $guest->passTypeLabel(),
            'allowed_entries' => $guest->allowed_entries,
            'used_entries' => $guest->used_entries,
            'remaining_entries' => $guest->remainingEntries(),
            'status' => $guest->status,
            'status_label' => match ($guest->status) {
                Guest::STATUS_ACTIVE => 'Active',
                Guest::STATUS_PARTIALLY_USED => 'Partially Used',
                Guest::STATUS_FULLY_USED => 'Fully Used',
                Guest::STATUS_CANCELLED => 'Cancelled',
                default => str_replace('_', ' ', ucwords($guest->status, '_')),
            },
            'match_reasons' => $matchReasons,
        ];
    }

    private function attributed(Checkin $checkin, int $eventId): bool
    {
        return PositiveIntegerId::parse($checkin->getRawOriginal('event_id')) === $eventId;
    }

    /**
     * The page, path and query string, captured from the request once before the attempt.
     * Nothing inside the attempt or later reads them from the request again.
     *
     * @return array{page: int, path: string, query: array<string, mixed>|null}
     */
    private function pageWindow(): array
    {
        return ['page' => Paginator::resolveCurrentPage('page'), 'path' => Paginator::resolveCurrentPath(), 'query' => Paginator::resolveQueryString()];
    }

    /**
     * The same page window and total as Eloquent's paginate(), from one identity set.
     *
     * @param  list<int>  $identity
     * @param  array{page: int, path: string, query: array<string, mixed>|null}  $window
     * @param  Closure(list<int>): Collection  $views
     */
    private function paginate(array $identity, int $perPage, array $window, Closure $views): LengthAwarePaginator
    {
        return new LengthAwarePaginator($views($this->forPage($identity, $perPage, $window['page'])), count($identity), $perPage,
            $window['page'], ['path' => $window['path'], 'pageName' => 'page']);
    }

    /**
     * @param  list<int>  $identity
     * @return list<int>
     */
    private function forPage(array $identity, int $perPage, int $page): array
    {
        $offset = ($page - 1) * $perPage;

        return is_int($offset) && $offset < count($identity) ? array_slice($identity, $offset, $perPage) : [];
    }

    /**
     * Safe canonical IDs, in the given order.
     *
     * @return list<int>
     */
    private function ids(iterable $rawIds): array
    {
        $ids = [];
        foreach ($rawIds as $rawId) {
            $id = PositiveIntegerId::parse($rawId);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return list<int> */
    private function uniqueIds(iterable $rawIds): array
    {
        return array_values(array_unique($this->ids($rawIds)));
    }

    /** Row values as integers, so flag comparisons do not depend on the PDO driver's types. */
    private function integers(object $row): object
    {
        return (object) array_map(static fn (mixed $value): int => (int) $value, (array) $row);
    }

    private function total(Collection $rows, string $field): int
    {
        return (int) $rows->sum(static fn (object $row): int => (int) $row->{$field});
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : null;
    }

    private function actorId(User $actor): int
    {
        abort_unless($actor->hasSafeIdentityId(), 403);
        $actorId = PositiveIntegerId::parse($actor->getRawOriginal($actor->getKeyName()));
        abort_unless($actorId !== null
            && PositiveIntegerId::parse($actor->getAttributes()[$actor->getKeyName()] ?? null) === $actorId, 403);

        return $actorId;
    }

    /** Exactly one read-only attempt; actual SQL keeps framework handling, anything else is fixed. */
    private function attempt(Closure $body): mixed
    {
        try {
            return DB::connection()->transaction(function () use ($body): mixed {
                try {
                    return $body();
                } catch (HttpExceptionInterface|LegacyAdminReadUnavailable|PDOException $exception) {
                    throw $exception;
                } catch (Throwable) {
                    throw new LegacyAdminReadUnavailable;
                }
            }, 1);
        } catch (HttpExceptionInterface|LegacyAdminReadUnavailable|PDOException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LegacyAdminReadUnavailable;
        }
    }
}
