<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Validation;

use App\Accounting\Validation\Severity;
use App\Accounting\Validation\Validators\IbanValidator;
use PHPUnit\Framework\TestCase;

class IbanValidatorTest extends TestCase
{
    public function test_valid_iban_produces_no_finding(): void
    {
        $findings = (new IbanValidator)->validate([
            'party' => ['iban' => 'NL91ABNA0417164300'],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_invalid_checksum_is_an_error(): void
    {
        $findings = (new IbanValidator)->validate([
            'party' => ['iban' => 'NL00BANK0123456789'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('iban.checksum_invalid', $findings[0]->code);
        $this->assertSame(Severity::Error, $findings[0]->severity);
        $this->assertTrue($findings[0]->blocking);
    }

    public function test_unnormalized_iban_suggests_normalized_form(): void
    {
        $findings = (new IbanValidator)->validate([
            'party' => ['iban' => 'nl91 abna 0417 1643 00'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('iban.normalize', $findings[0]->code);
        $this->assertSame('NL91ABNA0417164300', $findings[0]->suggestion);
        $this->assertFalse($findings[0]->blocking);
    }

    public function test_absent_iban_is_skipped(): void
    {
        $this->assertSame([], (new IbanValidator)->validate(['party' => []]));
    }
}
