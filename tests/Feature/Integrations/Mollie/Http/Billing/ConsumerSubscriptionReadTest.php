<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Http\Billing;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumerSubscriptionReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_subscribed_false_for_consumer_without_subscription(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test', [TokenAbilities::BILLING_READ])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/billing/subscription');

        $response->assertOk();
        $response->assertJsonPath('subscribed', false);
        $response->assertJsonPath('consumer_id', $consumer->id);
    }

    public function test_returns_active_subscription_details_when_subscribed(): void
    {
        $consumer = Consumer::factory()->withActiveSubscription('naschool-license', 'main')->create();
        $token = $consumer->createToken('test', [TokenAbilities::BILLING_READ])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/billing/subscription');

        $response->assertOk();
        $response->assertJsonPath('subscribed', true);
        $response->assertJsonPath('plan', 'naschool-license');
        $response->assertJsonPath('status', 'active');
    }

    public function test_billing_write_ability_can_also_read(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test', [TokenAbilities::BILLING_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/billing/subscription');

        $response->assertOk();
    }
}
