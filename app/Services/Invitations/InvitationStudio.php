<?php

namespace App\Services\Invitations;

use App\Models\Guest;
use App\Services\Invitations\Render\InvitationRenderer;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use ZipArchive;

/**
 * Orchestrates the invitation workflow for one authorized event: the saved design,
 * previews, gallery thumbnails, per-guest card generation and exports. Callers
 * (controllers) authorize the account, workspace and event first; every guest id is
 * re-checked here against that event inside the generation transaction.
 */
class InvitationStudio
{
    public const PREVIEW_WIDTH = 600;

    public const PNG_WIDTH = 1500;

    public const PRINT_WIDTH = 1800;

    public const THUMB_WIDTH = 330;

    public const LARGE_PREVIEW_WIDTH = 900;

    public function __construct(
        private InvitationRenderer $renderer,
        private InvitationPhotoStore $photos,
        private InvitationCredentials $credentials,
        private InvitationPdf $pdf,
    ) {}

    // ----- Event + design ----------------------------------------------------------

    /** Full event row needed for invitation text (the authorization row is narrower). */
    public function event(int $eventId): object
    {
        $event = DB::table('events')->useWritePdo()
            ->select(['id', 'workspace_id', 'event_name', 'bride_name', 'groom_name', 'venue_name', 'event_date', 'event_time', 'event_type', 'invitation_template', 'status'])
            ->where('id', $eventId)->first();
        abort_if($event === null, 404);

        return $event;
    }

    /** @return array{doc: array, version: int, saved: bool, updated_at: ?string} */
    public function design(object $event, string $language): array
    {
        $defaults = InvitationDesign::defaults($event->invitation_template, $event->event_type, $language);
        $row = DB::table('invitation_designs')->useWritePdo()->where('event_id', $event->id)->first();
        if ($row === null) {
            return ['doc' => $defaults, 'version' => 0, 'saved' => false, 'updated_at' => null];
        }
        $stored = json_decode((string) $row->document, true);
        // Re-validate on read so an old or tampered document can never reach the renderer.
        [$doc] = InvitationDesign::validate(is_array($stored) ? $stored : [], InvitationDesign::defaults($row->template_key, $event->event_type, $language));

        return ['doc' => $doc, 'version' => (int) $row->version, 'saved' => true, 'updated_at' => $row->updated_at];
    }

    /**
     * Saves a validated document. $expectedVersion guards against overwriting a newer
     * save from another tab/device (returns null on conflict).
     */
    public function save(object $event, int $userId, array $doc, ?int $expectedVersion): ?int
    {
        return DB::transaction(function () use ($event, $userId, $doc, $expectedVersion): ?int {
            $row = DB::table('invitation_designs')->useWritePdo()->where('event_id', $event->id)->lockForUpdate()->first();
            $current = $row === null ? 0 : (int) $row->version;
            if ($expectedVersion !== null && $expectedVersion !== $current) {
                return null;
            }
            $values = [
                'workspace_id' => $event->workspace_id,
                'template_key' => $doc['template'],
                'document' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'version' => $current + 1,
                'photo_id' => $doc['photo']['id'],
                'updated_by' => $userId,
                'updated_at' => now(),
            ];
            if ($row === null) {
                DB::table('invitation_designs')->insert($values + ['event_id' => $event->id, 'created_at' => now()]);
            } else {
                DB::table('invitation_designs')->where('id', $row->id)->update($values);
            }
            // Keep the existing Working Flow 14 selection column in step with the design.
            DB::table('events')->where('id', $event->id)->where('workspace_id', $event->workspace_id)
                ->update(['invitation_template' => $doc['template'], 'updated_at' => now()]);
            $this->activity((int) $event->id, $userId, 'design_saved', null, ['version' => $current + 1, 'template' => $doc['template']]);

            return $current + 1;
        });
    }

    /**
     * Moves a saved design to another template. Wording, photo and QR size are kept;
     * colours, fonts, decoration switches and QR side follow the new template.
     */
    public function switchTemplate(object $event, int $userId, string $template, string $language): void
    {
        ['doc' => $doc, 'saved' => $saved] = $this->design($event, $language);
        if (! $saved || $doc['template'] === $template || ! InvitationTemplateCatalog::isValid($template)) {
            return;
        }
        $fresh = InvitationDesign::defaults($template, $event->event_type, $language);
        $fresh['content'] = $doc['content'];
        $fresh['photo'] = $doc['photo'];
        $fresh['qr']['size'] = $doc['qr']['size'];
        $fresh['font_scale'] = $doc['font_scale'];
        $this->save($event, $userId, $fresh, null);
    }

    /** @return array{spec: array, warnings: list<string>} */
    public function spec(object $event, array $doc): array
    {
        return InvitationDesign::resolve($doc, $event, $this->photos->path((int) $event->id, $doc['photo']['id'] ?? null));
    }

    // ----- Rendering -------------------------------------------------------------

