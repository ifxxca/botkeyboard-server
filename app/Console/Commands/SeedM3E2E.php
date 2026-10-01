<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('app:seed-m3-e2e {tokenHash} {secondTokenHash}')]
#[Description('Seed a dedicated empty local M3 integration database; accepts only token hashes')]
class SeedM3E2E extends Command
{
    public function handle(): int
    {
        $database = (string) config('database.connections.mysql.database');
        $hash = (string) $this->argument('tokenHash');
        $secondHash = (string) $this->argument('secondTokenHash');

        if (app()->isProduction() || config('database.default') !== 'mysql' ||
            ! str_ends_with($database, '_m3_e2e') || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1 ||
            preg_match('/\A[a-f0-9]{64}\z/D', $secondHash) !== 1 || hash_equals($hash, $secondHash) ||
            DB::table('clients')->exists() || DB::table('import_batches')->exists()) {
            $this->error('Use an empty non-production MySQL database ending _m3_e2e and two distinct SHA-256 token hashes.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($hash, $secondHash): void {
            $batch = DB::table('import_batches')->insertGetId([
                'public_id' => (string) Str::uuid(), 'filename' => 'M3 integration fixture',
                'file_sha256' => str_repeat('0', 64), 'stored_path' => 'fixture-only',
                'status' => 'COMMITTED', 'total_rows' => 4, 'valid_rows' => 4,
                'committed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ([
                ['0201234567890', 'HIGH_VALUE', 'critical', 'High Value Shipment', 'Handle with care'],
                ['0209876543210', 'HIGH_VALUE', 'critical', 'High Value Shipment', 'Check value'],
                ['0209876543210', 'FRAGILE', 'warning', 'Fragile Shipment', 'Handle gently'],
                ['0200000000001', 'HIGH_VALUE', 'critical', 'Concurrency Fixture', 'Concurrency regression'],
            ] as [$awb, $reason, $severity, $title, $message]) {
                DB::table('attention_items')->insert([
                    'public_id' => (string) Str::uuid(), 'import_batch_id' => $batch,
                    'awb' => $awb, 'reason_code' => $reason, 'severity' => $severity,
                    'title' => $title, 'message' => $message, 'active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('active_datasets')->where('id', 1)->update(['active_batch_id' => $batch, 'updated_at' => now()]);
            DB::table('clients')->insert([
                'public_id' => (string) Str::uuid(), 'name' => 'PC-M3-E2E',
                'token_hash' => $hash, 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('clients')->insert([
                'public_id' => (string) Str::uuid(), 'name' => 'PC-M3-E2E-SECOND',
                'token_hash' => $secondHash, 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->info('M3 fixture ready. No plaintext token was stored or printed.');

        return self::SUCCESS;
    }
}
