<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Read;

use App\Accounting\Read\Cursor;
use App\Accounting\Read\ReadQuery;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CursorTest extends TestCase
{
    public function test_a_cursor_survives_an_encode_decode_round_trip(): void
    {
        $original = Cursor::of("guid'abc-123'");

        $this->assertSame("guid'abc-123'", Cursor::decode($original->encode())?->value);
    }

    /**
     * De cursor gaat in een URL, dus geen `+`, `/` of `=`.
     */
    public function test_the_encoded_form_is_url_safe(): void
    {
        $encoded = Cursor::of("guid'~!@#$%^&*()_+/='")->encode();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
    }

    /**
     * Een consumer mag de cursor niet interpreteren, dus mag hij er ook niet op
     * vastlopen. Onleesbaar betekent "begin opnieuw", geen 400.
     */
    public function test_an_unreadable_cursor_means_start_over(): void
    {
        $this->assertNull(Cursor::decode(''));
        $this->assertNull(Cursor::decode('!!!niet-base64!!!'));
    }

    public function test_the_query_defaults_to_a_sane_limit_without_a_cursor(): void
    {
        $query = ReadQuery::fromRequest([]);

        $this->assertSame(ReadQuery::DEFAULT_LIMIT, $query->limit);
        $this->assertNull($query->cursor);
    }

    public function test_the_query_reads_limit_and_cursor(): void
    {
        $query = ReadQuery::fromRequest(['limit' => '25', 'cursor' => Cursor::of('8000')->encode()]);

        $this->assertSame(25, $query->limit);
        $this->assertSame('8000', $query->cursor?->value);
    }

    public function test_an_out_of_range_limit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReadQuery::fromRequest(['limit' => ReadQuery::MAX_LIMIT + 1]);
    }

    public function test_a_zero_limit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReadQuery::fromRequest(['limit' => 0]);
    }

    /**
     * `external_id`, `number`, `from` en `issued_after` werden stil genegeerd —
     * een consumer die filtert kreeg 200 met de ongefilterde lijst terug.
     */
    public function test_an_unsupported_query_parameter_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReadQuery::fromRequest(['external_id' => 'INV-001']);
    }

    public function test_an_endpoint_specific_parameter_is_allowed_when_declared(): void
    {
        $query = ReadQuery::fromRequest(['type' => 'sales_invoice'], allowedExtra: ['type']);

        $this->assertSame(ReadQuery::DEFAULT_LIMIT, $query->limit);
    }
}
