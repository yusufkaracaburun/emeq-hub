<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'provider' => 'snelstart',
            'status' => 'active',
        ];
    }

    public function forSnelstart(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'snelstart',
            'client_key' => 'CK-'.Str::random(40),
            'subscription_key' => 'SK-'.Str::random(40),
            'subscription_id' => (string) Str::uuid(),
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'scopes' => null,
        ]);
    }

    public function forMollie(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'mollie',
            'access_token' => 'access_'.Str::random(40),
            'refresh_token' => 'refresh_'.Str::random(40),
            'expires_at' => now()->addHour(),
            'scopes' => ['payments.read', 'payments.write'],
            'client_key' => null,
            'subscription_key' => null,
            'subscription_id' => null,
        ]);
    }
}
