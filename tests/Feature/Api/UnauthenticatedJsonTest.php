<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
