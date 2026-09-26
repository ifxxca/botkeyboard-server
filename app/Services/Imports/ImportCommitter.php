<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;
use App\Services\AuditWriter;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportCommitter
{
    public function __construct(private readonly AuditWriter $auditWriter) {}

    public function commit(ImportBatch $batch, int $actorUserId): void
    {
        $path = Storage::disk('local')->path($batch->stored_path);

        if (! is_file($path) || hash_file('sha256', $path) !== $batch->file_sha256) {
            throw new DomainException('Uploaded source file is missing or changed; upload it again.');
        }

        DB::transaction(function () use ($batch, $actorUserId): void {
            $active = DB::table('active_datasets')->where('id', 1)->lockForUpdate()->first();
            $lockedBatch = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($active === null || $lockedBatch->status !== 'VALIDATED' || $lockedBatch->valid_rows < 1 ||
                $lockedBatch->invalid_rows !== 0 || $lockedBatch->duplicate_rows !== 0) {
                throw new DomainException('Only a clean validated import with at least one selected AWB can be committed.');
            }

            $base = $lockedBatch->base_active_batch_id === null ? null : (int) $lockedBatch->base_active_batch_id;
            $current = $active->active_batch_id === null ? null : (int) $active->active_batch_id;

            if ($base !== $current) {
                throw new DomainException('Active dataset changed since preview; upload a new workbook.');
            }

            $inserted = 0;

            DB::table('import_rows')->where('import_batch_id', $lockedBatch->id)
                ->where('status', 'VALID')->orderBy('id')->chunkById(500, function ($rows) use ($lockedBatch, &$inserted): void {
                    $items = [];

                    foreach ($rows as $row) {
                        $items[] = [
                            'public_id' => (string) Str::uuid(),
                            'import_batch_id' => $lockedBatch->id,
                            'awb' => $row->awb,
                            'reason_code' => $row->reason_code,
                            'severity' => $row->severity,
                            'title' => $row->title,
                            'message' => $row->message,
                            'declared_value' => $row->declared_value,
                            'active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    DB::table('attention_items')->insert($items);
                    $inserted += count($items);
                });

            if ($inserted !== $lockedBatch->valid_rows) {
                throw new DomainException('Preview row count changed; commit rolled back.');
            }

            DB::table('active_datasets')->where('id', 1)->update([
                'active_batch_id' => $lockedBatch->id, 'updated_at' => now(),
            ]);
            $lockedBatch->update(['status' => 'COMMITTED', 'committed_at' => now()]);
            $this->auditWriter->record('IMPORT_COMMITTED', $actorUserId, 'import_batch', $lockedBatch->id,
                ['valid_rows' => $inserted]);
        }, attempts: 3);
    }
}
