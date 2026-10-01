<?php

namespace App\Services\Imports;

use App\Domain\Attention\AwbNormalizer;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ZipArchive;

class WorkbookPreview
{
    private const MAX_DATA_ROWS = 50000;

    private const HEADERS = ['AWB', 'VALUE', 'REASON', 'SEVERITY', 'TITLE', 'MESSAGE'];

    private const REASONS = ['HIGH_VALUE', 'VIP', 'FRAGILE', 'SPECIAL_HANDLING', 'HOLD', 'PROBLEM_SHIPMENT'];

    public function __construct(private readonly AwbNormalizer $normalizer) {}

    public function preview(ImportBatch $batch, string $absolutePath): void
    {
        $this->checkArchiveSize($absolutePath);
        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($absolutePath);

        try {
            if ($workbook->getSheetCount() !== 1) {
                throw new InvalidArgumentException('Workbook must contain exactly one worksheet.');
            }

            $sheet = $workbook->getActiveSheet();
            $lastRow = $sheet->getHighestDataRow();

            if ($lastRow < 2 || $lastRow - 1 > self::MAX_DATA_ROWS) {
                throw new InvalidArgumentException('Workbook must contain 1–50000 data rows.');
            }

            $headers = $this->headers($sheet);
            $seen = [];
            $counts = ['total_rows' => 0, 'valid_rows' => 0, 'invalid_rows' => 0, 'duplicate_rows' => 0];

            DB::transaction(function () use ($batch, $sheet, $headers, $lastRow, &$seen, &$counts): void {
                $pending = [];

                for ($rowNumber = 2; $rowNumber <= $lastRow; $rowNumber++) {
                    $row = $this->parseRow($sheet, $headers, $rowNumber, $seen);

                    if ($row === null) {
                        continue;
                    }

                    $counts['total_rows']++;

                    if ($row['status'] !== 'EXCLUDED') {
                        $counts[strtolower($row['status']).'_rows']++;
                    }

                    $pending[] = ['import_batch_id' => $batch->id, 'row_number' => $rowNumber, ...$row,
                        'created_at' => now(), 'updated_at' => now()];

                    if (count($pending) >= 500) {
                        DB::table('import_rows')->insert($pending);
                        $pending = [];
                    }
                }

                if ($pending !== []) {
                    DB::table('import_rows')->insert($pending);
                }

                if ($counts['total_rows'] === 0) {
                    throw new InvalidArgumentException('Workbook contains no non-empty data rows.');
                }

                $batch->update([...$counts, 'status' => 'VALIDATED']);
            });
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    private function checkArchiveSize(string $path): void
    {
        $archive = new ZipArchive;

        if ($archive->open($path) !== true) {
            throw new InvalidArgumentException('Workbook is not a valid .xlsx archive.');
        }

        try {
            if ($archive->numFiles > 2000) {
                throw new InvalidArgumentException('Workbook contains too many archive entries.');
            }

            $expandedBytes = 0;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $expandedBytes += $entry['size'] ?? 0;

                if ($expandedBytes > 150_000_000) {
                    throw new InvalidArgumentException('Workbook expands beyond the safety limit.');
                }
            }
        } finally {
            $archive->close();
        }
    }

    /** @return array<string, int> */
    private function headers(Worksheet $sheet): array
    {
        $rawHeaders = [];
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($column = 1; $column <= $lastColumn; $column++) {
            $cell = $sheet->getCell([$column, 1]);

            if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                throw new InvalidArgumentException('Header formulas are not allowed.');
            }

            $rawHeaders[$column] = strtoupper(trim((string) $cell->getValue()));
        }

        $isHviReport = in_array('CNOTE NO', $rawHeaders, true)
            && in_array('CNOTE GOODS VALUE', $rawHeaders, true);

        if ($isHviReport) {
            $columns = [];
            $aliases = ['CNOTE NO' => 'AWB', 'CNOTE GOODS VALUE' => 'VALUE'];

            foreach ($rawHeaders as $column => $header) {
                $canonical = $aliases[$header] ?? null;

                if ($canonical !== null) {
                    if (isset($columns[$canonical])) {
                        throw new InvalidArgumentException('Workbook has duplicate HVI/RDO headers.');
                    }

                    $columns[$canonical] = $column;
                }
            }

            $columns['__HVI_REPORT'] = -1;

            return $columns;
        }

        if ($lastColumn > count(self::HEADERS)) {
            throw new InvalidArgumentException('Workbook contains unexpected columns.');
        }

        $columns = [];

        foreach ($rawHeaders as $column => $header) {

            if ($header === '' || ! in_array($header, self::HEADERS, true) || isset($columns[$header])) {
                throw new InvalidArgumentException('Workbook headers are missing, duplicated, or unsupported.');
            }

            $columns[$header] = $column;
        }

        if (! isset($columns['AWB'])) {
            throw new InvalidArgumentException('AWB header is required.');
        }

        return $columns;
    }

