<?php

namespace App\Models;

use Database\Factories\AttentionAcknowledgementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['public_id', 'attention_item_id', 'client_id', 'method', 'acknowledged_at'])]
class AttentionAcknowledgement extends Model
{
    /** @use HasFactory<AttentionAcknowledgementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    public function attentionItem(): BelongsTo
    {
        return $this->belongsTo(AttentionItem::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
