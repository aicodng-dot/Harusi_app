<?php

namespace App\Services\Invitations;

/**
 * Minimal, dependency-free PDF 1.4 writer: one page carrying one full-bleed JPEG
 * (DCTDecode), so the PDF is pixel-identical to the PNG/JPEG export. The page is
 * 6 x 8 in (432 x 576 pt), the 3:4 card ratio; an 1800 x 2400 image gives 300 dpi.
 */
class InvitationPdf
{
    public function fromJpeg(string $jpeg, int $width, int $height, string $title = 'Invitation'): string
    {
        $pageW = 432;
        $pageH = (int) round($pageW * $height / $width);
        $title = preg_replace('/[^\x20-\x7E]/', '', $title) ?? 'Invitation';
        $title = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], mb_substr($title, 0, 120));
        $content = "q\n$pageW 0 0 $pageH 0 0 cm\n/Im1 Do\nQ\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pageW $pageH] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>",
            "<< /Type /XObject /Subtype /Image /Width $width /Height $height /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg)." >>\nstream\n".$jpeg."\nendstream",
            '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream',
            "<< /Title ($title) /Producer (PangaEvents) >>",
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R /Info 6 0 R >>\nstartxref\n$xref\n%%EOF\n";

        return $pdf;
    }
}