    /** @param array<string, int> $headers
     * @param  array<string, bool>  $seen
     * @return array<string, int|string|null>|null
     */
    private function parseRow(Worksheet $sheet, array $headers, int $rowNumber, array &$seen): ?array
    {
        $cells = [];

        foreach ($headers as $header => $column) {
            if ($header === '__HVI_REPORT') {
                continue;
            }

            $cells[$header] = $sheet->getCell([$column, $rowNumber]);
        }

        if (collect($cells)->every(fn (Cell $cell): bool => $cell->getValue() === null || $cell->getValue() === '')) {
            return null;
        }

        if (isset($headers['__HVI_REPORT'])) {
            return $this->parseHviRow($cells, $seen);
        }

        $issue = null;
        $awb = null;

        try {
            $awbCell = $cells['AWB'];

            if (! in_array($awbCell->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_STRING2, DataType::TYPE_INLINE], true)) {
                throw new InvalidArgumentException('AWB must be stored as text, not numeric or formula.');
            }

            $awb = $this->normalizer->normalize((string) $awbCell->getValue());
            $reason = $this->text($cells['REASON'] ?? null, 'HIGH_VALUE', 32);
            $reason = strtoupper($reason);

            if (! in_array($reason, self::REASONS, true)) {
                throw new InvalidArgumentException('Unknown reason code.');
            }

            $severity = strtolower($this->text($cells['SEVERITY'] ?? null, 'critical', 16));

            if (! in_array($severity, ['critical', 'warning', 'info'], true)) {
                throw new InvalidArgumentException('Invalid severity.');
            }

            $title = $this->text($cells['TITLE'] ?? null, $reason === 'HIGH_VALUE' ? 'High Value Shipment' : str_replace('_', ' ', $reason), 120);
            $message = $this->text($cells['MESSAGE'] ?? null, 'Shipment membutuhkan perhatian khusus.', 500);
            $value = $this->value($cells['VALUE'] ?? null);
            $key = $awb."\0".$reason;

            if (isset($seen[$key])) {
                return ['awb' => $awb, 'reason_code' => $reason, 'severity' => $severity,
                    'title' => $title, 'message' => $message, 'declared_value' => $value,
                    'status' => 'DUPLICATE', 'issue' => 'Repeated AWB/reason in workbook.'];
            }

            $seen[$key] = true;

            return ['awb' => $awb, 'reason_code' => $reason, 'severity' => $severity,
                'title' => $title, 'message' => $message, 'declared_value' => $value,
                'status' => 'VALID', 'issue' => null];
        } catch (InvalidArgumentException $exception) {
            $issue = $exception->getMessage();
        }

        return ['awb' => $awb, 'reason_code' => null, 'severity' => null,
            'title' => null, 'message' => null, 'declared_value' => null,
            'status' => 'INVALID', 'issue' => $issue];
    }

    /** @param array<string, Cell> $cells
     * @param  array<string, bool>  $seen
     * @return array<string, int|string|null>
     */
    private function parseHviRow(array $cells, array &$seen): array
    {
        try {
            $value = $this->value($cells['VALUE']);

            if ($value === null) {
                return $this->hviRow('EXCLUDED', 'Cnote Goods Value is empty.');
            }

            if ($value <= 2_000_000) {
                return $this->hviRow('EXCLUDED', 'Cnote Goods Value is not above 2000000.');
            }

            $awbCell = $cells['AWB'];

            if (! in_array($awbCell->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_STRING2, DataType::TYPE_INLINE], true)) {
                throw new InvalidArgumentException('Cnote No must be stored as text, not numeric or formula.');
            }

            $rawAwb = trim((string) $awbCell->getValue(), ' ');
            $rawAwb = str_starts_with($rawAwb, "'") ? substr($rawAwb, 1) : $rawAwb;
            // HVI/RDO exports may add hyphens as visual separators in Cnote No.
            $awb = $this->normalizer->normalize(str_replace('-', '', $rawAwb));

            if (isset($seen[$awb])) {
                return $this->hviRow('DUPLICATE', 'Repeated qualifying AWB in workbook.', $awb);
            }

            $seen[$awb] = true;

            return $this->hviRow('VALID', null, $awb, $value);
        } catch (InvalidArgumentException $exception) {
            return $this->hviRow('INVALID', $exception->getMessage());
        }
    }

    /** @return array<string, int|string|null> */
    private function hviRow(string $status, ?string $issue, ?string $awb = null, ?int $value = null): array
    {
        return ['awb' => $awb, 'reason_code' => $status === 'VALID' ? 'HIGH_VALUE' : null,
            'severity' => $status === 'VALID' ? 'critical' : null,
            'title' => $status === 'VALID' ? 'High Value Shipment' : null,
            'message' => $status === 'VALID' ? 'Shipment membutuhkan perhatian khusus.' : null,
            'declared_value' => $value, 'status' => $status, 'issue' => $issue];
    }

    private function text(?Cell $cell, string $default, int $limit): string
    {
        if ($cell === null || $cell->getValue() === null || $cell->getValue() === '') {
            return $default;
        }

        if (! in_array($cell->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_STRING2, DataType::TYPE_INLINE], true)) {
            throw new InvalidArgumentException('Text field must not be formula or numeric.');
        }

        $value = trim((string) $cell->getValue());

        if ($value === '' || mb_strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || str_starts_with($value, '=')) {
            throw new InvalidArgumentException('Text field contains invalid content.');
        }

        return $value;
    }

    private function value(?Cell $cell): ?int
    {
        if ($cell === null || $cell->getValue() === null || $cell->getValue() === '') {
            return null;
        }

        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            throw new InvalidArgumentException('Value formula is not allowed.');
        }

        $raw = $cell->getValue();
        $text = is_int($raw) || is_float($raw) || is_string($raw) ? (string) $raw : '';

        if (preg_match('/\A(?:0|[1-9][0-9]{0,14})\z/D', $text) !== 1) {
            throw new InvalidArgumentException('Value must be a whole number of at most 15 digits.');
        }

        return (int) $text;
    }
}
