<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
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
            'filename' => 'attention.xlsx',
            'file_sha256' => str_repeat('a', 64),
            'stored_path' => 'imports/test.xlsx',
            'status' => 'VALIDATED',
            'total_rows' => 1,
            'valid_rows' => 1,
            'invalid_rows' => 0,
            'duplicate_rows' => 0,
        ];
    }
}
