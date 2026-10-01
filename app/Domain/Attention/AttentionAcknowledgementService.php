<?php

namespace App\Domain\Attention;

use App\Models\Client;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttentionAcknowledgementService
{
    public function __construct(private readonly AuditWriter $auditWriter) {}

    /** @return array{acknowledged: true, already_acknowledged: bool}|null */
    public function acknowledge(string $attentionPublicId, Client $client, string $method): ?array
    {
        return DB::transaction(function () use ($attentionPublicId, $client, $method): ?array {
            $dataset = DB::table('active_datasets')->where('id', 1)->lockForUpdate()->first();

            if ($dataset === null || $dataset->active_batch_id === null) {
                return null;
            }

            $now = now();
            $target = DB::table('attention_items')
                ->where('public_id', $attentionPublicId)
                ->where('import_batch_id', $dataset->active_batch_id)
                ->where('active', true)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('valid_until')->orWhere('valid_until', '>', $now);
                })
                ->first(['id', 'public_id', 'import_batch_id', 'awb']);

            if ($target === null) {
                return null;
            }

            $items = DB::table('attention_items as item')
                ->where('item.import_batch_id', $target->import_batch_id)
                ->where('item.awb', $target->awb)
                ->where('item.active', true)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('item.valid_from')->orWhere('item.valid_from', '<=', $now);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('item.valid_until')->orWhere('item.valid_until', '>', $now);
                })
                ->orderBy('item.id')
                ->lockForUpdate()
                ->get(['item.id', 'item.public_id']);

            $itemIds = $items->pluck('id');
            $existingIds = DB::table('attention_acknowledgements')
                ->whereIn('attention_item_id', $itemIds)
                ->pluck('attention_item_id')
                ->map(static fn ($id): int => (int) $id);
            $alreadyAcknowledged = $existingIds->count() === $items->count();
            $timestamp = now();
            $newAcknowledgements = $items
                ->reject(static fn ($item): bool => $existingIds->contains((int) $item->id))
                ->map(static fn ($item): array => [
                    'public_id' => (string) Str::uuid(),
                    'attention_item_id' => $item->id,
                    'client_id' => $client->id,
                    'method' => $method,
                    'acknowledged_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])
                ->all();

            if ($newAcknowledgements !== []) {
                DB::table('attention_acknowledgements')->insert($newAcknowledgements);
                $this->auditWriter->record('ATTENTION_ACKNOWLEDGED', null, 'attention_item', (int) $target->id, [
                    'attention_public_id' => $target->public_id,
                    'client_id' => $client->id,
                    'method' => $method,
                    'acknowledged_item_count' => count($newAcknowledgements),
                ]);
            }

            return ['acknowledged' => true, 'already_acknowledged' => $alreadyAcknowledged];
        }, attempts: 3);
    }
}
