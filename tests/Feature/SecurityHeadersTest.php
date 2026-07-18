<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_present_on_every_response(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');
    }

    public function test_headers_present_on_json_api_route(): void
    {
        $this->getJson('/v1/accounting/documents')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_absent_over_http(): void
    {
        $this->get('/up')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_present_over_https(): void
    {
        $this->get('https://localhost/up')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
