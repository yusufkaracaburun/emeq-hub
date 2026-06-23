<?php

namespace Tests\Unit\Mollie;

use App\Exceptions\Mollie\MissingPartnerTokenException;
use App\Support\Mollie\UpstreamErrorMapper;
use Emeq\MollieApi\Exceptions\NotFoundException;
use PHPUnit\Framework\TestCase;

class UpstreamErrorMapperPartnerTokenTest extends TestCase
{
    public function test_missing_partner_token_maps_to_503_partner_token_missing(): void
    {
        $result = UpstreamErrorMapper::mapException(new MissingPartnerTokenException);

        $this->assertSame(503, $result['status']);
        $this->assertSame('partner_token_missing', $result['body']['error']);
        $this->assertStringContainsString(
            'partner-access-token niet geconfigureerd',
            $result['body']['message'],
        );
        $this->assertSame(0, $result['body']['upstream_status']);
        $this->assertSame('partner_token_missing', $result['short_code']);
        $this->assertSame([], $result['headers']);
    }

    public function test_existing_not_found_branch_remains_unchanged(): void
    {
        $result = UpstreamErrorMapper::mapException(new NotFoundException('payment not found'));

        $this->assertSame(404, $result['status']);
        $this->assertSame('not_found', $result['body']['error']);
        $this->assertNull($result['short_code']);
    }
}
