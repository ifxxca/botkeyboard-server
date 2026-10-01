<?php

namespace Database\Factories;

use App\Models\AttentionAcknowledgement;
use App\Models\AttentionItem;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AttentionAcknowledgement>
 */
class AttentionAcknowledgementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'attention_item_id' => AttentionItem::factory(),
            'client_id' => Client::factory(),
            'method' => 'SCAN',
            'acknowledged_at' => now(),
        ];
    }
}