    /** PNG bytes of the owner preview (sample guest + non-scannable sample QR). */
    public function previewPng(object $event, array $doc, int $width = self::PREVIEW_WIDTH, bool $hints = true): array
    {
        ['spec' => $spec, 'warnings' => $warnings] = $this->spec($event, $doc);
        $image = $this->renderer->render($spec, $width, null, ['hints' => $hints]);

        return ['png' => $this->encode($image, 'png'), 'warnings' => array_values(array_unique(array_merge($warnings, $this->renderer->warnings()))),
            'layout' => array_map(fn ($r) => [round($r[0], 1), round($r[1], 1), round($r[2], 1), round($r[3], 1), $r[4]], $this->renderer->layoutMap())];
    }

    /** Gallery thumbnail of a template with this event's real details (cached per event facts). */
    public function thumbnail(object $event, string $template, string $language, bool $large = false): string
    {
        $doc = InvitationDesign::defaults($template, $event->event_type, $language);
        ['spec' => $spec] = $this->spec($event, $doc);
        // Small for the gallery grid; large (900 px) only when the customer opens a preview.
        $width = $large ? self::LARGE_PREVIEW_WIDTH : self::THUMB_WIDTH;
        $key = 'events/'.$event->id.'/thumbs/'.$template.'-'.$width.'-'.substr(hash('sha256', json_encode([$spec, InvitationTemplateCatalog::VERSION, 2])), 0, 20).'.webp';
        $disk = Storage::disk('invitations');
        if ($disk->exists($key)) {
            return $disk->get($key);
        }
        $bytes = $this->encode($this->renderer->render($spec, $width), 'webp');
        $disk->put($key, $bytes);

        return $bytes;
    }

    /** Guest-specific card image at $width, reusing a cached base layer. */
    public function guestImage(object $event, array $doc, Guest $guest, string $payload, int $width): GdImage
    {
        ['spec' => $spec] = $this->spec($event, $doc);
        $base = $this->base($event, $spec, $width);
        $this->renderer->guest($base, $spec, [
            'name' => (string) $guest->name,
            'pass_line' => InvitationWording::passLine($spec['lang'], (string) $guest->pass_type, (int) $guest->allowed_entries),
            'manual_code' => (string) $guest->manual_code,
            'qr_payload' => $payload,
        ]);

        return $base;
    }

    private function base(object $event, array $spec, int $width): GdImage
    {
        $key = 'events/'.$event->id.'/cache/'.InvitationDesign::fingerprint($spec).'-'.$width.'.png';
        $disk = Storage::disk('invitations');
        if ($disk->exists($key)) {
            $image = @imagecreatefromstring($disk->get($key));
            if ($image instanceof GdImage) {
                return $image;
            }
        }
        $image = $this->renderer->base($spec, $width);
        $disk->put($key, $this->encode($image, 'png', 1));

        return $image;
    }

    // ----- Cards -----------------------------------------------------------------

