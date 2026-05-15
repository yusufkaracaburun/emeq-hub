<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountSubscription>
 */
class AccountSubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $account = Account::factory();
        $connection = Connection::factory()->forMollie()->for($account);

        return [
            'account_id' => $account,
            'connection_id' => $connection,
            'mollie_customer_id' => 'cst_'.fake()->bothify('??????????'),
            'mollie_subscription_id' => null,
            'mollie_mandate_id' => null,
            'status' => 'pending',
            'amount_currency' => 'EUR',
            'amount_value' => '10.00',
            'interval' => '1 month',
            'description' => 'Test bijdrage',
            'times' => null,
            'start_date' => null,
            'starts_at' => null,
            'paused_at' => null,
            'canceled_at' => null,
            'completed_at' => null,
            'metadata' => null,
            'last_payment_status' => null,
            'last_webhook_event_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'pending',
            'mollie_subscription_id' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'active',
            'mollie_subscription_id' => 'sub_'.fake()->bothify('??????????'),
            'starts_at' => now(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'paused',
            'mollie_subscription_id' => $attributes['mollie_subscription_id'] ?? 'sub_'.fake()->bothify('??????????'),
            'paused_at' => now(),
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'canceled',
            'mollie_subscription_id' => $attributes['mollie_subscription_id'] ?? 'sub_'.fake()->bothify('??????????'),
            'canceled_at' => now(),
        ]);
    }

    public function forConnection(Connection $connection): static
    {
        return $this->state(fn (array $attributes): array => [
            'connection_id' => $connection->id,
            'account_id' => $connection->account_id,
        ]);
    }
}
