<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Throwable;

class GuestImportExcelReader
{
    public const MAX_ROWS = 5000;

    public const REQUIRED_COLUMNS = [
        'name',
        'phone_number',
        'pass_type',
        'allowed_entries',
    ];

    /**
     * @return array{0: array<int, array{row_number: int, data: array<string, string>}>, 1: array<int, string>}
     */
    public function read(string $path): array
    {
        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(false);
            $reader->setReadEmptyCells(false);
            $reader->setIncludeCharts(false);
            $spreadsheet = $reader->load($path);
        } catch (Throwable) {
            return [[], ['The Excel workbook could not be opened. Check that it is a valid, unprotected .xlsx file.']];
        }

        try {
            if ($spreadsheet->getSheetCount() < 1) {
                return [[], ['The Excel workbook does not contain a worksheet.']];
            }

            $worksheet = $spreadsheet->getSheet(0);
            $highestHeaderColumn = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn(1));
            $headers = [];

            for ($column = 1; $column <= $highestHeaderColumn; $column++) {
                $headers[$column] = $this->normalizeHeader(
                    $this->cellString($worksheet->getCell([$column, 1]))
                );
            }

            if (collect($headers)->filter()->isEmpty()) {
                return [[], ['The Excel workbook is empty.']];
            }

            $columnIndexes = [];
            $duplicateColumns = [];

            foreach ($headers as $column => $header) {
                if ($header === '') {
                    continue;
                }

                if (array_key_exists($header, $columnIndexes)) {
                    $duplicateColumns[] = $header;
                }

                $columnIndexes[$header] = $column;
            }

            $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($columnIndexes)));

            if ($missingColumns !== []) {
                return [[], ['Missing required columns: '.implode(', ', $missingColumns).'.']];
            }

            $duplicateRequiredColumns = array_values(array_unique(array_intersect($duplicateColumns, self::REQUIRED_COLUMNS)));

            if ($duplicateRequiredColumns !== []) {
                return [[], ['Duplicate required columns: '.implode(', ', $duplicateRequiredColumns).'.']];
            }

            $highestDataRow = $worksheet->getHighestDataRow();

            if ($highestDataRow <= 1) {
                return [[], ['The Excel worksheet does not contain any guest rows.']];
            }

            if ($highestDataRow - 1 > self::MAX_ROWS) {
                return [[], ['The Excel worksheet cannot contain more than '.number_format(self::MAX_ROWS).' guest rows.']];
            }

            $rows = [];

            for ($rowNumber = 2; $rowNumber <= $highestDataRow; $rowNumber++) {
                $data = [];

                foreach (self::REQUIRED_COLUMNS as $columnName) {
                    $cell = $worksheet->getCell([$columnIndexes[$columnName], $rowNumber]);
                    $data[$columnName] = $columnName === 'phone_number'
                        ? $this->phoneCellString($cell)
                        : $this->cellString($cell);
                }

                if ($this->rowIsBlank($data)) {
                    continue;
                }

                $rows[] = [
                    'row_number' => $rowNumber,
                    'data' => $data,
                ];
            }

            if ($rows === []) {
                return [[], ['The Excel worksheet does not contain any guest rows.']];
            }

            return [$rows, []];
        } catch (Throwable) {
            return [[], ['The Excel worksheet could not be read. Check that the workbook is a valid .xlsx file.']];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = mb_strtolower(trim($header), 'UTF-8');
        $header = preg_replace('/[\s\-]+/u', '_', $header) ?? $header;

        return trim($header, '_');
    }

    private function phoneCellString(Cell $cell): string
    {
        $value = $cell->getValue();

        if (is_int($value) || is_float($value)) {
            $formatted = trim($cell->getFormattedValue());

            if (preg_match('/^\+?\d+$/', $formatted)) {
                return $formatted;
            }

            if (is_finite((float) $value) && floor((float) $value) === (float) $value) {
                return sprintf('%.0f', $value);
            }
        }

        return $this->cellString($cell);
    }

    private function cellString(Cell $cell): string
    {
        $value = $cell->getValue();

        if ($value instanceof RichText) {
            return $value->getPlainText();
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return sprintf('%.0f', $value);
        }

        return (string) $value;
    }

    private function rowIsBlank(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
