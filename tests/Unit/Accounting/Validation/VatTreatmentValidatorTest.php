<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Validation;

use App\Accounting\Validation\Severity;
use App\Accounting\Validation\Validators\VatTreatmentValidator;
use PHPUnit\Framework\TestCase;

class VatTreatmentValidatorTest extends TestCase
{
    public function test_domestic_supplier_with_standard_rate_is_fine(): void
    {
        $findings = (new VatTreatmentValidator)->validate([
            'party' => ['vat_number' => 'NL000099998B57'],
            'lines' => [['amount' => 100, 'tax_rate' => 21]],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_intra_eu_supplier_with_vat_expects_reverse_charge(): void
    {
        $findings = (new VatTreatmentValidator)->validate([
            'party' => ['vat_number' => 'DE123456789'],
            'lines' => [['amount' => 100, 'tax_rate' => 21]],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('vat_treatment.reverse_charge_expected', $findings[0]->code);
        $this->assertSame('reverse_charge', $findings[0]->suggestion);
        $this->assertSame(Severity::Warning, $findings[0]->severity);
        $this->assertFalse($findings[0]->blocking);
    }

    public function test_non_eu_supplier_with_domestic_rate_is_error(): void
    {
        $findings = (new VatTreatmentValidator)->validate([
            'party' => ['vat_number' => 'CHE123456789'],
            'lines' => [['amount' => 100, 'tax_rate' => 21]],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('vat_treatment.domestic_rate_on_non_eu', $findings[0]->code);
        $this->assertSame(0, $findings[0]->suggestion);
        $this->assertSame(Severity::Error, $findings[0]->severity);
        $this->assertTrue($findings[0]->blocking);
    }

    public function test_zero_rated_lines_produce_no_finding(): void
    {
        $findings = (new VatTreatmentValidator)->validate([
            'party' => ['vat_number' => 'DE123456789'],
            'lines' => [['amount' => 100, 'tax_rate' => 0]],
        ]);

        $this->assertSame([], $findings);
    }
}
