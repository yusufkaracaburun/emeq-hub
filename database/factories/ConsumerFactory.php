<?php

namespace Database\Factories;

use App\Models\Consumer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Consumer>
 */
class ConsumerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
        ];
    }

    public function withWebhookCallback(?string $url = null, ?string $secret = null): static
    {
        return $this->state(fn (array $attributes) => [
            'webhook_callback_url' => $url ?? 'https://example.test/hooks',
            'webhook_callback_secret' => $secret ?? 'whsec_'.Str::random(32),
        ]);
    }
}
