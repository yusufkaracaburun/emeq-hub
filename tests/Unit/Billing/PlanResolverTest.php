<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Billing\Exceptions\UnknownPlanException;
use App\Billing\PlanResolver;
use Tests\TestCase;

class PlanResolverTest extends TestCase
{
    private PlanResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing-plans' => [
            'naschool-license' => [
                'amount' => ['value' => '49.00', 'currency' => 'EUR'],
                'interval' => '1 month',
                'description' => 'Naschool license — test',
            ],
            'planny-license' => [
                'amount' => ['value' => '29.00', 'currency' => 'EUR'],
                'interval' => '1 month',
                'description' => 'Planny license — test',
            ],
        ]]);

        $this->resolver = new PlanResolver;
    }

    public function test_find_returns_plan_array_for_known_slug(): void
    {
        $plan = $this->resolver->find('naschool-license');

        $this->assertArrayHasKey('amount', $plan);
        $this->assertArrayHasKey('interval', $plan);
        $this->assertArrayHasKey('description', $plan);
        $this->assertSame('1 month', $plan['interval']);
    }

    public function test_find_throws_unknown_plan_exception_for_unknown_slug(): void
    {
        $this->expectException(UnknownPlanException::class);
        $this->expectExceptionMessageMatches('/does-not-exist/');

        $this->resolver->find('does-not-exist');
    }

    public function test_all_returns_indexed_array_of_all_configured_plans(): void
    {
        $plans = $this->resolver->all();

        $this->assertCount(2, $plans);
        $this->assertArrayHasKey('naschool-license', $plans);
        $this->assertArrayHasKey('planny-license', $plans);
    }

    public function test_returned_plan_shape_matches_cashier_expected_shape(): void
    {
        $plan = $this->resolver->find('naschool-license');

        $this->assertIsArray($plan['amount']);
        $this->assertArrayHasKey('value', $plan['amount']);
        $this->assertArrayHasKey('currency', $plan['amount']);
        $this->assertSame('EUR', $plan['amount']['currency']);
        $this->assertIsString($plan['amount']['value']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $plan['amount']['value']);
    }

    public function test_find_is_case_sensitive(): void
    {
        $this->expectException(UnknownPlanException::class);

        $this->resolver->find('Naschool-License');
    }

    public function test_resolver_is_container_bindable(): void
    {
        $resolved = $this->app->make(PlanResolver::class);

        $this->assertInstanceOf(PlanResolver::class, $resolved);
    }
}
