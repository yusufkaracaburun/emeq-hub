<?php

namespace Tests\Feature\Books;

use App\Books\Models\BooksCompany;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Services\InvoicePdfRenderer;
use App\Filament\Books\Resources\Invoices\Pages\ListInvoices;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $company = BooksCompany::create(['name' => 'Emeq']);
        config(['books.company_id' => $company->id]);

        Role::firstOrCreate(['name' => 'boekhouder']);
        $boekhouder = User::factory()->create();
        $boekhouder->assignRole('boekhouder');
        $this->actingAs($boekhouder);

        $this->client = Client::create([
            'name' => 'Acme BV',
            'address_line_1' => 'Dorpsstraat 1',
            'postal_code' => '1234 AB',
            'city' => 'Amsterdam',
            'vat_number' => 'NL001234567B01',
        ]);
    }

    private function invoiceWithLines(): Invoice
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'invoice_number' => '2026-001',
            'status' => 'sent',
            'date' => now(),
            'due_date' => now()->addDays(14),
        ]);

        $invoice->lines()->create(['description' => 'Advieswerk', 'quantity' => 2, 'unit_price' => 10000, 'tax_rate' => 21]);
        $invoice->lines()->create(['description' => 'Reiskosten', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 9]);

        return $invoice->refresh();
    }

    public function test_renderer_produces_pdf_bytes(): void
    {
        $invoice = $this->invoiceWithLines();

        $bytes = app(InvoicePdfRenderer::class)->output($invoice);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    public function test_filename_uses_invoice_number(): void
    {
        $invoice = $this->invoiceWithLines();

        $this->assertSame('factuur-2026-001.pdf', app(InvoicePdfRenderer::class)->filename($invoice));
    }

    public function test_filename_falls_back_to_id_without_number(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'status' => 'draft', 'date' => now()]);

        $this->assertSame('factuur-'.$invoice->id.'.pdf', app(InvoicePdfRenderer::class)->filename($invoice));
    }

    public function test_pdf_table_action_streams_a_download(): void
    {
        $invoice = $this->invoiceWithLines();

        Livewire::test(ListInvoices::class)
            ->callTableAction('pdf', $invoice)
            ->assertFileDownloaded('factuur-2026-001.pdf');
    }
}
