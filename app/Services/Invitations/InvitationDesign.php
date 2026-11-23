<?php

namespace App\Services\Invitations;

use App\Services\Invitations\Render\Ornaments;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The versioned, per-event design document and its resolution into a render spec.
 *
 * The document stores only the owner's choices on top of a published template
 * (template key, colour/font overrides, decoration switches, photo crop, QR placement
 * and invitation wording). Null text means "use the event record / wording library",
 * so invitation-only edits never change the event row. validate() is strict: unknown
 * keys are dropped and every supplied value must match its allow-list or limit.
 */
class InvitationDesign
{
    public const SCHEMA = 1;

    public const COLOR_KEYS = ['bg', 'bg2', 'text', 'text2', 'accent', 'decor', 'border'];

    public const FONT_ROLES = ['heading', 'body', 'accent', 'label'];

    public const BACKGROUNDS = ['solid', 'gradient', 'vignette', 'glow'];

    /** Text blocks the owner may move, resize or hide. */
    public const BLOCK_KEYS = ['motif', 'monogram', 'label', 'hosts', 'intro', 'names', 'divider', 'message', 'note', 'date', 'date_hero', 'venue', 'extras'];

    public const PHOTO_SHAPES = ['arch', 'circle', 'oval', 'rounded', 'square', 'band'];

    public const PHOTO_BORDERS = ['none', 'line', 'double', 'thick'];

    /** Offsets are logical card units (1200 x 1600); the renderer also clamps them to the card. */
    public const MAX_DX = 1200;

    public const MAX_DY = 1600;

    public const TEXT_LIMITS = [
        'label' => 60, 'names' => 120, 'host_one' => 120, 'host_two' => 120, 'intro' => 240, 'message' => 240,
        'venue' => 160, 'location' => 160, 'dress_code' => 80, 'note' => 160, 'guest_label' => 40, 'qr_caption' => 50,
    ];

    /** Default design for an event: its saved template, else a suitable one for its type. */
    public static function defaults(?string $template, ?string $eventType, string $language = 'sw'): array
    {
        $key = InvitationTemplateCatalog::isValid($template) ? $template : self::defaultTemplate($eventType);
        $definition = InvitationTemplateCatalog::get($key);

        return [
            'v' => self::SCHEMA,
            'template' => $key,
            'colors' => [],
            'fonts' => [],
            'font_scale' => 1.0,
            'align' => null,
            'decor' => ['art' => true, 'pattern' => null, 'frame' => null, 'background' => null, 'items' => []],
            'photo' => ['id' => null, 'zoom' => 1.0, 'x' => 0.5, 'y' => 0.4, 'shape' => null, 'border' => null],
            'layout' => ['blocks' => [], 'photo' => []],
            'qr' => ['position' => $definition['qr_position'], 'size' => 'm'],
            'content' => array_merge(
                ['language' => InvitationWording::language($language), 'style' => 'formal', 'host_mode' => 'couple'],
                array_fill_keys(array_keys(self::TEXT_LIMITS), null),
            ),
        ];
    }

    public static function defaultTemplate(?string $eventType): string
    {
        return match ($eventType) {
            'send_off' => 'sendoff_blush',
            'kitchen_party' => 'kitchen_confetti',
            'other' => 'celebration_confetti',
            default => 'floral_classic',
        };
    }

