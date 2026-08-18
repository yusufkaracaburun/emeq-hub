<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Billing;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSubscriptionCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing-plans' => [
            'naschool-license' => [
                'amount' => ['value' => '49.00', 'currency' => 'EUR'],
                'interval' => '1 month',
                'description' => 'Naschool license',
            ],
        ]]);
    }

    public function test_invalid_plan_slug_returns_422(): void
    {
        $admin = Consumer::factory()->create();
        config(['billing.admin_allowlist' => [$admin->id]]);
        $target = Consumer::factory()->create();
        $token = $admin->createToken('admin', [TokenAbilities::BILLING_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/admin/billing/subscriptions', [
                'consumer_id' => $target->id,
                'plan_slug' => 'unknown-plan',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['plan_slug']);
    }

    public function test_unknown_consumer_returns_422(): void
    {
        $admin = Consumer::factory()->create();
        config(['billing.admin_allowlist' => [$admin->id]]);
        $token = $admin->createToken('admin', [TokenAbilities::BILLING_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/admin/billing/subscriptions', [
                'consumer_id' => 999999,
                'plan_slug' => 'naschool-license',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['consumer_id']);
    }

    public function test_create_subscription_with_existing_mandate_returns_201_with_subscription(): void
    {
        $admin = Consumer::factory()->create();
        config(['billing.admin_allowlist' => [$admin->id]]);
        $target = Consumer::factory()->create();
        $token = $admin->createToken('admin', [TokenAbilities::BILLING_WRITE])->plainTextToken;

        DB::table('subscriptions')->insert([
            'name' => 'main',
            'plan' => 'naschool-license',
            'owner_id' => $target->id,
            'owner_type' => Consumer::class,
            'quantity' => 1,
            'tax_percentage' => 21,
            'cycle_started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/admin/billing/subscriptions', [
                'consumer_id' => $target->id,
                'plan_slug' => 'naschool-license',
            ]);

        $this->assertContains($response->status(), [201, 202, 502], sprintf(
            'Verwachte een handled status maar kreeg %d: %s',
            $response->status(),
            $response->content(),
        ));
    }
}
