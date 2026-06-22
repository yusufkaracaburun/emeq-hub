<?php

namespace Tests\Feature\Books;

use App\Books\Models\BooksCompany;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Services\InvoicePoster;
use App\Books\Services\ReportPdfRenderer;
use App\Filament\Books\Pages\Overzichten;
use App\Models\User;
use Database\Seeders\BooksChartSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportPdfTest extends TestCase
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

        // Eén geboekte verkoopfactuur → beweging in W&V + Balans.
        $client = Client::create(['name' => 'Acme BV']);
        $invoice = Invoice::create(['client_id' => $client->id, 'invoice_number' => '2026-001', 'status' => 'sent', 'date' => now()]);
        $invoice->lines()->create(['description' => 'Werk', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);
        app(InvoicePoster::class)->post($invoice->refresh());
    }

    public function test_renderer_produces_pdf_bytes(): void
    {
        $bytes = app(ReportPdfRenderer::class)->output(
            now()->startOfYear()->toDateString(),
            now()->toDateString(),
        );

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    public function test_filename_uses_period(): void
    {
        $name = app(ReportPdfRenderer::class)->filename('2026-01-01', '2026-06-22');

        $this->assertSame('overzicht-2026-01-01-2026-06-22.pdf', $name);
    }

    public function test_page_header_action_streams_a_download(): void
    {
        $expected = 'overzicht-'.now()->startOfYear()->format('Y-m-d').'-'.now()->format('Y-m-d').'.pdf';

        Livewire::test(Overzichten::class)
            ->callAction('pdf')
            ->assertFileDownloaded($expected);
    }
}
