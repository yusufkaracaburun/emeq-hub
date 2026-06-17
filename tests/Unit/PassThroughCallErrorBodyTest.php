<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PassThroughCall;
use PHPUnit\Framework\TestCase;

/**
 * Borgt het gedeelde errors-only-contract dat alle pass-through-writers
 * (Exact/Snelstart/Mollie/Mollie-Connect/Subscriptions) gebruiken om
 * response_body te vullen.
 */
class PassThroughCallErrorBodyTest extends TestCase
{
    public function test_returns_null_for_success_status_even_with_body(): void
    {
        $this->assertNull(PassThroughCall::errorBody(200, '{"d":[]}'));
        $this->assertNull(PassThroughCall::errorBody(201, 'created'));
        $this->assertNull(PassThroughCall::errorBody(399, 'still ok'));
    }

    public function test_returns_body_for_error_status(): void
    {
        $this->assertSame('{"error":"bad"}', PassThroughCall::errorBody(400, '{"error":"bad"}'));
        $this->assertSame('boom', PassThroughCall::errorBody(500, 'boom'));
    }

    public function test_returns_null_for_empty_or_null_body(): void
    {
        $this->assertNull(PassThroughCall::errorBody(500, null));
        $this->assertNull(PassThroughCall::errorBody(500, ''));
    }

    public function test_truncates_body_over_8kb(): void
    {
        $body = str_repeat('x', 9000);

        $result = PassThroughCall::errorBody(502, $body);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('…[afgekapt]', $result);
        $this->assertSame(8000, mb_strlen((string) preg_replace('/\n…\[afgekapt\]$/', '', $result)));
    }
}
