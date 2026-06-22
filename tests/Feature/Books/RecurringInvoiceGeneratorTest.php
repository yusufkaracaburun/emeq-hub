<?php

namespace Tests\Feature\Books;

use App\Books\Enums\InvoiceStatus;
use App\Books\Enums\RecurringFrequency;
use App\Books\Enums\RecurringStatus;
use App\Books\Models\BooksCompany;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Models\RecurringInvoice;
use App\Books\Services\RecurringInvoiceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurringInvoiceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $company = BooksCompany::create(['name' => 'Emeq']);
        config(['books.company_id' => $company->id]);

        $this->client = Client::create(['name' => 'ACME']);
    }

    private function template(array $attributes = []): RecurringInvoice
    {
        $template = RecurringInvoice::create(array_merge([
            'client_id' => $this->client->id,
            'status' => RecurringStatus::Active,
            'frequency' => RecurringFrequency::Monthly,
            'start_date' => Carbon::today(),
            'due_days' => 14,
        ], $attributes));

        $template->lines()->create([
            'description' => 'Maandelijkse dienst',
            'quantity' => 1,
            'unit_price' => 10000, // €100,00
            'tax_rate' => 21,
            'sort' => 0,
        ]);

        return $template;
    }

    public function test_due_template_generates_draft_invoice_with_lines(): void
    {
        $template = $this->template();

        $count = app(RecurringInvoiceGenerator::class)->generateDue();

        $this->assertSame(1, $count);

        $invoice = Invoice::firstOrFail();
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame(1, $invoice->lines()->count());
        // €100 + 21% BTW = €121,00 → 12100 centen (observer-berekend).
        $this->assertSame(12100, $invoice->total);
        $this->assertTrue($invoice->due_date->isSameDay(Carbon::today()->addDays(14)));

        $template->refresh();
        $this->assertSame(1, $template->occurrences_count);
        $this->assertTrue($template->next_date->isSameDay(Carbon::today()->addMonthNoOverflow()));
        $this->assertSame(RecurringStatus::Active, $template->status);
    }

    public function test_max_occurrences_ends_template(): void
    {
        $template = $this->template(['max_occurrences' => 1]);

        app(RecurringInvoiceGenerator::class)->generateDue();

        $template->refresh();
        $this->assertSame(RecurringStatus::Ended, $template->status);
        $this->assertSame(1, Invoice::query()->count());

        // Tweede run levert niets meer.
        app(RecurringInvoiceGenerator::class)->generateDue();
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_end_date_ends_template(): void
    {
        $template = $this->template(['end_date' => Carbon::today()]);

        app(RecurringInvoiceGenerator::class)->generateDue();

        $template->refresh();
        // Eén factuur (vandaag <= einddatum), daarna next_date voorbij einddatum → Ended.
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(RecurringStatus::Ended, $template->status);
    }

    public function test_paused_template_is_skipped(): void
    {
        $this->template(['status' => RecurringStatus::Paused]);

        $count = app(RecurringInvoiceGenerator::class)->generateDue();

        $this->assertSame(0, $count);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_catchup_is_capped(): void
    {
        // Start 3 jaar terug, maandelijks → zonder cap 36; cap = 24.
        $this->template(['start_date' => Carbon::today()->subYears(3)]);

        $count = app(RecurringInvoiceGenerator::class)->generateDue();

        $this->assertSame(24, $count);
        $this->assertSame(24, Invoice::query()->count());
    }
}
