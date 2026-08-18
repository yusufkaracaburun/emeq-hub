<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_and_read_endpoints_have_independent_budgets(): void
    {
        config(['hub.rate_limits.writes_per_minute' => 1, 'hub.rate_limits.reads_per_minute' => 5]);

        [, $token] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);

        $this->withToken($token)
            ->postJson('/v1/accounts', ['external_id' => 'a1', 'display_name' => 'A1'])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/v1/accounts', ['external_id' => 'a2', 'display_name' => 'A2'])
            ->assertStatus(429);

        $this->withToken($token)
            ->getJson('/v1/ping')
            ->assertOk();
    }

    public function test_scope_key_is_per_consumer_not_shared(): void
    {
        config(['hub.rate_limits.writes_per_minute' => 1, 'hub.rate_limits.reads_per_minute' => 300]);

        [, $tokenA] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);
        [, $tokenB] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);

        $this->withToken($tokenA)
            ->postJson('/v1/accounts', ['external_id' => 'a1', 'display_name' => 'A1'])
            ->assertCreated();

        $this->withToken($tokenA)
            ->postJson('/v1/accounts', ['external_id' => 'a2', 'display_name' => 'A2'])
            ->assertStatus(429);

        $this->app['auth']->forgetGuards();

        $this->withToken($tokenB)
            ->postJson('/v1/accounts', ['external_id' => 'b1', 'display_name' => 'B1'])
            ->assertCreated();
    }

    public function test_rate_limit_headers_are_present_on_a_successful_response(): void
    {
        config(['hub.rate_limits.reads_per_minute' => 300]);

        [, $token] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);

        $response = $this->withToken($token)->getJson('/v1/ping');

        $response->assertOk();
        $this->assertSame('300', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('299', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_throttled_response_carries_retry_after_and_reset_headers(): void
    {
        config(['hub.rate_limits.writes_per_minute' => 1]);

        [, $token] = $this->consumerWithToken([TokenAbilities::CONSUMER_MANAGE_ACCOUNTS]);

        $this->withToken($token)
            ->postJson('/v1/accounts', ['external_id' => 'a1', 'display_name' => 'A1'])
            ->assertCreated();

        $response = $this->withToken($token)
            ->postJson('/v1/accounts', ['external_id' => 'a2', 'display_name' => 'A2']);

        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Reset'));
    }

    /**
     * @param  list<string>  $abilities
     * @return array{0: Consumer, 1: string}
     */
    private function consumerWithToken(array $abilities): array
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test', $abilities)->plainTextToken;

        return [$consumer, $token];
    }
}
