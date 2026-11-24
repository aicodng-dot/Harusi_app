<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCustomerEvent;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Services\Invitations\InvitationDesign;
use App\Services\Invitations\InvitationStudio;
use App\Services\Sharing\InvitationLinks;
use App\Services\Sharing\ShareMessage;
use App\Services\Sharing\ShareRecords;
use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Invitation Sharing Center: the customer shares each guest's personal invitation through
 * their own WhatsApp, SMS app or phone share sheet. No messaging API is called and nothing
 * is sent automatically: the server prepares text and links, the customer opens the app and
 * presses Send, and only what the customer did or told us is recorded.
 *
 * Every request authorizes the account and the event through WorkspaceAccess (`event.view`);
 * anything that records or changes sharing state also needs `event.update` and an active event.
 * Guest, session and template ids are always scoped to the authorized event (and sessions to
 * the customer who started them); unknown or foreign ids get 404.
 */
class CustomerShareController extends Controller
{
    use AuthorizesCustomerEvent;

    public const MAX_RECIPIENTS = 2000;

    public const MAX_TEMPLATES = 30;

    public const MAX_OPEN_SESSIONS = 5;

    public function __construct(private InvitationStudio $studio, private InvitationLinks $links, private ShareRecords $records) {}

    // ----- Hub ---------------------------------------------------------------------

