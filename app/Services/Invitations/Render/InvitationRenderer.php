<?php

namespace App\Services\Invitations\Render;

use App\Services\Invitations\InvitationFonts;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use GdImage;
use RuntimeException;

/**
 * The single invitation renderer used for the editor preview, the template gallery,
 * PNG/JPEG export and the PDF page image, so every output matches the preview.
 *
 * Rendering is two passes:
 *  1. base()  - everything that does not depend on the guest (background, pattern,
 *               frame, artwork, photo, event text, guest box and white QR tile). It is
 *               drawn supersampled for smooth vector edges and is cacheable per design.
 *  2. guest() - the guest's name, pass line, manual code and the QR modules, drawn
 *               last at output resolution so nothing can cover or blur the QR.
 *
 * The QR payload is supplied by the caller from the guest's existing admission
 * credential; this class never creates, edits or derives credentials.
 */
class InvitationRenderer
{
    public const QR_SIZES = ['s' => 236, 'm' => 266, 'l' => 296];

    private const SUPERSAMPLE = 2;

    private const MAX_WORK_WIDTH = 3600;

    /** @var list<string> */
    private array $warnings = [];

    private Canvas $c;

    private Ornaments $o;

    private array $spec;

    private float $scale = 1.0;

    /** @var array<string, GdImage> */
    private static array $decorCache = [];

    // ----- Public API ----------------------------------------------------------

    /** @return list<string> warnings from the last render */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /** Full card: base + guest overlay (or the non-scannable sample overlay when $guest is null). */
    public function render(array $spec, int $width, ?array $guest = null, array $options = []): GdImage
    {
        $base = $this->base($spec, $width, $options);
        $this->guest($base, $spec, $guest, $options);

        return $base;
    }

    /**
     * Where each element was drawn in the last base() call, in logical card units:
     * key => [x, y, w, h, kind]. kind is text|photo|decor (movable) or protected (QR and
     * guest box). The editor uses it to place drag handles over the preview.
     *
     * @return array<string, array{0: float, 1: float, 2: float, 3: float, 4: string}>
     */
    public function layoutMap(): array
    {
        return $this->layoutMap;
    }

    /** @var array<string, array{0: float, 1: float, 2: float, 3: float, 4: string}> */
    private array $layoutMap = [];

    /** @var list<array{0: float, 1: float, 2: float, 3: float}> QR tile + caption and guest box */
    private array $protected = [];

    /** Guest-independent layer at $width pixels. */
    public function base(array $spec, int $width, array $options = []): GdImage
    {
        $this->warnings = [];
        $this->layoutMap = [];
        $this->spec = $spec;
        $ss = max(1, min(self::SUPERSAMPLE, (int) floor(self::MAX_WORK_WIDTH / $width)));
        $this->c = Canvas::forWidth($width * $ss);
        $this->o = new Ornaments($this->c);
        $colors = $spec['colors'];
        $geometry = $this->geometry();
        $this->protectFooter($geometry['footer']);

        $this->background();
        if (($spec['pattern'] ?? 'none') !== 'none') {
            $this->o->pattern($spec['pattern'], $colors['decor'], $colors['accent'], $colors['border'], crc32($spec['template'] ?? 'x'));
        }
        if (! empty($geometry['panel'])) {
            $this->panel($geometry['panel']);
        }
        if (($spec['frame'] ?? 'none') !== 'none') {
            $this->o->frame($spec['frame'], $colors['border'], $colors['accent']);
        }
        $this->decor();
        if (! empty($geometry['photo'])) {
            $this->photo($geometry['photo'], $options);
        }
        if (! empty($geometry['medallion'])) {
            [$mx, $my, $mr] = $geometry['medallion'];
            $this->c->circle($mx, $my, $mr, $colors['bg2']);
            $this->c->ring($mx, $my, $mr, 3, $colors['border']);
            $this->c->ring($mx, $my, $mr - 16, 1.4, $colors['border'], 0.7);
        }

        $this->flowRegions($geometry);
        $this->footerBase($geometry['footer']);
        $this->checkOverlaps();

        if (Canvas::contrast($colors['text'], $this->surface()) < 3.0) {
            $this->warnings[] = 'low_contrast';
        }

        if ($ss === 1) {
            return $this->c->image;
        }
        $out = imagecreatetruecolor($width, (int) round($width * Canvas::HEIGHT / Canvas::WIDTH));
        imagecopyresampled($out, $this->c->image, 0, 0, 0, 0, imagesx($out), imagesy($out), imagesx($this->c->image), imagesy($this->c->image));
        imagedestroy($this->c->image);

        return $out;
    }

    /** Draws the guest-specific overlay onto a base image of the same spec. */
    public function guest(GdImage $image, array $spec, ?array $guest, array $options = []): void
    {
        $this->spec = $spec;
        $width = imagesx($image);
        $canvas = new Canvas(1, $width / Canvas::WIDTH);
        $canvas->image = $image;
        $this->c = $canvas;
        $this->o = new Ornaments($canvas);
        $geometry = $this->geometry();
        $footer = $geometry['footer'];
        $text = $spec['text'];
        $colors = $spec['colors'];
        $box = $footer['guest'];
        $fill = $this->guestFill();
        $nameColor = $this->readable($colors['text'], $fill);
        $subColor = $this->readable($colors['text2'], $fill);

        $name = $guest['name'] ?? ($text['guest_sample'] ?? 'Guest');
        $pass = $guest['pass_line'] ?? ($text['pass_sample'] ?? '');
        [$bx, $by, $bw, $bh] = $box;
        $role = $spec['guest_font'] ?? 'accent';
        $nameFont = $this->font($role, 'regular');
        // The guest's name is the most important line on a personal card: set it large.
        $size = $this->isScript($role) ? 46 : 36;
        $maxWidth = $bw - 56;
        while ($size > 16 && $this->c->textWidth($name, $nameFont, $size) > $maxWidth) {
            $size -= 1;
        }
        if ($this->c->textWidth($name, $nameFont, $size) > $maxWidth) {
            $name = $this->ellipsize($name, $nameFont, $size, $maxWidth);
            $this->warnings[] = 'guest_name_truncated';
        }
        // Name sits between the label (baseline by+32) and the pass line (baseline by+bh-16).
        [$ascent, $descent] = Canvas::fontMetrics($nameFont);
        $slotTop = $by + 42;
        $slotBottom = $by + $bh - ($pass !== '' ? 34 : 14);
        $baseline = ($slotTop + $slotBottom) / 2 + ($ascent - $descent) * $size / 2;
        $cx = $bx + $bw / 2;
        $this->c->text($name, $nameFont, $size, $cx, $baseline, $nameColor, 'center');
        if ($pass !== '') {
            $this->c->text(mb_strtoupper($pass), $this->font('label', 'regular'), 15, $cx, $by + $bh - 16, $subColor, 'center', 0.16);
        }

        [$qx, $qy, $qs] = $footer['qr'];
        if (isset($guest['qr_payload']) && is_string($guest['qr_payload']) && $guest['qr_payload'] !== '') {
            $this->qrModules($guest['qr_payload'], $qx, $qy, $qs);
        } else {
            $this->sampleQr($qx, $qy, $qs, $text['qr_sample'] ?? 'SAMPLE');
        }
        $code = isset($guest['manual_code']) ? ($text['code_label'] ?? 'Code').': '.$guest['manual_code'] : ($text['code_label'] ?? 'Code').': ••••';
        $captionY = $qy + $qs + 30;
        $this->c->text(mb_strtoupper($text['qr_caption'] ?? ''), $this->font('label', 'bold'), 14, $qx + $qs / 2, $captionY, $colors['text'], 'center', 0.16);
        $this->c->text($code, $this->font('label', 'regular'), 14, $qx + $qs / 2, $captionY + 24, $colors['text2'], 'center', 0.1);
    }

