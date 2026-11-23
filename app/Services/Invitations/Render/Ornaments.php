<?php

namespace App\Services\Invitations\Render;

/**
 * Vector ornaments, icons, frames and background patterns drawn with Canvas
 * primitives. All coordinates are logical (1200 x 1600 card).
 */
class Ornaments
{
    public const MOTIFS = ['none', 'rings', 'flower', 'heart', 'sparkle', 'cross', 'crescent', 'crown', 'sprig', 'dove_branch'];

    public const DIVIDERS = ['line', 'diamond', 'heart', 'flower', 'rings', 'leaves', 'dots', 'flourish'];

    public const FRAMES = ['none', 'thin', 'double', 'ornate', 'rounded', 'arch', 'chamfer', 'deco', 'corners', 'band'];

    public const PATTERNS = ['none', 'dots', 'lattice', 'stripes', 'deco_fans', 'confetti', 'kanga', 'stars', 'leaves', 'waves', 'grid'];

    public function __construct(private Canvas $c) {}

    // ----- Icons ---------------------------------------------------------------

    public function icon(string $name, float $cx, float $cy, float $size, string $color, string $surface): void
    {
        $s = $size / 2;
        $w = max(2.0, $size * 0.075);
        match ($name) {
            'calendar' => $this->calendar($cx, $cy, $s, $w, $color),
            'clock' => $this->clock($cx, $cy, $s, $w, $color),
            'pin' => $this->pin($cx, $cy, $s, $color, $surface),
            'rings' => $this->rings($cx, $cy, $s, $w, $color),
            'flower' => $this->flower($cx, $cy, $s, $w, $color),
            'heart' => $this->heart($cx, $cy, $s, $color),
            'sparkle' => $this->sparkle($cx, $cy, $s, $color),
            'cross' => $this->cross($cx, $cy, $s, $color),
            'crescent' => $this->crescent($cx, $cy, $s, $color, $surface),
            'crown' => $this->crown($cx, $cy, $s, $color),
            'sprig' => $this->sprig($cx, $cy, $s * 2.2, 0.0, $color),
            'dove_branch' => $this->doveBranch($cx, $cy, $s, $w, $color),
            'diamond' => $this->c->polygon([[$cx, $cy - $s], [$cx + $s * 0.7, $cy], [$cx, $cy + $s], [$cx - $s * 0.7, $cy]], $color),
            default => null,
        };
    }

    private function calendar(float $cx, float $cy, float $s, float $w, string $color): void
    {
        $this->c->roundedRect($cx - $s, $cy - $s * 0.8, $s * 2, $s * 1.8, $s * 0.25, $color, 1, $w);
        $this->c->rect($cx - $s, $cy - $s * 0.8, $s * 2, $s * 0.42, $color);
        $this->c->rect($cx - $s * 0.55, $cy - $s * 1.05, $w * 1.2, $s * 0.45, $color);
        $this->c->rect($cx + $s * 0.55 - $w, $cy - $s * 1.05, $w * 1.2, $s * 0.45, $color);
        foreach ([-0.5, 0, 0.5] as $dx) {
            foreach ([0.15, 0.55] as $dy) {
                $this->c->circle($cx + $dx * $s, $cy + $dy * $s, $w * 0.8, $color);
            }
        }
    }

    private function clock(float $cx, float $cy, float $s, float $w, string $color): void
    {
        $this->c->ring($cx, $cy, $s * 0.92, $w, $color);
        $this->c->line($cx, $cy, $cx, $cy - $s * 0.55, $w, $color);
        $this->c->line($cx, $cy, $cx + $s * 0.42, $cy + $s * 0.2, $w, $color);
    }

    private function pin(float $cx, float $cy, float $s, string $color, string $surface): void
    {
        $this->c->circle($cx, $cy - $s * 0.25, $s * 0.66, $color);
        $this->c->polygon([[$cx - $s * 0.58, $cy - $s * 0.05], [$cx + $s * 0.58, $cy - $s * 0.05], [$cx, $cy + $s * 1.0]], $color);
        $this->c->circle($cx, $cy - $s * 0.25, $s * 0.26, $surface);
    }

