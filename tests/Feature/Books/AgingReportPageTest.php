<?php

namespace Tests\Feature\Books;

use App\Books\Models\BooksCompany;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Models\Vendor;
use App\Filament\Books\Pages\AgingReport;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AgingReportPageTest extends TestCase
{
    use RefreshDatabase;

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

        $client = Client::create(['name' => 'Acme BV']);
        Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => '2026-001',
            'status' => 'sent',
            'date' => now()->subDays(45)->toDateString(),
            'due_date' => now()->subDays(45)->toDateString(),
            'total' => 12100,
        ]);
    }

    public function test_page_renders_receivables_by_default(): void
    {
        Livewire::test(AgingReport::class)
            ->assertOk()
            ->assertSet('kind', 'receivable')
            ->assertSee('Acme BV')
            ->assertSee('€ 121,00');
    }

    public function test_defaults_as_of_to_today(): void
    {
        Livewire::test(AgingReport::class)
            ->assertSet('asOfDate', now()->toDateString());
    }

    public function test_switches_to_payables(): void
    {
        Vendor::create(['name' => 'Hosting BV']);

        Livewire::test(AgingReport::class)
            ->call('setKind', 'payable')
            ->assertSet('kind', 'payable')
            ->assertSee('Crediteuren')
            ->assertDontSee('Acme BV'); // verkoopfacturen horen niet in de crediteuren-weergave
    }

    public function test_pdf_action_streams_download(): void
    {
        Livewire::test(AgingReport::class)
            ->callAction('pdf')
            ->assertOk();
    }
}
