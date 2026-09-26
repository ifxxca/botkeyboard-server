<?php

namespace Tests\Feature;

use App\Models\AttentionItem;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttentionItemAdministrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_active_items_are_paginated_and_can_be_searched_by_exact_awb(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = ImportBatch::factory()->create();
        DB::table('active_datasets')->where('id', 1)->update(['active_batch_id' => $batch->id]);

        for ($number = 0; $number < 30; $number++) {
            AttentionItem::factory()->create([
                'import_batch_id' => $batch->id,
                'awb' => sprintf('020%010d', $number),
            ]);
        }

        $this->get(route('admin.items.index'))
            ->assertOk()->assertViewHas('items', fn ($items): bool => $items->count() === 25);
        $this->get(route('admin.items.index', ['page' => 2]))
            ->assertOk()->assertViewHas('items', fn ($items): bool => $items->count() === 5);
        $this->get(route('admin.items.index', ['awb' => '0200000000007']))
            ->assertOk()->assertViewHas('items', fn ($items): bool => $items->count() === 1)
            ->assertSee('0200000000007');
    }
}