    private function rings(float $cx, float $cy, float $s, float $w, string $color): void
    {
        $this->c->ring($cx - $s * 0.36, $cy + $s * 0.1, $s * 0.58, $w, $color);
        $this->c->ring($cx + $s * 0.36, $cy + $s * 0.1, $s * 0.58, $w, $color);
        $this->c->polygon([[$cx - $s * 0.36, $cy - $s * 0.62], [$cx - $s * 0.2, $cy - $s * 0.78], [$cx - $s * 0.52, $cy - $s * 0.78]], $color);
    }

    private function flower(float $cx, float $cy, float $s, float $w, string $color): void
    {
        for ($i = 0; $i < 5; $i++) {
            $a = deg2rad(-90 + $i * 72);
            $this->c->ring($cx + cos($a) * $s * 0.48, $cy + sin($a) * $s * 0.48, $s * 0.36, $w, $color);
        }
        $this->c->circle($cx, $cy, $s * 0.2, $color);
    }

    private function heart(float $cx, float $cy, float $s, string $color): void
    {
        $this->c->circle($cx - $s * 0.38, $cy - $s * 0.22, $s * 0.44, $color);
        $this->c->circle($cx + $s * 0.38, $cy - $s * 0.22, $s * 0.44, $color);
        $this->c->polygon([[$cx - $s * 0.8, $cy - $s * 0.08], [$cx + $s * 0.8, $cy - $s * 0.08], [$cx, $cy + $s * 0.85]], $color);
    }

    private function sparkle(float $cx, float $cy, float $s, string $color): void
    {
        $points = [];
        for ($i = 0; $i < 8; $i++) {
            $a = deg2rad(-90 + $i * 45);
            $r = $i % 2 === 0 ? $s : $s * 0.22;
            $points[] = [$cx + cos($a) * $r, $cy + sin($a) * $r];
        }
        $this->c->polygon($points, $color);
    }

    private function cross(float $cx, float $cy, float $s, string $color): void
    {
        $t = $s * 0.18;
        $this->c->rect($cx - $t, $cy - $s, $t * 2, $s * 2, $color);
        $this->c->rect($cx - $s * 0.62, $cy - $s * 0.45, $s * 1.24, $t * 2, $color);
    }

    private function crescent(float $cx, float $cy, float $s, string $color, string $surface): void
    {
        $this->c->circle($cx - $s * 0.1, $cy, $s * 0.8, $color);
        $this->c->circle($cx + $s * 0.18, $cy - $s * 0.12, $s * 0.68, $surface);
        $this->sparkle($cx + $s * 0.62, $cy - $s * 0.35, $s * 0.3, $color);
    }

    private function crown(float $cx, float $cy, float $s, string $color): void
    {
        $this->c->polygon([[$cx - $s, $cy + $s * 0.5], [$cx - $s, $cy - $s * 0.45], [$cx - $s * 0.5, $cy + $s * 0.05], [$cx, $cy - $s * 0.75],
            [$cx + $s * 0.5, $cy + $s * 0.05], [$cx + $s, $cy - $s * 0.45], [$cx + $s, $cy + $s * 0.5]], $color);
        foreach ([-1, 0, 1] as $i) {
            $this->c->circle($cx + $i * $s, $cy - ($i === 0 ? $s * 0.85 : $s * 0.55), $s * 0.13, $color);
        }
        $this->c->rect($cx - $s, $cy + $s * 0.6, $s * 2, $s * 0.18, $color);
    }

    private function doveBranch(float $cx, float $cy, float $s, float $w, string $color): void
    {
        $this->sprig($cx - $s * 0.9, $cy, $s * 1.6, 0.0, $color);
        $this->sprig($cx + $s * 0.9, $cy, $s * 1.6, 180.0, $color);
        $this->c->ring($cx, $cy, $s * 0.22, $w, $color);
    }

    /** A leafy sprig centred at (cx, cy), length $len, rotated $angle degrees (0 = pointing right). */
    public function sprig(float $cx, float $cy, float $len, float $angle, string $color, float $opacity = 1.0): void
    {
        $a = deg2rad($angle);
        $dx = cos($a);
        $dy = sin($a);
        $x0 = $cx - $dx * $len / 2;
        $y0 = $cy - $dy * $len / 2;
        $this->c->line($x0, $y0, $cx + $dx * $len / 2, $cy + $dy * $len / 2, max(1.5, $len * 0.018), $color, $opacity);
        $leaves = 5;
        for ($i = 0; $i < $leaves; $i++) {
            $t = 0.15 + 0.8 * $i / $leaves;
            $px = $x0 + $dx * $len * $t;
            $py = $y0 + $dy * $len * $t;
            $size = $len * (0.16 - 0.015 * $i);
            foreach ([1, -1] as $side) {
                $la = $a + $side * deg2rad(48);
                $this->leaf($px, $py, $size, $la, $color, $opacity);
            }
        }
        $this->leaf($x0 + $dx * $len, $y0 + $dy * $len, $len * 0.12, $a, $color, $opacity);
    }