    /**
     * Pixel box of the QR symbol area (inside the protected white tile) for a spec at
     * $width, plus module geometry for $payload. Used by verification tests and tools.
     *
     * @return array{x: int, y: int, size: int, module: int, modules: int, offset_x: int, offset_y: int}
     */
    public function qrBox(array $spec, int $width, string $payload): array
    {
        $this->spec = $spec;
        $this->c = new Canvas(1, $width / Canvas::WIDTH);
        [$x, $y, $size] = $this->geometry()['footer']['qr'];
        $modules = Encoder::encode($payload, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix()->getWidth();
        $px = $this->c->px($size);
        $module = intdiv($px, $modules + 8);

        return ['x' => $this->c->px($x), 'y' => $this->c->px($y), 'size' => $px, 'module' => $module, 'modules' => $modules,
            'offset_x' => $this->c->px($x) + intdiv($px - $module * $modules, 2), 'offset_y' => $this->c->px($y) + intdiv($px - $module * $modules, 2)];
    }

    // ----- Geometry --------------------------------------------------------------

    /** Regions for the current spec's layout and QR position. */
    private function geometry(): array
    {
        $layout = $this->spec['layout'];
        $inset = (float) ($this->spec['inset'] ?? 140);
        $panelInset = in_array($layout, ['panel', 'arch_window'], true);
        $footerInset = $panelInset ? max($inset, 175) : max($inset, 130);
        $footer = $this->footerGeometry($footerInset, $layout === 'arch_window' ? 1450 : ($panelInset ? 1440 : 1478));
        $bottom = $footer['top'] - 24;
        $x = $inset;
        $w = Canvas::WIDTH - 2 * $inset;
        $top = (float) ($this->spec['content_top'] ?? 130);
        $g = ['footer' => $footer, 'panel' => null, 'photo' => null, 'medallion' => null, 'regions' => []];
        $blocks = $this->spec['blocks'];

        switch ($layout) {
            case 'photo_arch':
                $g['photo'] = ['shape' => 'arch', 'rect' => [370, 112, 460, 470]];
                $g['regions'][] = [$x, 612, $w, $bottom - 612, $blocks];
                break;
            case 'photo_circle':
                $g['photo'] = ['shape' => 'circle', 'rect' => [405, 118, 390, 390]];
                $g['regions'][] = [$x, 540, $w, $bottom - 540, $blocks];
                break;
            case 'photo_oval':
                $g['photo'] = ['shape' => 'oval', 'rect' => [420, 110, 360, 460]];
                $g['regions'][] = [$x, 600, $w, $bottom - 600, $blocks];
                break;
            case 'photo_band':
                $g['photo'] = ['shape' => 'band', 'rect' => [0, 0, Canvas::WIDTH, 640]];
                $g['regions'][] = [$x, 600, $w, $bottom - 600, $blocks];
                break;
            case 'panel':
                $g['panel'] = ['shape' => $this->spec['panel_shape'] ?? 'rounded', 'rect' => [110, 110, 980, 1380]];
                $g['regions'][] = [max($x, 170), $top + 50, Canvas::WIDTH - 2 * max($x, 170), $bottom - $top - 50, $blocks];
                break;
            case 'arch_window':
                $g['panel'] = ['shape' => 'arch', 'rect' => [130, 80, 940, 1440]];
                $g['regions'][] = [max($x, 200), 300, Canvas::WIDTH - 2 * max($x, 200), $bottom - 300, $blocks];
                break;
            case 'medallion':
                $g['medallion'] = [600, 440, 310];
                $inner = array_values(array_intersect($blocks, ['motif', 'label', 'names', 'divider']));
                $outer = array_values(array_diff($blocks, $inner));
                $g['regions'][] = [365, 215, 470, 450, $inner, $this->spec['colors']['bg2']];
                $g['regions'][] = [$x, 775, $w, $bottom - 775, $outer];
                break;
            default:
                $g['regions'][] = [$x, $top, $w, $bottom - $top, $blocks];
        }

        return $g;
    }

    private function footerGeometry(float $inset, float $bottom): array
    {
        $size = self::QR_SIZES[$this->spec['qr']['size'] ?? 'm'] ?? self::QR_SIZES['m'];
        $position = $this->spec['qr']['position'] ?? 'center';
        $captionSpace = 52;
        $qrTop = $bottom - $captionSpace - $size;
        if ($position === 'center') {
            $boxH = 132;
            $boxW = 640;
            $boxTop = $qrTop - 22 - $boxH;

            return ['top' => $boxTop, 'position' => 'center', 'guest' => [600 - $boxW / 2, $boxTop, $boxW, $boxH],
                'qr' => [600 - $size / 2, $qrTop, $size], 'divider' => null];
        }
        $qrX = $position === 'left' ? $inset : Canvas::WIDTH - $inset - $size;
        $boxW = Canvas::WIDTH - 2 * $inset - $size - 50;
        $boxH = 140;
        $boxX = $position === 'left' ? $inset + $size + 50 : $inset;
        $boxY = $qrTop + ($size - $boxH) / 2;

        return ['top' => $qrTop - 10, 'position' => $position, 'guest' => [$boxX, $boxY, $boxW, $boxH], 'qr' => [$qrX, $qrTop, $size],
            'divider' => $position === 'left' ? $qrX + $size + 25 : $qrX - 25];
    }

    // ----- Base layers -------------------------------------------------------------

    private function background(): void
    {
        $colors = $this->spec['colors'];
        $bg = $colors['bg'];
        $this->c->fill($bg);
        switch ($this->spec['background'] ?? 'solid') {
            case 'gradient':
                $this->c->verticalGradient(0, 0, Canvas::WIDTH, Canvas::HEIGHT, $bg, Canvas::mix($bg, $colors['decor'], 0.16));
                break;
            case 'vignette':
                $this->c->vignette(Canvas::mix($bg, '#000000', 0.45), 0.55);
                break;
            case 'glow':
                $this->c->vignette(Canvas::mix($bg, $colors['decor'], 0.3), 0.6);
                break;
            case 'split':
                $this->c->rect(0, 0, Canvas::WIDTH, 560, $colors['bg2']);
                $this->c->rect(0, 560, Canvas::WIDTH, 6, $colors['border']);
                break;
        }
        if (! empty($this->spec['texture'])) {
            $this->texture();
        }
    }

    private function texture(): void
    {
        static $tile = null;
        if ($tile === null) {
            $tile = imagecreatetruecolor(192, 192);
            imagealphablending($tile, false);
            imagesavealpha($tile, true);
            mt_srand(7);
            for ($y = 0; $y < 192; $y++) {
                for ($x = 0; $x < 192; $x++) {
                    $v = mt_rand(0, 255);
                    imagesetpixel($tile, $x, $y, imagecolorallocatealpha($tile, $v, $v, $v, 121 - mt_rand(0, 3)));
                }
            }
            mt_srand();
        }
        $w = imagesx($this->c->image);
        $h = imagesy($this->c->image);
        for ($y = 0; $y < $h; $y += 192) {
            for ($x = 0; $x < $w; $x += 192) {
                imagecopy($this->c->image, $tile, $x, $y, 0, 0, 192, 192);
            }
        }
    }

    private function panel(array $panel): void
    {
        [$x, $y, $w, $h] = $panel['rect'];
        $colors = $this->spec['colors'];
        $shadow = Canvas::mix($colors['bg'], '#000000', 0.35);
        match ($panel['shape']) {
            'arch' => [$this->c->arch($x + 6, $y + 10, $w, $h, $shadow, 0.25), $this->c->arch($x, $y, $w, $h, $colors['bg2']), $this->c->arch($x + 16, $y + 16, $w - 32, $h - 32, $colors['border'], 1, 2.4)],
            'chamfer' => [$this->c->chamferRect($x + 6, $y + 10, $w, $h, 60, $shadow, 0.25), $this->c->chamferRect($x, $y, $w, $h, 60, $colors['bg2']), $this->c->chamferRect($x + 16, $y + 16, $w - 32, $h - 32, 50, $colors['border'], 1, 2.4)],
            default => [$this->c->roundedRect($x + 6, $y + 10, $w, $h, 36, $shadow, 0.25), $this->c->roundedRect($x, $y, $w, $h, 36, $colors['bg2']), $this->c->roundedRect($x + 16, $y + 16, $w - 32, $h - 32, 26, $colors['border'], 1, 2.4)],
        };
    }

    private ?string $regionSurface = null;

    private function surface(): string
    {
        if ($this->regionSurface !== null) {
            return $this->regionSurface;
        }

        return in_array($this->spec['layout'], ['panel', 'arch_window'], true) ? $this->spec['colors']['bg2'] : $this->spec['colors']['bg'];
    }

    private function decor(): void
    {
        if (empty($this->spec['decor_enabled'])) {
            return;
        }
        $dark = Canvas::luminance($this->spec['colors']['bg']) < 0.3;
        foreach ($this->spec['decor'] ?? [] as $index => $item) {
            // Owner overrides per piece: hide, move (dx/dy) and resize around its centre.
            $override = $this->spec['decor_items'][(string) $index] ?? [];
            if (! empty($override['hidden'])) {
                continue;
            }
            $size = (float) ($override['size'] ?? 1.0);
            $moveX = (float) ($override['dx'] ?? 0);
            $moveY = (float) ($override['dy'] ?? 0);
            if (($item['type'] ?? 'art') === 'sprig') {
                $len = $item['len'] * $size;
                $sx = max(0, min(Canvas::WIDTH, $item['x'] + $moveX));
                $sy = max(0, min(Canvas::HEIGHT, $item['y'] + $moveY));
                $this->o->sprig($sx, $sy, $len, $item['angle'], $this->spec['colors']['decor'], $item['opacity'] ?? 0.9);
                $this->layoutMap['decor.'.$index] = [$sx - $len / 2, $sy - $len / 2, $len, $len, 'decor'];

                continue;
            }
            $asset = $item['asset'];
            $meta = self::decorManifest()[$asset] ?? null;
            if ($meta === null) {
                $this->warnings[] = 'artwork_missing';

                continue;
            }
            if ($dark && ! in_array($asset, self::DARK_SAFE, true)) {
                $this->warnings[] = 'decor_hidden_dark';

                continue;
            }
            // A missing or unreadable artwork file never breaks the card: the piece is left out.
            $file = resource_path('invitation/decor/'.$asset.'.png');
            $image = self::$decorCache[$asset] ?? (is_file($file) ? @imagecreatefrompng($file) : false);
            if (! $image instanceof GdImage) {
                $this->warnings[] = 'artwork_missing';

                continue;
            }
            self::$decorCache[$asset] = $image;
            $w = (float) $item['w'];
            $h = $w * $meta['height'] / $meta['width'];
            $anchor = $item['anchor'];
            $dx = (float) ($item['dx'] ?? 0);
            $dy = (float) ($item['dy'] ?? 0);
            $x = match ($anchor) {
                'tl', 'bl', 'l' => $dx,
                'tr', 'br', 'r' => Canvas::WIDTH - $w - $dx,
                default => (Canvas::WIDTH - $w) / 2 + $dx,
            };
            $y = match ($anchor) {
                'tl', 'tr', 'top' => $dy,
                'bl', 'br', 'bottom' => Canvas::HEIGHT - $h - $dy,
                default => (Canvas::HEIGHT - $h) / 2 + $dy,
            };
            // Resize around the piece's centre, then move; keep at least a corner on the card.
            $cx = $x + $w / 2 + $moveX;
            $cy = $y + $h / 2 + $moveY;
            $w *= $size;
            $h *= $size;
            $x = max(-$w * 0.75, min(Canvas::WIDTH - $w * 0.25, $cx - $w / 2));
            $y = max(-$h * 0.75, min(Canvas::HEIGHT - $h * 0.25, $cy - $h / 2));
            $this->c->image($image, $x, $y, $w, $h, (bool) ($item['flip_x'] ?? false), (bool) ($item['flip_y'] ?? false));
            $this->layoutMap['decor.'.$index] = [$x, $y, $w, $h, 'decor'];
        }
    }

    /** Assets that read well on dark grounds (gold foliage and the burgundy roses). */
    public const DARK_SAFE = ['gold_leaf_left', 'gold_sprig_tr', 'rose_burgundy_tl', 'rose_burgundy_br', 'peony_br', 'lavender_right'];

    public static function decorManifest(): array
    {
        static $manifest = null;

        return $manifest ??= json_decode((string) file_get_contents(resource_path('invitation/decor/manifest.json')), true) ?: [];
    }

    /**
     * The photo frame inside the template's slot for the chosen shape. Band slots keep
     * framed shapes in the upper centre so the text below stays where it was designed.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function photoRect(array $slot, string $slotShape, string $shape): array
    {
        [$x, $y, $w, $h] = $slot;
        if ($slotShape === 'band' && $shape !== 'band') {
            [$x, $y, $w, $h] = [370, 70, 460, 500];
        }

        return match ($shape) {
            'circle', 'square' => [$x + ($w - min($w, $h)) / 2, $y, min($w, $h), min($w, $h)],
            'oval' => [$x + ($w - min($w, $h * 0.78)) / 2, $y, min($w, $h * 0.78), $h],
            default => [$x, $y, $w, $h],
        };
    }

    private function photo(array $photo, array $options): void
    {
        $shape = $this->spec['photo']['shape'] ?? $photo['shape'];
        [$x, $y, $w, $h] = $this->photoRect($photo['rect'], $photo['shape'], $shape);
        // Owner arrangement: resize around the centre, move, keep on the card and clear of the QR.
        $arrange = $this->spec['arrange']['photo'] ?? [];
        if ($arrange !== [] && $shape !== 'band') {
            $size = (float) ($arrange['size'] ?? 1.0);
            $cx = $x + $w / 2;
            $cy = $y + $h / 2;
            $w *= $size;
            $h *= $size;
            [$x, $y] = $this->place($cx - $w / 2, $cy - $h / 2, $w, $h, (float) ($arrange['dx'] ?? 0), (float) ($arrange['dy'] ?? 0), true);
        }
        $this->layoutMap['photo'] = [$x, $y, $w, $h, 'photo'];
        $colors = $this->spec['colors'];
        $span = $this->shapeSpan($shape, $x, $y, $w, $h);
        $file = $this->spec['photo']['file'] ?? null;
        $source = null;
        if (is_string($file) && is_file($file)) {
            $source = @imagecreatefromstring((string) file_get_contents($file)) ?: null;
        }

        if ($source !== null) {
            $this->c->clippedImage($source, $x, $y, $w, $h, (float) ($this->spec['photo']['zoom'] ?? 1), (float) ($this->spec['photo']['x'] ?? 0.5), (float) ($this->spec['photo']['y'] ?? 0.5), $span);
            imagedestroy($source);
        } else {
            $fill = Canvas::mix($colors['bg2'], $colors['accent'], 0.18);
            $this->fillShape($shape, $x, $y, $w, $h, $fill);
            $initials = $this->spec['text']['initials'] ?? '';
            $this->c->text($initials, $this->font('heading', 'regular'), 70, $x + $w / 2, $y + $h * ($shape === 'band' ? 0.55 : 0.6), $colors['text'], 'center', 0.05, 0.75);
            if (! empty($options['hints'])) {
                $this->c->text(mb_strtoupper($this->spec['text']['photo_hint'] ?? ''), $this->font('label', 'bold'), 15, $x + $w / 2, $y + $h * ($shape === 'band' ? 0.68 : 0.74), $colors['text2'], 'center', 0.2);
            }
            $this->warnings[] = 'photo_missing';
        }

        if ($shape === 'band') {
            $this->c->fadeBand(0, $y + $h - 230, Canvas::WIDTH, 230, $colors['bg']);
            $this->c->rect(0, $y + $h - 2, Canvas::WIDTH, 2, $colors['bg']);

            return;
        }
        $border = $this->spec['photo']['border'] ?? 'double';
        if ($border === 'none') {
            return;
        }
        $stroke = $colors['border'];
        [$gap, $width] = $border === 'thick' ? [9.0, 8.0] : [10.0, 3.0];
        $this->photoOutline($shape, $x - $gap, $y - $gap, $w + 2 * $gap, $h + 2 * $gap, $stroke, $width, 1.0);
        if ($border === 'double') {
            $this->photoOutline($shape, $x, $y, $w, $h, $stroke, 1.6, 0.8);
        }
    }

    private function photoOutline(string $shape, float $x, float $y, float $w, float $h, string $color, float $width, float $opacity): void
    {
        match ($shape) {
            'arch' => $this->c->arch($x, $y, $w, $h, $color, $opacity, $width),
            'circle', 'oval' => $this->c->polyline(Canvas::arcPoints($x + $w / 2, $y + $h / 2, $w / 2, $h / 2, 0, 360, 96), $width, $color, $opacity, true),
            'rounded' => $this->c->roundedRect($x, $y, $w, $h, 40, $color, $opacity, $width),
            default => $this->c->polyline([[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]], $width, $color, $opacity, true),
        };
    }

    /** @return callable(float): ?array{0: float, 1: float} */
    private function shapeSpan(string $shape, float $x, float $y, float $w, float $h): callable
    {
        return match ($shape) {
            'arch' => function (float $py) use ($x, $y, $w, $h): ?array {
                if ($py < $y || $py > $y + $h) {
                    return null;
                }
                $r = $w / 2;
                if ($py >= $y + $r) {
                    return [$x, $x + $w];
                }
                $dy = $y + $r - $py;
                $dx = sqrt(max(0.0, $r * $r - $dy * $dy));

                return [$x + $r - $dx, $x + $r + $dx];
            },
            'circle', 'oval' => function (float $py) use ($x, $y, $w, $h): ?array {
                $ry = $h / 2;
                $dy = ($py - ($y + $ry)) / $ry;
                if (abs($dy) > 1) {
                    return null;
                }
                $dx = $w / 2 * sqrt(1 - $dy * $dy);

                return [$x + $w / 2 - $dx, $x + $w / 2 + $dx];
            },
            'rounded' => function (float $py) use ($x, $y, $w, $h): ?array {
                if ($py < $y || $py > $y + $h) {
                    return null;
                }
                $r = min(40.0, $w / 2, $h / 2);
                $edge = min($py - $y, $y + $h - $py);
                $inset = $edge >= $r ? 0.0 : $r - sqrt(max(0.0, $r * $r - ($r - $edge) ** 2));

                return [$x + $inset, $x + $w - $inset];
            },
            default => fn (float $py): ?array => $py < $y || $py > $y + $h ? null : [$x, $x + $w],
        };
    }

    private function fillShape(string $shape, float $x, float $y, float $w, float $h, string $color): void
    {
        match ($shape) {
            'arch' => $this->c->arch($x, $y, $w, $h, $color),
            'circle', 'oval' => $this->c->ellipse($x + $w / 2, $y + $h / 2, $w / 2, $h / 2, $color),
            'rounded' => $this->c->roundedRect($x, $y, $w, $h, 40, $color),
            default => $this->c->rect($x, $y, $w, $h, $color),
        };
    }

    private function footerBase(array $footer): void
    {
        $colors = $this->spec['colors'];
        [$bx, $by, $bw, $bh] = $footer['guest'];
        $label = mb_strtoupper($this->spec['text']['guest_label'] ?? '');
        $fill = $this->guestFill();
        $labelColor = $this->readable($colors['accent'], $fill);
        switch ($this->spec['guest_style'] ?? 'box') {
            case 'ribbon':
                $this->c->roundedRect($bx, $by, $bw, $bh, 18, $fill);
                $this->c->roundedRect($bx + 8, $by + 8, $bw - 16, $bh - 16, 12, $this->readable($colors['bg'], $fill), 0.7, 1.4);
                $labelColor = $this->readable($colors['bg'], $fill);
                break;
            case 'plaque':
                $this->c->chamferRect($bx, $by, $bw, $bh, 22, $fill);
                $this->c->chamferRect($bx, $by, $bw, $bh, 22, $colors['border'], 1, 2.4);
                break;
            case 'line':
                $this->c->line($bx + 40, $by + $bh - 4, $bx + $bw - 40, $by + $bh - 4, 1.4, $colors['border'], 0.8);
                break;
            default:
                $this->c->roundedRect($bx, $by, $bw, $bh, 22, $fill);
                $this->c->roundedRect($bx, $by, $bw, $bh, 22, $colors['border'], 1, 2.2);
        }
        $this->c->text($label, $this->font('label', 'bold'), 13.5, $bx + $bw / 2, $by + 32, $labelColor, 'center', 0.24);
        if ($footer['divider'] !== null) {
            $this->c->line($footer['divider'], $by + 6, $footer['divider'], $by + $bh - 6, 1.6, $colors['border'], 0.8);
        }

        // Protected QR tile: opaque white with a border; nothing is drawn over it later.
        [$qx, $qy, $qs] = $footer['qr'];
        $this->c->roundedRect($qx - 8, $qy - 8, $qs + 16, $qs + 16, 18, $colors['border']);
        $this->c->roundedRect($qx - 4, $qy - 4, $qs + 8, $qs + 8, 15, '#ffffff');
    }

    // ----- Text flow -------------------------------------------------------------

    private function flowRegions(array $geometry): void
    {
        $original = $this->spec['colors'];
        foreach ($geometry['regions'] as $region) {
            [$x, $y, $w, $h, $blocks] = $region;
            $this->regionSurface = $region[5] ?? null;
            $this->spec['colors'] = $original;
            if ($this->regionSurface !== null) {
                // Text inside a light medallion on a dark card: use readable palette colours.
                foreach (['text', 'text2', 'accent'] as $role) {
                    $this->spec['colors'][$role] = $this->readable($original[$role], $this->regionSurface);
                }
            }
            // Hidden blocks leave the flow entirely so the remaining blocks re-space.
            $blocks = array_values(array_filter($blocks, fn (string $block) => $this->blockHasContent($block)
                && empty($this->spec['arrange']['blocks'][$block]['hidden'])));
            $scale = (float) ($this->spec['font_scale'] ?? 1.0);
            $heights = [];
            $attempts = 0;
            while (true) {
                $this->scale = $scale;
                $heights = array_map(fn ($block) => $this->block($block, $x, 0, $w, false), $blocks);
                $minGap = 14 * $scale;
                $total = array_sum($heights) + $minGap * max(0, count($blocks) - 1);
                if ($total <= $h || $attempts > 30) {
                    break;
                }
                $attempts++;
                if ($scale > 0.62) {
                    $scale *= 0.96;
                    $this->warnings[] = 'text_shrunk';

                    continue;
                }
                // Still too tall: drop the least essential optional block.
                foreach (['note', 'message', 'motif', 'intro', 'divider', 'hosts'] as $optional) {
                    $index = array_search($optional, $blocks, true);
                    if ($index !== false) {
                        array_splice($blocks, $index, 1);
                        $this->warnings[] = 'text_dropped';
                        break;
                    }
                }
            }
            $count = count($blocks);
            if ($count === 0) {
                continue;
            }
            $content = array_sum($heights);
            $gap = $count > 1 ? min(64 * $this->scale, max(14 * $this->scale, ($h - $content) / ($count + 1))) : 0;
            $used = $content + $gap * ($count - 1);
            $cursor = $y + max(0, ($h - $used) / 2);
            foreach ($blocks as $i => $block) {
                $this->block($block, $x, $cursor, $w, true);
                $cursor += $heights[$i] + $gap;
            }
        }
        $this->spec['colors'] = $original;
        $this->regionSurface = null;
    }

    private function blockHasContent(string $block): bool
    {
        $t = $this->spec['text'];

        return match ($block) {
            'label' => filled($t['label'] ?? null),
            'hosts' => filled($t['host_one'] ?? null) || filled($t['host_two'] ?? null) || filled($t['host_line'] ?? null),
            'intro' => filled($t['intro'] ?? null),
            'names' => filled($t['names'] ?? null),
            'message' => filled($t['message'] ?? null),
            'date', 'date_hero' => filled($t['day'] ?? null),
            'venue' => ($this->spec['date_style'] ?? '') !== 'icons'
                && (filled($t['venue'] ?? null) || (filled($t['time'] ?? null) && ! $this->dateIncludesTime())),
            'extras' => filled($t['dress_code'] ?? null),
            'note' => filled($t['note'] ?? null),
            'monogram' => filled($t['initials'] ?? null),
            'motif' => ($this->spec['motif'] ?? 'none') !== 'none',
            default => true,
        };
    }

    private function dateIncludesTime(): bool
    {
        return in_array($this->spec['date_style'] ?? 'big_day', ['icons', 'line'], true);
    }

    /**
     * Measures or draws one block with the owner's per-block size, then (when drawing)
     * applies the owner's move, keeping the block on the card and clear of the QR.
     */
    private function block(string $block, float $x, float $y, float $w, bool $draw): float
    {
        $arrange = $this->spec['arrange']['blocks'][$block] ?? [];
        $saved = $this->scale;
        $this->scale = $saved * (float) ($arrange['size'] ?? 1.0);
        try {
            $h = $this->blockContent($block, $x, $y, $w, false);
            if (! $draw) {
                return $h;
            }
            [$nx, $ny] = $this->place($x, $y, $w, $h, (float) ($arrange['dx'] ?? 0), (float) ($arrange['dy'] ?? 0), false);
            $this->blockContent($block, $nx, $ny, $w, true);
            $this->layoutMap[$block] = [$nx, $ny, $w, $h, 'text'];

            return $h;
        } finally {
            $this->scale = $saved;
        }
    }

    /**
     * Applies a move to an element rect: clamps to the card margins and, if it would
     * cover the QR tile or guest box, lifts it above them (the QR always stays readable).
     *
     * @return array{0: float, 1: float}
     */
    private function place(float $x, float $y, float $w, float $h, float $dx, float $dy, bool $force): array
    {
        if (! $force && $dx == 0.0 && $dy == 0.0) {
            return [$x, $y];
        }
        $nx = max(20.0, min(Canvas::WIDTH - 20.0 - $w, $x + $dx));
        $ny = max(20.0, min(Canvas::HEIGHT - 20.0 - $h, $y + $dy));
        // Lift above every protected zone it touches; re-check, because lifting clear of
        // the QR can land it on the guest box stacked just above.
        for ($pass = 0; $pass < 4; $pass++) {
            $lifted = false;
            foreach ($this->protected as [$px, $py, $pw, $ph]) {
                if ($nx < $px + $pw && $nx + $w > $px && $ny < $py + $ph && $ny + $h > $py) {
                    $ny = max(20.0, $py - 14.0 - $h);
                    $lifted = true;
                    $this->warnings[] = 'moved_clear_of_qr';
                }
            }
            if (! $lifted) {
                break;
            }
        }

        return [$nx, $ny];
    }

    /** Warns when an element the owner moved or resized now sits on top of other text. */
    private function checkOverlaps(): void
    {
        $changed = array_keys(array_filter($this->spec['arrange']['blocks'] ?? [], fn ($a) => isset($a['dx']) || isset($a['dy']) || isset($a['size'])));
        if (($this->spec['arrange']['photo'] ?? []) !== []) {
            $changed[] = 'photo';
        }
        $rects = array_filter($this->layoutMap, fn ($r) => in_array($r[4], ['text', 'photo'], true));
        foreach ($changed as $key) {
            if (! isset($rects[$key])) {
                continue;
            }
            [$ax, $ay, $aw, $ah] = $rects[$key];
            foreach ($rects as $other => [$bx, $by, $bw, $bh]) {
                if ($other !== $key && $ax + 6 < $bx + $bw && $ax + $aw - 6 > $bx && $ay + 6 < $by + $bh && $ay + $ah - 6 > $by) {
                    $this->warnings[] = 'elements_overlap';

                    return;
                }
            }
        }
    }

    private function protectFooter(array $footer): void
    {
        [$bx, $by, $bw, $bh] = $footer['guest'];
        [$qx, $qy, $qs] = $footer['qr'];
        $this->protected = [[$bx, $by, $bw, $bh], [$qx - 10, $qy - 10, $qs + 20, $qs + 70]];
        $this->layoutMap['guest'] = [$bx, $by, $bw, $bh, 'protected'];
        $this->layoutMap['qr'] = [$qx - 10, $qy - 10, $qs + 20, $qs + 70, 'protected'];
    }

    /** Measures (draw = false) or draws one block at top $y. Returns its height. */
    private function blockContent(string $block, float $x, float $y, float $w, bool $draw): float
    {
        $s = $this->scale;
        $t = $this->spec['text'];
        $colors = $this->spec['colors'];
        $left = ($this->spec['align'] ?? 'center') === 'left';
        $ax = $left ? $x : $x + $w / 2;
        $align = $left ? 'left' : 'center';
        $surface = $this->surface();

        switch ($block) {
            case 'motif':
                $size = 64 * $s;
                if ($draw) {
                    $this->o->icon($this->spec['motif'], $left ? $x + $size / 2 : $ax, $y + $size / 2, $size, $colors['accent'], $surface);
                }

                return $size;
            case 'monogram':
                $r = 96 * $s;
                if ($draw) {
                    $cx = $left ? $x + $r : $ax;
                    $this->c->ring($cx, $y + $r, $r, 3 * $s, $colors['border']);
                    $this->c->ring($cx, $y + $r, $r - 12 * $s, 1.2 * $s, $colors['border'], 0.7);
                    $this->c->text($t['initials'], $this->font('heading', 'regular'), 58 * $s, $cx, $y + $r + 22 * $s, $colors['text'], 'center');
                }

                return $r * 2;
            case 'label':
                return $this->paragraph(mb_strtoupper($t['label']), 'label', 'bold', 21, 0.3, $colors['accent'], $x, $y, $w, $draw, 1, $align);
            case 'intro':
                return $this->paragraph($t['intro'], 'body', 'italic', 26, 0, $colors['text2'], $x, $y, $w, $draw, 3, $align);
            case 'message':
                return $this->paragraph($t['message'], 'body', 'italic', 24, 0, $colors['text2'], $x, $y, $w, $draw, 3, $align);
            case 'note':
                return $this->paragraph($t['note'], 'body', 'regular', 20, 0, $colors['text2'], $x, $y, $w, $draw, 2, $align);
            case 'names':
                return $this->names($x, $y, $w, $draw);
            case 'divider':
                $height = 46 * $s;
                if ($draw) {
                    $width = min($w, 430 * $s);
                    $this->o->divider($this->spec['divider'] ?? 'line', $left ? $x + $width / 2 : $ax, $y + $height / 2, $width, $s, $colors['border'], $colors['accent'], $surface);
                }

                return $height;
            case 'hosts':
                return $this->hosts($x, $y, $w, $draw);
            case 'date':
                return $this->date($x, $y, $w, $draw);
            case 'date_hero':
                return $this->dateHero($x, $y, $w, $draw);
            case 'venue':
                $height = 0.0;
                if (filled($t['venue'] ?? null)) {
                    $height += $this->paragraph(mb_strtoupper($t['venue']), 'label', 'bold', 25, 0.14, $colors['text'], $x, $y, $w, $draw, 2, $align);
                }
                if (filled($t['location'] ?? null)) {
                    $height += 6 * $s + $this->paragraph(mb_strtoupper($t['location']), 'label', 'regular', 17, 0.2, $colors['text2'], $x, $y + $height + 6 * $s, $w, $draw, 2, $align);
                }
                if (filled($t['time'] ?? null) && ! $this->dateIncludesTime()) {
                    $height += 10 * $s + $this->paragraph(mb_strtoupper($t['time']), 'label', 'bold', 19, 0.2, $colors['accent'], $x, $y + $height + 10 * $s, $w, $draw, 1, $align);
                }

                return $height;
            case 'extras':
                $text = ($t['dress_code_label'] ?? '').': '.$t['dress_code'];
                $font = $this->font('label', 'bold');
                $size = 16 * $s;
                $tw = min($w - 40, $this->c->textWidth(mb_strtoupper($text), $font, $size, 0.14));
                $height = 52 * $s;
                if ($draw) {
                    $pw = $tw + 70 * $s;
                    $px = $left ? $x : $ax - $pw / 2;
                    $this->c->roundedRect($px, $y, $pw, $height, $height / 2, $colors['accent']);
                    $label = mb_strtoupper($text);
                    if ($this->c->textWidth($label, $font, $size, 0.14) > $w - 40) {
                        $label = $this->ellipsize($label, $font, $size, $w - 40, 0.14);
                    }
                    $this->c->text($label, $font, $size, $px + $pw / 2, $y + $height / 2 + $size * 0.45, $surface, 'center', 0.14);
                }

                return $height;
        }

        return 0.0;
    }

    private function paragraph(string $text, string $role, string $weight, float $size, float $tracking, string $color, float $x, float $y, float $w, bool $draw, int $maxLines, string $align): float
    {
        $font = $this->font($role, $weight);
        $size *= $this->scale;
        $lines = $this->c->wrap($text, $font, $size, $w, $tracking);
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = $this->ellipsize($lines[$maxLines - 1].' …', $font, $size, $w, $tracking);
            $this->warnings[] = 'text_truncated';
        }
        [$ascent, $descent] = Canvas::fontMetrics($font);
        $lineHeight = ($ascent + $descent) * $size * 1.08;
        if ($draw) {
            $ax = $align === 'left' ? $x : $x + $w / 2;
            foreach ($lines as $i => $line) {
                $this->c->text($line, $font, $size, $ax, $y + $ascent * $size + $i * $lineHeight, $color, $align, $tracking);
            }
        }

        return count($lines) * $lineHeight;
    }

