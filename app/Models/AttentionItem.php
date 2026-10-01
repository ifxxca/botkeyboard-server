<?php

namespace App\Models;

use Database\Factories\AttentionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['public_id', 'import_batch_id', 'awb', 'reason_code', 'severity', 'title', 'message', 'declared_value', 'active', 'valid_from', 'valid_until'])]
class AttentionItem extends Model
{
    /** @use HasFactory<AttentionItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'declared_value' => 'integer', 'valid_from' => 'datetime', 'valid_until' => 'datetime'];
    }

    public function acknowledgement(): HasOne
    {
        return $this->hasOne(AttentionAcknowledgement::class);
    }
}
