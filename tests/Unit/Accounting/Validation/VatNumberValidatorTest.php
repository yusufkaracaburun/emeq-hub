<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Validation;

use App\Accounting\Validation\Severity;
use App\Accounting\Validation\Validators\VatNumberValidator;
use PHPUnit\Framework\TestCase;

class VatNumberValidatorTest extends TestCase
{
    public function test_valid_nl_vat_number_produces_no_finding(): void
    {
        // NL123456789B01 doorstaat de 11-proef (som 165, deelbaar door 11).
        $findings = (new VatNumberValidator)->validate([
            'party' => ['vat_number' => 'NL123456789B01'],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_malformed_nl_vat_number_is_error(): void
    {
        // NL valideert Exact strikt op formaat → harde fout (blokkeert de boeking).
        $findings = (new VatNumberValidator)->validate([
            'party' => ['vat_number' => 'NL123B01'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('vat_number.malformed', $findings[0]->code);
        $this->assertSame(Severity::Error, $findings[0]->severity);
    }

    public function test_invalid_nl_checksum_is_error(): void
    {
        // NL001234567B01: juist formaat, maar faalt de 11-proef (som 84) — Exact weigert dit
        // hard (HTTP 500). De dry-run spiegelt dat als Error zodat valid=false.
        $findings = (new VatNumberValidator)->validate([
            'party' => ['name' => 'Bouwbedrijf Noord', 'vat_number' => 'NL001234567B01'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('vat_number.checksum', $findings[0]->code);
        $this->assertSame(Severity::Error, $findings[0]->severity);
        $this->assertStringContainsString('Bouwbedrijf Noord', $findings[0]->message);
    }

    public function test_unnormalized_vat_number_suggests_normalized(): void
    {
        $findings = (new VatNumberValidator)->validate([
            'party' => ['vat_number' => 'nl 1234 56789 b01'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('vat_number.normalize', $findings[0]->code);
        $this->assertSame('NL123456789B01', $findings[0]->suggestion);
    }

    public function test_non_eu_vat_number_is_skipped(): void
    {
        $this->assertSame([], (new VatNumberValidator)->validate([
            'party' => ['vat_number' => 'CHE123456789'],
        ]));
    }
}
