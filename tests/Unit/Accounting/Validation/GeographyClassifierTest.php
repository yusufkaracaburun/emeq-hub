<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Validation;

use App\Accounting\Validation\Validators\GeographyClassifier;
use PHPUnit\Framework\TestCase;

class GeographyClassifierTest extends TestCase
{
    public function test_vat_and_iban_country_mismatch_is_flagged(): void
    {
        $findings = (new GeographyClassifier)->validate([
            'party' => ['vat_number' => 'DE123456789', 'iban' => 'NL91ABNA0417164300'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame('geography.country_mismatch', $findings[0]->code);
    }

    public function test_matching_countries_produce_no_finding(): void
    {
        $findings = (new GeographyClassifier)->validate([
            'party' => ['vat_number' => 'NL000099998B57', 'iban' => 'NL91ABNA0417164300'],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_only_one_source_produces_no_finding(): void
    {
        $this->assertSame([], (new GeographyClassifier)->validate([
            'party' => ['iban' => 'NL91ABNA0417164300'],
        ]));
    }
}
