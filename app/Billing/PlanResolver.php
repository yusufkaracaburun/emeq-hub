<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\Exceptions\UnknownPlanException;

/**
 * Config-driven plan-resolver voor Cashier-Mollie subscriptions.
 *
 * D-05: plans worden in config/billing-plans.php gedefinieerd (niet in
 *       Cashier's eigen vendor-config-tree).
 * D-06: simpele find()/all() public API zonder Eloquent Plan-model.
 *
 * Retourneert plan-arrays in Cashier-Mollie ^2.20's verwachte shape
 * zodat plan 06-05 ze 1:1 aan SubscriptionBuilder kan voeren.
 */
final class PlanResolver
{
    /**
     * @return array{amount: array{value: string, currency: string}, interval: string, description: string}
     *
     * @throws UnknownPlanException Wanneer de slug niet in config/billing-plans.php staat.
     */
    public function find(string $slug): array
    {
        /** @var array<string, mixed>|null $plan */
        $plan = config("billing-plans.{$slug}");

        if (! is_array($plan)) {
            throw UnknownPlanException::forSlug($slug);
        }

        /** @var array{amount: array{value: string, currency: string}, interval: string, description: string} $plan */
        return $plan;
    }

    /**
     * @return array<string, array{amount: array{value: string, currency: string}, interval: string, description: string}>
     */
    public function all(): array
    {
        /** @var array<string, array{amount: array{value: string, currency: string}, interval: string, description: string}> $plans */
        $plans = config('billing-plans', []);

        return $plans;
    }
}
