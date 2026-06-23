<?php

namespace Tests\Unit\Support\Mollie;

use App\Support\Mollie\UpstreamErrorMapper;
use Emeq\MollieApi\Exceptions\AuthenticationException;
use Emeq\MollieApi\Exceptions\MollieException;
use Emeq\MollieApi\Exceptions\NotFoundException;
use Emeq\MollieApi\Exceptions\RateLimitException;
use Emeq\MollieApi\Exceptions\ServerException;
use Emeq\MollieApi\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class UpstreamErrorMapperTest extends TestCase
{
    public function test_validation_exception_maps_to_422_validation_failed(): void
    {
        $result = UpstreamErrorMapper::mapException(
            new ValidationException('amount.value invalid', 'amount.value'),
        );

        $this->assertSame(422, $result['status']);
        $this->assertSame('validation_failed', $result['body']['error']);
        $this->assertSame('amount.value', $result['body']['field']);
        $this->assertNull($result['short_code']);
    }

    public function test_authentication_exception_maps_to_502_with_short_code_mollie_auth(): void
    {
        $result = UpstreamErrorMapper::mapException(new AuthenticationException('401 from Mollie'));

        $this->assertSame(502, $result['status']);
        $this->assertSame('mollie_auth_failed', $result['body']['error']);
        $this->assertSame('mollie_auth', $result['short_code']);
    }

    public function test_not_found_exception_maps_to_404_not_found(): void
    {
        $result = UpstreamErrorMapper::mapException(new NotFoundException('payment not found'));

        $this->assertSame(404, $result['status']);
        $this->assertSame('not_found', $result['body']['error']);
        $this->assertNull($result['short_code']);
    }

    public function test_rate_limit_exception_maps_to_429_rate_limited(): void
    {
        $result = UpstreamErrorMapper::mapException(new RateLimitException('rate limited'));

        $this->assertSame(429, $result['status']);
        $this->assertSame('rate_limited', $result['body']['error']);
        $this->assertNull($result['short_code']);
    }

    public function test_server_exception_maps_to_502_with_short_code_mollie_5xx(): void
    {
        $result = UpstreamErrorMapper::mapException(new ServerException('500 from Mollie'));

        $this->assertSame(502, $result['status']);
        $this->assertSame('mollie_unavailable', $result['body']['error']);
        $this->assertSame('mollie_5xx', $result['short_code']);
    }

    public function test_base_mollie_exception_maps_to_502_mollie_error_unknown(): void
    {
        $result = UpstreamErrorMapper::mapException(new MollieException('unknown'));

        $this->assertSame(502, $result['status']);
        $this->assertSame('mollie_error', $result['body']['error']);
        $this->assertSame('mollie_unknown', $result['short_code']);
    }

    public function test_unexpected_throwable_maps_to_502_mollie_error_unknown(): void
    {
        $result = UpstreamErrorMapper::mapException(new \RuntimeException('whoops'));

        $this->assertSame(502, $result['status']);
        $this->assertSame('mollie_error', $result['body']['error']);
        $this->assertSame('mollie_unknown', $result['short_code']);
    }
}
