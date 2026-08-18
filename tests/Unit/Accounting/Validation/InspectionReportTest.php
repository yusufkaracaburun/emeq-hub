<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Validation;

use App\Accounting\Validation\Finding;
use App\Accounting\Validation\InspectionReport;
use App\Accounting\Validation\Severity;
use PHPUnit\Framework\TestCase;

class InspectionReportTest extends TestCase
{
    public function test_invalid_when_any_error_present(): void
    {
        $report = new InspectionReport([
            new Finding('x.warn', Severity::Warning, blocking: false, path: 'a', message: 'm'),
            new Finding('x.err', Severity::Error, blocking: true, path: 'b', message: 'm'),
        ]);

        $this->assertFalse($report->valid());
    }

    public function test_valid_when_only_warnings_and_infos(): void
    {
        $report = new InspectionReport([
            new Finding('x.warn', Severity::Warning, blocking: false, path: 'a', message: 'm'),
            new Finding('x.info', Severity::Info, blocking: false, path: 'b', message: 'm'),
        ]);

        $this->assertTrue($report->valid());
    }

    /**
     * `valid` belooft de consumer dat de boeking daarna niet alsnog met een 422 strandt.
     * Een blokkerende warning (bv. `exact.vat_code.unmapped`) doet dat wél, dus telt die
     * net zo hard mee als een error.
     */
    public function test_invalid_when_a_warning_blocks_the_booking(): void
    {
        $report = new InspectionReport([
            new Finding('exact.vat_code.unmapped', Severity::Warning, blocking: true, path: 'lines.0.tax_rate', message: 'm'),
        ]);

        $this->assertFalse($report->valid());
    }

    public function test_summary_counts_and_error_first_ordering(): void
    {
        $report = new InspectionReport([
            new Finding('x.info', Severity::Info, blocking: false, path: 'a', message: 'm'),
            new Finding('x.err', Severity::Error, blocking: true, path: 'b', message: 'm'),
            new Finding('x.warn', Severity::Warning, blocking: true, path: 'c', message: 'm'),
        ]);

        $array = $report->toArray();

        $this->assertFalse($array['valid']);
        $this->assertSame(['errors' => 1, 'warnings' => 1, 'infos' => 1, 'blocking' => 2], $array['summary']);
        $this->assertSame('x.err', $array['findings'][0]['code']);
    }

    public function test_blocking_count_is_independent_of_severity(): void
    {
        // Een niet-blokkerende warning naast een blokkerende: `blocking` telt op basis van
        // het veld, niet van severity.
        $report = new InspectionReport([
            new Finding('x.warn.blocking', Severity::Warning, blocking: true, path: 'a', message: 'm'),
            new Finding('x.warn.advisory', Severity::Warning, blocking: false, path: 'b', message: 'm'),
            new Finding('x.info', Severity::Info, blocking: false, path: 'c', message: 'm'),
        ]);

        $this->assertSame(1, $report->toArray()['summary']['blocking']);
    }
}
