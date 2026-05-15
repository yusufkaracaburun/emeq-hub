<?php

namespace Database\Factories;

use App\Models\Consumer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
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

    /**
     * Maak een Consumer aan met een actieve subscription-rij voor de gegeven
     * plan-slug. Vereist dat plan 06-02's subscriptions-tabel is gemigreerd.
     * Cashier-Mollie's eigen factory-helpers zijn niet stabiel in v2.x; deze
     * state schrijft een minimale rij die de `subscribed('main')`-assert groen
     * maakt. Geen Mollie-API-hit — alleen DB-state.
     */
    public function withActiveSubscription(string $planSlug = 'naschool-license', string $subscriptionName = 'main'): static
    {
        return $this->afterCreating(function (Consumer $consumer) use ($planSlug, $subscriptionName): void {
            DB::table('subscriptions')->insert([
                'name' => $subscriptionName,
                'plan' => $planSlug,
                'owner_id' => $consumer->id,
                'owner_type' => Consumer::class,
                'quantity' => 1,
                'tax_percentage' => 21,
                'cycle_started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
