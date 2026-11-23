<?php

namespace App\Services\Invitations\Render;

use GdImage;
use RuntimeException;

/**
 * A GD truecolor canvas addressed in logical invitation units (1200 x 1600 for the
 * 3:4 card). `$k` converts logical units to pixels, so one layout renders identically
 * at preview, PNG and print sizes. Shapes that GD cannot antialias are drawn on a
 * supersampled base canvas by the renderer; text uses FreeType antialiasing.
 */
class Canvas
{
    public const WIDTH = 1200;

    public const HEIGHT = 1600;

    public GdImage $image;

    /** @var array<string, array{0: float, 1: float}> */
    private static array $metrics = [];

    public function __construct(public readonly int $pixelWidth, public readonly float $k)
    {
        $height = (int) round($pixelWidth * self::HEIGHT / self::WIDTH);
        $image = imagecreatetruecolor($pixelWidth, $height);
        if ($image === false) {
            throw new RuntimeException('Unable to allocate the invitation canvas.');
        }
        $this->image = $image;
        imagealphablending($this->image, true);
        imagesavealpha($this->image, true);
    }

    public static function forWidth(int $pixelWidth): self
    {
        return new self($pixelWidth, $pixelWidth / self::WIDTH);
    }

    public function px(float $logical): int
    {
        return (int) round($logical * $this->k);
    }

    public function pixelHeight(): int
    {
        return imagesy($this->image);
    }

    /** Hex (#rrggbb) plus opacity 0..1 to a GD colour index. */
    public function color(string $hex, float $opacity = 1.0): int
    {
        [$r, $g, $b] = self::rgb($hex);
        $alpha = 127 - (int) round(max(0.0, min(1.0, $opacity)) * 127);

        return imagecolorallocatealpha($this->image, $r, $g, $b, $alpha);
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function mix(string $a, string $b, float $t): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);
        $t = max(0.0, min(1.0, $t));

