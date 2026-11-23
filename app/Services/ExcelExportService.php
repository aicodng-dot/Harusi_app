<?php

namespace App\Services;

use App\Models\Guest;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelExportService
{
    public const XLSX_MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const QR_LIST_HEADERS = [
        'Guest ID',
        'Guest name',
        'Phone number',
        'Pass type',
        'Allowed entries',
        'Used entries',
        'Guest status',
        'QR status',
        'Generated at',
        'Revoked at',
        'Download file name',
    ];

    public function downloadGuestImportTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator(config('app.name'))
            ->setTitle('Guest Import Template')
            ->setSubject('Guest import workbook');

        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle('Guests');
        $worksheet->setShowGridlines(false);

        $headers = GuestImportExcelReader::REQUIRED_COLUMNS;

        foreach ($headers as $index => $header) {
            $worksheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }

        $examples = [
            ['John Peter', '0712345678', 'single', 1],
            ['Mary Joseph', '0755555555', 'double', 2],
            ['Kimaro Family', '0788888888', 'special', 6],
        ];

        foreach ($examples as $rowOffset => $example) {
            $row = $rowOffset + 2;
            $worksheet->setCellValueExplicit([1, $row], $example[0], DataType::TYPE_STRING);
            $worksheet->setCellValueExplicit([2, $row], $example[1], DataType::TYPE_STRING);
            $worksheet->setCellValueExplicit([3, $row], $example[2], DataType::TYPE_STRING);
            $worksheet->setCellValueExplicit([4, $row], $example[3], DataType::TYPE_NUMERIC);
        }

        $worksheet->freezePane('A2');
        $worksheet->setAutoFilter('A1:D'.(count($examples) + 1));
        $worksheet->getStyle('A1:D1')->applyFromArray($this->headerStyle());
        $worksheet->getStyle('A2:C'.(GuestImportExcelReader::MAX_ROWS + 1))
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_TEXT);
        $worksheet->getStyle('D2:D'.(GuestImportExcelReader::MAX_ROWS + 1))
            ->getNumberFormat()
            ->setFormatCode('0');
        $worksheet->getStyle('A2:D'.(count($examples) + 1))
            ->getBorders()
            ->getBottom()
            ->setBorderStyle(Border::BORDER_HAIR)
            ->getColor()
            ->setARGB('FFE5E7EB');
        $worksheet->getStyle('A1:D'.(count($examples) + 1))
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);
        $worksheet->getColumnDimension('A')->setWidth(36);
        $worksheet->getColumnDimension('B')->setWidth(20);
        $worksheet->getColumnDimension('C')->setWidth(18);
        $worksheet->getColumnDimension('D')->setWidth(18);
        $worksheet->getRowDimension(1)->setRowHeight(24);

        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle('Instructions');
        $instructions->setShowGridlines(false);
        $instructionRows = [
            ['Guest Import Instructions'],
            ['Use the Guests sheet and keep the four header names unchanged.'],
            ['Enter phone_number as text so local leading zeros are preserved.'],
            ['Supported pass_type values are single, double, and special.'],
            ['single requires 1 entry; double requires 2; special allows '.Guest::MIN_SPECIAL_ENTRIES.' to '.Guest::MAX_ALLOWED_ENTRIES.'.'],
            ['Do not add formulas, macros, or external workbook links.'],
        ];

        foreach ($instructionRows as $rowOffset => $instructionRow) {
            $instructions->setCellValueExplicit([1, $rowOffset + 1], $instructionRow[0], DataType::TYPE_STRING);
        }

        $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $instructions->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
        $instructions->getStyle('A1:A'.count($instructionRows))->getAlignment()->setWrapText(true);
        $instructions->getColumnDimension('A')->setWidth(78);
        $instructions->getRowDimension(1)->setRowHeight(28);

        $spreadsheet->setActiveSheetIndex(0);
        $worksheet->setSelectedCell('A1');

        return $this->download($spreadsheet, 'guest_import_template.xlsx');
    }

    public function downloadQrList(string $filename, iterable $rows): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator(config('app.name'))
            ->setTitle('QR Codes')
            ->setSubject('Wedding QR code list');

        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle('QR Codes');
        $worksheet->setShowGridlines(false);

        foreach (self::QR_LIST_HEADERS as $index => $header) {
            $worksheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }

        $rowNumber = 2;

        foreach ($rows as $row) {
            $values = array_values($row);

            foreach ($values as $index => $value) {
                $columnNumber = $index + 1;
                $dataType = in_array($columnNumber, [5, 6], true)
                    ? DataType::TYPE_NUMERIC
                    : DataType::TYPE_STRING;

                $worksheet->setCellValueExplicit(
                    [$columnNumber, $rowNumber],
                    $dataType === DataType::TYPE_NUMERIC ? (int) $value : (string) ($value ?? ''),
                    $dataType,
                );
            }

            $rowNumber++;
        }

        $lastRow = max(1, $rowNumber - 1);
        $lastColumn = Coordinate::stringFromColumnIndex(count(self::QR_LIST_HEADERS));
        $worksheet->freezePane('A2');
        $worksheet->setAutoFilter('A1:'.$lastColumn.$lastRow);
        $worksheet->getStyle('A1:'.$lastColumn.'1')->applyFromArray($this->headerStyle());
        $worksheet->getStyle('A1:'.$lastColumn.$lastRow)
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);

        if ($lastRow > 1) {
            foreach (['A', 'B', 'C', 'D', 'G', 'H', 'I', 'J', 'K'] as $textColumn) {
                $worksheet->getStyle($textColumn.'2:'.$textColumn.$lastRow)
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_TEXT);
            }

            $worksheet->getStyle('A2:'.$lastColumn.$lastRow)
                ->getBorders()
                ->getBottom()
                ->setBorderStyle(Border::BORDER_HAIR)
                ->getColor()
                ->setARGB('FFE5E7EB');
            $worksheet->getStyle('E2:F'.$lastRow)->getNumberFormat()->setFormatCode('0');
        }

        $widths = [
            'A' => 12,
            'B' => 38,
            'C' => 20,
            'D' => 18,
            'E' => 17,
            'F' => 14,
            'G' => 18,
            'H' => 16,
            'I' => 21,
            'J' => 21,
            'K' => 58,
        ];

        foreach ($widths as $column => $width) {
            $worksheet->getColumnDimension($column)->setWidth($width);
        }

        $worksheet->getRowDimension(1)->setRowHeight(24);
        $worksheet->setSelectedCell('A1');

        return $this->download($spreadsheet, $filename);
    }

    private function headerStyle(): array
    {
        return [
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FF1F2937'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFE2E8F0'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFCBD5E1'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
    }

    /**
     * The workbook is fully serialized before the response is returned, so the
     * deferred callback only emits frozen bytes and cannot fail part-way.
     */
    private function download(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        // In memory, not php://temp: private rows never spill to an OS temp file.
        $handle = fopen('php://memory', 'w+b');
        try {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($handle);
            rewind($handle);
            $bytes = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
            $spreadsheet->disconnectWorksheets();
        }

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, $filename, [
            'Content-Type' => self::XLSX_MIME_TYPE,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
