<?php

namespace Tests\Feature\Api;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CorsPreflightTest extends TestCase
{
    public function test_preflight_from_dev_origin_is_allowed_on_v1(): void
    {
        $response = $this->preflight('http://localhost:3000');

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $this->assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function originProvider(): array
    {
        return [
            // Elke https-origin mag — multi-tenant consumers (emeq.nl, planny.nl,
            // …) werken zonder per-consumer config. De PAT is de security-grens.
            'tenant-subdomein emeq' => ['https://bob.emeq.nl', true],
            'consumer-admin emeq' => ['https://admin.emeq.nl', true],
            'andere consumer planny' => ['https://admin.planny.nl', true],
            'tenant van andere consumer' => ['https://klant.planny.nl', true],
            'willekeurige https-origin' => ['https://app.willekeurig.example', true],
            // Plain-http (anders dan de expliciete dev-host) wordt geweigerd.
            'non-tls geweigerd' => ['http://bob.emeq.nl', false],
        ];
    }

    #[DataProvider('originProvider')]
    public function test_any_https_origin_is_allowed_http_is_not(string $origin, bool $allowed): void
    {
        $response = $this->preflight($origin);

        if ($allowed) {
            $response->assertNoContent();
            $response->assertHeader('Access-Control-Allow-Origin', $origin);
        } else {
            $this->assertNotSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
        }
    }

    private function preflight(string $origin): TestResponse
    {
        return $this->call('OPTIONS', '/v1/oauth/exact/init', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);
    }
}
