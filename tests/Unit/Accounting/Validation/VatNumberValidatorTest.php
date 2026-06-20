<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Validation;

use App\Accounting\Validation\Validators\VatNumberValidator;
use PHPUnit\Framework\TestCase;

class VatNumberValidatorTest extends TestCase
{
    public function test_valid_nl_vat_number_produces_no_finding(): void
    {
        $findings = (new VatNumberValidator)->validate([
            'party' => ['vat_number' => 'NL000099998B57'],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_malformed_nl_vat_number_is_warning(): void
    {
        $findings = (new VatNumberValidator)->validate([
            'party' => ['vat_number' => 'NL123B01'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('vat_number.malformed', $findings[0]->code);
    }

    public function test_unnormalized_vat_number_suggests_normalized(): void
    {
        $findings = (new VatNumberValidator)->validate([
            'party' => ['vat_number' => 'nl 0000 99998 b57'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('vat_number.normalize', $findings[0]->code);
        $this->assertSame('NL000099998B57', $findings[0]->suggestion);
    }

    public function test_non_eu_vat_number_is_skipped(): void
    {
        $this->assertSame([], (new VatNumberValidator)->validate([
            'party' => ['vat_number' => 'CHE123456789'],
        ]));
    }
}
