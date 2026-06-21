<?php

namespace Tests\Feature\Books;

use App\Books\Models\BankAccount;
use App\Books\Models\BooksCompany;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Services\InvoicePoster;
use App\Filament\Books\Resources\Invoices\Pages\ListInvoices;
use App\Models\User;
use Database\Seeders\BooksChartSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentActionTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    private int $bankAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('books');

        $company = BooksCompany::create(['name' => 'Emeq']);
        config(['books.company_id' => $company->id]);

        $this->seed(BooksChartSeeder::class);
        $this->bankAccountId = (int) BankAccount::query()->value('id');

        Role::firstOrCreate(['name' => 'boekhouder']);
        $boekhouder = User::factory()->create();
        $boekhouder->assignRole('boekhouder');
        $this->actingAs($boekhouder);

        $client = Client::create(['name' => 'Acme BV']);
        $this->invoice = Invoice::create(['client_id' => $client->id, 'invoice_number' => '2026-001', 'status' => 'sent', 'date' => now()]);
        $this->invoice->lines()->create(['description' => 'Werk', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);
        app(InvoicePoster::class)->post($this->invoice->refresh());
    }

    public function test_register_payment_action_settles_the_invoice(): void
    {
        Livewire::test(ListInvoices::class)
            ->callTableAction('betaling', $this->invoice, data: [
                'bank_account_id' => $this->bankAccountId,
                'amount' => '121.00',
                'date' => now()->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->invoice->refresh();

        $this->assertTrue($this->invoice->isPaid());
        $this->assertSame(1, $this->invoice->payments()->count());
    }

    public function test_undo_action_reverses_the_last_payment(): void
    {
        Livewire::test(ListInvoices::class)
            ->callTableAction('betaling', $this->invoice, data: [
                'bank_account_id' => $this->bankAccountId,
                'amount' => '121.00',
                'date' => now()->toDateString(),
            ]);

        Livewire::test(ListInvoices::class)
            ->callTableAction('betaling_terugdraaien', $this->invoice->refresh());

        $this->invoice->refresh();

        $this->assertFalse($this->invoice->isPaid());
        $this->assertSame(0, $this->invoice->payments()->count());
        $this->assertSame(12100, $this->invoice->amountDue());
    }
}
