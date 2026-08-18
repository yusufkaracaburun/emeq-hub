<?php

namespace Tests\Feature\Books;

use App\Books\Models\BooksCompany;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Services\InvoicePoster;
use App\Filament\Books\Pages\Overzichten;
use App\Models\User;
use Database\Seeders\BooksChartSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OverzichtenPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $company = BooksCompany::create(['name' => 'Emeq']);
        config(['books.company_id' => $company->id]);
        $this->seed(BooksChartSeeder::class);

        Role::firstOrCreate(['name' => 'boekhouder']);
        $boekhouder = User::factory()->create();
        $boekhouder->assignRole('boekhouder');
        $this->actingAs($boekhouder);

        $client = Client::create(['name' => 'Acme BV']);
        $invoice = Invoice::create(['client_id' => $client->id, 'invoice_number' => '2026-001', 'status' => 'sent', 'date' => now()]);
        $invoice->lines()->create(['description' => 'Werk', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);
        app(InvoicePoster::class)->post($invoice->refresh());
    }

    public function test_page_renders_with_statements(): void
    {
        Livewire::test(Overzichten::class)
            ->assertOk()
            ->assertSee('Winst & Verlies')
            ->assertSee('Balans')
            ->assertSee('Omzet hoog (21%)')
            ->assertSee('Debiteuren');
    }

    public function test_period_filter_recomputes(): void
    {
        Livewire::test(Overzichten::class)
            ->set('startDate', '2020-01-01')
            ->set('endDate', '2020-12-31')
            ->assertSee('Geen omzet in deze periode.');
    }
}
