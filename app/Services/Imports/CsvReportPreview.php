<?php

namespace App\Services\Imports;

use App\Domain\Attention\AwbNormalizer;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CsvReportPreview
{
    private const MINIMUM_GOODS_VALUE = 2_000_000;

    private const MAX_DATA_ROWS = 50000;

    public function __construct(private readonly AwbNormalizer $normalizer) {}

    public function preview(ImportBatch $batch, string $absolutePath): void
    {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new InvalidArgumentException('CSV report could not be opened.');
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');

            if ($header === false) {
                throw new InvalidArgumentException('CSV report is empty.');
            }

            $columns = $this->columns($header);
            $counts = ['total_rows' => 0, 'valid_rows' => 0, 'invalid_rows' => 0, 'duplicate_rows' => 0];
            $seen = [];

            DB::transaction(function () use ($batch, $handle, $columns, $header, &$counts, &$seen): void {
                $pending = [];
                $rowNumber = 1;

                while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    $rowNumber++;

                    if ($rowNumber - 1 > self::MAX_DATA_ROWS) {
                        throw new InvalidArgumentException('CSV report exceeds 50000 data rows.');
                    }

                    if (count($fields) === 1 && ($fields[0] === null || $fields[0] === '')) {
                        continue;
                    }

                    $row = count($fields) === count($header)
                        ? $this->parseRow($fields, $columns, $seen)
                        : $this->row('INVALID', 'CSV column count differs from header.');

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
                    throw new InvalidArgumentException('CSV report contains no data rows.');
                }

                $batch->update([...$counts, 'status' => 'VALIDATED']);
            });
        } finally {
            fclose($handle);
        }
    }

    /** @param array<int, string|null> $header
     * @return array{AWB: int, GOODS_VALUE: int}
     */
    private function columns(array $header): array
    {
        $found = [];

        foreach ($header as $index => $name) {
            $normalized = strtoupper(trim($index === 0 ? preg_replace('/^\xEF\xBB\xBF/', '', (string) $name) : (string) $name));

            if (in_array($normalized, ['AWB', 'GOODS_VALUE'], true)) {
                if (isset($found[$normalized])) {
                    throw new InvalidArgumentException('CSV report has duplicate required headers.');
                }

                $found[$normalized] = $index;
            }
        }

        if (! isset($found['AWB'], $found['GOODS_VALUE'])) {
            throw new InvalidArgumentException('CSV report requires AWB and GOODS_VALUE headers.');
        }

        return $found;
    }

    /** @param array<int, string|null> $fields
     * @param  array{AWB: int, GOODS_VALUE: int}  $columns
     * @param  array<string, bool>  $seen
     * @return array<string, int|string|null>
     */
    private function parseRow(array $fields, array $columns, array &$seen): array
    {
        $rawValue = trim((string) $fields[$columns['GOODS_VALUE']]);

        if ($rawValue === '') {
            return $this->row('EXCLUDED', 'GOODS_VALUE is empty.');
        }

        if (preg_match('/\A(?:0|[1-9][0-9]{0,14})\z/D', $rawValue) !== 1) {
            return $this->row('INVALID', 'GOODS_VALUE must be a whole number of at most 15 digits.');
        }

        $value = (int) $rawValue;

        if ($value <= self::MINIMUM_GOODS_VALUE) {
            return $this->row('EXCLUDED', 'GOODS_VALUE is not above 2000000.');
        }

        try {
            $rawAwb = trim((string) $fields[$columns['AWB']], ' ');
            // The operational CSV prefixes identifiers with an apostrophe to protect them in Excel.
            $awb = $this->normalizer->normalize(str_starts_with($rawAwb, "'") ? substr($rawAwb, 1) : $rawAwb);
        } catch (InvalidArgumentException $exception) {
            return $this->row('INVALID', $exception->getMessage());
        }

        if (isset($seen[$awb])) {
            return $this->row('DUPLICATE', 'Repeated qualifying AWB in CSV.', $awb);
        }

        $seen[$awb] = true;

        return $this->row('VALID', null, $awb, $value);
    }

    /** @return array<string, int|string|null> */
    private function row(string $status, ?string $issue, ?string $awb = null, ?int $value = null): array
    {
        return ['awb' => $awb, 'reason_code' => $status === 'VALID' ? 'HIGH_VALUE' : null,
            'severity' => $status === 'VALID' ? 'critical' : null,
            'title' => $status === 'VALID' ? 'High Value Shipment' : null,
            'message' => $status === 'VALID' ? 'Shipment membutuhkan perhatian khusus.' : null,
            'declared_value' => $value, 'status' => $status, 'issue' => $issue];
    }
}
