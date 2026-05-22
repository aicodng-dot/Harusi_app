<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrCodeService
{
    public function svg(string $content, int $size = 360): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 2),
            new SvgImageBackEnd()
        );

        return (new Writer($renderer))->writeString($content, 'UTF-8', ErrorCorrectionLevel::M());
    }

    public function png(string $content, int $size = 480, int $margin = 4): string
    {
        $qrCode = Encoder::encode($content, ErrorCorrectionLevel::M(), 'UTF-8');

        return $this->matrixToPng($qrCode->getMatrix(), $size, $margin);
    }

    private function matrixToPng(ByteMatrix $matrix, int $size, int $margin): string
    {
        $matrixSize = $matrix->getWidth();
        $modulesOnSide = $matrixSize + ($margin * 2);
        $moduleSize = max(1, intdiv($size, $modulesOnSide));
        $imageSize = $modulesOnSide * $moduleSize;
        $raw = '';

        for ($pixelY = 0; $pixelY < $imageSize; $pixelY++) {
            $raw .= "\x00";
            $moduleY = intdiv($pixelY, $moduleSize) - $margin;

            for ($pixelX = 0; $pixelX < $imageSize; $pixelX++) {
                $moduleX = intdiv($pixelX, $moduleSize) - $margin;
                $isDark = $moduleX >= 0
                    && $moduleY >= 0
                    && $moduleX < $matrixSize
                    && $moduleY < $matrixSize
                    && $matrix->get($moduleX, $moduleY) === 1;

                $raw .= $isDark ? "\x17\x18\x1b" : "\xff\xff\xff";
            }
        }

        return "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', pack('NNCCCCC', $imageSize, $imageSize, 8, 2, 0, 0, 0))
            .$this->pngChunk('IDAT', gzcompress($raw, 9))
            .$this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
