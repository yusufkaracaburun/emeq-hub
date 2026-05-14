<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PassThroughCall>
 */
class PassThroughCallFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $consumer = Consumer::factory();
        $account = Account::factory()->for($consumer);

        return [
            'consumer_id' => $consumer,
            'account_id' => $account,
            'connection_id' => Connection::factory()->forSnelstart()->for($account),
            'provider' => 'snelstart',
            'method' => 'GET',
            'path' => 'echo/ping',
            'status' => 200,
            'duration_ms' => fake()->numberBetween(20, 400),
            'request_fingerprint' => null,
            'response_size_bytes' => fake()->numberBetween(20, 5000),
            'upstream_error' => null,
            'created_at' => now(),
        ];
    }
}
