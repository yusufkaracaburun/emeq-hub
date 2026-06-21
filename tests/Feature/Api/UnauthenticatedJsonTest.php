<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /v1/* is een pure JSON-API. Een consumer-app praat via een eigen-origin proxy
 * die niet altijd een `Accept: application/json`-header meestuurt. Zonder de
 * expliciete JSON-render zou een ontbrekende/ongeldige PAT een redirect naar de
 * niet-bestaande `login`-route worden → `RouteNotFoundException` (500).
 */
class UnauthenticatedJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_v1_without_token_or_accept_header_returns_json_401(): void
    {
        $this->get('/v1/integrations')
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);
    }

    public function test_v1_with_invalid_bearer_returns_json_401(): void
    {
        $this->withHeader('Authorization', 'Bearer invalid-token')
            ->get('/v1/integrations')
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);
    }
}
