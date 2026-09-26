<?php

namespace Database\Factories;

use App\Models\AttentionItem;
use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttentionItem>
 */
class AttentionItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => fake()->uuid(),
            'import_batch_id' => ImportBatch::factory(),
            'awb' => '0201234567890',
            'reason_code' => 'HIGH_VALUE',
            'severity' => 'critical',
            'title' => 'High Value Shipment',
            'message' => 'Shipment membutuhkan perhatian khusus.',
            'declared_value' => 4250000,
            'active' => true,
        ];
    }
}
