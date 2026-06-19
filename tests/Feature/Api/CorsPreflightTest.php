<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class CorsPreflightTest extends TestCase
{
    public function test_preflight_from_spa_origin_is_allowed_on_v1(): void
    {
        $response = $this->call('OPTIONS', '/v1/oauth/exact/init', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $this->assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }

    public function test_preflight_from_unknown_origin_is_not_allowed(): void
    {
        $response = $this->call('OPTIONS', '/v1/oauth/exact/init', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
