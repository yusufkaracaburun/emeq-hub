<?php

namespace Tests\Feature\Books;

use App\Books\Models\Bill;
use App\Books\Models\BooksCompany;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Models\Vendor;
use App\Books\Services\AgingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgingServiceTest extends TestCase
{
    use RefreshDatabase;

    private const AS_OF = '2026-06-30';

    protected function setUp(): void
    {
        parent::setUp();

        $company = BooksCompany::create(['name' => 'Emeq']);
        config(['books.company_id' => $company->id]);
    }

    private function service(): AgingService
    {
        return app(AgingService::class);
    }

    private function invoice(int $clientId, string $dueDate, int $total, string $status = 'sent', ?string $date = '2026-01-01'): Invoice
    {
        return Invoice::create([
            'client_id' => $clientId,
            'invoice_number' => 'INV-'.$dueDate.'-'.$total,
            'status' => $status,
            'date' => $date,
            'due_date' => $dueDate,
            'total' => $total,
        ]);
    }

    public function test_buckets_open_invoices_by_age_and_groups_per_client(): void
    {
        $acme = Client::create(['name' => 'Acme BV'])->id;
        $beta = Client::create(['name' => 'Beta BV'])->id;

        $this->invoice($acme, '2026-07-10', 10000);
        $this->invoice($acme, '2026-06-20', 10000);
        $this->invoice($acme, '2026-05-20', 10000);
        $this->invoice($acme, '2026-04-20', 10000);
        $this->invoice($acme, '2026-02-01', 10000);
        $this->invoice($beta, '2026-07-10', 20000);

        $report = $this->service()->receivables(self::AS_OF);

        $this->assertSame('receivable', $report['kind']);
        $this->assertSame('2026-06-30', $report['as_of']);

        $this->assertSame('Acme BV', $report['rows'][0]['relation']);
        $this->assertSame('Beta BV', $report['rows'][1]['relation']);

        $acmeBuckets = $report['rows'][0]['buckets'];
        $this->assertSame(10000, $acmeBuckets['current']);
        $this->assertSame(10000, $acmeBuckets['d1_30']);
        $this->assertSame(10000, $acmeBuckets['d31_60']);
        $this->assertSame(10000, $acmeBuckets['d61_90']);
        $this->assertSame(10000, $acmeBuckets['d90_plus']);
        $this->assertSame(50000, $report['rows'][0]['total']);

        $this->assertSame(30000, $report['totals']['current']);
        $this->assertSame(70000, $report['totals']['total']);
    }

    public function test_excludes_draft_zero_due_and_future_dated(): void
    {
        $acme = Client::create(['name' => 'Acme BV'])->id;

        $this->invoice($acme, '2026-05-01', 10000);
        $this->invoice($acme, '2026-05-01', 9999, 'draft');
        $this->invoice($acme, '2026-05-01', 0);
        $this->invoice($acme, '2026-05-01', 5000, 'sent', '2026-12-01');

        $report = $this->service()->receivables(self::AS_OF);

        $this->assertCount(1, $report['rows']);
        $this->assertSame(10000, $report['totals']['total']);
    }

    public function test_null_due_date_falls_back_to_document_date(): void
    {
        $acme = Client::create(['name' => 'Acme BV'])->id;

        $this->invoice($acme, '2026-05-21', 10000, date: '2026-05-21');

        Invoice::create([
            'client_id' => $acme,
            'invoice_number' => 'INV-NULLDUE',
            'status' => 'sent',
            'date' => '2026-05-21',
            'due_date' => null,
            'total' => 7000,
        ]);

        $report = $this->service()->receivables(self::AS_OF);

        $this->assertSame(17000, $report['totals']['d31_60']);
        $this->assertSame(17000, $report['totals']['total']);
    }

    public function test_payables_aggregates_open_bills_per_vendor(): void
    {
        $vendor = Vendor::create(['name' => 'Hosting BV'])->id;

        Bill::create([
            'vendor_id' => $vendor,
            'bill_number' => 'INK-1',
            'status' => 'received',
            'date' => '2026-01-01',
            'due_date' => '2026-03-01',
            'total' => 12100,
        ]);
        Bill::create([
            'vendor_id' => $vendor,
            'bill_number' => 'INK-DRAFT',
            'status' => 'draft',
            'date' => '2026-01-01',
            'due_date' => '2026-03-01',
            'total' => 5000,
        ]);

        $report = $this->service()->payables(self::AS_OF);

        $this->assertSame('payable', $report['kind']);
        $this->assertCount(1, $report['rows']);
        $this->assertSame('Hosting BV', $report['rows'][0]['relation']);
        $this->assertSame(12100, $report['rows'][0]['buckets']['d90_plus']);
        $this->assertSame(12100, $report['totals']['total']);
    }

    public function test_empty_report_has_zero_totals(): void
    {
        $report = $this->service()->receivables(self::AS_OF);

        $this->assertSame([], $report['rows']);
        $this->assertSame(0, $report['totals']['total']);
        $this->assertSame(0, $report['totals']['current']);
    }
}