    public function index(Request $request, string $event, WorkspaceAccess $access): Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access);
        $full = $this->studio->event((int) $row->id);
        $pool = $this->pool($full);
        $statuses = $this->records->eventStatuses((int) $full->id);
        $active = array_filter($pool, fn ($g) => $g['status'] !== Guest::STATUS_CANCELLED);
        $count = fn (string $status) => count(array_filter($active, fn ($g) => ($statuses[$g['id']]['status'] ?? null) === $status));
        $stats = [
            'guests' => count($active),
            'with_phone' => count(array_filter($active, fn ($g) => $g['phone'])),
            'cards' => count(array_filter($active, fn ($g) => $g['card'])),
            'not_shared' => count(array_filter($active, fn ($g) => ! isset($statuses[$g['id']]) || $statuses[$g['id']]['status'] === 'skipped')),
            'initiated' => $count('initiated'),
            'confirmed' => $count('confirmed'),
            'skipped' => $count('skipped'),
        ];
        $stats['awaiting'] = $stats['guests'] - $stats['confirmed'];

        return response()->view('reference_ui.customer_share_hub', [
            'language' => $language,
            'event' => $full,
            'stats' => $stats,
            'sessions' => $this->openSessions((int) $full->id, (int) $user->getKey()),
            'readOnly' => $row->status !== Event::STATUS_ACTIVE,
        ])->withHeaders($this->privateEventHeaders());
    }

    // ----- Bulk wizard ---------------------------------------------------------------

    /** Guests chosen on the Guests page ("Share" in the selection bar) are carried here. */
    public function select(Request $request, string $event, WorkspaceAccess $access): RedirectResponse
    {
        [, $row] = $this->context($request, $event, $access, true);
        $input = is_string($request->input('guest_ids')) ? explode(',', $request->input('guest_ids'), self::MAX_RECIPIENTS + 1) : $request->input('guests');
        $ids = $this->guestIds($input, (int) $row->id, self::MAX_RECIPIENTS);
        $request->session()->put('share_selection.'.$row->id, $ids);

        return redirect()->route('customer.events.share.create', ['event' => $row->id, 'from' => 'selection']);
    }

    public function create(Request $request, string $event, WorkspaceAccess $access): Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $full = $this->studio->event((int) $row->id);
        $statuses = $this->records->eventStatuses((int) $full->id);
        $pool = array_values(array_filter($this->pool($full), fn ($g) => $g['status'] !== Guest::STATUS_CANCELLED));
        $selection = $request->query('from') === 'selection' ? (array) $request->session()->get('share_selection.'.$full->id, []) : [];
        $selection = array_values(array_intersect($selection, array_column($pool, 'id')));
        $first = $pool[0] ?? null;

        return response()->view('reference_ui.customer_share_new', [
            'language' => $language,
            'event' => $full,
            'pool' => array_map(fn ($g) => ['id' => $g['id'], 'pass' => $g['pass_type'], 'phone' => $g['phone'], 'card' => $g['card'],
                'share' => $statuses[$g['id']]['status'] ?? null], $pool),
            'selection' => $selection,
            'templates' => $this->templates((int) $full->id, $language),
            'values' => ShareMessage::eventValues($full, $language) + [
                'guest_name' => $first['name'] ?? __('customer_share.sample_guest', [], $language),
                'invitation_url' => route('invitations.public', ['key' => str_repeat('x', 32)]),
            ],
            'channel' => in_array($request->query('channel'), ShareMessage::CHANNELS, true) ? $request->query('channel') : 'whatsapp',
            'max' => self::MAX_RECIPIENTS,
        ])->withHeaders($this->privateEventHeaders());
    }

    public function store(Request $request, string $event, WorkspaceAccess $access): RedirectResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $full = $this->studio->event((int) $row->id);
        $data = $request->validate([
            'audience' => ['required', 'in:all,not_shared,pass,selection'],
            'pass' => ['array'], 'pass.*' => ['in:'.implode(',', [Guest::PASS_SINGLE, Guest::PASS_DOUBLE, Guest::PASS_SPECIAL])],
            'channel' => ['required', 'in:'.implode(',', ShareMessage::CHANNELS)],
            'template' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:'.(ShareMessage::MAX_LENGTH * 2)],
            'template_name' => ['nullable', 'string', 'max:80'],
        ]);
        $message = ShareMessage::clean($data['message']);
        $problems = ShareMessage::problems($message);
        if ($problems !== []) {
            return back()->withInput()->withErrors(['message' => array_map(fn ($p) => __('customer_share.problems.'.$p, ['max' => ShareMessage::MAX_LENGTH], $language), $problems)]);
        }

        $statuses = $this->records->eventStatuses((int) $full->id);
        $selection = (array) $request->session()->get('share_selection.'.$full->id, []);
        $withPhone = $request->boolean('with_phone');
        $skipDone = $request->boolean('skip_done');
        $recipients = [];
        foreach ($this->pool($full) as $g) {
            $share = $statuses[$g['id']]['status'] ?? null;
            $in = match ($data['audience']) {
                'all' => true,
                'not_shared' => $share === null || $share === 'skipped',
                'pass' => in_array($g['pass_type'], $data['pass'] ?? [], true),
                'selection' => in_array($g['id'], $selection, true),
            };
            if (! $in || $g['status'] === Guest::STATUS_CANCELLED || ! $g['card'] || ($withPhone && ! $g['phone']) || ($skipDone && $share === 'confirmed')) {
                continue;
            }
            $recipients[] = $g['id'];
        }
        if ($recipients === []) {
            return back()->withInput()->withErrors(['audience' => __('customer_share.wizard.none_selected', [], $language)]);
        }
        if (count($recipients) > self::MAX_RECIPIENTS) {
            return back()->withInput()->withErrors(['audience' => __('customer_share.wizard.too_many', ['max' => self::MAX_RECIPIENTS], $language)]);
        }

        $templateId = $this->templateId((int) $full->id, $data['template'] ?? null);
        $name = trim((string) ($data['template_name'] ?? ''));
        if ($request->boolean('save_template') && $name !== '') {
            abort_if(DB::table('invitation_share_templates')->where('event_id', $full->id)->count() >= self::MAX_TEMPLATES, 422);
            $templateId = (int) DB::table('invitation_share_templates')->insertGetId([
                'event_id' => $full->id, 'created_by' => $user->getKey(), 'name' => strip_tags($name), 'body' => $message,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $id = DB::transaction(function () use ($full, $user, $data, $templateId, $message, $recipients): int {
            // Keep the list of unfinished runs short: the oldest ones are closed (their history stays).
            $open = DB::table('invitation_share_sessions')->where('event_id', $full->id)->where('user_id', $user->getKey())
                ->whereIn('status', ['active', 'paused'])->orderByDesc('last_activity_at')->orderByDesc('id')->pluck('id');
            if ($open->count() >= self::MAX_OPEN_SESSIONS) {
                DB::table('invitation_share_sessions')->whereIn('id', $open->slice(self::MAX_OPEN_SESSIONS - 1)->all())
                    ->update(['status' => 'closed', 'updated_at' => now()]);
            }

            return (int) DB::table('invitation_share_sessions')->insertGetId([
                'event_id' => $full->id, 'user_id' => $user->getKey(), 'channel' => $data['channel'], 'template_id' => $templateId,
                'message' => $message, 'recipient_ids' => json_encode($recipients), 'position' => 0, 'status' => 'active',
                'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $request->session()->forget('share_selection.'.$full->id);
        $this->studio->activity((int) $full->id, (int) $user->getKey(), 'share_session_started', null, ['session' => $id, 'channel' => $data['channel'], 'guests' => count($recipients)]);

        return redirect()->route('customer.events.share.sessions.show', ['event' => $full->id, 'session' => $id]);
    }

    // ----- Guided session ------------------------------------------------------------

    public function session(Request $request, string $event, string $session, WorkspaceAccess $access): Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $full = $this->studio->event((int) $row->id);
        $run = $this->ownedSession((int) $full->id, (int) $user->getKey(), $session);
        if ($run->status === 'paused') {
            DB::table('invitation_share_sessions')->where('id', $run->id)->update(['status' => 'active', 'last_activity_at' => now(), 'updated_at' => now()]);
            $run->status = 'active';
        }
        $ids = json_decode($run->recipient_ids, true) ?: [];
        $guests = DB::table('guests')->where('event_id', $full->id)->whereIn('id', $ids)->get(['id', 'name', 'phone_number', 'pass_type', 'allowed_entries', 'status'])->keyBy('id');
        $links = $this->links->forGuests($full, array_keys($guests->all()));
        $here = $this->records->statuses((int) $full->id, $ids, (int) $run->id);
        $overall = $this->records->statuses((int) $full->id, $ids);
        $values = ShareMessage::eventValues($full, $language);
        $recipients = [];
        foreach ($ids as $id) {
            $g = $guests[$id] ?? null;
            if ($g === null) {
                continue; // removed since the run started
            }
            $digits = ShareMessage::phoneDigits($g->phone_number);
            $recipients[] = [
                'id' => (int) $g->id,
                'name' => (string) $g->name,
                'phone' => $digits,
                'phone_display' => $digits === '' ? '' : '+'.$digits,
                'pass' => __('invitation_studio.guests.passes.'.$g->pass_type, ['count' => (int) $g->allowed_entries], $language),
                'initials' => InvitationDesign::initials((string) $g->name),
                'url' => $links[(int) $g->id]['url'] ?? null,
                'unavailable' => $g->status === Guest::STATUS_CANCELLED || ($links[(int) $g->id]['url'] ?? null) === null,
                'card' => route('customer.events.invitation.card', ['event' => $full->id, 'guest' => $g->id, 'format' => 'png']),
                'state' => $here[(int) $g->id]['status'] ?? null,
                'elsewhere' => ($overall[(int) $g->id]['status'] ?? null) === 'confirmed' && ($here[(int) $g->id]['status'] ?? null) !== 'confirmed'
                    ? ['channel' => $overall[(int) $g->id]['channel'], 'at' => $overall[(int) $g->id]['at']] : null,
            ];
        }

        return response()->view('reference_ui.customer_share_session', [
            'language' => $language,
            'event' => $full,
            'run' => $run,
            'recipients' => $recipients,
            'values' => $values,
            'position' => min((int) $run->position, max(0, count($recipients))),
        ])->withHeaders($this->privateEventHeaders());
    }

    public function progress(Request $request, string $event, string $session, WorkspaceAccess $access): JsonResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $run = $this->ownedSession((int) $row->id, (int) $user->getKey(), $session);
        abort_if(in_array($run->status, ['completed', 'closed'], true) && ! in_array($request->input('action'), ['move', 'complete'], true), 409);
        $data = $request->validate([
            'action' => ['required', 'in:initiated,confirmed,skipped,unconfirm,move,pause,complete,message'],
            'guest_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'in:'.implode(',', ShareMessage::CHANNELS)],
            'position' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_RECIPIENTS],
            'message' => ['nullable', 'string', 'max:'.(ShareMessage::MAX_LENGTH * 2)],
        ]);
        $ids = json_decode($run->recipient_ids, true) ?: [];
        $update = ['last_activity_at' => now(), 'updated_at' => now()];
        $result = ['ok' => true];

        if (in_array($data['action'], ShareRecords::ACTIONS, true)) {
            $guestId = (int) ($data['guest_id'] ?? 0);
            abort_unless(in_array($guestId, $ids, true), 404);
            $result['state'] = $this->records->record((int) $row->id, $guestId, (int) $user->getKey(), $data['channel'] ?? $run->channel,
                $data['action'], (int) $run->id, $run->template_id === null ? null : (int) $run->template_id)['status'];
        } elseif ($data['action'] === 'pause') {
            $update['status'] = 'paused';
        } elseif ($data['action'] === 'complete') {
            $update['status'] = 'completed';
        } elseif ($data['action'] === 'message') {
            $message = ShareMessage::clean((string) ($data['message'] ?? ''));
            $problems = ShareMessage::problems($message);
            if ($problems !== []) {
                return response()->json(['ok' => false, 'errors' => array_map(fn ($p) => __('customer_share.problems.'.$p, ['max' => ShareMessage::MAX_LENGTH], $language), $problems)], 422);
            }
            $update['message'] = $message;
            $result['message'] = $message;
        }
        if (isset($data['position'])) {
            $update['position'] = min((int) $data['position'], count($ids));
        }
        if (! isset($update['status']) && $run->status === 'paused') {
            $update['status'] = 'active';
        }
        DB::table('invitation_share_sessions')->where('id', $run->id)->update($update);
        if ($data['action'] === 'complete') {
            $this->studio->activity((int) $row->id, (int) $user->getKey(), 'share_session_completed', null, ['session' => (int) $run->id]);
        }

        return response()->json($result)->withHeaders($this->privateEventHeaders());
    }

    // ----- One guest -----------------------------------------------------------------

    public function guest(Request $request, string $event, string $guest, WorkspaceAccess $access): Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access);
        $full = $this->studio->event((int) $row->id);
        $record = $this->eventGuest((int) $full->id, $guest);
        $readOnly = $row->status !== Event::STATUS_ACTIVE || ! $this->canUpdate($request, $row, $access);
        $link = $this->links->forGuests($full, [$record->id], ! $readOnly)[$record->id] ?? ['url' => null, 'reason' => 'no_card'];
        $digits = ShareMessage::phoneDigits($record->phone_number);
        $history = DB::table('invitation_shares')->where('event_id', $full->id)->where('guest_id', $record->id)
            ->orderByDesc('updated_at')->limit(10)->get(['channel', 'status', 'attempts', 'initiated_at', 'confirmed_at', 'skipped_at', 'updated_at']);

        return response()->view('reference_ui.customer_share_guest', [
            'language' => $language,
            'event' => $full,
            'guest' => $record,
            'phone' => $digits,
            'phoneInvalid' => trim((string) $record->phone_number) !== '' && $digits === '',
            'link' => $link,
            'templates' => $this->templates((int) $full->id, $language),
            'values' => ShareMessage::eventValues($full, $language) + ['guest_name' => (string) $record->name, 'invitation_url' => (string) $link['url']],
            'history' => $history,
            'overall' => $this->records->statuses((int) $full->id, [$record->id])[$record->id] ?? null,
            'readOnly' => $readOnly,
        ])->withHeaders($this->privateEventHeaders());
    }

    public function record(Request $request, string $event, WorkspaceAccess $access): JsonResponse
    {
        [$user, $row] = $this->context($request, $event, $access, true);
        $data = $request->validate([
            'guest_id' => ['required', 'integer', 'min:1'],
            'channel' => ['required', 'in:'.implode(',', ShareMessage::CHANNELS)],
            'action' => ['required', 'in:initiated,confirmed,unconfirm'],
            'template' => ['nullable', 'string', 'max:40'],
        ]);
        $guest = $this->eventGuest((int) $row->id, (string) $data['guest_id']);
        abort_if($guest->status === Guest::STATUS_CANCELLED, 409);
        $result = $this->records->record((int) $row->id, (int) $guest->id, (int) $user->getKey(), $data['channel'], $data['action'], null,
            $this->templateId((int) $row->id, $data['template'] ?? null));

        return response()->json(['ok' => true, 'state' => $result['status']])->withHeaders($this->privateEventHeaders());
    }

    // ----- History -------------------------------------------------------------------

    public function history(Request $request, string $event, WorkspaceAccess $access): Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access);
        $full = $this->studio->event((int) $row->id);
        $channel = in_array($request->query('channel'), ShareMessage::CHANNELS, true) ? $request->query('channel') : '';
        $status = in_array($request->query('status'), ShareRecords::STATUSES, true) ? $request->query('status') : '';
        $q = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 60) : '';
        $query = DB::table('invitation_shares as s')->join('guests as g', 'g.id', '=', 's.guest_id')
            ->where('s.event_id', $full->id)->where('g.event_id', $full->id);
        if ($channel !== '') {
            $query->where('s.channel', $channel);
        }
        if ($status !== '') {
            $query->where('s.status', $status);
        }
        if ($q !== '') {
            $query->whereRaw('lower(g.name) like ?', ['%'.addcslashes(mb_strtolower($q), '%_\\').'%']);
        }
        $rows = $query->orderByDesc('s.updated_at')->orderByDesc('s.id')
            ->select(['s.id', 's.guest_id', 's.channel', 's.status', 's.attempts', 's.session_id', 's.initiated_at', 's.confirmed_at', 's.skipped_at', 's.updated_at', 'g.name'])
            ->paginate(50)->withQueryString();

        return response()->view('reference_ui.customer_share_history', [
            'language' => $language,
            'event' => $full,
            'rows' => $rows,
            'filters' => ['channel' => $channel, 'status' => $status, 'q' => $q],
            'sessions' => $this->openSessions((int) $full->id, (int) $user->getKey()),
            'readOnly' => $row->status !== Event::STATUS_ACTIVE,
        ])->withHeaders($this->privateEventHeaders());
    }

    // ----- Message templates ---------------------------------------------------------

    public function templatesPage(Request $request, string $event, WorkspaceAccess $access): Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access);
        $full = $this->studio->event((int) $row->id);

        return response()->view('reference_ui.customer_share_templates', [
            'language' => $language,
            'event' => $full,
            'defaults' => ShareMessage::defaults($language),
            'saved' => DB::table('invitation_share_templates')->where('event_id', $full->id)->orderBy('name')->get(['id', 'name', 'body', 'updated_at']),
            'values' => ShareMessage::eventValues($full, $language) + [
                'guest_name' => __('customer_share.sample_guest', [], $language),
                'invitation_url' => route('invitations.public', ['key' => str_repeat('x', 32)]),
            ],
            'max' => self::MAX_TEMPLATES,
            'readOnly' => $row->status !== Event::STATUS_ACTIVE,
        ])->withHeaders($this->privateEventHeaders());
    }

    public function storeTemplate(Request $request, string $event, WorkspaceAccess $access): RedirectResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        [$name, $body] = $this->validTemplate($request, $language);
        if (DB::table('invitation_share_templates')->where('event_id', $row->id)->count() >= self::MAX_TEMPLATES) {
            return back()->withInput()->withErrors(['name' => __('customer_share.templates.limit', ['max' => self::MAX_TEMPLATES], $language)]);
        }
        DB::table('invitation_share_templates')->insert([
            'event_id' => $row->id, 'created_by' => $user->getKey(), 'name' => $name, 'body' => $body, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('customer.events.share.templates', ['event' => $row->id])->with('status', __('customer_share.templates.saved', [], $language));
    }

    public function updateTemplate(Request $request, string $event, string $template, WorkspaceAccess $access): RedirectResponse
    {
        [, $row, $language] = $this->context($request, $event, $access, true);
        $id = $this->ownedTemplate((int) $row->id, $template);
        [$name, $body] = $this->validTemplate($request, $language);
        DB::table('invitation_share_templates')->where('id', $id)->update(['name' => $name, 'body' => $body, 'updated_at' => now()]);

        return redirect()->route('customer.events.share.templates', ['event' => $row->id])->with('status', __('customer_share.templates.saved', [], $language));
    }

    public function destroyTemplate(Request $request, string $event, string $template, WorkspaceAccess $access): RedirectResponse
    {
        [, $row, $language] = $this->context($request, $event, $access, true);
        DB::table('invitation_share_templates')->where('id', $this->ownedTemplate((int) $row->id, $template))->delete();

        return redirect()->route('customer.events.share.templates', ['event' => $row->id])->with('status', __('customer_share.templates.deleted', [], $language));
    }

    // ----- Helpers -------------------------------------------------------------------

    /** @return array{0: User, 1: object, 2: string} */
    private function context(Request $request, string $event, WorkspaceAccess $access, bool $write = false): array
    {
        $user = $this->authorizedCustomer($request);
        $connection = DB::connection();
        $row = $this->authorizedEvent($connection, $user, $event, $access);
        if ($write) {
            abort_unless($access->allows($connection, (int) $user->getKey(), (int) $row->workspace_id, 'event.update', (int) $row->id), 404);
            abort_unless($row->status === Event::STATUS_ACTIVE, 409);
        }

        return [$user, $row, CustomerHomeController::language($request, $user)];
    }

    private function canUpdate(Request $request, object $row, WorkspaceAccess $access): bool
    {
        return $access->allows(DB::connection(), (int) $request->user()->getKey(), (int) $row->workspace_id, 'event.update', (int) $row->id);
    }

    /**
     * Every guest of the event with what sharing needs to know (no names leave this method
     * except through the views that already show them).
     *
     * @return list<array{id: int, name: string, pass_type: string, phone: bool, card: bool, status: string}>
     */
    private function pool(object $event): array
    {
        $guests = DB::table('guests')->where('event_id', $event->id)->orderByRaw('lower(name)')->orderBy('id')
            ->get(['id', 'name', 'phone_number', 'pass_type', 'status']);
        $links = $this->links->forGuests($event, $guests->pluck('id')->map(fn ($id) => (int) $id)->all(), false);

        return $guests->map(fn ($g) => [
            'id' => (int) $g->id, 'name' => (string) $g->name, 'pass_type' => (string) $g->pass_type, 'status' => (string) $g->status,
            'phone' => ShareMessage::phoneDigits($g->phone_number) !== '',
            'card' => ($links[(int) $g->id]['reason'] ?? '') === 'ok',
        ])->all();
    }

    /** Built-in templates plus the event's saved ones: key => [name, body, saved]. */
    private function templates(int $eventId, string $language): array
    {
        $out = [];
        foreach (ShareMessage::defaults($language) as $key => $template) {
            $out[$key] = $template + ['saved' => false];
        }
        foreach (DB::table('invitation_share_templates')->where('event_id', $eventId)->orderBy('name')->get(['id', 'name', 'body']) as $template) {
            $out['saved_'.$template->id] = ['name' => (string) $template->name, 'body' => (string) $template->body, 'saved' => true];
        }

        return $out;
    }

    private function templateId(int $eventId, ?string $key): ?int
    {
        if ($key === null || preg_match('/\Asaved_([1-9][0-9]{0,18})\z/', $key, $m) !== 1) {
            return null;
        }
        $id = DB::table('invitation_share_templates')->where('event_id', $eventId)->where('id', (int) $m[1])->value('id');

        return $id === null ? null : (int) $id;
    }

    private function ownedTemplate(int $eventId, string $template): int
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,18}\z/', $template) === 1, 404);
        $id = DB::table('invitation_share_templates')->where('event_id', $eventId)->where('id', (int) $template)->value('id');
        abort_if($id === null, 404);

        return (int) $id;
    }

    /** @return array{0: string, 1: string} */
    private function validTemplate(Request $request, string $language): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'body' => ['required', 'string', 'max:'.(ShareMessage::MAX_LENGTH * 2)]]);
        $body = ShareMessage::clean($data['body']);
        $problems = ShareMessage::problems($body);
        $name = trim(strip_tags($data['name']));
        if ($name === '') {
            $problems[] = 'name';
        }
        if ($problems !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['body' => array_map(fn ($p) => __('customer_share.problems.'.$p, ['max' => ShareMessage::MAX_LENGTH], $language), $problems)]);
        }

        return [$name, $body];
    }

    private function ownedSession(int $eventId, int $userId, string $session): object
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,18}\z/', $session) === 1, 404);
        $run = DB::table('invitation_share_sessions')->useWritePdo()->where('id', (int) $session)->where('event_id', $eventId)->where('user_id', $userId)->first();
        abort_if($run === null, 404);

        return $run;
    }

    private function openSessions(int $eventId, int $userId): array
    {
        return DB::table('invitation_share_sessions')->where('event_id', $eventId)->where('user_id', $userId)->whereIn('status', ['active', 'paused'])
            ->orderByDesc('last_activity_at')->limit(self::MAX_OPEN_SESSIONS)->get()
            ->map(function ($run) use ($eventId) {
                $ids = json_decode($run->recipient_ids, true) ?: [];
                $states = DB::table('invitation_shares')->where('event_id', $eventId)->where('session_id', $run->id)->pluck('status');

                return (object) [
                    'id' => (int) $run->id, 'channel' => $run->channel, 'status' => $run->status, 'total' => count($ids),
                    'position' => (int) $run->position, 'confirmed' => $states->filter(fn ($s) => $s === 'confirmed')->count(),
                    'skipped' => $states->filter(fn ($s) => $s === 'skipped')->count(), 'at' => $run->last_activity_at,
                ];
            })->all();
    }

    /** Validated, de-duplicated guest ids that all belong to the event (else 404/422). */
    private function guestIds(mixed $input, int $eventId, int $max): array
    {
        abort_unless(is_array($input) && $input !== [] && count($input) <= $max, 422);
        $ids = [];
        foreach ($input as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_if($id === false, 422);
            $ids[$id] = $id;
        }
        $ids = array_values($ids);
        $found = 0;
        foreach (array_chunk($ids, 900) as $chunk) {
            $found += DB::table('guests')->where('event_id', $eventId)->whereIn('id', $chunk)->count();
        }
        abort_unless($found === count($ids), 404);

        return $ids;
    }

    private function eventGuest(int $eventId, string $guest): Guest
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,18}\z/', $guest) === 1, 404);

        return Guest::query()->where('event_id', $eventId)->whereKey((int) $guest)->firstOrFail();
    }
}
