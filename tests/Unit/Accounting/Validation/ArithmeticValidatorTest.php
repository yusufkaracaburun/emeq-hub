<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Validation;

use App\Accounting\Validation\Validators\ArithmeticValidator;
use PHPUnit\Framework\TestCase;

class ArithmeticValidatorTest extends TestCase
{
    private function codes(array $findings): array
    {
        return array_map(fn ($f): string => $f->code, $findings);
    }

    public function test_clean_document_produces_no_findings(): void
    {
        $findings = (new ArithmeticValidator)->validate([
            'subtotal' => 100,
            'tax_total' => 21,
            'total' => 121,
            'lines' => [
                ['description' => 'A', 'amount' => 100, 'tax_rate' => 21],
            ],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_total_mismatch_suggests_recomputed_total(): void
    {
        $findings = (new ArithmeticValidator)->validate([
            'total' => 120,
            'lines' => [['description' => 'A', 'amount' => 100, 'tax_rate' => 21]],
        ]);

        $this->assertContains('arithmetic.total_mismatch', $this->codes($findings));
        $finding = array_values(array_filter($findings, fn ($f): bool => $f->code === 'arithmetic.total_mismatch'))[0];
        $this->assertSame(121.0, $finding->suggestion);
        $this->assertSame(120.0, $finding->current);
        $this->assertFalse($finding->blocking); // `total` bestaat niet op het boekcontract
    }

    public function test_discount_is_factored_into_expected_total(): void
    {
        $findings = (new ArithmeticValidator)->validate([
            'total' => 111,
            'discount' => 10,
            'lines' => [['description' => 'A', 'amount' => 100, 'tax_rate' => 21]],
        ]);

        // 100 + 21 − 10 = 111 → sluit, geen mismatch.
        $this->assertNotContains('arithmetic.total_mismatch', $this->codes($findings));
    }

    public function test_non_numeric_amount_is_flagged(): void
    {
        $findings = (new ArithmeticValidator)->validate([
            'lines' => [['description' => 'A', 'amount' => 'tien', 'tax_rate' => 21]],
        ]);

        $this->assertSame(['arithmetic.amount_not_numeric'], $this->codes($findings));
        $this->assertTrue($findings[0]->blocking); // `lines.*.amount` moet numeriek zijn om te boeken
    }

    public function test_line_amount_against_quantity_times_price(): void
    {
        $findings = (new ArithmeticValidator)->validate([
            'lines' => [['description' => 'A', 'amount' => 99, 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 0]],
        ]);

        $this->assertContains('arithmetic.line_amount_mismatch', $this->codes($findings));
        $finding = array_values(array_filter($findings, fn ($f): bool => $f->code === 'arithmetic.line_amount_mismatch'))[0];
        $this->assertSame(100.0, $finding->suggestion);
        $this->assertFalse($finding->blocking); // het boekpad valideert amount ≠ qty × prijs niet
    }
}
