<?php

namespace Tests\Unit\Integrations\Exact\Errors;

use App\Integrations\Exact\Errors\UpstreamErrorMapper;
use Emeq\ExactApi\Exceptions\AuthenticationException;
use Emeq\ExactApi\Exceptions\NotFoundException;
use Emeq\ExactApi\Exceptions\RateLimitException;
use Emeq\ExactApi\Exceptions\RequestTooBroadException;
use Emeq\ExactApi\Exceptions\ServerException;
use Emeq\ExactApi\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class UpstreamErrorMapperTest extends TestCase
{
    public function test_401_maps_to_masked_502_with_auth_short_code(): void
    {
        $mapped = UpstreamErrorMapper::mapException(AuthenticationException::apiUnauthorized(401, 'nope'));

        $this->assertSame(502, $mapped['status']);
        $this->assertSame(401, $mapped['body']['upstream_status']);
        $this->assertSame('authentication_failed', $mapped['body']['upstream_detail']);
        $this->assertSame('exact_auth', $mapped['short_code']);
    }

    public function test_403_masks_to_502_but_is_distinct_in_audit(): void
    {
        $mapped = UpstreamErrorMapper::mapException(AuthenticationException::apiUnauthorized(403, 'forbidden'));

        // Naar de consumer nog steeds 502 (auth-state niet lekken)…
        $this->assertSame(502, $mapped['status']);
        // …maar operator-actionable onderscheiden in de audit.
        $this->assertSame(403, $mapped['body']['upstream_status']);
        $this->assertSame('forbidden', $mapped['body']['upstream_detail']);
        $this->assertSame('exact_forbidden', $mapped['short_code']);
    }

    public function test_500_with_functional_message_maps_to_422_rejected(): void
    {
        // Exact geeft 500 voor functionele afwijzingen — permanent, niet retryable. Map naar
        // 422 zodat de body (en dus de reden) niet achter een Cloudflare-502 verdwijnt.
        $body = '{"error":{"message":{"value":"Can\'t delete: used in journal entry"}}}';
        $mapped = UpstreamErrorMapper::mapException(ServerException::fromResponse(500, $body));

        $this->assertSame(422, $mapped['status']);
        $this->assertSame("Can't delete: used in journal entry", $mapped['body']['message']);
        $this->assertSame(500, $mapped['body']['upstream_status']);
        $this->assertSame('rejected', $mapped['body']['upstream_detail']);
        $this->assertSame('exact_rejected', $mapped['short_code']);
        // Geen humanisatie → geen duplicaat-veld; `message` is zelf al de bron.
        $this->assertArrayNotHasKey('provider_message', $mapped['body']);
    }

    public function test_500_vat_rejection_is_humanized_with_provider_message(): void
    {
        $body = '{"error":{"message":{"value":"Ongeldig controlecijfer voor btw-nummer. Het nummer moet in het volgende formaat worden ingevoerd: NL999999999B99"}}}';
        $mapped = UpstreamErrorMapper::mapException(ServerException::fromResponse(500, $body));

        $this->assertSame(422, $mapped['status']);
        $this->assertSame('exact_rejected', $mapped['short_code']);
        // Schone, partner-neutrale uitleg voor de consument…
        $this->assertStringContainsString('btw-nummer is ongeldig', $mapped['body']['message']);
        $this->assertStringContainsString('NL000099998B57', $mapped['body']['message']);
        // …met de rauwe Exact-tekst bewaard voor traceability.
        $this->assertStringContainsString('controlecijfer', $mapped['body']['provider_message']);
    }

    public function test_500_without_odata_message_falls_back(): void
    {
        $mapped = UpstreamErrorMapper::mapException(ServerException::fromResponse(500, 'plain text boom'));

        $this->assertSame(502, $mapped['status']);
        $this->assertSame('Upstream returned server error', $mapped['body']['message']);
    }

    public function test_503_maps_to_503_with_retry_after(): void
    {
        $mapped = UpstreamErrorMapper::mapException(ServerException::fromResponse(503, 'maintenance', 1800));

        $this->assertSame(503, $mapped['status']);
        $this->assertSame(503, $mapped['body']['upstream_status']);
        $this->assertSame('1800', $mapped['headers']['Retry-After']);
        $this->assertSame('exact_unavailable', $mapped['short_code']);
    }

    public function test_503_without_retry_after_omits_header(): void
    {
        $mapped = UpstreamErrorMapper::mapException(ServerException::fromResponse(503, 'maintenance'));

        $this->assertSame(503, $mapped['status']);
        $this->assertArrayNotHasKey('Retry-After', $mapped['headers']);
    }

    public function test_408_request_too_broad_maps_to_504_with_hint(): void
    {
        $mapped = UpstreamErrorMapper::mapException(RequestTooBroadException::fromBody('too broad'));

        $this->assertSame(504, $mapped['status']);
        $this->assertSame(408, $mapped['body']['upstream_status']);
        $this->assertSame('exact_request_too_broad', $mapped['short_code']);
        $this->assertStringContainsString('sync-endpoints', $mapped['body']['message']);
    }

    public function test_429_forwards_rate_limit_headers_and_retry_after(): void
    {
        $mapped = UpstreamErrorMapper::mapException(RateLimitException::fromBody('slow', 60, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => '1718700000000',
        ]));

        $this->assertSame(429, $mapped['status']);
        $this->assertSame('0', $mapped['headers']['X-RateLimit-Remaining']);
        $this->assertSame('1718700000000', $mapped['headers']['X-RateLimit-Reset']);
        $this->assertSame('60', $mapped['headers']['Retry-After']);
    }

    public function test_400_validation_passes_message_through(): void
    {
        $mapped = UpstreamErrorMapper::mapException(ValidationException::fromBody(
            '{"error":{"message":{"value":"Veld ontbreekt"}}}',
        ));

        $this->assertSame(400, $mapped['status']);
        $this->assertStringContainsString('Veld ontbreekt', $mapped['body']['message']);
    }

    public function test_404_maps_to_404(): void
    {
        $mapped = UpstreamErrorMapper::mapException(NotFoundException::forUrl('https://exact/x'));

        $this->assertSame(404, $mapped['status']);
        $this->assertSame(404, $mapped['body']['upstream_status']);
    }
}
