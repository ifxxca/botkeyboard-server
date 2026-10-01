<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['public_id', 'name', 'token_hash', 'active', 'last_authenticated_at', 'last_seen_at', 'agent_version'])]
#[Hidden(['token_hash'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_authenticated_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function connectionStatus(): string
    {
        if (! $this->active) {
            return 'disabled';
        }

        if ($this->last_seen_at === null) {
            return 'never';
        }

        return $this->last_seen_at->greaterThanOrEqualTo(now()->subSeconds((int) config('attention.client_online_seconds')))
            ? 'online' : 'offline';
    }

    public function attentionAcknowledgements(): HasMany
    {
        return $this->hasMany(AttentionAcknowledgement::class);
    }
}
