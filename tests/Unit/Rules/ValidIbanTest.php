<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\ValidIban;
use PHPUnit\Framework\TestCase;

class ValidIbanTest extends TestCase
{
    /** @return list<string> de fail-meldingen (leeg = geldig) */
    private function failures(string $value): array
    {
        $messages = [];
        (new ValidIban)->validate('iban', $value, function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        return $messages;
    }

    public function test_accepts_valid_nl_iban(): void
    {
        $this->assertSame([], $this->failures('NL91ABNA0417164300'));
    }

    public function test_rejects_single_digit_typo(): void
    {
        $this->assertNotEmpty($this->failures('NL91ABNA0417164301'));
    }

    public function test_rejects_iban_with_wrong_length_for_its_country(): void
    {
        $this->assertNotEmpty($this->failures('NL91ABNA041716430'));
    }

    public function test_accepts_unnormalized_input(): void
    {
        $this->assertSame([], $this->failures('nl91 abna 0417 1643 00'));
    }

    public function test_accepts_foreign_ibans(): void
    {
        $this->assertSame([], $this->failures('DE89370400440532013000'));
        $this->assertSame([], $this->failures('BE68539007547034'));
        $this->assertSame([], $this->failures('GB82WEST12345698765432'));
        $this->assertSame([], $this->failures('FR1420041010050500013M02606'));
    }

    public function test_rejects_malformed_input(): void
    {
        $this->assertNotEmpty($this->failures('ABNA0417164300'));
        $this->assertNotEmpty($this->failures('NL9!ABNA0417164300'));
    }

    public function test_blank_is_left_to_other_rules(): void
    {
        $this->assertSame([], $this->failures(''));
        $this->assertSame([], $this->failures('   '));
    }

    public function test_static_predicate_mirrors_rule(): void
    {
        $this->assertTrue(ValidIban::isValid('NL91ABNA0417164300'));
        $this->assertFalse(ValidIban::isValid('NL91ABNA0417164301'));
        $this->assertSame('NL91ABNA0417164300', ValidIban::normalize('nl91 abna 0417 1643 00'));
    }
}