    private function names(float $x, float $y, float $w, bool $draw): float
    {
        $t = $this->spec['text'];
        $colors = $this->spec['colors'];
        $role = $this->spec['names_font'] ?? 'heading';
        $font = $this->font($role, $role === 'heading' ? 'regular' : 'regular');
        $script = $this->isScript($role);
        $size = ($script ? 92 : 74) * $this->scale;
        $names = trim($t['names']);
        $stacked = ($this->spec['names_layout'] ?? 'inline') === 'stacked';
        $parts = preg_split('/\s+&\s+/u', $names, 2);
        $hasAmp = count($parts) === 2;
        $left = ($this->spec['align'] ?? 'center') === 'left';
        [$ascent, $descent] = Canvas::fontMetrics($font);

        if ($stacked && $hasAmp) {
            $lines = [[$parts[0], false], ['&', true], [$parts[1], false]];
        } elseif ($this->c->textWidth($names, $font, $size) <= $w) {
            $lines = [[$names, false]];
        } elseif ($hasAmp) {
            $lines = [[$parts[0], false], ['& '.$parts[1], false]];
        } else {
            $lines = array_map(fn ($line) => [$line, false], $this->c->wrap($names, $font, $size, $w));
        }
        // Shrink until every line fits the safe width.
        $widest = fn () => max(array_map(fn ($line) => $this->c->textWidth($line[0], $font, $line[1] ? $size * 0.7 : $size), $lines));
        $initial = $size;
        while ($size > 30 && $widest() > $w) {
            $size *= 0.95;
        }
        if ($size < $initial * 0.75) {
            $this->warnings[] = 'text_shrunk';
        }
        if (count($lines) > 3) {
            $lines = array_slice($lines, 0, 3);
            $this->warnings[] = 'text_truncated';
        }
        // Script faces report tall ascenders/descenders; tighten so stacked names stay together.
        $lineHeight = ($ascent + $descent) * $size * ($script ? 0.78 : 1.02);
        if ($draw) {
            foreach ($lines as $i => [$line, $isAmp]) {
                $baseline = $y + $ascent * $size + $i * $lineHeight;
                $lineSize = $isAmp ? $size * 0.7 : $size;
                if ($isAmp) {
                    $this->c->text('&', $font, $lineSize, $left ? $x + 20 : $x + $w / 2, $baseline - $lineHeight * 0.12, $colors['accent'], $left ? 'left' : 'center');

                    continue;
                }
                $this->ampLine($line, $font, $lineSize, $left ? $x : $x + $w / 2, $baseline, $left ? 'left' : 'center');
            }
        }

        return count($lines) * $lineHeight;
    }

