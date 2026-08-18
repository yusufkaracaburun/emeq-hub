<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\ValidVatNumber;
use PHPUnit\Framework\TestCase;

class ValidVatNumberTest extends TestCase
{
    /** @return list<string> de fail-meldingen (leeg = geldig) */
    private function failures(string $value): array
    {
        $messages = [];
        (new ValidVatNumber)->validate('vat_number', $value, function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        return $messages;
    }

    public function test_rejects_format_valid_but_checksum_invalid_nl(): void
    {
        $this->assertNotEmpty($this->failures('NL123456789B01'));
    }

    public function test_accepts_legacy_elfproef_number(): void
    {
        $this->assertSame([], $this->failures('NL123456782B01'));
    }

    public function test_accepts_modern_mod97_number(): void
    {
        $this->assertSame([], $this->failures('NL000099998B57'));
    }

    public function test_rejects_malformed_nl(): void
    {
        $this->assertNotEmpty($this->failures('NL123B01'));
    }

    public function test_accepts_unnormalized_input(): void
    {
        $this->assertSame([], $this->failures('nl 0000 99998 b57'));
    }

    public function test_non_nl_eu_is_format_only(): void
    {
        $this->assertSame([], $this->failures('BE0123456789'));
    }

    public function test_blank_is_left_to_other_rules(): void
    {
        $this->assertSame([], $this->failures(''));
        $this->assertSame([], $this->failures('   '));
    }

    public function test_static_predicate_mirrors_rule(): void
    {
        $this->assertFalse(ValidVatNumber::isValidNl('NL123456789B01'));
        $this->assertTrue(ValidVatNumber::isValidNl('NL000099998B57'));
        $this->assertTrue(ValidVatNumber::isValidNl('NL123456782B01'));
    }
}
