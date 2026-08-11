<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Snelstart;

use App\Integrations\Snelstart\Errors\UpstreamErrorMapper;
use Emeq\SnelstartApi\Exceptions\AuthenticationException;
use Emeq\SnelstartApi\Exceptions\NotFoundException;
use Emeq\SnelstartApi\Exceptions\RateLimitException;
use Emeq\SnelstartApi\Exceptions\ServerException;
use Emeq\SnelstartApi\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\PendingRequest;

class UpstreamErrorMapperTest extends TestCase
{
    public function test_authentication_exception_maps_to_502_with_snelstart_auth_short_code(): void
    {
        $exception = AuthenticationException::tokenFetchFailed(401, '{"error":"invalid_client"}', 'ac942340c588');

        $result = UpstreamErrorMapper::mapException($exception);

        $this->assertSame(502, $result['status']);
        $this->assertSame('snelstart_auth', $result['short_code']);
        $this->assertSame('upstream_error', $result['body']['error']);
        $this->assertSame(401, $result['body']['upstream_status']);
        $this->assertSame('authentication_failed', $result['body']['upstream_detail']);
        $this->assertSame([], $result['headers']);
    }

    public function test_server_exception_maps_to_502_with_snelstart_5xx_short_code(): void
    {
        $exception = ServerException::fromResponse(503, '{"error":"service_unavailable"}');

        $result = UpstreamErrorMapper::mapException($exception);

        $this->assertSame(502, $result['status']);
        $this->assertSame('snelstart_5xx', $result['short_code']);
        $this->assertSame('upstream_error', $result['body']['error']);
        $this->assertSame(503, $result['body']['upstream_status']);
        $this->assertSame('server_error', $result['body']['upstream_detail']);
    }

    public function test_validation_exception_passes_through_as_400_with_error_codes(): void
    {
        $exception = ValidationException::fromBody('{"errorCode":"ALG-0100"}');

        $result = UpstreamErrorMapper::mapException($exception);

        $this->assertSame(400, $result['status']);
        $this->assertNull($result['short_code']);
        $this->assertSame('upstream_validation', $result['body']['error']);
        $this->assertSame(400, $result['body']['upstream_status']);
        $this->assertContains('ALG-0100', $result['body']['error_codes']);
        $this->assertIsString($result['body']['message']);
    }

    public function test_not_found_exception_passes_through_as_404(): void
    {
        $exception = NotFoundException::forUrl('/v2/relaties/00000000-0000-0000-0000-000000000000');

        $result = UpstreamErrorMapper::mapException($exception);

        $this->assertSame(404, $result['status']);
        $this->assertNull($result['short_code']);
        $this->assertSame('upstream_not_found', $result['body']['error']);
        $this->assertSame(404, $result['body']['upstream_status']);
    }

    public function test_rate_limit_exception_passes_through_with_retry_after_header(): void
    {
        $exception = RateLimitException::fromBody('{}', 30);

        $result = UpstreamErrorMapper::mapException($exception);

        $this->assertSame(429, $result['status']);
        $this->assertNull($result['short_code']);
        $this->assertSame('upstream_rate_limited', $result['body']['error']);
        $this->assertSame(429, $result['body']['upstream_status']);
        $this->assertSame(['Retry-After' => '30'], $result['headers']);
    }

    public function test_rate_limit_exception_without_retry_after_omits_header(): void
    {
        $exception = RateLimitException::fromBody('{}', null);

        $result = UpstreamErrorMapper::mapException($exception);

        $this->assertSame(429, $result['status']);
        $this->assertSame([], $result['headers']);
    }

    public function test_fatal_request_exception_maps_to_504_with_snelstart_timeout(): void
    {
        $pendingRequest = $this->createMock(PendingRequest::class);
        $exception = new FatalRequestException(new RuntimeException('connection refused'), $pendingRequest);

        $result = UpstreamErrorMapper::mapException($exception);

        $this->assertSame(504, $result['status']);
        $this->assertSame('snelstart_timeout', $result['short_code']);
        $this->assertSame('upstream_timeout', $result['body']['error']);
        $this->assertSame(0, $result['body']['upstream_status']);
    }

    public function test_unknown_throwable_maps_to_502_with_unknown_short_code(): void
    {
        $exception = new RuntimeException('anders');

        $result = UpstreamErrorMapper::mapException($exception);

        $this->assertSame(502, $result['status']);
        $this->assertSame('snelstart_unknown', $result['short_code']);
        $this->assertSame('upstream_error', $result['body']['error']);
        $this->assertSame(0, $result['body']['upstream_status']);
        $this->assertSame('unknown', $result['body']['upstream_detail']);
    }
}