        return sprintf('#%02x%02x%02x', (int) round($r1 + ($r2 - $r1) * $t), (int) round($g1 + ($g2 - $g1) * $t), (int) round($b1 + ($b2 - $b1) * $t));
    }

    /** Relative luminance 0..1 (WCAG). */
    public static function luminance(string $hex): float
    {
        $channel = function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($hex);

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public function fill(string $hex): void
    {
        imagealphablending($this->image, false);
        imagefilledrectangle($this->image, 0, 0, $this->pixelWidth - 1, $this->pixelHeight() - 1, $this->color($hex));
        imagealphablending($this->image, true);
    }

    public function rect(float $x, float $y, float $w, float $h, string $hex, float $opacity = 1.0): void
    {
        imagefilledrectangle($this->image, $this->px($x), $this->px($y), $this->px($x + $w) - 1, $this->px($y + $h) - 1, $this->color($hex, $opacity));
    }

    /** Vertical gradient between two colours. */
    public function verticalGradient(float $x, float $y, float $w, float $h, string $from, string $to, float $opacity = 1.0): void
    {
        $top = $this->px($y);
        $bottom = $this->px($y + $h);
        $span = max(1, $bottom - $top);
        $left = $this->px($x);
        $right = $this->px($x + $w) - 1;
        for ($row = $top; $row < $bottom; $row++) {
            imageline($this->image, $left, $row, $right, $row, $this->color(self::mix($from, $to, ($row - $top) / $span), $opacity));
        }
    }

    /** Fades a band from transparent to a colour (photo blends). */
    public function fadeBand(float $x, float $y, float $w, float $h, string $hex, bool $down = true): void
    {
        $top = $this->px($y);
        $bottom = $this->px($y + $h);
        $span = max(1, $bottom - $top);
        $left = $this->px($x);
        $right = $this->px($x + $w) - 1;
        for ($row = $top; $row < $bottom; $row++) {
            $t = ($row - $top) / $span;
            $t = $down ? $t : 1 - $t;
            imageline($this->image, $left, $row, $right, $row, $this->color($hex, $t * $t * (3 - 2 * $t)));
        }
    }

    /** Soft radial vignette darkening/lightening the edges towards `$hex`. */
    public function vignette(string $hex, float $strength): void
    {
        // Drawn at a quarter of the size and scaled up: smooth and much faster.
        $steps = 28;
        $fullW = imagesx($this->image);
        $fullH = imagesy($this->image);
        $w = max(1, intdiv($fullW, 4));
        $h = max(1, intdiv($fullH, 4));
        $cx = $w / 2;
        $cy = $h / 2;
        $mask = imagecreatetruecolor($w, $h);
        imagealphablending($mask, false);
        imagesavealpha($mask, true);
        [$r, $g, $b] = self::rgb($hex);
        imagefilledrectangle($mask, 0, 0, $w, $h, imagecolorallocatealpha($mask, $r, $g, $b, 127 - (int) round(127 * $strength)));
        for ($i = 0; $i < $steps; $i++) {
            $t = $i / $steps;
            $alpha = 127 - (int) round(127 * $strength * (1 - $t) * (1 - $t));
            imagefilledellipse($mask, (int) $cx, (int) $cy, (int) ($w * (1.55 - 0.9 * $t)), (int) ($h * (1.45 - 0.85 * $t)), imagecolorallocatealpha($mask, $r, $g, $b, max(0, min(127, $alpha))));
        }
        imagefilledellipse($mask, (int) $cx, (int) $cy, (int) ($w * 0.62), (int) ($h * 0.6), imagecolorallocatealpha($mask, $r, $g, $b, 127));
        imagecopyresampled($this->image, $mask, 0, 0, 0, 0, $fullW, $fullH, $w, $h);
        imagedestroy($mask);
    }

    /** Thick straight line drawn as a polygon (antialiased by supersampling). */
    public function line(float $x1, float $y1, float $x2, float $y2, float $width, string $hex, float $opacity = 1.0): void
    {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $length = sqrt($dx * $dx + $dy * $dy);
        if ($length < 0.001) {
            return;
        }
        $nx = -$dy / $length * $width / 2;
        $ny = $dx / $length * $width / 2;
        $this->polygon([[$x1 + $nx, $y1 + $ny], [$x2 + $nx, $y2 + $ny], [$x2 - $nx, $y2 - $ny], [$x1 - $nx, $y1 - $ny]], $hex, $opacity);
    }

    /** @param list<array{0: float, 1: float}> $points */
    public function polygon(array $points, string $hex, float $opacity = 1.0): void
    {
        if (count($points) < 3) {
            return;
        }
        $flat = [];
        foreach ($points as [$x, $y]) {
            $flat[] = (int) round($x * $this->k);
            $flat[] = (int) round($y * $this->k);
        }
        imagefilledpolygon($this->image, $flat, $this->color($hex, $opacity));
    }

    /** @param list<array{0: float, 1: float}> $points open or closed polyline with thickness */
    public function polyline(array $points, float $width, string $hex, float $opacity = 1.0, bool $closed = false): void
    {
        $count = count($points);
        $segments = $closed ? $count : $count - 1;
        $color = $this->color($hex, $opacity);
        $thickness = max(1, (int) round($width * $this->k));
        imagesetthickness($this->image, $thickness);
        for ($i = 0; $i < $segments; $i++) {
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[($i + 1) % $count];
            imageline($this->image, (int) round($x1 * $this->k), (int) round($y1 * $this->k), (int) round($x2 * $this->k), (int) round($y2 * $this->k), $color);
        }
        imagesetthickness($this->image, 1);
        if ($thickness > 2) {
            // Round joins hide the gaps imagesetthickness leaves between segments.
            foreach ($points as [$x, $y]) {
                imagefilledellipse($this->image, (int) round($x * $this->k), (int) round($y * $this->k), $thickness, $thickness, $color);
            }
        }
    }

    public function circle(float $cx, float $cy, float $radius, string $hex, float $opacity = 1.0): void
    {
        $d = max(1, (int) round($radius * 2 * $this->k));
        imagefilledellipse($this->image, (int) round($cx * $this->k), (int) round($cy * $this->k), $d, $d, $this->color($hex, $opacity));
    }

    public function ellipse(float $cx, float $cy, float $rx, float $ry, string $hex, float $opacity = 1.0): void
    {
        imagefilledellipse($this->image, (int) round($cx * $this->k), (int) round($cy * $this->k), max(1, (int) round($rx * 2 * $this->k)), max(1, (int) round($ry * 2 * $this->k)), $this->color($hex, $opacity));
    }

    public function ring(float $cx, float $cy, float $radius, float $width, string $hex, float $opacity = 1.0): void
    {
        $this->polyline($this->arcPoints($cx, $cy, $radius, $radius, 0, 360, 96), $width, $hex, $opacity, true);
    }

    /** @return list<array{0: float, 1: float}> */
    public static function arcPoints(float $cx, float $cy, float $rx, float $ry, float $from, float $to, int $steps = 48): array
    {
        $points = [];
        for ($i = 0; $i <= $steps; $i++) {
            $a = deg2rad($from + ($to - $from) * $i / $steps);
            $points[] = [$cx + cos($a) * $rx, $cy + sin($a) * $ry];
        }

        return $points;
    }

    /** Rounded rectangle outline or fill. */
    public function roundedRect(float $x, float $y, float $w, float $h, float $r, string $hex, float $opacity = 1.0, ?float $stroke = null): void
    {
        $r = min($r, $w / 2, $h / 2);
        $points = array_merge(
            $this->arcPoints($x + $w - $r, $y + $r, $r, $r, -90, 0, 12),
            $this->arcPoints($x + $w - $r, $y + $h - $r, $r, $r, 0, 90, 12),
            $this->arcPoints($x + $r, $y + $h - $r, $r, $r, 90, 180, 12),
            $this->arcPoints($x + $r, $y + $r, $r, $r, 180, 270, 12),
        );
        $stroke === null ? $this->polygon($points, $hex, $opacity) : $this->polyline($points, $stroke, $hex, $opacity, true);
    }

    /** Arch-topped rectangle (semicircular top) outline or fill. */
    public function arch(float $x, float $y, float $w, float $h, string $hex, float $opacity = 1.0, ?float $stroke = null): void
    {
        $r = $w / 2;
        $points = array_merge(
            [[$x + $w, $y + $h], [$x, $y + $h]],
            $this->arcPoints($x + $r, $y + $r, $r, $r, 180, 360, 48),
        );
        $stroke === null ? $this->polygon($points, $hex, $opacity) : $this->polyline($points, $stroke, $hex, $opacity, true);
    }

    /** Rectangle with cut (chamfered) corners. */
    public function chamferRect(float $x, float $y, float $w, float $h, float $cut, string $hex, float $opacity = 1.0, ?float $stroke = null): void
    {
        $points = [[$x + $cut, $y], [$x + $w - $cut, $y], [$x + $w, $y + $cut], [$x + $w, $y + $h - $cut], [$x + $w - $cut, $y + $h],
            [$x + $cut, $y + $h], [$x, $y + $h - $cut], [$x, $y + $cut]];
        $stroke === null ? $this->polygon($points, $hex, $opacity) : $this->polyline($points, $stroke, $hex, $opacity, true);
    }

    /** Ascent/descent (logical units per size unit) for consistent line boxes. */
    public static function fontMetrics(string $font): array
    {
        if (! isset(self::$metrics[$font])) {
            $box = imagettfbbox(100, 0, $font, 'ÁHÉbdfhklgjpqy');
            self::$metrics[$font] = [abs($box[7]) / 100, abs($box[1]) / 100];
        }

        return self::$metrics[$font];
    }

    /** Text width in logical units for size (logical) with extra tracking (em fraction). */
    public function textWidth(string $text, string $font, float $size, float $tracking = 0.0): float
    {
        if ($text === '') {
            return 0.0;
        }
        if ($tracking == 0.0) {
            $box = imagettfbbox($size, 0, $font, $text);

            return (float) ($box[2] - $box[0]);
        }
        $width = 0.0;
        $chars = mb_str_split($text);
        foreach ($chars as $char) {
            $width += $this->advance($char, $font, $size) + $tracking * $size;
        }

        return $width - $tracking * $size;
    }

    /** Horizontal advance of one character (including side bearings), measured between two H's. */
    private function advance(string $char, string $font, float $size): float
    {
        static $cache = [];
        $key = $font.'|'.$char;
        if (! isset($cache[$key])) {
            $with = imagettfbbox(100, 0, $font, 'H'.$char.'H');
            $without = imagettfbbox(100, 0, $font, 'HH');
            $cache[$key] = (($with[2] - $with[0]) - ($without[2] - $without[0])) / 100;
        }

        return $cache[$key] * $size;
    }

    /**
     * Draws one line of text with its baseline at $y. $align: left|center|right
     * relative to $x. Sizes are logical; GD receives pixels via $k.
     */
    public function text(string $text, string $font, float $size, float $x, float $y, string $hex, string $align = 'left', float $tracking = 0.0, float $opacity = 1.0): float
    {
        $width = $this->textWidth($text, $font, $size, $tracking);
        $start = match ($align) {
            'center' => $x - $width / 2,
            'right' => $x - $width,
            default => $x,
        };
        $color = $this->color($hex, $opacity);
        $px = $size * $this->k;
        if ($tracking == 0.0) {
            imagettftext($this->image, $px, 0, (int) round($start * $this->k), (int) round($y * $this->k), $color, $font, $text);

            return $width;
        }
        $cursor = $start;
        foreach (mb_str_split($text) as $char) {
            if ($char !== ' ') {
                imagettftext($this->image, $px, 0, (int) round($cursor * $this->k), (int) round($y * $this->k), $color, $font, $char);
            }
            $cursor += $this->advance($char, $font, $size) + $tracking * $size;
        }

        return $width;
    }

    /**
     * Greedy word wrap. Words longer than the width are hard-broken so nothing can
     * spill past the safe area.
     *
     * @return list<string>
     */
    public function wrap(string $text, string $font, float $size, float $maxWidth, float $tracking = 0.0): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            $words = preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $line = '';
            foreach ($words as $word) {
                while ($this->textWidth($word, $font, $size, $tracking) > $maxWidth && mb_strlen($word) > 1) {
                    if ($line !== '') {
                        $lines[] = $line;
                        $line = '';
                    }
                    $cut = mb_strlen($word) - 1;
                    while ($cut > 1 && $this->textWidth(mb_substr($word, 0, $cut), $font, $size, $tracking) > $maxWidth) {
                        $cut--;
                    }
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $candidate = $line === '' ? $word : $line.' '.$word;
                if ($line !== '' && $this->textWidth($candidate, $font, $size, $tracking) > $maxWidth) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Copies an image (with alpha) into a logical rectangle. */
    public function image(GdImage $source, float $x, float $y, float $w, float $h, bool $flipX = false, bool $flipY = false): void
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $dx = (int) round($x * $this->k);
        $dy = (int) round($y * $this->k);
        $dw = max(1, (int) round($w * $this->k));
        $dh = max(1, (int) round($h * $this->k));
        $srcX = $flipX ? $sw - 1 : 0;
        $srcY = $flipY ? $sh - 1 : 0;
        imagecopyresampled($this->image, $source, $dx, $dy, $srcX, $srcY, $dw, $dh, $flipX ? -$sw : $sw, $flipY ? -$sh : $sh);
    }

    /**
     * Draws $source "cover"-fitted into a shape, row by row. Every supported shape is
     * convex per row, so each row is one horizontal span: [x0, x1] for row y.
     *
     * @param  callable(float): ?array{0: float, 1: float}  $span  logical span for logical y
     */
    public function clippedImage(GdImage $source, float $x, float $y, float $w, float $h, float $zoom, float $focusX, float $focusY, callable $span): void
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $scale = max($w / $sw, $h / $sh) * max(1.0, $zoom);
        $visibleW = $w / $scale;
        $visibleH = $h / $scale;
        $srcX = max(0.0, min($sw - $visibleW, $focusX * $sw - $visibleW / 2));
        $srcY = max(0.0, min($sh - $visibleH, $focusY * $sh - $visibleH / 2));

        $dw = max(1, (int) round($w * $this->k));
        $dh = max(1, (int) round($h * $this->k));
        $fitted = imagecreatetruecolor($dw, $dh);
        imagecopyresampled($fitted, $source, 0, 0, (int) round($srcX), (int) round($srcY), $dw, $dh, (int) round($visibleW), (int) round($visibleH));

        $ox = (int) round($x * $this->k);
        $oy = (int) round($y * $this->k);
        for ($row = 0; $row < $dh; $row++) {
            $range = $span($y + ($row + 0.5) / $this->k);
            if ($range === null) {
                continue;
            }
            $from = max(0, (int) round($range[0] * $this->k) - $ox);
            $to = min($dw, (int) round($range[1] * $this->k) - $ox);
            if ($to > $from) {
                imagecopy($this->image, $fitted, $ox + $from, $oy + $row, $from, $row, $to - $from, 1);
            }
        }
        imagedestroy($fitted);
    }
}
