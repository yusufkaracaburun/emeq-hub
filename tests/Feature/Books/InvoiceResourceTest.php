<?php

namespace Tests\Feature\Books;

use App\Books\Models\BooksCompany;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Filament\Books\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Books\Resources\Invoices\Pages\ListInvoices;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceResourceTest extends TestCase
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

        $this->client = Client::create(['name' => 'Acme BV']);
    }

    public function test_line_observer_computes_line_and_invoice_totals(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'status' => 'draft',
            'date' => now(),
        ]);

        $invoice->lines()->create(['description' => 'Werk', 'quantity' => 2, 'unit_price' => 10000, 'tax_rate' => 21]);
        $invoice->lines()->create(['description' => 'Reis', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 9]);

        $line = $invoice->lines()->where('description', 'Werk')->firstOrFail();
        $this->assertSame(20000, $line->subtotal);
        $this->assertSame(4200, $line->tax_amount);
        $this->assertSame(24200, $line->total);

        $invoice->refresh();
        $this->assertSame(25000, $invoice->subtotal);
        $this->assertSame(4650, $invoice->tax_total);
        $this->assertSame(29650, $invoice->total);
    }

    public function test_deleting_a_line_recalculates_totals(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'status' => 'draft', 'date' => now()]);
        $line = $invoice->lines()->create(['description' => 'X', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);

        $invoice->refresh();
        $this->assertSame(12100, $invoice->total);

        $line->delete();

        $invoice->refresh();
        $this->assertSame(0, $invoice->total);
    }

    public function test_create_invoice_via_form_converts_euros_and_computes_totals(): void
    {
        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'client_id' => $this->client->id,
                'invoice_number' => '2026-001',
                'status' => 'draft',
                'date' => now()->toDateString(),
                'lines' => [
                    ['description' => 'Werk', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 21],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = Invoice::firstOrFail();

        $this->assertSame(1, $invoice->lines()->count());
        $this->assertSame(20000, $invoice->subtotal);
        $this->assertSame(4200, $invoice->tax_total);
        $this->assertSame(24200, $invoice->total);
    }

    public function test_list_page_shows_invoice(): void
    {
        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'invoice_number' => '2026-009',
            'status' => 'draft',
            'date' => now(),
        ]);

        Livewire::test(ListInvoices::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$invoice]);
    }
}