    /** Almond leaf from (x, y) along angle (radians). */
    public function leaf(float $x, float $y, float $size, float $angle, string $color, float $opacity = 1.0): void
    {
        $points = [];
        $steps = 14;
        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            $points[] = [$t, sin($t * M_PI) * 0.32];
        }
        for ($i = $steps; $i >= 0; $i--) {
            $t = $i / $steps;
            $points[] = [$t, -sin($t * M_PI) * 0.32];
        }
        $cos = cos($angle);
        $sin = sin($angle);
        $this->c->polygon(array_map(fn ($p) => [$x + ($p[0] * $cos - $p[1] * $sin) * $size, $y + ($p[0] * $sin + $p[1] * $cos) * $size], $points), $color, $opacity);
    }

    // ----- Dividers ------------------------------------------------------------

    public function divider(string $style, float $cx, float $cy, float $width, float $scale, string $line, string $accent, string $surface): float
    {
        $half = $width / 2;
        $w = 2.2 * $scale;
        $gap = 34 * $scale;
        switch ($style) {
            case 'diamond':
                $this->c->line($cx - $half, $cy, $cx - $gap, $cy, $w, $line);
                $this->c->line($cx + $gap, $cy, $cx + $half, $cy, $w, $line);
                $this->icon('diamond', $cx, $cy, 26 * $scale, $accent, $surface);
                $this->c->circle($cx - $gap - 6 * $scale, $cy, 4 * $scale, $accent);
                $this->c->circle($cx + $gap + 6 * $scale, $cy, 4 * $scale, $accent);
                break;
            case 'heart':
            case 'flower':
            case 'rings':
                $this->c->line($cx - $half, $cy, $cx - $gap * 1.4, $cy, $w, $line);
                $this->c->line($cx + $gap * 1.4, $cy, $cx + $half, $cy, $w, $line);
                $this->icon($style, $cx, $cy, 44 * $scale, $accent, $surface);
                break;
            case 'leaves':
                $this->sprig($cx - $half * 0.5, $cy, $half * 0.85, 180.0, $accent);
                $this->sprig($cx + $half * 0.5, $cy, $half * 0.85, 0.0, $accent);
                $this->c->circle($cx, $cy, 6 * $scale, $accent);
                break;
            case 'dots':
                foreach ([-1, 0, 1] as $i) {
                    $this->c->circle($cx + $i * 22 * $scale, $cy, ($i === 0 ? 6 : 4) * $scale, $accent);
                }
                break;
            case 'flourish':
                foreach ([-1, 1] as $side) {
                    $points = [];
                    for ($i = 0; $i <= 40; $i++) {
                        $t = $i / 40;
                        $points[] = [$cx + $side * ($gap * 0.6 + $t * ($half - $gap * 0.6)), $cy + sin($t * M_PI * 2) * 10 * $scale * (1 - $t)];
                    }
                    $this->c->polyline($points, $w, $line);
                }
                $this->icon('sparkle', $cx, $cy, 30 * $scale, $accent, $surface);
                break;
            default:
                $this->c->line($cx - $half, $cy, $cx + $half, $cy, $w, $line);
                $this->c->circle($cx, $cy, 5 * $scale, $accent);
        }

        return 46 * $scale;
    }

    // ----- Frames --------------------------------------------------------------

    public function frame(string $style, string $color, string $accent): void
    {
        $W = Canvas::WIDTH;
        $H = Canvas::HEIGHT;
        switch ($style) {
            case 'thin':
                $this->c->polyline([[50, 50], [$W - 50, 50], [$W - 50, $H - 50], [50, $H - 50]], 3, $color, 1, true);
                break;
            case 'double':
                $this->c->polyline([[42, 42], [$W - 42, 42], [$W - 42, $H - 42], [42, $H - 42]], 5, $color, 1, true);
                $this->c->polyline([[62, 62], [$W - 62, 62], [$W - 62, $H - 62], [62, $H - 62]], 2, $color, 1, true);
                break;
            case 'ornate':
                $this->c->polyline([[46, 46], [$W - 46, 46], [$W - 46, $H - 46], [46, $H - 46]], 3, $color, 1, true);
                $this->c->polyline([[70, 70], [$W - 70, 70], [$W - 70, $H - 70], [70, $H - 70]], 1.6, $color, 1, true);
                foreach ([[58, 58], [$W - 58, 58], [58, $H - 58], [$W - 58, $H - 58]] as [$x, $y]) {
                    $this->c->polygon([[$x, $y - 22], [$x + 22, $y], [$x, $y + 22], [$x - 22, $y]], $accent);
                    $this->c->circle($x, $y, 6, $color);
                }
                foreach ([[$W / 2, 58], [$W / 2, $H - 58]] as [$x, $y]) {
                    $this->c->polygon([[$x, $y - 14], [$x + 30, $y], [$x, $y + 14], [$x - 30, $y]], $accent);
                }
                break;
            case 'rounded':
                $this->c->roundedRect(55, 55, $W - 110, $H - 110, 46, $color, 1, 3);
                $this->c->roundedRect(72, 72, $W - 144, $H - 144, 34, $color, 0.6, 1.4);
                break;
            case 'arch':
                $this->c->arch(70, 60, $W - 140, $H - 120, $color, 1, 3);
                $this->c->arch(88, 78, $W - 176, $H - 156, $color, 0.55, 1.4);
                break;
            case 'chamfer':
                $this->c->chamferRect(48, 48, $W - 96, $H - 96, 70, $color, 1, 3.4);
                $this->c->chamferRect(66, 66, $W - 132, $H - 132, 58, $color, 0.65, 1.5);
                break;
            case 'deco':
                $this->c->polyline([[60, 60], [$W - 60, 60], [$W - 60, $H - 60], [60, $H - 60]], 2.4, $color, 1, true);
                foreach ([[60, 60, 1, 1], [$W - 60, 60, -1, 1], [60, $H - 60, 1, -1], [$W - 60, $H - 60, -1, -1]] as [$x, $y, $sx, $sy]) {
                    foreach ([22, 44, 66] as $i => $d) {
                        $this->c->polyline([[$x + $sx * $d, $y + $sy * (90 - $i * 22)], [$x + $sx * $d, $y + $sy * $d], [$x + $sx * (90 - $i * 22), $y + $sy * $d]], 2, $color);
                    }
                    $this->c->polygon([[$x + $sx * 10, $y + $sy * 10], [$x + $sx * 30, $y + $sy * 10], [$x + $sx * 10, $y + $sy * 30]], $accent);
                }
                break;
            case 'corners':
                foreach ([[56, 56, 1, 1], [$W - 56, 56, -1, 1], [56, $H - 56, 1, -1], [$W - 56, $H - 56, -1, -1]] as [$x, $y, $sx, $sy]) {
                    $this->c->polyline([[$x, $y + $sy * 150], [$x, $y], [$x + $sx * 150, $y]], 4, $color);
                    $this->c->polyline([[$x + $sx * 18, $y + $sy * 90], [$x + $sx * 18, $y + $sy * 18], [$x + $sx * 90, $y + $sy * 18]], 1.8, $color);
                    $this->c->circle($x + $sx * 18, $y + $sy * 18, 5, $accent);
                }
                break;
            case 'band':
                $this->c->rect(0, 0, $W, 26, $color);
                $this->c->rect(0, $H - 26, $W, 26, $color);
                $this->c->rect(0, 0, 26, $H, $color);
                $this->c->rect($W - 26, 0, 26, $H, $color);
                $this->c->polyline([[52, 52], [$W - 52, 52], [$W - 52, $H - 52], [52, $H - 52]], 2, $accent, 1, true);
                break;
        }
    }

    // ----- Patterns ------------------------------------------------------------

    public function pattern(string $style, string $color, string $accent, string $third, int $seed): void
    {
        $W = Canvas::WIDTH;
        $H = Canvas::HEIGHT;
        mt_srand($seed);
        switch ($style) {
            case 'dots':
                for ($y = 30; $y < $H; $y += 46) {
                    for ($x = (($y / 46) % 2) * 23 + 20; $x < $W; $x += 46) {
                        $this->c->circle($x, $y, 3.2, $color, 0.28);
                    }
                }
                break;
            case 'lattice':
                for ($i = -$H; $i < $W + $H; $i += 70) {
                    $this->c->line($i, 0, $i + $H, $H, 1.4, $color, 0.16);
                    $this->c->line($i + $H, 0, $i, $H, 1.4, $color, 0.16);
                }
                break;
            case 'stripes':
                for ($x = 18; $x < $W; $x += 36) {
                    $this->c->rect($x, 0, 9, $H, $color, 0.07);
                }
                break;
            case 'deco_fans':
                foreach ([0.0, (float) $H] as $edge) {
                    for ($x = 0; $x <= $W; $x += 120) {
                        foreach ([110, 80, 50] as $r) {
                            $points = $edge === 0.0
                                ? Canvas::arcPoints($x, 0, $r, $r, 0, 180, 24)
                                : Canvas::arcPoints($x, $H, $r, $r, 180, 360, 24);
                            $this->c->polyline($points, 2, $color, 0.35);
                        }
                    }
                }
                break;
            case 'confetti':
                $palette = [$color, $accent, $third];
                for ($i = 0; $i < 90; $i++) {
                    $x = mt_rand(0, $W);
                    $y = mt_rand(0, $H);
                    if ($x > 230 && $x < $W - 230 && $y > 200 && $y < $H - 200) {
                        continue;
                    }
                    $hex = $palette[$i % 3];
                    if ($i % 3 === 0) {
                        $this->c->circle($x, $y, mt_rand(5, 12), $hex, 0.75);
                    } else {
                        $angle = mt_rand(0, 180);
                        $this->c->line($x, $y, $x + cos(deg2rad($angle)) * 24, $y + sin(deg2rad($angle)) * 24, 7, $hex, 0.75);
                    }
                }
                break;
            case 'kanga':
                // Geometric border bands inspired by East African printed-cloth borders.
                foreach ([[0, 1], [$H - 92, -1]] as [$top, $dir]) {
                    $this->c->rect(0, $top, $W, 92, $color, 0.92);
                    for ($x = 0; $x < $W; $x += 60) {
                        $this->c->polygon([[$x, $top + 14], [$x + 30, $top + 46], [$x + 60, $top + 14]], $accent);
                        $this->c->polygon([[$x, $top + 78], [$x + 30, $top + 46], [$x + 60, $top + 78]], $third);
                        $this->c->circle($x + 30, $top + 46, 6, $accent);
                    }
                }
                break;
            case 'stars':
                for ($y = 0; $y < $H + 100; $y += 150) {
                    for ($x = 0; $x < $W + 100; $x += 150) {
                        $this->eightPointStar($x, $y, 54, $color, 0.2);
                    }
                }
                break;
            case 'leaves':
                for ($i = 0; $i < 46; $i++) {
                    $x = mt_rand(0, $W);
                    $y = mt_rand(0, $H);
                    if ($x > 180 && $x < $W - 180) {
                        continue;
                    }
                    $this->leaf($x, $y, mt_rand(26, 52), deg2rad(mt_rand(0, 359)), $i % 2 ? $color : $accent, 0.38);
                }
                break;
            case 'waves':
                for ($y = 40; $y < $H; $y += 60) {
                    $points = [];
                    for ($x = 0; $x <= $W; $x += 20) {
                        $points[] = [$x, $y + sin($x / 60) * 8];
                    }
                    $this->c->polyline($points, 1.6, $color, 0.16);
                }
                break;
            case 'grid':
                for ($x = 0; $x < $W; $x += 60) {
                    $this->c->rect($x, 0, 1.4, $H, $color, 0.12);
                }
                for ($y = 0; $y < $H; $y += 60) {
                    $this->c->rect(0, $y, $W, 1.4, $color, 0.12);
                }
                break;
        }
        mt_srand();
    }

    private function eightPointStar(float $cx, float $cy, float $r, string $color, float $opacity): void
    {
        foreach ([0, 45] as $rot) {
            $points = [];
            for ($i = 0; $i < 4; $i++) {
                $a = deg2rad($rot + 45 + $i * 90);
                $points[] = [$cx + cos($a) * $r, $cy + sin($a) * $r];
            }
            $this->c->polyline($points, 1.6, $color, $opacity, true);
        }
    }
}
