<?php

namespace App\Console\Commands;

use App\Domain\Attention\AttentionLookup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('app:benchmark-lookup {--items=10000} {--checks=1000}')]
#[Description('Seed a dedicated empty *_perf MySQL database and measure indexed lookup latency')]
class BenchmarkLookup extends Command
{
    public function handle(AttentionLookup $lookup): int
    {
        $database = (string) config('database.connections.mysql.database');
        $items = (int) $this->option('items');
        $checks = (int) $this->option('checks');

        if (app()->isProduction() || config('database.default') !== 'mysql' || ! str_ends_with($database, '_perf') ||
            $items < 10000 || $items > 100000 || $checks < 100 || $checks > 5000 ||
            DB::table('import_batches')->exists() || DB::table('attention_items')->exists()) {
            $this->error('Use an empty non-production MySQL database ending in _perf; items 10000–100000, checks 100–5000.');

            return self::FAILURE;
        }

        $batchId = DB::table('import_batches')->insertGetId([
            'public_id' => (string) Str::uuid(), 'filename' => 'benchmark-generated',
            'file_sha256' => str_repeat('0', 64), 'stored_path' => 'none', 'status' => 'COMMITTED',
            'total_rows' => $items, 'valid_rows' => $items,
            'created_at' => now(), 'updated_at' => now(), 'committed_at' => now(),
        ]);

        for ($start = 0; $start < $items; $start += 500) {
            $rows = [];

            for ($number = $start; $number < min($items, $start + 500); $number++) {
                $rows[] = [
                    'public_id' => (string) Str::uuid(), 'import_batch_id' => $batchId,
                    'awb' => str_pad((string) $number, 13, '0', STR_PAD_LEFT),
                    'reason_code' => 'HIGH_VALUE', 'severity' => 'critical',
                    'title' => 'High Value Shipment', 'message' => 'Attention',
                    'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ];
            }

            DB::table('attention_items')->insert($rows);
        }

        DB::table('active_datasets')->where('id', 1)->update(['active_batch_id' => $batchId, 'updated_at' => now()]);

        for ($warmup = 0; $warmup < 100; $warmup++) {
            $lookup->check('0000000000000');
        }

        $latencies = [];
        $matches = 0;

        for ($iteration = 0; $iteration < $checks; $iteration++) {
            $awb = $iteration % 2 === 0
                ? str_pad((string) ($iteration % $items), 13, '0', STR_PAD_LEFT)
                : '9999999999999';
            $started = hrtime(true);
            $result = $lookup->check($awb);
            $latencies[] = (hrtime(true) - $started) / 1_000_000;
            $matches += (int) $result['matched'];
        }

        sort($latencies, SORT_NUMERIC);
        $plan = DB::select('EXPLAIN SELECT item.reason_code FROM active_datasets AS dataset
            JOIN attention_items AS item ON item.import_batch_id = dataset.active_batch_id
            WHERE dataset.id = 1 AND item.awb = ? AND item.active = 1', ['0000000000000']);

        $this->line(json_encode([
            'dataset' => $items, 'checks' => $checks, 'matched_checks' => $matches,
            'median_ms' => round($latencies[intdiv($checks, 2)], 3),
            'p95_ms' => round($latencies[(int) ceil($checks * 0.95) - 1], 3),
            'max_ms' => round($latencies[$checks - 1], 3),
            'explain' => array_map(fn (object $row): array => [
                'table' => $row->table, 'access_type' => $row->type, 'key' => $row->key, 'estimated_rows' => $row->rows,
            ], $plan),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
