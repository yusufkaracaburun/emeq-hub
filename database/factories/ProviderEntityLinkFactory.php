<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\ProviderEntityLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProviderEntityLink> */
class ProviderEntityLinkFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $account = Account::factory()->for(Consumer::factory());

        return [
            'connection_id' => Connection::factory()->forExact()->for($account),
            'provider' => 'exact',
            'entity_type' => ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT,
            'external_id' => 'INV-'.fake()->unique()->numberBetween(1000, 9999),
            'provider_entity_id' => fake()->uuid(),
            'provider_entity_number' => (string) fake()->numberBetween(1, 9999),
            'payload_fingerprint' => hash('sha256', fake()->uuid()),
            'origin' => ProviderEntityLink::ORIGIN_HUB,
            'last_synced_at' => now(),
        ];
    }
}