    /**
     * Generates (or confirms up to date) the personalised card for each guest id.
     * Unknown/foreign guest ids are rejected as a whole before anything is written.
     *
     * @param  list<int>  $guestIds
     * @return list<array{guest_id: int, status: string}>
     */
    public function generate(object $event, array $guestIds, int $userId, string $language, bool $force = false): array
    {
        $guestIds = array_values(array_unique(array_map('intval', $guestIds)));
        $owned = DB::table('guests')->useWritePdo()->where('event_id', $event->id)->whereIn('id', $guestIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        abort_unless(count($owned) === count($guestIds), 404);

        ['doc' => $doc, 'version' => $version] = $this->design($event, $language);
        ['spec' => $spec] = $this->spec($event, $doc);
        $designFingerprint = InvitationDesign::fingerprint($spec);
        $disk = Storage::disk('invitations');
        $results = [];

        foreach ($guestIds as $guestId) {
            try {
                $credential = $this->credentials->ensure((int) $event->id, $guestId);
                if ($credential['status'] !== InvitationCredentials::OK) {
                    $results[] = ['guest_id' => $guestId, 'status' => $credential['status']];

                    continue;
                }
                $qr = $credential['qr'];
                $tokenFingerprint = InvitationCredentials::fingerprint($qr);
                $existing = DB::table('invitation_cards')->useWritePdo()->where('event_id', $event->id)->where('guest_id', $guestId)->first();
                if (! $force && $existing !== null && $existing->design_fingerprint === $designFingerprint
                    && $existing->token_fingerprint === $tokenFingerprint && $disk->exists($existing->png_path) && $disk->exists($existing->pdf_path)) {
                    $results[] = ['guest_id' => $guestId, 'status' => 'current'];

                    continue;
                }

                $guest = Guest::query()->useWritePdo()->whereKey($guestId)->where('event_id', $event->id)->firstOrFail();
                $payload = InvitationCredentials::payload($qr);
                $png = $this->encode($this->guestImage($event, $doc, $guest, $payload, self::PNG_WIDTH), 'png', 6);
                $print = $this->guestImage($event, $doc, $guest, $payload, self::PRINT_WIDTH);
                $pdf = $this->pdf->fromJpeg($this->encode($print, 'jpeg'), imagesx($print), imagesy($print), (string) $event->event_name);
                $pngPath = 'events/'.$event->id.'/cards/'.$guestId.'.png';
                $pdfPath = 'events/'.$event->id.'/cards/'.$guestId.'.pdf';
                $disk->put($pngPath, $png);
                $disk->put($pdfPath, $pdf);

                DB::table('invitation_cards')->upsert([[
                    'event_id' => $event->id, 'guest_id' => $guestId, 'qr_code_id' => $qr->getKey(), 'design_version' => $version,
                    'design_fingerprint' => $designFingerprint, 'token_fingerprint' => $tokenFingerprint, 'png_path' => $pngPath, 'pdf_path' => $pdfPath,
                    'width' => self::PNG_WIDTH, 'height' => (int) round(self::PNG_WIDTH * 4 / 3), 'generated_by' => $userId, 'generated_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]], ['event_id', 'guest_id'], ['qr_code_id', 'design_version', 'design_fingerprint', 'token_fingerprint', 'png_path', 'pdf_path', 'width', 'height', 'generated_by', 'generated_at', 'updated_at']);
                $this->activity((int) $event->id, $userId, 'card_generated', $guestId, ['design_version' => $version, 'credential_created' => $credential['created']]);
                $results[] = ['guest_id' => $guestId, 'status' => 'generated'];
            } catch (Throwable $exception) {
                if ($exception instanceof HttpExceptionInterface) {
                    throw $exception;
                }
                report($exception);
                $results[] = ['guest_id' => $guestId, 'status' => 'failed'];
            }
        }

        return $results;
    }

    /**
     * Card state per guest: none | current | stale (design or credential changed since generation).
     *
     * @return array<int, array{state: string, generated_at: ?string}>
     */
    public function cardStates(object $event, array $guestIds, array $spec): array
    {
        $fingerprint = InvitationDesign::fingerprint($spec);
        $tokens = DB::table('qr_codes')->whereIn('guest_id', $guestIds)->get(['guest_id', 'qr_token'])
            ->mapWithKeys(fn ($row) => [(int) $row->guest_id => hash('sha256', 'invitation-card|'.$row->qr_token)]);
        $states = [];
        foreach (DB::table('invitation_cards')->where('event_id', $event->id)->whereIn('guest_id', $guestIds)->get() as $card) {
            $fresh = $card->design_fingerprint === $fingerprint && ($tokens[(int) $card->guest_id] ?? null) === $card->token_fingerprint;
            $states[(int) $card->guest_id] = ['state' => $fresh ? 'current' : 'stale', 'generated_at' => $card->generated_at];
        }

        return $states;
    }

    /** The stored card row for an event guest, or null. */
    public function card(object $event, int $guestId): ?object
    {
        return DB::table('invitation_cards')->where('event_id', $event->id)->where('guest_id', $guestId)->first();
    }

    /** Builds a ZIP of the given guests' cards (PNG + PDF). Returns the temp file path. */
    public function zip(object $event, array $guestIds, string $format): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP support is unavailable.');
        }
        $cards = DB::table('invitation_cards as c')->join('guests as g', 'g.id', '=', 'c.guest_id')
            ->where('c.event_id', $event->id)->where('g.event_id', $event->id)->whereIn('c.guest_id', $guestIds)
            ->orderBy('g.name')->orderBy('g.id')->get(['c.guest_id', 'c.png_path', 'c.pdf_path', 'g.name']);
        $disk = Storage::disk('invitations');
        $path = tempnam(sys_get_temp_dir(), 'inv');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the archive.');
        }
        $used = [];
        foreach ($cards as $i => $card) {
            $name = self::fileName((string) $card->name, $i + 1);
            if (in_array($format, ['png', 'all'], true) && $disk->exists($card->png_path)) {
                $zip->addFile($disk->path($card->png_path), $name.'.png');
            }
            if (in_array($format, ['pdf', 'all'], true) && $disk->exists($card->pdf_path)) {
                $zip->addFile($disk->path($card->pdf_path), $name.'.pdf');
            }
            $used[] = $name;
        }
        $zip->close();

        return $path;
    }

    /** Safe download name: sequence + ASCII slug of the guest name; no ids, tokens or phone numbers. */
    public static function fileName(string $guestName, int $sequence): string
    {
        $slug = Str::of($guestName)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-')->limit(40, '');

        return sprintf('%03d-%s', $sequence, $slug->isEmpty() ? 'mgeni' : $slug);
    }

    public function activity(int $eventId, ?int $userId, string $action, ?int $guestId = null, array $details = []): void
    {
        DB::table('invitation_activity')->insert([
            'event_id' => $eventId, 'user_id' => $userId, 'guest_id' => $guestId, 'action' => $action,
            'details' => $details === [] ? null : json_encode($details), 'created_at' => now(),
        ]);
    }

    public function encode(GdImage $image, string $format, int $level = 6): string
    {
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($image, null, 92),
            'webp' => imagewebp($image, null, 82),
            default => imagepng($image, null, $level),
        };

        return (string) ob_get_clean();
    }
}
