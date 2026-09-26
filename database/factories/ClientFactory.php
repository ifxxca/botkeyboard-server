<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
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
            'name' => fake()->unique()->bothify('PC-OUTBOUND-##'),
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'active' => true,
        ];
    }
}
