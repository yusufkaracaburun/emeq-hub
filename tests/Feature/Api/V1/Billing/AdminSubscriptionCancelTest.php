<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Billing;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSubscriptionCancelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_unknown_subscription_returns_404(): void
    {
        $admin = Consumer::factory()->create();
        config(['billing.admin_allowlist' => [$admin->id]]);
        $token = $admin->createToken('admin', [TokenAbilities::BILLING_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/v1/admin/billing/subscriptions/999999');

        $response->assertStatus(404);
    }

    public function test_cancel_without_admin_allowlist_returns_403(): void
    {
        config(['billing.admin_allowlist' => []]);
        $admin = Consumer::factory()->create();
        $token = $admin->createToken('admin', [TokenAbilities::BILLING_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/v1/admin/billing/subscriptions/1');

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'not_admin');
    }
}
