<?php

namespace Tests\Feature\Api;

use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_consumer_receives_pong_payload(): void
    {
        $consumer = Consumer::factory()->create(['slug' => 'naschool', 'name' => 'Naschool']);
        $token = $consumer->createToken('test', ['snelstart:read'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/ping')
            ->assertOk()
            ->assertJson([
                'pong' => true,
                'consumer' => 'naschool',
            ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/v1/ping')->assertUnauthorized();
    }

    public function test_abilities_are_surfaced_in_response(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer
            ->createToken('multi', ['snelstart:read', 'mollie:write'])
            ->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/ping')
            ->assertOk();

        $response->assertJsonPath('abilities.0', 'snelstart:read');
        $response->assertJsonPath('abilities.1', 'mollie:write');
    }
}
