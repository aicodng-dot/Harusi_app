<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCustomerEvent;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Services\Invitations\InvitationStudio;
use App\Services\Rsvp\RsvpService;
use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owner/manager RSVP page: summary, settings, replies list, recording a reply received by
 * phone and a CSV export. Authorized through WorkspaceAccess (`event.view`; changes need
 * `event.update` and an active event). Every guest id is scoped to the authorized event.
 */
class CustomerRsvpController extends Controller
{
    use AuthorizesCustomerEvent;

    public function __construct(private RsvpService $rsvp, private InvitationStudio $studio) {}

    public function index(Request $request, string $event, WorkspaceAccess $access): Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access);
        $full = $this->studio->event((int) $row->id);
        $filter = in_array($request->query('response'), RsvpService::STATES, true) ? $request->query('response') : '';
        $q = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 60) : '';
        $query = $this->rows((int) $full->id, $filter, $q);
        $settings = $this->rsvp->settings((int) $full->id);

        return response()->view('reference_ui.customer_rsvp', [
            'language' => $language,
            'event' => $full,
            'summary' => $this->rsvp->summary((int) $full->id),
            'settings' => $settings,
            'open' => $this->rsvp->isOpen($full, $settings),
            'rows' => $query->paginate(50)->withQueryString(),
            'filters' => ['response' => $filter, 'q' => $q],
            'readOnly' => $row->status !== Event::STATUS_ACTIVE,
        ])->withHeaders($this->privateEventHeaders());
    }

    public function settings(Request $request, string $event, WorkspaceAccess $access): RedirectResponse
    {
        [, $row, $language] = $this->context($request, $event, $access, true);
        $data = $request->validate(['deadline' => ['nullable', 'string', 'max:20']]);
        $deadline = null;
        if (filled($data['deadline'] ?? null)) {
            try {
                $deadline = Carbon::createFromFormat('Y-m-d\TH:i', (string) $data['deadline'], RsvpService::TIMEZONE)->utc();
            } catch (\Throwable) {
                return back()->withErrors(['deadline' => __('rsvp.owner.deadline_invalid', [], $language)]);
            }
        }
        DB::table('events')->where('id', $row->id)->update([
            'rsvp_enabled' => $request->boolean('enabled'),
            'rsvp_allow_changes' => $request->boolean('allow_changes'),
            'rsvp_deadline' => $deadline,
        ]);

        return redirect()->route('customer.events.rsvp', ['event' => $row->id])->with('status', __('rsvp.owner.saved', [], $language));
    }

    public function record(Request $request, string $event, WorkspaceAccess $access): RedirectResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $data = $request->validate([
            'guest_id' => ['required', 'integer', 'min:1'],
            'response' => ['required', 'in:'.implode(',', RsvpService::RESPONSES)],
            'count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'submission' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
        ]);
        abort_unless(DB::table('guests')->where('event_id', $row->id)->where('id', $data['guest_id'])->exists(), 404);
        $full = $this->studio->event((int) $row->id);
        $existing = $this->rsvp->forGuest((int) $row->id, (int) $data['guest_id']);
        $result = $this->rsvp->submit($full, (int) $data['guest_id'], $data['response'], isset($data['count']) ? (int) $data['count'] : null,
            $existing?->note, null, $data['submission'], 'owner', (int) $user->getKey());
        $ok = in_array($result['status'], ['saved', 'no_change', 'replay'], true);

        return back()->with($ok ? 'status' : 'error', __($ok ? 'rsvp.owner.recorded' : 'rsvp.owner.record_failed', [], $language));
    }

    public function export(Request $request, string $event, WorkspaceAccess $access): StreamedResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access);
        $full = $this->studio->event((int) $row->id);
        $rows = $this->rows((int) $full->id, '', '')->get();
        $c = fn (string $key) => __('rsvp.csv.'.$key, [], $language);
        $name = InvitationStudio::fileName((string) $full->event_name, 1).'-rsvp.csv';

        return response()->streamDownload(function () use ($rows, $c, $language): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 with a BOM
            fputcsv($out, [$c('guest'), $c('phone'), $c('pass'), $c('reply'), $c('people'), $c('note'), $c('replied_at'), $c('source')]);
            foreach ($rows as $r) {
                fputcsv($out, array_map([self::class, 'csvSafe'], [
                    $r->name, $r->phone_number, __('customer_guests.add.passes.'.$r->pass_type, [], $language),
                    __('rsvp.responses.'.$r->response, [], $language), $r->response === 'attending' ? (string) ($r->attending_count ?? 1) : '',
                    (string) $r->note, $r->responded_at ? RsvpService::local(Carbon::parse($r->responded_at, 'UTC'), 'Y-m-d H:i') : '',
                    $r->response_source ? __('rsvp.owner.source.'.$r->response_source, [], $language) : '',
                ]));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8'] + $this->privateEventHeaders());
    }

    /** Spreadsheet formula injection guard. */
    public static function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/\A[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    private function rows(int $eventId, string $filter, string $q)
    {
        $query = DB::table('guest_rsvps as r')->join('guests as g', 'g.id', '=', 'r.guest_id')
            ->where('r.event_id', $eventId)->where('g.event_id', $eventId)->where('g.status', '!=', Guest::STATUS_CANCELLED);
        if ($filter !== '') {
            $query->where('r.response', $filter);
        }
        if ($q !== '') {
            $query->whereRaw('lower(g.name) like ?', ['%'.addcslashes(mb_strtolower($q), '%_\\').'%']);
        }

        return $query->orderByRaw("case r.response when 'attending' then 1 when 'not_attending' then 2 else 3 end")
            ->orderByRaw('lower(g.name)')->orderBy('g.id')
            ->select(['g.id', 'g.name', 'g.phone_number', 'g.pass_type', 'g.allowed_entries', 'r.response', 'r.attending_count', 'r.note',
                'r.responded_at', 'r.response_changed_at', 'r.response_source']);
    }

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
}
