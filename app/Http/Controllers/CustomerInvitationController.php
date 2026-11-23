<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCustomerEvent;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Services\Invitations\InvitationDelivery;
use App\Services\Invitations\InvitationDesign;
use App\Services\Invitations\InvitationFonts;
use App\Services\Invitations\InvitationPhotoStore;
use App\Services\Invitations\InvitationProgress;
use App\Services\Invitations\InvitationStudio;
use App\Services\Invitations\InvitationTemplateCatalog;
use App\Services\Invitations\InvitationWording;
use App\Services\Tenancy\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Invitation workflow for an authorized event owner/manager:
 *   editor (reference 14) -> recipients (31) -> per-guest preview (06) -> status (17).
 *
 * Every request authorizes the account and the event through WorkspaceAccess
 * (`event.view`); writes additionally require `event.update` and an active event.
 * Guest ids are always scoped to the authorized event; unknown and foreign ids get 404.
 * Files are streamed from the private `invitations` disk, never linked publicly.
 */
class CustomerInvitationController extends Controller
{
    use AuthorizesCustomerEvent;

    public const GENERATE_BATCH = 25;

    public function __construct(private InvitationStudio $studio) {}

    // ----- Editor ----------------------------------------------------------------

    public function edit(Request $request, string $event, WorkspaceAccess $access): Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access);
        $full = $this->studio->event((int) $row->id);
        $design = $this->studio->design($full, $language);
        ['warnings' => $warnings] = $this->studio->spec($full, $design['doc']);
        $template = InvitationTemplateCatalog::get($design['doc']['template']);
        $eventType = InvitationWording::eventType($full->event_type);
        $wording = [];
        foreach (InvitationWording::LANGUAGES as $lang) {
            foreach (InvitationWording::HOST_MODES as $mode) {
                $wording[$lang][$mode] = InvitationWording::options($lang, $eventType, $mode);
            }
        }

        return response()->view('reference_ui.14_edit_invitation_card', [
            'language' => $language,
            'event' => $full,
            'design' => $design,
            'defaults' => InvitationDesign::defaults($design['doc']['template'], $full->event_type, $design['doc']['content']['language']),
            'template' => $template,
            'warnings' => $warnings,
            'fonts' => InvitationFonts::FONTS,
            'palettes' => $this->palettesFor($template),
            'elements' => $this->elementsFor($template, $language),
            'photoShapes' => InvitationDesign::photoShapes($template),
            'wording' => $wording,
            'eventFacts' => [
                'names' => InvitationDesign::eventNames($full),
                'venue' => (string) ($full->venue_name ?? ''),
            ],
            'photoUrl' => $design['doc']['photo']['id'] && app(InvitationPhotoStore::class)->path((int) $full->id, $design['doc']['photo']['id'])
                ? route('customer.events.invitation.photo', ['event' => $full->id, 'photo' => $design['doc']['photo']['id']]) : null,
            'readOnly' => $row->status !== Event::STATUS_ACTIVE,
            'savedTemplate' => session('invitation_template_saved'),
            'progress' => app(InvitationProgress::class)->forEvent($full, $language),
        ])->withHeaders($this->privateEventHeaders());
    }

    public function save(Request $request, string $event, WorkspaceAccess $access): JsonResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $full = $this->studio->event((int) $row->id);
        $current = $this->studio->design($full, $language);
        [$doc, $errors] = InvitationDesign::validate($request->input('document'), $current['doc']);
        if ($doc['photo']['id'] !== null && app(InvitationPhotoStore::class)->path((int) $full->id, $doc['photo']['id']) === null) {
            $errors[] = 'photo.id';
        }
        if ($errors !== []) {
            return response()->json(['ok' => false, 'errors' => $errors, 'message' => __('invitation_studio.invalid', [], $language)], 422);
        }
        $expected = filter_var($request->input('version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $version = $this->studio->save($full, (int) $user->getKey(), $doc, $expected === false ? null : $expected);
        if ($version === null) {
            return response()->json(['ok' => false, 'message' => __('invitation_studio.conflict', [], $language)], 409);
        }
        ['warnings' => $warnings] = $this->studio->spec($full, $doc);

        return response()->json(['ok' => true, 'version' => $version, 'warnings' => $warnings, 'message' => __('invitation_studio.saved', [], $language)])
            ->withHeaders($this->privateEventHeaders());
    }

    /** Live preview of an unsaved draft, rendered by the same engine as the final cards. */
    public function preview(Request $request, string $event, WorkspaceAccess $access): Response|JsonResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access);
        $full = $this->studio->event((int) $row->id);
        $current = $this->studio->design($full, $language);
        [$doc, $errors] = InvitationDesign::validate($request->input('document'), $current['doc']);
        if ($errors !== []) {
            return response()->json(['ok' => false, 'errors' => $errors], 422);
        }
        $width = $request->input('size') === 'large' ? 900 : InvitationStudio::PREVIEW_WIDTH;
        $result = $this->studio->previewPng($full, $doc, $width);

        // Element positions (logical units) let the editor place drag handles over the image.
        return response($result['png'], 200, ['Content-Type' => 'image/png', 'X-Invitation-Warnings' => implode(',', $result['warnings']),
            'X-Invitation-Layout' => base64_encode((string) json_encode($result['layout']))] + $this->privateEventHeaders());
    }

    public function uploadPhoto(Request $request, string $event, WorkspaceAccess $access, InvitationPhotoStore $photos): JsonResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $file = $request->file('photo');
        if ($file === null || is_array($file)) {
            return response()->json(['ok' => false, 'message' => __('invitation_studio.photo_errors.missing', [], $language)], 422);
        }
        try {
            $id = $photos->store($file, (int) $row->id);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['ok' => false, 'message' => __('invitation_studio.photo_errors.'.$exception->getMessage(), [], $language)], 422);
        }
        $this->studio->activity((int) $row->id, (int) $user->getKey(), 'photo_uploaded');

        return response()->json(['ok' => true, 'id' => $id, 'url' => route('customer.events.invitation.photo', ['event' => $row->id, 'photo' => $id])]);
    }

    public function photo(Request $request, string $event, string $photo, WorkspaceAccess $access, InvitationPhotoStore $photos): BinaryFileResponse
    {
        [, $row] = $this->context($request, $event, $access);
        $path = $photos->path((int) $row->id, $photo);
        abort_if($path === null, 404);

        return response()->file($path, ['Content-Type' => 'image/jpeg'] + $this->privateEventHeaders());
    }

    public function thumbnail(Request $request, string $event, string $template, WorkspaceAccess $access): Response
    {
        [, $row, $language] = $this->context($request, $event, $access);
        abort_unless(InvitationTemplateCatalog::isValid($template), 404);
        $bytes = $this->studio->thumbnail($this->studio->event((int) $row->id), $template, $language, $request->query('size') === 'large');

        return response($bytes, 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=3600', 'Referrer-Policy' => 'no-referrer']);
    }

    /**
     * "Continue Invitation": sends the customer to their next unfinished invitation step,
     * decided from saved data. Read-only; the destination re-authorizes as usual.
     */
    public function resume(Request $request, string $event, WorkspaceAccess $access, InvitationProgress $progress): RedirectResponse
    {
        [, $row, $language] = $this->context($request, $event, $access);

        return redirect()->to($progress->forEvent($this->studio->event((int) $row->id), $language)['url'])->withHeaders($this->privateEventHeaders());
    }

    // ----- Guests ----------------------------------------------------------------

    public function guests(Request $request, string $event, WorkspaceAccess $access, InvitationDelivery $delivery): Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access);
        $full = $this->studio->event((int) $row->id);
        $design = $this->studio->design($full, $language);
        ['spec' => $spec, 'warnings' => $warnings] = $this->studio->spec($full, $design['doc']);

        $search = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 60) : '';
        $filter = in_array($request->query('filter'), ['all', 'no_card', 'ready', 'sent', 'failed'], true) ? $request->query('filter') : 'all';
        $query = DB::table('guests')->where('event_id', $full->id);
        if ($search !== '') {
            $digits = preg_replace('/\D+/', '', $search);
            $query->where(function ($q) use ($search, $digits): void {
                $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%');
                if ($digits !== '' && strlen($digits) >= 3) {
                    $q->orWhere('phone_number', 'like', '%'.$digits.'%');
                }
            });
        }
        $rows = $query->orderBy('name')->orderBy('id')->limit(2000)
            ->get(['id', 'name', 'phone_number', 'pass_type', 'allowed_entries', 'used_entries', 'status']);
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $cards = $this->studio->cardStates($full, $ids, $spec);
        $messages = $delivery->statuses((int) $full->id, $ids);
        $guests = $rows->map(function ($guest) use ($cards, $messages) {
            $id = (int) $guest->id;

            return (object) [
                'id' => $id, 'name' => (string) $guest->name, 'phone' => self::maskPhone((string) $guest->phone_number),
                'pass_type' => $guest->pass_type, 'allowed' => (int) $guest->allowed_entries, 'status' => $guest->status,
                'card' => $cards[$id]['state'] ?? 'none', 'message' => $messages[$id] ?? null,
                'initials' => InvitationDesign::initials((string) $guest->name),
            ];
        });
        $guests = $guests->filter(fn ($g) => match ($filter) {
            'no_card' => $g->card !== 'current',
            'ready' => $g->card === 'current' && $g->message === null,
            'sent' => in_array($g->message, ['sent', 'delivered', 'read'], true),
            'failed' => in_array($g->message, ['failed', 'skipped'], true),
            default => true,
        })->values();

        return response()->view('reference_ui.31_select_invitation_recipients', [
            'language' => $language,
            'event' => $full,
            'guests' => $guests,
            'total' => DB::table('guests')->where('event_id', $full->id)->count(),
            'invited' => DB::table('guests')->where('event_id', $full->id)->where('status', '!=', Guest::STATUS_CANCELLED)->count(),
            'progress' => app(InvitationProgress::class)->forEvent($full, $language),
            'search' => $search,
            'filter' => $filter,
            'warnings' => $warnings,
            'designSaved' => $design['saved'],
            'whatsapp' => $delivery->configured(),
            'readOnly' => $row->status !== Event::STATUS_ACTIVE,
        ])->withHeaders($this->privateEventHeaders());
    }

    public function generate(Request $request, string $event, WorkspaceAccess $access): JsonResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $ids = $this->guestIds($request->input('guests'), (int) $row->id, self::GENERATE_BATCH);
        $full = $this->studio->event((int) $row->id);
        // Extend a finite web request limit for one batch; never impose a limit where none exists (CLI).
        if ((int) ini_get('max_execution_time') > 0) {
            @set_time_limit(max(120, (int) ini_get('max_execution_time')));
        }
        $results = $this->studio->generate($full, $ids, (int) $user->getKey(), $language, (bool) $request->boolean('force'));

        return response()->json(['ok' => true, 'results' => $results])->withHeaders($this->privateEventHeaders());
    }

    public function send(Request $request, string $event, WorkspaceAccess $access, InvitationDelivery $delivery): JsonResponse
    {
        [$user, $row, $language] = $this->context($request, $event, $access, true);
        $ids = $this->guestIds($request->input('guests'), (int) $row->id, self::GENERATE_BATCH);
        if (! $delivery->configured()) {
            return response()->json(['ok' => false, 'configured' => false, 'message' => __('invitation_studio.whatsapp_not_configured', [], $language),
                'results' => array_map(fn ($id) => ['guest_id' => $id, 'status' => 'not_configured'], $ids)], 409);
        }
        $results = $delivery->send($this->studio->event((int) $row->id), $ids, (int) $user->getKey(), $this->studio, $language, $request->boolean('resend'));

        return response()->json(['ok' => true, 'configured' => true, 'results' => $results])->withHeaders($this->privateEventHeaders());
    }

    public function guest(Request $request, string $event, string $guest, WorkspaceAccess $access, InvitationDelivery $delivery): Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access);
        $full = $this->studio->event((int) $row->id);
        $record = $this->eventGuest((int) $full->id, $guest);
        $design = $this->studio->design($full, $language);
        ['spec' => $spec, 'warnings' => $warnings] = $this->studio->spec($full, $design['doc']);
        $state = $this->studio->cardStates($full, [$record->id], $spec)[$record->id] ?? ['state' => 'none', 'generated_at' => null];
        $qr = DB::table('qr_codes')->where('guest_id', $record->id)->first(['is_active', 'revoked_at']);

        // Neighbours in the same order as the recipients list, for card-by-card review.
        $order = DB::table('guests')->where('event_id', $full->id)->orderBy('name')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $index = array_search($record->id, $order, true);

        return response()->view('reference_ui.06_invitation_preview', [
            'language' => $language,
            'event' => $full,
            'guest' => $record,
            'passLine' => InvitationWording::passLine($spec['lang'], (string) $record->pass_type, (int) $record->allowed_entries),
            'card' => $state,
            'credential' => $qr === null ? 'none' : (($qr->is_active && $qr->revoked_at === null) ? 'active' : 'revoked'),
            'message' => $delivery->latest((int) $full->id, $record->id),
            'warnings' => $warnings,
            'previous' => $index !== false && $index > 0 ? $order[$index - 1] : null,
            'next' => $index !== false && $index < count($order) - 1 ? $order[$index + 1] : null,
            'position' => $index === false ? 0 : $index + 1,
            'count' => count($order),
            'whatsapp' => $delivery->configured(),
            'readOnly' => $row->status !== Event::STATUS_ACTIVE,
        ])->withHeaders($this->privateEventHeaders());
    }

    /** Streams a generated card: png (inline or download), jpg (converted) or pdf. */
    public function card(Request $request, string $event, string $guest, string $format, WorkspaceAccess $access): Response|BinaryFileResponse
    {
        [$user, $row] = $this->context($request, $event, $access);
        abort_unless(in_array($format, ['png', 'jpg', 'pdf'], true), 404);
        $record = $this->eventGuest((int) $row->id, $guest);
        $card = $this->studio->card($this->studio->event((int) $row->id), $record->id);
        abort_if($card === null, 404);
        $disk = Storage::disk('invitations');
        $path = $format === 'pdf' ? $card->pdf_path : $card->png_path;
        abort_unless($disk->exists($path), 404);
        $name = InvitationStudio::fileName((string) $record->name, 1);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        $headers = $this->privateEventHeaders() + ['Content-Disposition' => $disposition.'; filename="'.$name.'.'.$format.'"'];
        if ($request->boolean('download')) {
            $this->studio->activity((int) $row->id, (int) $user->getKey(), 'card_downloaded', $record->id, ['format' => $format]);
        }
        if ($format === 'jpg') {
            $image = imagecreatefromstring($disk->get($path));

            return response($this->studio->encode($image, 'jpeg'), 200, ['Content-Type' => 'image/jpeg'] + $headers);
        }

        return response()->file($disk->path($path), ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'image/png'] + $headers);
    }

    public function download(Request $request, string $event, WorkspaceAccess $access): BinaryFileResponse|Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access);
        $ids = $this->guestIds($request->input('guests'), (int) $row->id, 2000);
        $format = in_array($request->input('format'), ['png', 'pdf', 'all'], true) ? $request->input('format') : 'png';
        $full = $this->studio->event((int) $row->id);
        $path = $this->studio->zip($full, $ids, $format);
        $this->studio->activity((int) $row->id, (int) $user->getKey(), 'zip_downloaded', null, ['guests' => count($ids), 'format' => $format]);
        $name = InvitationStudio::fileName((string) $full->event_name, count($ids)).'-kadi.zip';

        return response()->download($path, $name, ['Content-Type' => 'application/zip'] + $this->privateEventHeaders())->deleteFileAfterSend();
    }

    public function status(Request $request, string $event, WorkspaceAccess $access, InvitationDelivery $delivery): Response
    {
        [$user, $row, $language] = $this->context($request, $event, $access);
        $full = $this->studio->event((int) $row->id);
        $design = $this->studio->design($full, $language);
        ['spec' => $spec] = $this->studio->spec($full, $design['doc']);
        $ids = DB::table('guests')->where('event_id', $full->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $cards = $this->studio->cardStates($full, $ids, $spec);
        $messages = $delivery->statuses((int) $full->id, $ids);
        $counts = [
            'guests' => count($ids),
            'cards' => count(array_filter($cards, fn ($c) => $c['state'] === 'current')),
            'stale' => count(array_filter($cards, fn ($c) => $c['state'] === 'stale')),
            'accepted' => count(array_filter($messages, fn ($s) => $s === 'sent')),
            'delivered' => count(array_filter($messages, fn ($s) => in_array($s, ['delivered', 'read'], true))),
            'pending' => count(array_filter($messages, fn ($s) => $s === 'pending')),
            'failed' => count(array_filter($messages, fn ($s) => in_array($s, ['failed', 'skipped'], true))),
        ];
        $counts['not_sent'] = $counts['guests'] - count($messages);
        $recent = DB::table('notification_logs as n')->join('guests as g', 'g.id', '=', 'n.guest_id')
            ->where('n.event_id', $full->id)->where('g.event_id', $full->id)->where('n.message_type', 'digital_card')
            ->orderByDesc('n.id')->limit(8)->get(['g.id', 'g.name', 'g.phone_number', 'n.status', 'n.updated_at'])
            ->map(fn ($r) => (object) ['id' => (int) $r->id, 'name' => $r->name, 'phone' => self::maskPhone((string) $r->phone_number), 'status' => $r->status, 'initials' => InvitationDesign::initials((string) $r->name)]);

        return response()->view('reference_ui.17_invitation_sending_progress', [
            'language' => $language,
            'event' => $full,
            'counts' => $counts,
            'recent' => $recent,
            'progress' => app(InvitationProgress::class)->forEvent($full, $language),
            'whatsapp' => $delivery->configured(),
        ])->withHeaders($this->privateEventHeaders());
    }

    // ----- Helpers -----------------------------------------------------------------

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
        abort_unless(DB::table('guests')->where('event_id', $eventId)->whereIn('id', $ids)->count() === count($ids), 404);

        return $ids;
    }

    private function eventGuest(int $eventId, string $guest): Guest
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,18}\z/', $guest) === 1, 404);

        return Guest::query()->where('event_id', $eventId)->whereKey((int) $guest)->firstOrFail();
    }

    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) < 7) {
            return '•••';
        }

        return '+'.substr($digits, 0, 3).' '.substr($digits, 3, 3).' ••• '.substr($digits, -3);
    }

    /**
     * Movable elements of a template for the editor's arrangement tools: text blocks,
     * the photo frame and each artwork piece, with their size limits.
     *
     * @return list<array{key: string, kind: string, label: string, hideable: bool, min: float, max: float}>
     */
    private function elementsFor(array $template, string $language): array
    {
        $label = fn (string $key, array $replace = []) => __('invitation_studio.elements.'.$key, $replace, $language);
        $elements = [];
        foreach ($template['blocks'] as $block) {
            if (in_array($block, InvitationDesign::BLOCK_KEYS, true)) {
                $elements[] = ['key' => $block, 'kind' => 'text', 'label' => $label($block), 'hideable' => true, 'min' => 0.5, 'max' => 1.8];
            }
        }
        if ($template['photo_shape'] !== null) {
            $elements[] = ['key' => 'photo', 'kind' => 'photo', 'label' => $label('photo'), 'hideable' => false, 'min' => 0.6, 'max' => 1.4];
        }
        foreach ($template['decor'] as $index => $item) {
            $position = ($item['type'] ?? 'art') === 'sprig' ? 'sprig' : $item['anchor'];
            $elements[] = ['key' => 'decor.'.$index, 'kind' => 'decor', 'hideable' => true, 'min' => 0.5, 'max' => 1.6,
                'label' => $label('decor', ['n' => $index + 1, 'position' => __('invitation_studio.anchors.'.$position, [], $language)])];
        }

        return $elements;
    }

    /** The template palette first, then complementary curated palettes as quick swatches. */
    private function palettesFor(array $template): array
    {
        $keys = array_values(array_unique(array_merge([$template['palette']], ['ivory_emerald', 'blush_rose', 'burgundy_gold', 'royal_green', 'navy_gold', 'sage', 'terracotta', 'lavender', 'charcoal', 'champagne'])));
        $palettes = [];
        foreach ($keys as $key) {
            $palettes[$key] = $key === $template['palette'] ? $template['colors'] : InvitationTemplateCatalog::PALETTES[$key];
        }

        return $palettes;
    }
}
