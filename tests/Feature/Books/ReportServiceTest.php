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

        // Verkoopfactuur 10000 @21% → omzet 8000 = 10000, debiteuren 13000... 1300 = 12100.
        $client = Client::create(['name' => 'Acme BV']);
        $invoice = Invoice::create(['client_id' => $client->id, 'invoice_number' => '2026-001', 'status' => 'sent', 'date' => now()]);
        $invoice->lines()->create(['description' => 'Werk', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);
        app(InvoicePoster::class)->post($invoice->refresh());

        // Verkoopfactuur 5000 @0% → omzet 8020 = 5000 (zorgt voor een positief resultaat).
        $invoice2 = Invoice::create(['client_id' => $client->id, 'invoice_number' => '2026-002', 'status' => 'sent', 'date' => now()]);
        $invoice2->lines()->create(['description' => 'Export', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 0]);
        app(InvoicePoster::class)->post($invoice2->refresh());

        // Inkoopfactuur 10000 @21% op 4400 → kosten 4400 = 10000, te vorderen BTW 1530 = 2100.
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

        $this->assertSame(15000, $pl['total_revenue']); // 10000 + 5000
        $this->assertSame(10000, $pl['total_expense']);
        $this->assertSame(5000, $pl['result']);

        // Alleen rekeningen met beweging verschijnen.
        $codes = array_column($pl['revenue'], 'code');
        $this->assertEqualsCanonicalizing(['8000', '8020'], $codes);
    }

    public function test_balance_sheet_balances(): void
    {
        $balance = $this->service()->balanceSheet(now()->toDateString());

        // Activa: debiteuren 1300 = 17100 (12100 + 5000), te vorderen BTW 1530 = 2100.
        $this->assertSame(19200, $balance['total_assets']);

        // Passiva: crediteuren 1600 = 12100, af te dragen BTW 1620 = 2100, + resultaat 5000.
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
