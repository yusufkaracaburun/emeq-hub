<?php

namespace Tests\Feature\Books;

use App\Books\Models\Account;
use App\Books\Models\Bill;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Models\Vendor;
use App\Books\Services\BillPoster;
use App\Books\Services\InvoicePoster;
use App\Books\Services\ReportService;
use Database\Seeders\BooksChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BooksChartSeeder::class);

        $client = Client::create(['name' => 'Acme BV']);
        $invoice = Invoice::create(['client_id' => $client->id, 'invoice_number' => '2026-001', 'status' => 'sent', 'date' => now()]);
        $invoice->lines()->create(['description' => 'Werk', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);
        app(InvoicePoster::class)->post($invoice->refresh());

        $invoice2 = Invoice::create(['client_id' => $client->id, 'invoice_number' => '2026-002', 'status' => 'sent', 'date' => now()]);
        $invoice2->lines()->create(['description' => 'Export', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 0]);
        app(InvoicePoster::class)->post($invoice2->refresh());

        $vendor = Vendor::create(['name' => 'Hosting BV']);
        $bill = Bill::create(['vendor_id' => $vendor->id, 'bill_number' => 'INK-1', 'status' => 'received', 'date' => now()]);
        $bill->lines()->create(['account_id' => Account::where('code', '4400')->value('id'), 'description' => 'Hosting', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);
        app(BillPoster::class)->post($bill->refresh());
    }

    private function service(): ReportService
    {
        return app(ReportService::class);
    }

    public function test_profit_and_loss_sums_revenue_and_expense(): void
    {
        $pl = $this->service()->profitAndLoss(now()->startOfYear()->toDateString(), now()->toDateString());

        $this->assertSame(15000, $pl['total_revenue']);
        $this->assertSame(10000, $pl['total_expense']);
        $this->assertSame(5000, $pl['result']);

        $codes = array_column($pl['revenue'], 'code');
        $this->assertEqualsCanonicalizing(['8000', '8020'], $codes);
    }

    public function test_balance_sheet_balances(): void
    {
        $balance = $this->service()->balanceSheet(now()->toDateString());

        $this->assertSame(19200, $balance['total_assets']);

        $this->assertSame(5000, $balance['result']);
        $this->assertSame(19200, $balance['total_liabilities_and_equity']);

        $this->assertTrue($balance['balances']);
    }

    public function test_period_outside_movements_yields_zero(): void
    {
        $pl = $this->service()->profitAndLoss('2020-01-01', '2020-12-31');

        $this->assertSame(0, $pl['total_revenue']);
        $this->assertSame(0, $pl['result']);
        $this->assertSame([], $pl['revenue']);
    }
}
