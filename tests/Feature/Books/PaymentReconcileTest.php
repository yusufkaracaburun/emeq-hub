<?php

namespace Tests\Feature\Books;

use App\Books\Enums\InvoiceStatus;
use App\Books\Enums\TransactionType;
use App\Books\Models\Account;
use App\Books\Models\BankAccount;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Models\Transaction;
use App\Books\Services\InvoicePoster;
use App\Books\Services\PaymentService;
use Database\Seeders\BooksChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PaymentReconcileTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    private int $bankAccountId;

    private int $debiteurenId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BooksChartSeeder::class);
        $this->bankAccountId = (int) BankAccount::query()->value('id');
        $this->debiteurenId = (int) Account::where('code', '1300')->value('id');

        $client = Client::create(['name' => 'Acme BV']);
        $this->invoice = Invoice::create(['client_id' => $client->id, 'invoice_number' => '2026-001', 'status' => 'sent', 'date' => now()]);
        $this->invoice->lines()->create(['description' => 'Werk', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);
        app(InvoicePoster::class)->post($this->invoice->refresh());
    }

    private function depositToDebiteuren(int $amount): Transaction
    {
        return Transaction::create([
            'type' => TransactionType::Deposit,
            'account_id' => $this->debiteurenId,
            'bank_account_id' => $this->bankAccountId,
            'amount' => $amount,
            'description' => 'Bijschrijving',
            'posted_at' => now(),
        ]);
    }

    public function test_reconcile_settles_invoice_without_a_new_bank_leg(): void
    {
        $transaction = $this->depositToDebiteuren(12100);
        $journalCountBefore = $transaction->journalEntries()->count();

        $payment = app(PaymentService::class)->reconcile($transaction, $this->invoice, 12100);
        $this->invoice->refresh();

        $this->assertTrue($this->invoice->isPaid());
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->status);
        $this->assertSame($transaction->id, $payment->transaction_id);
        $this->assertSame(0, $transaction->refresh()->unallocatedAmount());
        $this->assertSame($journalCountBefore, $transaction->journalEntries()->count());
    }

    public function test_partial_reconcile_leaves_unallocated_remainder(): void
    {
        $transaction = $this->depositToDebiteuren(12100);

        app(PaymentService::class)->reconcile($transaction, $this->invoice, 5000);

        $this->assertSame(7100, $transaction->refresh()->unallocatedAmount());
        $this->assertSame(7100, $this->invoice->refresh()->amountDue());
    }

    public function test_reconcile_rejects_amount_above_unallocated_remainder(): void
    {
        $transaction = $this->depositToDebiteuren(5000);

        $this->expectException(RuntimeException::class);

        app(PaymentService::class)->reconcile($transaction, $this->invoice, 8000);
    }

    public function test_reconcile_rejects_wrong_counter_account(): void
    {
        $transaction = Transaction::create([
            'type' => TransactionType::Deposit,
            'account_id' => Account::where('code', '8000')->value('id'),
            'bank_account_id' => $this->bankAccountId,
            'amount' => 12100,
            'description' => 'Directe omzet',
            'posted_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);

        app(PaymentService::class)->reconcile($transaction, $this->invoice, 12100);
    }
}