    /** Draws a names line with any "&" in the accent colour. */
    private function ampLine(string $line, string $font, float $size, float $x, float $baseline, string $align): void
    {
        $colors = $this->spec['colors'];
        $pieces = preg_split('/(&)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $total = $this->c->textWidth($line, $font, $size);
        $cursor = $align === 'center' ? $x - $total / 2 : $x;
        foreach ($pieces as $piece) {
            $this->c->text($piece, $font, $size, $cursor, $baseline, $piece === '&' ? $colors['accent'] : $colors['text']);
            $cursor += $this->c->textWidth($piece, $font, $size);
        }
    }

    private function hosts(float $x, float $y, float $w, bool $draw): float
    {
        $t = $this->spec['text'];
        $colors = $this->spec['colors'];
        $s = $this->scale;
        $height = 0.0;
        $align = ($this->spec['align'] ?? 'center') === 'left' ? 'left' : 'center';
        $hasColumns = filled($t['host_one'] ?? null) || filled($t['host_two'] ?? null);
        if (filled($t['host_line'] ?? null) && (($this->spec['hosts_style'] ?? 'lines') === 'lines' || ! $hasColumns)) {
            return $this->paragraph(mb_strtoupper($t['host_line']), 'label', 'bold', 20, 0.1, $colors['text'], $x, $y, $w, $draw, 3, $align);
        }
        if (filled($t['hosts_heading'] ?? null)) {
            $height += $this->paragraph(mb_strtoupper($t['hosts_heading']), 'label', 'regular', 15, 0.28, $colors['text2'], $x, $y, $w, $draw, 1, $align) + 14 * $s;
        }
        $columns = array_values(array_filter([
            [$t['host_one_title'] ?? '', $t['host_one'] ?? ''],
            [$t['host_two_title'] ?? '', $t['host_two'] ?? ''],
        ], fn ($column) => filled($column[1])));
        if ($columns === []) {
            return $height;
        }
        $colW = count($columns) === 2 ? ($w - 40 * $s) / 2 : $w;
        $max = 0.0;
        foreach ($columns as $i => [$title, $name]) {
            $cx = count($columns) === 2 ? $x + $i * ($colW + 40 * $s) : $x;
            $h = 0.0;
            if (filled($title)) {
                $h += $this->paragraph($title, 'body', 'italic', 21, 0, $colors['accent'], $cx, $y + $height, $colW, $draw, 2, 'center') + 4 * $s;
            }
            $h += $this->paragraph($name, 'body', 'regular', 22, 0, $colors['text'], $cx, $y + $height + $h, $colW, $draw, 2, 'center');
            $max = max($max, $h);
        }
        if ($draw && count($columns) === 2) {
            $this->c->line($x + $w / 2, $y + $height, $x + $w / 2, $y + $height + $max, 1.4, $colors['border'], 0.8);
        }

        return $height + $max;
    }

    private function date(float $x, float $y, float $w, bool $draw): float
    {
        $t = $this->spec['text'];
        $colors = $this->spec['colors'];
        $s = $this->scale;
        $style = $this->spec['date_style'] ?? 'big_day';
        $left = ($this->spec['align'] ?? 'center') === 'left';
        $cx = $left ? $x : $x + $w / 2;
        $heading = $this->font('heading', 'regular');
        $label = $this->font('label', 'bold');
        $labelRegular = $this->font('label', 'regular');
        $surface = $this->surface();

        switch ($style) {
            case 'row':
                $daySize = 82 * $s;
                [$asc] = Canvas::fontMetrics($heading);
                $h = $asc * $daySize + 16 * $s;
                if ($draw) {
                    $dayW = $this->c->textWidth($t['day'], $heading, $daySize);
                    $base = $y + $asc * $daySize;
                    $this->c->text($t['day'], $heading, $daySize, $x + $w / 2, $base, $colors['accent'], 'center');
                    $gap = $dayW / 2 + 36 * $s;
                    $this->c->line($x + $w / 2 - $gap + 14 * $s, $y + 10 * $s, $x + $w / 2 - $gap + 14 * $s, $base, 1.6, $colors['border']);
                    $this->c->line($x + $w / 2 + $gap - 14 * $s, $y + 10 * $s, $x + $w / 2 + $gap - 14 * $s, $base, 1.6, $colors['border']);
                    $this->c->text(mb_strtoupper($t['weekday'] ?? ''), $label, 21 * $s, $x + $w / 2 - $gap - 10 * $s, $base - $daySize * 0.32, $colors['text'], 'right', 0.16);
                    $this->c->text(mb_strtoupper($t['month']), $label, 21 * $s, $x + $w / 2 + $gap + 10 * $s, $base - $daySize * 0.45, $colors['text'], 'left', 0.16);
                    $this->c->text($t['year'], $labelRegular, 18 * $s, $x + $w / 2 + $gap + 10 * $s, $base - $daySize * 0.08, $colors['text2'], 'left', 0.3);
                }

                return $h;
            case 'icons':
                $cols = [['calendar', $t['date_short'] ?? '', $t['weekday'] ?? ''], ['clock', $t['time'] ?? '', $t['time_label'] ?? ''], ['pin', $t['venue'] ?? '', $t['location'] ?? '']];
                $cols = array_values(array_filter($cols, fn ($col) => filled($col[1])));
                $colW = $w / max(1, count($cols));
                $max = 0.0;
                foreach ($cols as $i => [$icon, $main, $sub]) {
                    $colX = $x + $i * $colW;
                    $h = 54 * $s;
                    if ($draw) {
                        $this->o->icon($icon, $colX + $colW / 2, $y + 22 * $s, 40 * $s, $colors['accent'], $surface);
                    }
                    if (filled($sub)) {
                        $h += $this->paragraph(mb_strtoupper($sub), 'label', 'regular', 14, 0.18, $colors['text2'], $colX + 10, $y + $h, $colW - 20, $draw, 1, 'center') + 4 * $s;
                    }
                    $h += $this->paragraph(mb_strtoupper($main), 'label', 'bold', 19, 0.08, $colors['text'], $colX + 10, $y + $h, $colW - 20, $draw, 3, 'center');
                    $max = max($max, $h);
                    if ($draw && $i > 0) {
                        $this->c->line($colX, $y + 6 * $s, $colX, $y + $max, 1.4, $colors['border'], 0.8);
                    }
                }

                return $max;
            case 'line':
                $h = $this->paragraph($t['date_long'], 'heading', 'regular', 34, 0, $colors['text'], $x, $y, $w, $draw, 2, $left ? 'left' : 'center');
                if (filled($t['time'] ?? null)) {
                    $h += 8 * $s + $this->paragraph(mb_strtoupper($t['time']), 'label', 'bold', 19, 0.22, $colors['accent'], $x, $y + $h + 8 * $s, $w, $draw, 1, $left ? 'left' : 'center');
                }

                return $h;
            default: // big_day
                $daySize = 96 * $s;
                [$asc] = Canvas::fontMetrics($heading);
                $h = $asc * $daySize + 6 * $s;
                if ($draw) {
                    $dayW = $this->c->textWidth($t['day'], $heading, $daySize);
                    $monthW = max($this->c->textWidth(mb_strtoupper($t['month']), $label, 22 * $s, 0.2), $this->c->textWidth($t['year'], $label, 22 * $s, 0.3));
                    $total = $dayW + 22 * $s + $monthW;
                    $start = $left ? $x : $cx - $total / 2;
                    $base = $y + $asc * $daySize;
                    $this->c->text($t['day'], $heading, $daySize, $start, $base, $colors['text']);
                    $this->c->text(mb_strtoupper($t['month']), $label, 22 * $s, $start + $dayW + 22 * $s, $base - $daySize * 0.5, $colors['accent'], 'left', 0.2);
                    $this->c->text($t['year'], $label, 22 * $s, $start + $dayW + 22 * $s, $base - $daySize * 0.08, $colors['accent'], 'left', 0.3);
                }

                return $h;
        }
    }

    private function dateHero(float $x, float $y, float $w, bool $draw): float
    {
        $t = $this->spec['text'];
        $colors = $this->spec['colors'];
        $s = $this->scale;
        $heading = $this->font('heading', 'regular');
        $size = 210 * $s;
        [$asc] = Canvas::fontMetrics($heading);
        $h = $this->paragraph(mb_strtoupper($t['weekday'] ?? ''), 'label', 'bold', 18, 0.4, $colors['text2'], $x, $y, $w, $draw, 1, 'center');
        $h += 10 * $s;
        if ($draw) {
            $this->c->text($t['day'], $heading, $size, $x + $w / 2, $y + $h + $asc * $size, $colors['accent'], 'center');
        }
        $h += $asc * $size + 18 * $s;
        $h += $this->paragraph(mb_strtoupper($t['month'].' '.$t['year']), 'label', 'bold', 26, 0.36, $colors['text'], $x, $y + $h, $w, $draw, 1, 'center');

        return $h;
    }

    // ----- QR ------------------------------------------------------------------

    /** Real QR modules inside the white tile, with a quiet zone of at least 4 modules. */
    private function qrModules(string $payload, float $x, float $y, float $size): void
    {
        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $modules = $matrix->getWidth();
        $px = $this->c->px($size);
        $module = intdiv($px, $modules + 8);
        if ($module < 2) {
            throw new RuntimeException('Output is too small for a scannable QR code.');
        }
        $qrPx = $module * $modules;
        $ox = $this->c->px($x) + intdiv($px - $qrPx, 2);
        $oy = $this->c->px($y) + intdiv($px - $qrPx, 2);
        $white = imagecolorallocate($this->c->image, 255, 255, 255);
        imagefilledrectangle($this->c->image, $this->c->px($x), $this->c->px($y), $this->c->px($x) + $px - 1, $this->c->px($y) + $px - 1, $white);
        $dark = imagecolorallocate($this->c->image, 17, 24, 27);
        for ($row = 0; $row < $modules; $row++) {
            for ($col = 0; $col < $modules; $col++) {
                if ($matrix->get($col, $row) === 1) {
                    imagefilledrectangle($this->c->image, $ox + $col * $module, $oy + $row * $module, $ox + ($col + 1) * $module - 1, $oy + ($row + 1) * $module - 1, $dark);
                }
            }
        }
    }

    /**
     * A clearly labelled, deliberately non-decodable placeholder: three finder-like
     * marks and a light dot field with no timing or format information.
     */
    private function sampleQr(float $x, float $y, float $size, string $label): void
    {
        $grey = '#9aa3a6';
        $light = '#d5dadb';
        $m = $size / 29;
        foreach ([[0, 0], [22, 0], [0, 22]] as [$fx, $fy]) {
            $this->c->rect($x + ($fx + 1) * $m, $y + ($fy + 1) * $m, 6 * $m, 6 * $m, $grey);
            $this->c->rect($x + ($fx + 2) * $m, $y + ($fy + 2) * $m, 4 * $m, 4 * $m, '#ffffff');
            $this->c->rect($x + ($fx + 3) * $m, $y + ($fy + 3) * $m, 2 * $m, 2 * $m, $grey);
        }
        mt_srand(29);
        for ($r = 1; $r < 28; $r++) {
            for ($col = 1; $col < 28; $col++) {
                if (($r < 9 && ($col < 9 || $col > 20)) || ($r > 20 && $col < 9)) {
                    continue;
                }
                if (mt_rand(0, 2) === 0) {
                    $this->c->circle($x + ($col + 0.5) * $m, $y + ($r + 0.5) * $m, $m * 0.32, $light);
                }
            }
        }
        mt_srand();
        $this->c->roundedRect($x + $size * 0.16, $y + $size * 0.4, $size * 0.68, $size * 0.2, 10, $this->spec['colors']['accent']);
        $this->c->text(mb_strtoupper($label), $this->font('label', 'bold'), 15, $x + $size / 2, $y + $size * 0.53, '#ffffff', 'center', 0.18);
    }

    // ----- Helpers -------------------------------------------------------------

    /** Opaque colour behind the guest's name for the template's guest-card style. */
    private function guestFill(): string
    {
        $colors = $this->spec['colors'];

        return match ($this->spec['guest_style'] ?? 'box') {
            'ribbon' => $colors['accent'],
            'plaque' => Canvas::mix($this->surface(), $colors['bg2'], 0.7),
            'line' => $this->surface(),
            default => Canvas::mix($this->surface(), '#ffffff', 0.3),
        };
    }

    /** $preferred when it reads on $fill (contrast >= 3), otherwise the best palette fallback. */
    private function readable(string $preferred, string $fill): string
    {
        if (Canvas::contrast($preferred, $fill) >= 3.0) {
            return $preferred;
        }
        $colors = $this->spec['colors'];
        $best = $preferred;
        foreach ([$colors['text'], $colors['bg'], $colors['text2'], '#1b1b1b', '#ffffff'] as $candidate) {
            if (Canvas::contrast($candidate, $fill) > Canvas::contrast($best, $fill)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function font(string $role, string $weight): string
    {
        $key = $this->spec['fonts'][$role] ?? $this->spec['fonts']['body'] ?? 'playfair';

        return InvitationFonts::path($key, $weight);
    }

    private function isScript(string $role): bool
    {
        $key = $this->spec['fonts'][$role] ?? 'playfair';

        return (InvitationFonts::FONTS[$key]['kind'] ?? 'serif') === 'script';
    }

    private function ellipsize(string $text, string $font, float $size, float $width, float $tracking = 0.0): string
    {
        $text = rtrim($text, ' …');
        while (mb_strlen($text) > 1 && $this->c->textWidth($text.'…', $font, $size, $tracking) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }
}
