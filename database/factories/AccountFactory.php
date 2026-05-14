<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Consumer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consumer_id' => Consumer::factory(),
            'external_id' => 'ext-'.fake()->unique()->numerify('######'),
            'display_name' => fake()->company(),
        ];
    }
}