    /**
     * Validates a submitted document against the defaults. Returns the clean document
     * and a list of rejected field paths (empty when everything was acceptable).
     *
     * @return array{0: array, 1: list<string>}
     */
    public static function validate(mixed $input, array $defaults): array
    {
        $errors = [];
        $doc = $defaults;
        if (! is_array($input)) {
            return [$doc, ['document']];
        }

        if (array_key_exists('template', $input)) {
            InvitationTemplateCatalog::isValid($input['template']) ? $doc['template'] = $input['template'] : $errors[] = 'template';
        }
        foreach ((array) ($input['colors'] ?? []) as $key => $value) {
            if (! in_array($key, self::COLOR_KEYS, true)) {
                continue;
            }
            if ($value === null || $value === '') {
                unset($doc['colors'][$key]);
            } elseif (is_string($value) && preg_match('/\A#[0-9a-fA-F]{6}\z/', $value) === 1) {
                $doc['colors'][$key] = strtolower($value);
            } else {
                $errors[] = 'colors.'.$key;
            }
        }
        foreach ((array) ($input['fonts'] ?? []) as $role => $value) {
            if (! in_array($role, self::FONT_ROLES, true)) {
                continue;
            }
            if ($value === null || $value === '') {
                unset($doc['fonts'][$role]);
            } elseif (InvitationFonts::isValid($value)) {
                $doc['fonts'][$role] = $value;
            } else {
                $errors[] = 'fonts.'.$role;
            }
        }
        if (array_key_exists('font_scale', $input)) {
            self::number($input['font_scale'], 0.8, 1.2) !== null ? $doc['font_scale'] = round(self::number($input['font_scale'], 0.8, 1.2), 2) : $errors[] = 'font_scale';
        }
        if (array_key_exists('align', $input)) {
            in_array($input['align'], [null, 'center', 'left'], true) ? $doc['align'] = $input['align'] : $errors[] = 'align';
        }

        $decor = (array) ($input['decor'] ?? []);
        if (array_key_exists('art', $decor)) {
            is_bool($decor['art']) ? $doc['decor']['art'] = $decor['art'] : $errors[] = 'decor.art';
        }
        foreach (['pattern' => Ornaments::PATTERNS, 'frame' => Ornaments::FRAMES, 'background' => self::BACKGROUNDS] as $key => $allowed) {
            if (array_key_exists($key, $decor)) {
                $decor[$key] === null || in_array($decor[$key], $allowed, true) ? $doc['decor'][$key] = $decor[$key] : $errors[] = 'decor.'.$key;
            }
        }

        $photo = (array) ($input['photo'] ?? []);
        if (array_key_exists('id', $photo)) {
            $photo['id'] === null || (is_string($photo['id']) && preg_match('/\A[a-f0-9]{32}\z/', $photo['id']) === 1)
                ? $doc['photo']['id'] = $photo['id'] : $errors[] = 'photo.id';
        }
        foreach (['zoom' => [1.0, 3.0], 'x' => [0.0, 1.0], 'y' => [0.0, 1.0]] as $key => [$min, $max]) {
            if (array_key_exists($key, $photo)) {
                self::number($photo[$key], $min, $max) !== null ? $doc['photo'][$key] = round(self::number($photo[$key], $min, $max), 3) : $errors[] = 'photo.'.$key;
            }
        }
        foreach (['shape' => self::PHOTO_SHAPES, 'border' => self::PHOTO_BORDERS] as $key => $allowed) {
            if (array_key_exists($key, $photo)) {
                $photo[$key] === null || in_array($photo[$key], $allowed, true) ? $doc['photo'][$key] = $photo[$key] : $errors[] = 'photo.'.$key;
            }
        }

        // Free arrangement: a submitted layout/items map replaces the stored one entirely,
        // so removing an override (reset position) is just leaving it out.
        if (array_key_exists('layout', $input)) {
            $layout = is_array($input['layout']) ? $input['layout'] : [];
            $doc['layout'] = ['blocks' => [], 'photo' => []];
            foreach ((array) ($layout['blocks'] ?? []) as $key => $value) {
                if (! in_array($key, self::BLOCK_KEYS, true)) {
                    $errors[] = 'layout.blocks';

                    continue;
                }
                $clean = self::element($value, 0.5, 1.8, true, 'layout.blocks.'.$key, $errors);
                if ($clean !== []) {
                    $doc['layout']['blocks'][$key] = $clean;
                }
            }
            if (array_key_exists('photo', $layout)) {
                $doc['layout']['photo'] = self::element($layout['photo'], 0.6, 1.4, false, 'layout.photo', $errors);
            }
        }
        if (array_key_exists('items', $decor)) {
            $doc['decor']['items'] = [];
            foreach ((array) $decor['items'] as $index => $value) {
                $i = filter_var($index, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 11]]);
                if ($i === false) {
                    $errors[] = 'decor.items';

                    continue;
                }
                $clean = self::element($value, 0.5, 1.6, true, 'decor.items.'.$i, $errors);
                if ($clean !== []) {
                    $doc['decor']['items'][(string) $i] = $clean;
                }
            }
        }

        $qr = (array) ($input['qr'] ?? []);
        if (array_key_exists('position', $qr)) {
            in_array($qr['position'], ['left', 'center', 'right'], true) ? $doc['qr']['position'] = $qr['position'] : $errors[] = 'qr.position';
        }
        if (array_key_exists('size', $qr)) {
            in_array($qr['size'], ['s', 'm', 'l'], true) ? $doc['qr']['size'] = $qr['size'] : $errors[] = 'qr.size';
        }
        // The QR payload and guest credentials are never part of the design document.
        foreach (['token', 'payload', 'qr_token', 'value'] as $forbidden) {
            if (array_key_exists($forbidden, $qr)) {
                $errors[] = 'qr.'.$forbidden;
            }
        }

        $content = (array) ($input['content'] ?? []);
        foreach (['language' => InvitationWording::LANGUAGES, 'style' => InvitationWording::STYLES, 'host_mode' => InvitationWording::HOST_MODES] as $key => $allowed) {
            if (array_key_exists($key, $content)) {
                in_array($content[$key], $allowed, true) ? $doc['content'][$key] = $content[$key] : $errors[] = 'content.'.$key;
            }
        }
        foreach (self::TEXT_LIMITS as $key => $limit) {
            if (! array_key_exists($key, $content)) {
                continue;
            }
            $value = $content[$key];
            if ($value === null) {
                $doc['content'][$key] = null;

                continue;
            }
            if (! is_string($value)) {
                $errors[] = 'content.'.$key;

                continue;
            }
            // Strip control characters (keep newlines in long text), collapse spaces.
            $clean = preg_replace('/[^\P{C}\n]/u', '', $value) ?? '';
            $clean = trim(preg_replace('/[ \t]+/u', ' ', $clean) ?? '');
            if (mb_strlen($clean) > $limit) {
                $errors[] = 'content.'.$key;

                continue;
            }
            $doc['content'][$key] = $clean;
        }

        return [$doc, array_values(array_unique($errors))];
    }

    /**
     * One movable element override: {dx, dy, size, hidden}. Only non-default values are
     * kept, so an untouched element stores nothing.
     *
     * @param  list<string>  $errors
     */
    private static function element(mixed $value, float $minSize, float $maxSize, bool $hideable, string $path, array &$errors): array
    {
        if (! is_array($value)) {
            $errors[] = $path;

            return [];
        }
        $clean = [];
        foreach (['dx' => self::MAX_DX, 'dy' => self::MAX_DY] as $key => $limit) {
            if (array_key_exists($key, $value)) {
                $number = self::number($value[$key], -$limit, $limit);
                $number === null ? $errors[] = $path.'.'.$key : ($number != 0.0 ? $clean[$key] = round($number, 1) : null);
            }
        }
        if (array_key_exists('size', $value)) {
            $number = self::number($value['size'], $minSize, $maxSize);
            $number === null ? $errors[] = $path.'.size' : (abs($number - 1.0) > 0.001 ? $clean['size'] = round($number, 2) : null);
        }
        if (array_key_exists('hidden', $value)) {
            if (! is_bool($value['hidden']) || (! $hideable && $value['hidden'])) {
                $errors[] = $path.'.hidden';
            } elseif ($value['hidden']) {
                $clean['hidden'] = true;
            }
        }
        foreach (array_diff(array_keys($value), ['dx', 'dy', 'size', 'hidden']) as $unknown) {
            $errors[] = $path.'.'.$unknown;
        }

        return $clean;
    }

    private static function number(mixed $value, float $min, float $max): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && $number >= $min && $number <= $max ? $number : null;
    }

    /**
     * Resolves a document plus the authoritative event row into a render spec.
     *
     * @param  object  $event  row with event_name, bride_name, groom_name, venue_name, event_date, event_time, event_type
     * @return array{spec: array, warnings: list<string>}
     */
    public static function resolve(array $doc, object $event, ?string $photoFile = null): array
    {
        $template = InvitationTemplateCatalog::get($doc['template']) ?? InvitationTemplateCatalog::get(self::defaultTemplate($event->event_type ?? null));
        $content = $doc['content'];
        $language = InvitationWording::language($content['language'] ?? 'sw');
        $eventType = InvitationWording::eventType($event->event_type ?? null);
        $warnings = [];

        $names = self::pick($content['names'] ?? null, self::eventNames($event));
        if ($names === '') {
            $warnings[] = 'missing_names';
        }

        $date = null;
        try {
            $date = filled($event->event_date ?? null) ? Carbon::parse($event->event_date) : null;
        } catch (Throwable) {
            $date = null;
        }
        $dateParts = $date ? InvitationWording::dateParts($date, $language) : ['weekday' => '', 'day' => '', 'month' => '', 'year' => '', 'date_long' => '', 'date_short' => ''];
        if ($date === null) {
            $warnings[] = 'missing_date';
        }
        $time = is_string($event->event_time ?? null) ? InvitationWording::time($event->event_time, $language) : null;
        if ($time === null) {
            $warnings[] = 'missing_time';
        }
        $venue = self::pick($content['venue'] ?? null, (string) ($event->venue_name ?? ''));
        if ($venue === '') {
            $warnings[] = 'missing_venue';
        }

        // Hosts: bride's family is host_one, groom's family host_two.
        $mode = $content['host_mode'] ?? 'couple';
        $hostOne = trim((string) ($content['host_one'] ?? ''));
        $hostTwo = trim((string) ($content['host_two'] ?? ''));
        $familyOf = fn (string $name) => InvitationWording::string($language, 'family_of', ['name' => $name]);
        $hostLine = match ($mode) {
            'bride_family' => $hostOne !== '' ? $familyOf($hostOne) : '',
            'groom_family' => $hostTwo !== '' ? $familyOf($hostTwo) : '',
            'both_families' => $hostOne !== '' && $hostTwo !== ''
                ? $familyOf($hostOne).' '.InvitationWording::string($language, 'and').' '.InvitationWording::string($language, 'family_of_second', ['name' => $hostTwo]) : '',
            'other' => $hostOne,
            default => '',
        };
        $effectiveMode = $mode;
        if ($mode !== 'couple' && $hostLine === '') {
            $warnings[] = 'hosts_missing';
            $effectiveMode = 'couple';
        }

        $layoutHasHosts = in_array('hosts', $template['blocks'], true);
        $style = $content['style'] ?? 'formal';
        $intro = $content['intro'] ?? null;
        if ($intro === null) {
            $intro = InvitationWording::intro($language, $eventType, $style, $effectiveMode);
            // Family wording continues the host line; without a hosts block it is joined into one sentence.
            if ($effectiveMode !== 'couple' && ! $layoutHasHosts) {
                $intro = $hostLine.' '.$intro;
            } elseif ($effectiveMode !== 'couple') {
                $intro = mb_strtoupper(mb_substr($intro, 0, 1)).mb_substr($intro, 1);
            }
        }
        $message = $content['message'] ?? InvitationWording::message($language, $eventType, $style);

        $colors = array_merge($template['colors'], $doc['colors'] ?? []);
        $fonts = array_merge($template['fonts'], $doc['fonts'] ?? []);
        $columns = ($template['hosts_style'] ?? 'lines') === 'columns';

        $spec = [
            'template' => $template['key'],
            'layout' => $template['layout'],
            'blocks' => $template['blocks'],
            'inset' => $template['inset'],
            'content_top' => $template['content_top'],
            'align' => $doc['align'] ?? $template['align'],
            'names_layout' => $template['names_layout'],
            'hosts_style' => $template['hosts_style'],
            'panel_shape' => $template['panel_shape'],
            'colors' => $colors,
            'fonts' => $fonts,
            'font_scale' => (float) ($doc['font_scale'] ?? 1.0),
            'background' => $doc['decor']['background'] ?? $template['background'],
            'pattern' => $doc['decor']['pattern'] ?? $template['pattern'],
            'frame' => $doc['decor']['frame'] ?? $template['frame'],
            'texture' => $template['texture'],
            'decor' => $template['decor'],
            'decor_enabled' => (bool) ($doc['decor']['art'] ?? true),
            'motif' => $template['motif'],
            'divider' => $template['divider'],
            'date_style' => $template['date_style'],
            'guest_style' => $template['guest_style'],
            'names_font' => $template['names_font'],
            'guest_font' => 'accent',
            'qr' => $doc['qr'],
            'photo' => ['file' => $photoFile, 'zoom' => $doc['photo']['zoom'], 'x' => $doc['photo']['x'], 'y' => $doc['photo']['y'],
                'shape' => self::photoShape($template, $doc['photo']['shape'] ?? null), 'border' => $doc['photo']['border'] ?? 'double'],
            'arrange' => ['blocks' => $doc['layout']['blocks'] ?? [], 'photo' => $doc['layout']['photo'] ?? []],
            'decor_items' => $doc['decor']['items'] ?? [],
            'text' => [
                'label' => self::pick($content['label'] ?? null, InvitationWording::label($language, $eventType)),
                'host_line' => $effectiveMode === 'couple' || ! $layoutHasHosts ? '' : ($columns && $mode === 'both_families' ? '' : $hostLine),
                'hosts_heading' => $columns && $mode === 'both_families' && $hostLine !== '' ? InvitationWording::string($language, 'hosts_heading') : '',
                'host_one_title' => InvitationWording::string($language, 'bride_family'),
                'host_one' => $columns && $mode === 'both_families' ? $hostOne : '',
                'host_two_title' => InvitationWording::string($language, 'groom_family'),
                'host_two' => $columns && $mode === 'both_families' ? $hostTwo : '',
                'intro' => $intro,
                'names' => $names,
                'message' => $message,
                'time' => $time ?? '',
                'time_label' => InvitationWording::string($language, 'time_label'),
                'venue' => $venue,
                'location' => (string) ($content['location'] ?? ''),
                'dress_code' => (string) ($content['dress_code'] ?? ''),
                'dress_code_label' => InvitationWording::string($language, 'dress_code_label'),
                'note' => (string) ($content['note'] ?? ''),
                'guest_label' => self::pick($content['guest_label'] ?? null, InvitationWording::string($language, 'guest_label')),
                'guest_sample' => InvitationWording::string($language, 'guest_sample'),
                'pass_sample' => InvitationWording::passLine($language, 'double', 2),
                'qr_caption' => self::pick($content['qr_caption'] ?? null, InvitationWording::string($language, 'qr_caption')),
                'code_label' => InvitationWording::string($language, 'code_label'),
                'qr_sample' => InvitationWording::string($language, 'qr_sample'),
                'photo_hint' => InvitationWording::string($language, 'photo_hint'),
                'initials' => self::initials($names),
            ] + $dateParts,
            'lang' => $language,
        ];

        return ['spec' => $spec, 'warnings' => $warnings];
    }

    /**
     * Frame shapes offered for a template's photo slot: every framed shape for portrait
     * slots; the full-width band additionally for band layouts.
     *
     * @return list<string>
     */
    public static function photoShapes(array $template): array
    {
        if ($template['photo_shape'] === null) {
            return [];
        }

        return $template['photo_shape'] === 'band' ? self::PHOTO_SHAPES : array_values(array_diff(self::PHOTO_SHAPES, ['band']));
    }

    private static function photoShape(array $template, ?string $choice): ?string
    {
        if ($template['photo_shape'] === null) {
            return null;
        }

        return $choice !== null && in_array($choice, self::photoShapes($template), true) ? $choice : $template['photo_shape'];
    }

    /** The couple/honoree line from the authoritative event record (never split or rewritten). */
    public static function eventNames(object $event): string
    {
        $bride = trim((string) ($event->bride_name ?? ''));
        $groom = trim((string) ($event->groom_name ?? ''));
        if ($bride !== '' && $groom !== '') {
            return $bride.' & '.$groom;
        }

        return trim((string) ($event->event_name ?? ''));
    }

    private static function pick(?string $override, string $fallback): string
    {
        return $override !== null && $override !== '' ? $override : $fallback;
    }

    public static function initials(string $names): string
    {
        $parts = preg_split('/\s+&\s+/u', trim($names), 2) ?: [];
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            return mb_strtoupper(mb_substr($parts[0], 0, 1)).' & '.mb_strtoupper(mb_substr($parts[1], 0, 1));
        }
        $words = preg_split('/\s+/u', trim($names), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(fn ($word) => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
    }

    /** Stable hash of everything that affects the guest-independent base image. */
    public static function fingerprint(array $spec): string
    {
        $file = $spec['photo']['file'] ?? null;
        $spec['photo']['file'] = is_string($file) && is_file($file) ? hash_file('sha256', $file) : null;

        return hash('sha256', json_encode([$spec, InvitationTemplateCatalog::VERSION]));
    }
}
