<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\Billing;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingAbilityGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_read_constant_exists(): void
    {
        $this->assertSame('billing:read', TokenAbilities::BILLING_READ);
        $this->assertSame('billing:write', TokenAbilities::BILLING_WRITE);
        $this->assertContains(TokenAbilities::BILLING_READ, TokenAbilities::all());
        $this->assertContains(TokenAbilities::BILLING_WRITE, TokenAbilities::all());
    }

    public function test_get_billing_subscription_requires_auth(): void
    {
        $response = $this->getJson('/v1/billing/subscription');

        $response->assertStatus(401);
    }

    public function test_get_billing_subscription_without_billing_ability_is_rejected(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/billing/subscription');

        $response->assertStatus(403);
    }

    public function test_admin_subscription_endpoints_require_billing_write_ability(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test', [TokenAbilities::BILLING_READ])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/admin/billing/subscriptions', [
                'consumer_id' => $consumer->id,
                'plan_slug' => 'naschool-license',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_subscription_endpoints_require_admin_allowlist(): void
    {
        config(['billing.admin_allowlist' => []]);
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test', [TokenAbilities::BILLING_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/admin/billing/subscriptions', [
                'consumer_id' => $consumer->id,
                'plan_slug' => 'naschool-license',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'not_admin');
    }
}
