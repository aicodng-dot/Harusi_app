<?php

/**
 * Builds the invitation decoration layers (resources/invitation/decor/*.png) from the
 * approved artwork in "reference UI/". The reference PNGs are only read, never changed.
 *
 * Each layer is a crop of one floral/foliage cluster. The paper colour sampled at
 * `bg` is keyed to transparency (soft ramp between KEY_LOW and KEY_HIGH colour
 * distance) and crop edges that cut through artwork are feathered, so the cluster can
 * sit on any compatible light or dark card background.
 *
 * Usage: php scripts/invitation/extract-decor.php
 */
$root = dirname(__DIR__, 2);
$source = $root.'/reference UI/';
$target = $root.'/resources/invitation/decor/';
if (! is_dir($target) && ! mkdir($target, 0775, true)) {
    fwrite(STDERR, "Cannot create $target\n");
    exit(1);
}

const KEY_LOW = 16.0;
const KEY_HIGH = 54.0;
const FEATHER = 46;

$files = [
    'emerald1' => 'Elegant Emerald and Gold Wedding Invitation-1.png',
    'cream1' => 'ChatGPT Image Nov 14, 2026, 11_57_20 PM-1.png',
    'blush3' => 'ChatGPT Image Nov 14, 2026, 11_57_25 PM-3.png',
    'terracotta5' => 'ChatGPT Image Nov 14, 2026, 11_57_31 PM-5.png',
    'forest5' => 'Elegant Forest Green Wedding Invitation-5.png',
    'garden7' => 'Elegant English Garden Wedding Invitation-7.png',
    'swahili8' => 'Elegant Swahili Wedding Invitation Template-8.png',
    'burgundy9' => 'Luxurious Burgundy Wedding Invitation-9.png',
];

/*
 * name => [source, x, y, w, h, bgX, bgY, tone]
 * tone: 'light' = keyed from cream/blush paper, 'dark' = keyed from a dark ground.
 */
$crops = [
    'lily_white_tl' => ['emerald1', 0, 0, 330, 470, 300, 440, 'light'],
    'lily_white_br' => ['emerald1', 740, 860, 346, 588, 760, 880, 'light'],
    'gold_leaf_left' => ['emerald1', 20, 770, 175, 330, 185, 1090, 'light'],
    'gold_leaf_right' => ['emerald1', 862, 128, 196, 330, 870, 140, 'light'],
    'sprig_cream_bl' => ['cream1', 30, 1150, 290, 290, 300, 1160, 'light'],
    'rose_blush_br' => ['blush3', 760, 950, 290, 450, 770, 960, 'light'],
    'hibiscus_tl' => ['terracotta5', 0, 20, 330, 500, 320, 510, 'light'],
    'hibiscus_bl' => ['terracotta5', 0, 830, 225, 618, 215, 840, 'light'],
    'pampas_br' => ['terracotta5', 760, 940, 326, 508, 770, 950, 'light'],
    'peony_tl' => ['forest5', 0, 0, 305, 830, 295, 820, 'light'],
    'peony_br' => ['forest5', 820, 870, 266, 578, 830, 880, 'light'],
    'gold_sprig_tr' => ['forest5', 830, 60, 190, 240, 840, 290, 'light'],
    'rose_garden_tl' => ['garden7', 0, 0, 255, 330, 250, 320, 'light'],
    'lavender_right' => ['garden7', 880, 420, 180, 600, 890, 430, 'light'],
    'rose_garden_br' => ['garden7', 790, 1000, 296, 448, 800, 1010, 'light'],
    'lavender_bl' => ['garden7', 20, 1050, 250, 398, 260, 1060, 'light'],
    'lily_band_bl' => ['swahili8', 0, 1100, 420, 348, 410, 1110, 'light'],
    'rose_band_br' => ['swahili8', 640, 1110, 446, 338, 650, 1120, 'light'],
    'rose_burgundy_tl' => ['burgundy9', 0, 0, 205, 600, 200, 590, 'dark'],
    'rose_burgundy_br' => ['burgundy9', 760, 800, 326, 648, 770, 810, 'dark'],
];

$images = [];
$manifest = [];
foreach ($crops as $name => [$key, $x, $y, $w, $h, $bgX, $bgY, $tone]) {
    $images[$key] ??= imagecreatefrompng($source.$files[$key]);
    $src = $images[$key];
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    [$br, $bg, $bb] = sample($src, $bgX, $bgY);

    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    // Edges that coincide with the source border are natural; interior cuts are feathered.
    $featherLeft = $x > 0;
    $featherTop = $y > 0;
    $featherRight = $x + $w < $srcW;
    $featherBottom = $y + $h < $srcH;

    for ($py = 0; $py < $h; $py++) {
        for ($px = 0; $px < $w; $px++) {
            $rgb = imagecolorat($src, $x + $px, $y + $py);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $distance = sqrt((($r - $br) ** 2) * 0.3 + (($g - $bg) ** 2) * 0.59 + (($b - $bb) ** 2) * 0.11) * 1.6;
            $alpha = min(1.0, max(0.0, ($distance - KEY_LOW) / (KEY_HIGH - KEY_LOW)));
            $edge = 1.0;
            if ($featherLeft) {
                $edge = min($edge, $px / FEATHER);
            }
            if ($featherTop) {
                $edge = min($edge, $py / FEATHER);
            }
            if ($featherRight) {
                $edge = min($edge, ($w - 1 - $px) / FEATHER);
            }
            if ($featherBottom) {
                $edge = min($edge, ($h - 1 - $py) / FEATHER);
            }
            $alpha *= max(0.0, min(1.0, $edge));
            if ($alpha >= 0.35 && $alpha < 1.0) {
                // Un-premultiply the paper colour so fringes do not carry a cream halo.
                $r = unmix($r, $br, $alpha);
                $g = unmix($g, $bg, $alpha);
                $b = unmix($b, $bb, $alpha);
            }
            $gdAlpha = 127 - (int) round($alpha * 127);
            imagesetpixel($out, $px, $py, imagecolorallocatealpha($out, $r, $g, $b, $gdAlpha));
        }
    }

    imagepng($out, $target.$name.'.png', 9);
    $manifest[$name] = ['width' => $w, 'height' => $h, 'tone' => $tone, 'source' => $files[$key]];
    imagedestroy($out);
    fwrite(STDOUT, sprintf("%-24s %4dx%-4d from %s\n", $name, $w, $h, $files[$key]));
}

file_put_contents($target.'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

function sample(GdImage $image, int $cx, int $cy): array
{
    $sum = [0, 0, 0];
    $count = 0;
    for ($y = $cy - 4; $y <= $cy + 4; $y++) {
        for ($x = $cx - 4; $x <= $cx + 4; $x++) {
            if ($x < 0 || $y < 0 || $x >= imagesx($image) || $y >= imagesy($image)) {
                continue;
            }
            $rgb = imagecolorat($image, $x, $y);
            $sum[0] += ($rgb >> 16) & 0xFF;
            $sum[1] += ($rgb >> 8) & 0xFF;
            $sum[2] += $rgb & 0xFF;
            $count++;
        }
    }

    return array_map(fn ($v) => (int) round($v / max(1, $count)), $sum);
}

function unmix(int $observed, int $paper, float $alpha): int
{
    return (int) max(0, min(255, round(($observed - $paper * (1 - $alpha)) / $alpha)));
}
