<?php

namespace App\Domain\Attention;

use Illuminate\Support\Facades\DB;

class AttentionLookup
{
    /** @return array{matched: bool, alerts?: list<array{id: string, reason: string, severity: string, title: string, message: string, declared_value: ?int}>} */
    public function check(string $awb): array
    {
        $now = now();
        $items = DB::table('active_datasets as dataset')
            ->join('attention_items as item', 'item.import_batch_id', '=', 'dataset.active_batch_id')
            ->where('dataset.id', 1)
            ->where('item.awb', $awb)
            ->where('item.active', true)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('attention_acknowledgements as acknowledgement')
                    ->whereColumn('acknowledgement.attention_item_id', 'item.id');
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('item.valid_from')->orWhere('item.valid_from', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('item.valid_until')->orWhere('item.valid_until', '>', $now);
            })
            ->get(['item.public_id', 'item.reason_code', 'item.severity', 'item.title', 'item.message', 'item.declared_value']);

        if ($items->isEmpty()) {
            return ['matched' => false];
        }

        $alerts = $items->map(fn ($item): array => [
            'id' => $item->public_id,
            'reason' => $item->reason_code,
            'severity' => $item->severity,
            'title' => $item->title,
            'message' => $item->message,
            'declared_value' => $item->declared_value === null ? null : (int) $item->declared_value,
        ])->all();

        $priority = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn (array $a, array $b): int => (($priority[$a['severity']] ?? 3) <=> ($priority[$b['severity']] ?? 3))
            ?: strcmp($a['reason'], $b['reason']));

        return ['matched' => true, 'alerts' => $alerts];
    }
}
