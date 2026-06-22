<?php

namespace Tests\Feature\Books;

use App\Books\Models\Account;
use App\Books\Models\Bill;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Models\Vendor;
use App\Books\Services\BillPoster;
use App\Books\Services\BtwService;
use App\Books\Services\BtwXmlExporter;
use App\Books\Services\InvoicePoster;
use Database\Seeders\BooksChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BtwServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BooksChartSeeder::class);

        $client = Client::create(['name' => 'Acme BV']);

        // Verkoop 21% → omzet 8000 = 10000, af te dragen BTW 1620 = 2100.
        $this->postInvoice($client->id, '2026-001', 10000, 21);
        // Verkoop 9% → omzet 8010 = 20000, af te dragen BTW 1621 = 1800.
        $this->postInvoice($client->id, '2026-002', 20000, 9);
        // Verkoop 0% → omzet 8020 = 5000, geen BTW.
        $this->postInvoice($client->id, '2026-003', 5000, 0);

        // Inkoop 21% op 4400 → voorbelasting 1530 = 2100.
        $vendor = Vendor::create(['name' => 'Hosting BV']);
        $bill = Bill::create(['vendor_id' => $vendor->id, 'bill_number' => 'INK-1', 'status' => 'received', 'date' => now()]);
        $bill->lines()->create(['account_id' => Account::where('code', '4400')->value('id'), 'description' => 'Hosting', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);
        app(BillPoster::class)->post($bill->refresh());
    }

    private function postInvoice(int $clientId, string $number, int $unitPrice, int $taxRate): void
    {
        $invoice = Invoice::create(['client_id' => $clientId, 'invoice_number' => $number, 'status' => 'sent', 'date' => now()]);
        $invoice->lines()->create(['description' => 'Werk', 'quantity' => 1, 'unit_price' => $unitPrice, 'tax_rate' => $taxRate]);
        app(InvoicePoster::class)->post($invoice->refresh());
    }

    private function service(): BtwService
    {
        return app(BtwService::class);
    }

    private function declaration(): array
    {
        return $this->service()->declaration(now()->startOfYear()->toDateString(), now()->toDateString());
    }

    public function test_declaration_aggregates_rubrieken_per_rate(): void
    {
        $d = $this->declaration();

        $this->assertSame(10000, $d['rubrieken']['1a']['grondslag']);
        $this->assertSame(2100, $d['rubrieken']['1a']['btw']);

        $this->assertSame(20000, $d['rubrieken']['1b']['grondslag']);
        $this->assertSame(1800, $d['rubrieken']['1b']['btw']);

        $this->assertSame(5000, $d['rubrieken']['1e']['grondslag']);
        $this->assertSame(0, $d['rubrieken']['1e']['btw']);
    }

    public function test_declaration_totals_and_saldo(): void
    {
        $d = $this->declaration();

        $this->assertSame(3900, $d['verschuldigd']);   // 5a = 2100 + 1800
        $this->assertSame(2100, $d['voorbelasting']);  // 5b = 1530
        $this->assertSame(1800, $d['saldo']);          // te betalen = 3900 - 2100
    }

    public function test_empty_period_yields_zeros(): void
    {
        $d = $this->service()->declaration('2020-01-01', '2020-12-31');

        $this->assertSame(0, $d['rubrieken']['1a']['grondslag']);
        $this->assertSame(0, $d['verschuldigd']);
        $this->assertSame(0, $d['voorbelasting']);
        $this->assertSame(0, $d['saldo']);
    }

    public function test_unposted_invoice_is_excluded(): void
    {
        // Concept-factuur (niet geboekt) → geen journaalposten → telt niet mee.
        $client = Client::create(['name' => 'Draft BV']);
        $invoice = Invoice::create(['client_id' => $client->id, 'invoice_number' => '2026-099', 'status' => 'draft', 'date' => now()]);
        $invoice->lines()->create(['description' => 'Concept', 'quantity' => 1, 'unit_price' => 99999, 'tax_rate' => 21]);

        $d = $this->declaration();

        $this->assertSame(10000, $d['rubrieken']['1a']['grondslag']);
        $this->assertSame(2100, $d['rubrieken']['1a']['btw']);
    }

    public function test_xml_export_contains_rubriek_elements(): void
    {
        $xml = app(BtwXmlExporter::class)->export(now()->startOfYear()->toDateString(), now()->toDateString());

        $loaded = simplexml_load_string($xml);
        $this->assertNotFalse($loaded, 'XML moet valide zijn.');
        $this->assertStringContainsString('code="1a"', $xml);
        $this->assertStringContainsString('<verschuldigd>3900</verschuldigd>', $xml);
        $this->assertStringContainsString('<saldo>1800</saldo>', $xml);
    }
}
