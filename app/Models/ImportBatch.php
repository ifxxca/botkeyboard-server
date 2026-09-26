<?php

namespace App\Models;

use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['public_id', 'filename', 'file_sha256', 'stored_path', 'status', 'total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows', 'created_by', 'base_active_batch_id', 'committed_at'])]
class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['committed_at' => 'datetime'];
    }
}
