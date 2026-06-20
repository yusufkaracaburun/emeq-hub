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
            new Finding('x.warn', Severity::Warning, 'a', 'm'),
            new Finding('x.err', Severity::Error, 'b', 'm'),
        ]);

        $this->assertFalse($report->valid());
    }

    public function test_valid_when_only_warnings_and_infos(): void
    {
        $report = new InspectionReport([
            new Finding('x.warn', Severity::Warning, 'a', 'm'),
            new Finding('x.info', Severity::Info, 'b', 'm'),
        ]);

        $this->assertTrue($report->valid());
    }

    public function test_summary_counts_and_error_first_ordering(): void
    {
        $report = new InspectionReport([
            new Finding('x.info', Severity::Info, 'a', 'm'),
            new Finding('x.err', Severity::Error, 'b', 'm'),
            new Finding('x.warn', Severity::Warning, 'c', 'm'),
        ]);

        $array = $report->toArray();

        $this->assertFalse($array['valid']);
        $this->assertSame(['errors' => 1, 'warnings' => 1, 'infos' => 1], $array['summary']);
        $this->assertSame('x.err', $array['findings'][0]['code']);
    }
}
