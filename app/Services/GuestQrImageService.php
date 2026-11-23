<?php

namespace App\Services;

use App\Models\Guest;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;
use RuntimeException;

class GuestQrImageService
{
    private const WIDTH = 1000;
    private const HEIGHT = 1200;
    private const QR_TARGET_SIZE = 800;
    private const QR_MARGIN_MODULES = 4;
    private const DARK = "\x17\x18\x1b";
    private const WHITE = "\xff\xff\xff";

    /**
     * Render the complete downloadable guest QR image.
     *
     * The QR payload remains the secure token URL. The manual admission code is
     * rendered only as visible text below the QR and is read from the same guest.
     */
    public function png(Guest $guest, string $payload): string
    {
        $guest->loadMissing('qrCode');

        if (! $guest->qrCode?->qr_token) {
            throw new RuntimeException('Guest QR token must exist before rendering the QR image.');
        }

        $manualCode = $this->manualCodeFor($guest);
        $image = array_fill(0, self::HEIGHT, str_repeat(self::WHITE, self::WIDTH));

        $this->drawQr($image, Encoder::encode($payload, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix());
        $this->drawCenteredText($image, 'Manual admission code', 925, 6);
        $this->drawCenteredText($image, $manualCode, 990, 16);

        return $this->encodePng($image, [
            'Manual admission code' => $manualCode,
            'QR token tail' => substr((string) $guest->qrCode->qr_token, -12),
        ]);
    }

    public function manualCodeFor(Guest $guest): string
    {
        $manualCode = $guest->manual_code;
        if (! is_string($manualCode) || preg_match('/\A[0-9]{4}\z/', $manualCode) !== 1) {
            throw new RuntimeException('Manual admission code must be exactly four numeric characters before rendering.');
        }

        return $manualCode;
    }

    /**
     * @param array<int, string> $image
     */
    private function drawQr(array &$image, ByteMatrix $matrix): void
    {
        $matrixSize = $matrix->getWidth();
        $modulesOnSide = $matrixSize + (self::QR_MARGIN_MODULES * 2);
        $moduleSize = max(1, intdiv(self::QR_TARGET_SIZE, $modulesOnSide));
        $qrSize = $modulesOnSide * $moduleSize;
        $startX = intdiv(self::WIDTH - $qrSize, 2);
        $startY = 70;

        for ($moduleY = -self::QR_MARGIN_MODULES; $moduleY < $matrixSize + self::QR_MARGIN_MODULES; $moduleY++) {
            for ($moduleX = -self::QR_MARGIN_MODULES; $moduleX < $matrixSize + self::QR_MARGIN_MODULES; $moduleX++) {
                $isDark = $moduleX >= 0
                    && $moduleY >= 0
                    && $moduleX < $matrixSize
                    && $moduleY < $matrixSize
                    && $matrix->get($moduleX, $moduleY) === 1;

                if (! $isDark) {
                    continue;
                }

                $x = $startX + ($moduleX + self::QR_MARGIN_MODULES) * $moduleSize;
                $y = $startY + ($moduleY + self::QR_MARGIN_MODULES) * $moduleSize;
                $this->fillRect($image, $x, $y, $moduleSize, $moduleSize, self::DARK);
            }
        }
    }

    /**
     * @param array<int, string> $image
     */
    private function drawCenteredText(array &$image, string $text, int $top, int $scale): void
    {
        $text = strtoupper($text);
        $width = $this->textWidth($text, $scale);
        $left = max(0, intdiv(self::WIDTH - $width, 2));
        $cursorX = $left;

        foreach (str_split($text) as $char) {
            if ($char === ' ') {
                $cursorX += 4 * $scale;
                continue;
            }

            $glyph = $this->glyph($char);
            foreach ($glyph as $rowIndex => $row) {
                foreach (str_split($row) as $columnIndex => $pixel) {
                    if ($pixel === '1') {
                        $this->fillRect(
                            $image,
                            $cursorX + ($columnIndex * $scale),
                            $top + ($rowIndex * $scale),
                            $scale,
                            $scale,
                            self::DARK
                        );
                    }
                }
            }

            $cursorX += (strlen($glyph[0]) + 1) * $scale;
        }
    }

    private function textWidth(string $text, int $scale): int
    {
        $width = 0;

        foreach (str_split($text) as $char) {
            $width += $char === ' '
                ? 4 * $scale
                : (strlen($this->glyph($char)[0]) + 1) * $scale;
        }

        return max(0, $width - $scale);
    }

    /**
     * @return list<string>
     */
    private function glyph(string $char): array
    {
        return self::GLYPHS[$char] ?? self::GLYPHS['?'];
    }

    /**
     * @param array<int, string> $image
     */
    private function fillRect(array &$image, int $x, int $y, int $width, int $height, string $color): void
    {
        $x = max(0, $x);
        $y = max(0, $y);
        $endX = min(self::WIDTH, $x + $width);
        $endY = min(self::HEIGHT, $y + $height);

        if ($x >= $endX || $y >= $endY) {
            return;
        }

        $replacement = str_repeat($color, $endX - $x);
        $byteOffset = $x * 3;
        $byteLength = ($endX - $x) * 3;

        for ($row = $y; $row < $endY; $row++) {
            $image[$row] = substr_replace($image[$row], $replacement, $byteOffset, $byteLength);
        }
    }

    /**
     * @param array<int, string> $image
     * @param array<string, string> $metadata
     */
    private function encodePng(array $image, array $metadata): string
    {
        $raw = '';
        foreach ($image as $row) {
            $raw .= "\x00".$row;
        }

        $png = "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', pack('NNCCCCC', self::WIDTH, self::HEIGHT, 8, 2, 0, 0, 0));

        foreach ($metadata as $key => $value) {
            $png .= $this->pngChunk('tEXt', $key."\x00".$value);
        }

        return $png
            .$this->pngChunk('IDAT', gzcompress($raw, 9))
            .$this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private const GLYPHS = [
        '0' => ['01110', '10001', '10011', '10101', '11001', '10001', '01110'],
        '1' => ['00100', '01100', '00100', '00100', '00100', '00100', '01110'],
        '2' => ['01110', '10001', '00001', '00010', '00100', '01000', '11111'],
        '3' => ['11110', '00001', '00001', '01110', '00001', '00001', '11110'],
        '4' => ['00010', '00110', '01010', '10010', '11111', '00010', '00010'],
        '5' => ['11111', '10000', '10000', '11110', '00001', '00001', '11110'],
        '6' => ['01110', '10000', '10000', '11110', '10001', '10001', '01110'],
        '7' => ['11111', '00001', '00010', '00100', '01000', '01000', '01000'],
        '8' => ['01110', '10001', '10001', '01110', '10001', '10001', '01110'],
        '9' => ['01110', '10001', '10001', '01111', '00001', '00001', '01110'],
        'A' => ['01110', '10001', '10001', '11111', '10001', '10001', '10001'],
        'C' => ['01111', '10000', '10000', '10000', '10000', '10000', '01111'],
        'D' => ['11110', '10001', '10001', '10001', '10001', '10001', '11110'],
        'E' => ['11111', '10000', '10000', '11110', '10000', '10000', '11111'],
        'I' => ['11111', '00100', '00100', '00100', '00100', '00100', '11111'],
        'L' => ['10000', '10000', '10000', '10000', '10000', '10000', '11111'],
        'M' => ['10001', '11011', '10101', '10101', '10001', '10001', '10001'],
        'N' => ['10001', '11001', '10101', '10011', '10001', '10001', '10001'],
        'O' => ['01110', '10001', '10001', '10001', '10001', '10001', '01110'],
        'S' => ['01111', '10000', '10000', '01110', '00001', '00001', '11110'],
        'T' => ['11111', '00100', '00100', '00100', '00100', '00100', '00100'],
        'U' => ['10001', '10001', '10001', '10001', '10001', '10001', '01110'],
        '?' => ['11111', '00001', '00010', '00100', '00100', '00000', '00100'],
    ];
}