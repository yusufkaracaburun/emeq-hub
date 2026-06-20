<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Validation;

use App\Accounting\Validation\Severity;
use App\Accounting\Validation\Validators\CurrencyValidator;
use PHPUnit\Framework\TestCase;

class CurrencyValidatorTest extends TestCase
{
    public function test_foreign_currency_is_info(): void
    {
        $findings = (new CurrencyValidator)->validate(['currency' => 'USD']);

        $this->assertCount(1, $findings);
        $this->assertSame('currency.foreign', $findings[0]->code);
        $this->assertSame(Severity::Info, $findings[0]->severity);
    }

    public function test_euro_produces_no_finding(): void
    {
        $this->assertSame([], (new CurrencyValidator)->validate(['currency' => 'EUR']));
    }

    public function test_absent_currency_produces_no_finding(): void
    {
        $this->assertSame([], (new CurrencyValidator)->validate([]));
    }
}
