<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Billing;

use App\Integrations\Mollie\Billing\Exceptions\UnknownPlanException;

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

    /** @return array<string, array{amount: array{value: string, currency: string}, interval: string, description: string}> */
    public function all(): array
    {
        /** @var array<string, array{amount: array{value: string, currency: string}, interval: string, description: string}> $plans */
        $plans = config('billing-plans', []);

        return $plans;
    }
}
