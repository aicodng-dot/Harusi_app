<?php

namespace App\Services\Events;

use App\Models\Guest;
use App\Services\Invitations\InvitationDesign;
use App\Services\Invitations\InvitationDelivery;
use App\Services\Rsvp\RsvpService;
use App\Services\Sharing\ShareRecords;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Search, filters, sorting and paging for one event's guest list (customer Guests page).
 *
 * Every query starts from `guests.event_id = <authorized event>`; the caller authorizes the
 * event. Fields the database holds (name, phone, manual code, pass, allowance, usage, status,
 * created date) are searched, filtered and sorted in SQL. Card state (needs the SHA-256 of the
 * guest's QR token, as InvitationStudio::cardStates does) and the latest WhatsApp status are
 * computed for the matching rows only, then the invitation filters, the "card date" sort and
 * paging are applied. Only data the application tracks is offered as a filter.
 */
class GuestDirectory
{
    public const PER_PAGE = 50;

    public const MAX_MATCHES = 5000;

    public const SORTS = ['name_asc', 'name_desc', 'newest', 'oldest', 'pass', 'allowed_desc', 'used_desc', 'remaining_desc', 'card_newest'];

    public const INVITE = ['generated', 'outdated', 'not_generated', 'sent', 'not_sent', 'failed'];

    public const ADMISSION = ['not_checked_in', 'partial', 'full', 'cancelled'];

    /** Manual sharing (Sharing Center): only what the customer did or told us. */
    public const SHARE = ['not_shared', 'initiated', 'confirmed', 'skipped'];

    /** RSVP reply (no row = the invitation has not been issued yet). */
    public const RSVP = ['attending', 'not_attending', 'pending', 'not_invited'];

    public const PASSES = [Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL];

    private const SENT = ['pending', 'sent', 'delivered', 'read'];

    public function __construct(private InvitationDelivery $delivery, private ShareRecords $shares, private RsvpService $rsvp) {}

    /**
     * Normalises the query string into known values only.
     *
     * @return array{q: string, pass: list<string>, invite: string, share: string, rsvp: string, admission: string, phone: string, added: string, entries: string, remaining: string, sort: string, page: int}
     */
    public static function params(array $query): array
    {
        $pick = fn (string $key, array $allowed, string $default = '') => in_array($query[$key] ?? null, $allowed, true) ? $query[$key] : $default;
        $pass = $query['pass'] ?? [];
        $pass = is_array($pass) ? array_values(array_intersect(self::PASSES, $pass)) : (in_array($pass, self::PASSES, true) ? [$pass] : []);
        // Older links used ?filter=no_card|ready|sent|failed.
        $legacy = ['no_card' => 'not_generated', 'ready' => 'generated', 'sent' => 'sent', 'failed' => 'failed'][$query['filter'] ?? ''] ?? '';

        return [
            'q' => is_string($query['q'] ?? null) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $query['q'])), 0, 60) : '',
            'pass' => $pass,
            'invite' => $pick('invite', self::INVITE, $legacy),
            'share' => $pick('share', self::SHARE),
            'rsvp' => $pick('rsvp', self::RSVP),
            'admission' => $pick('admission', self::ADMISSION),
            'phone' => $pick('phone', ['has', 'missing']),
            'added' => $pick('added', ['today', '7d', '30d']),
            'entries' => $pick('entries', ['1', '2', '3plus']),
            'remaining' => $pick('remaining', ['some', 'none']),
            'sort' => $pick('sort', self::SORTS, 'name_asc'),
            'page' => max(1, min(10_000, (int) ($query['page'] ?? 1))),
        ];
    }

    /** Number of active filters (search and sort are not filters). */
    public static function activeFilters(array $p): int
    {
        return count($p['pass']) + count(array_filter([$p['invite'], $p['share'], $p['rsvp'], $p['admission'], $p['phone'], $p['added'], $p['entries'], $p['remaining']], fn ($v) => $v !== ''));
    }

    /**
     * @return array{rows: list<object>, matching: int, ids: list<int>, page: int, pages: int, total: int, truncated: bool}
     */
    public function find(object $event, array $spec, array $p): array
    {
        $eventId = (int) $event->id;
        $query = DB::table('guests as g')->where('g.event_id', $eventId);
        $this->search($query, $p['q']);
        $this->filters($query, $p);
        $this->order($query, $p['sort']);
        $rows = $query->limit(self::MAX_MATCHES + 1)
            ->get(['g.id', 'g.name', 'g.phone_number', 'g.manual_code', 'g.pass_type', 'g.allowed_entries', 'g.used_entries', 'g.status', 'g.created_at']);
        $truncated = $rows->count() > self::MAX_MATCHES;
        $rows = $rows->take(self::MAX_MATCHES);
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

        $fingerprint = InvitationDesign::fingerprint($spec);
        $tokens = [];
        $cards = [];
        $messages = [];
        $shares = $this->shares->statuses($eventId, $ids);
        $replies = $this->rsvp->statuses($eventId, $ids);
        foreach (array_chunk($ids, 900) as $chunk) {
            foreach (DB::table('qr_codes')->whereIn('guest_id', $chunk)->get(['guest_id', 'qr_token']) as $qr) {
                $tokens[(int) $qr->guest_id] = hash('sha256', 'invitation-card|'.$qr->qr_token);
            }
            foreach (DB::table('invitation_cards')->where('event_id', $eventId)->whereIn('guest_id', $chunk)->get(['guest_id', 'design_fingerprint', 'token_fingerprint', 'generated_at']) as $card) {
                $cards[(int) $card->guest_id] = $card;
            }
            $messages += $this->delivery->statuses($eventId, $chunk);
        }

        $list = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $card = $cards[$id] ?? null;
            $state = $card === null ? 'none' : (($card->design_fingerprint === $fingerprint && ($tokens[$id] ?? null) === $card->token_fingerprint) ? 'current' : 'stale');
            $allowed = (int) $row->allowed_entries;
            $used = (int) $row->used_entries;
            $list[] = (object) [
                'id' => $id, 'name' => (string) $row->name, 'phone' => (string) $row->phone_number, 'code' => (string) $row->manual_code,
                'pass_type' => (string) $row->pass_type, 'allowed' => $allowed, 'used' => $used, 'remaining' => max(0, $allowed - $used),
                'status' => (string) $row->status, 'created_at' => $row->created_at,
                'card' => $state, 'card_at' => $card?->generated_at, 'message' => $messages[$id] ?? null, 'share' => $shares[$id]['status'] ?? null, 'rsvp' => $replies[$id] ?? null,
                'initials' => InvitationDesign::initials((string) $row->name),
                'admission' => $row->status === Guest::STATUS_CANCELLED ? 'cancelled' : ($used <= 0 ? 'not_checked_in' : ($used >= $allowed ? 'full' : 'partial')),
            ];
        }
        if ($p['invite'] !== '') {
            $list = array_values(array_filter($list, fn (object $g) => match ($p['invite']) {
                'generated' => $g->card === 'current',
                'outdated' => $g->card === 'stale',
                'not_generated' => $g->card === 'none',
                'sent' => in_array($g->message, self::SENT, true),
                'not_sent' => ! in_array($g->message, self::SENT, true),
                'failed' => in_array($g->message, ['failed', 'skipped'], true),
            }));
        }
        if ($p['share'] !== '') {
            $list = array_values(array_filter($list, fn (object $g) => $p['share'] === 'not_shared' ? $g->share === null : $g->share === $p['share']));
        }
        if ($p['rsvp'] !== '') {
            $list = array_values(array_filter($list, fn (object $g) => $p['rsvp'] === 'not_invited' ? $g->rsvp === null : $g->rsvp === $p['rsvp']));
        }
        if ($p['sort'] === 'card_newest') {
            usort($list, fn (object $a, object $b) => [$b->card_at === null ? 0 : 1, (string) $b->card_at, $a->name] <=> [$a->card_at === null ? 0 : 1, (string) $a->card_at, $b->name]);
        }

        $matching = count($list);
        $pages = max(1, (int) ceil($matching / self::PER_PAGE));
        $page = min($p['page'], $pages);

        return [
            'rows' => array_slice($list, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'matching' => $matching,
            'ids' => array_map(fn (object $g) => $g->id, array_filter($list, fn (object $g) => $g->status !== Guest::STATUS_CANCELLED)),
            'page' => $page,
            'pages' => $pages,
            'total' => (int) DB::table('guests')->where('event_id', $eventId)->count(),
            'truncated' => $truncated,
        ];
    }

    /** Partial name, phone digits (3+), invitation code, pass word or status word. */
    private function search(Builder $query, string $q): void
    {
        if ($q === '') {
            return;
        }
        $like = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
        $digits = (string) preg_replace('/\D+/', '', $q);
        $word = mb_strtolower($q);
        $pass = ['single' => 'single', 'mmoja' => 'single', 'double' => 'double', 'wawili' => 'double', 'special' => 'special', 'family' => 'special', 'familia' => 'special', 'maalum' => 'special'][$word] ?? null;
        $status = ['cancelled' => Guest::STATUS_CANCELLED, 'imefutwa' => Guest::STATUS_CANCELLED, 'admitted' => Guest::STATUS_FULLY_USED][$word] ?? null;
        $query->where(function (Builder $w) use ($like, $digits, $q, $pass, $status): void {
            $w->whereRaw('lower(g.name) like ?', [$like]);
            if (strlen($digits) >= 3) {
                // Stored numbers are normalised (+255…); a local 07… search drops its leading 0.
                $w->orWhere('g.phone_number', 'like', '%'.ltrim($digits, '0').'%');
            }
            if (preg_match('/\A[A-Za-z0-9]{4}\z/', $q) === 1) {
                $w->orWhereRaw('upper(g.manual_code) = ?', [strtoupper($q)]);
            }
            if ($pass !== null) {
                $w->orWhere('g.pass_type', $pass);
            }
            if ($status !== null) {
                $w->orWhere('g.status', $status);
            }
        });
    }

    private function filters(Builder $query, array $p): void
    {
        if ($p['pass'] !== []) {
            $query->whereIn('g.pass_type', $p['pass']);
        }
        match ($p['admission']) {
            'not_checked_in' => $query->where('g.used_entries', 0)->where('g.status', '!=', Guest::STATUS_CANCELLED),
            'partial' => $query->where('g.used_entries', '>', 0)->whereColumn('g.used_entries', '<', 'g.allowed_entries')->where('g.status', '!=', Guest::STATUS_CANCELLED),
            'full' => $query->whereColumn('g.used_entries', '>=', 'g.allowed_entries')->where('g.status', '!=', Guest::STATUS_CANCELLED),
            'cancelled' => $query->where('g.status', Guest::STATUS_CANCELLED),
            default => null,
        };
        match ($p['phone']) {
            'has' => $query->where('g.phone_number', '!=', ''),
            'missing' => $query->where('g.phone_number', ''),
            default => null,
        };
        $since = match ($p['added']) {
            'today' => Carbon::today(),
            '7d' => Carbon::now()->subDays(7),
            '30d' => Carbon::now()->subDays(30),
            default => null,
        };
        if ($since !== null) {
            $query->where('g.created_at', '>=', $since);
        }
        match ($p['entries']) {
            '1' => $query->where('g.allowed_entries', 1),
            '2' => $query->where('g.allowed_entries', 2),
            '3plus' => $query->where('g.allowed_entries', '>=', 3),
            default => null,
        };
        match ($p['remaining']) {
            'some' => $query->whereColumn('g.used_entries', '<', 'g.allowed_entries')->where('g.status', '!=', Guest::STATUS_CANCELLED),
            'none' => $query->where(fn (Builder $w) => $w->whereColumn('g.used_entries', '>=', 'g.allowed_entries')->orWhere('g.status', Guest::STATUS_CANCELLED)),
            default => null,
        };
    }

    private function order(Builder $query, string $sort): void
    {
        match ($sort) {
            'name_desc' => $query->orderByRaw('lower(g.name) desc'),
            'newest' => $query->orderByDesc('g.created_at')->orderByDesc('g.id'),
            'oldest' => $query->orderBy('g.created_at')->orderBy('g.id'),
            'pass' => $query->orderByRaw("case g.pass_type when 'single' then 1 when 'double' then 2 else 3 end"),
            'allowed_desc' => $query->orderByDesc('g.allowed_entries'),
            'used_desc' => $query->orderByDesc('g.used_entries'),
            'remaining_desc' => $query->orderByRaw('(g.allowed_entries - g.used_entries) desc'),
            default => null,
        };
        $query->orderByRaw('lower(g.name)')->orderBy('g.id');
    }
}
