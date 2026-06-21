<?php

namespace Tests\Feature\Books;

use App\Books\Enums\BillStatus;
use App\Books\Enums\InvoiceStatus;
use App\Books\Enums\JournalEntryType;
use App\Books\Enums\TransactionType;
use App\Books\Models\Account;
use App\Books\Models\BankAccount;
use App\Books\Models\Bill;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Models\JournalEntry;
use App\Books\Models\Transaction;
use App\Books\Models\Vendor;
use App\Books\Services\BillPoster;
use App\Books\Services\InvoicePoster;
use App\Books\Services\PaymentService;
use Database\Seeders\BooksChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    private int $bankAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BooksChartSeeder::class);
        $this->bankAccountId = (int) BankAccount::query()->value('id');

        $client = Client::create(['name' => 'Acme BV']);
        $this->invoice = Invoice::create(['client_id' => $client->id, 'invoice_number' => '2026-001', 'status' => 'sent', 'date' => now()]);
        $this->invoice->lines()->create(['description' => 'Werk', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);
        $this->invoice->refresh(); // total 12100
        app(InvoicePoster::class)->post($this->invoice->refresh());
    }

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function entryByCode(Transaction $transaction, string $code, JournalEntryType $type): int
    {
        return (int) $transaction->journalEntries()
            ->where('type', $type)
            ->whereHas('account', fn ($q) => $q->where('code', $code))
            ->sum('amount');
    }

    public function test_full_payment_settles_invoice_and_books_bank_leg(): void
    {
        $payment = $this->service()->register($this->invoice, $this->bankAccountId, 12100, now()->toDateString());
        $this->invoice->refresh();

        $this->assertTrue($this->invoice->isPaid());
        $this->assertSame(0, $this->invoice->amountDue());
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->status);

        // Klant betaalt → Deposit: debet 1100 Bank, credit 1300 Debiteuren.
        $transaction = $payment->transaction;
        $this->assertSame(TransactionType::Deposit, $transaction->type);
        $this->assertSame(12100, $this->entryByCode($transaction, '1100', JournalEntryType::Debit));
        $this->assertSame(12100, $this->entryByCode($transaction, '1300', JournalEntryType::Credit));
    }

    public function test_partial_payment_leaves_balance_and_keeps_status(): void
    {
        $this->service()->register($this->invoice, $this->bankAccountId, 5000, now()->toDateString());
        $this->invoice->refresh();

        $this->assertFalse($this->invoice->isPaid());
        $this->assertTrue($this->invoice->isPartiallyPaid());
        $this->assertSame(7100, $this->invoice->amountDue());
        $this->assertSame(InvoiceStatus::Sent, $this->invoice->status); // ongemoeid bij partial
    }

    public function test_multiple_partial_payments_settle_in_full(): void
    {
        $this->service()->register($this->invoice, $this->bankAccountId, 5000, now()->toDateString());
        $this->service()->register($this->invoice->refresh(), $this->bankAccountId, 7100, now()->toDateString());
        $this->invoice->refresh();

        $this->assertTrue($this->invoice->isPaid());
        $this->assertSame(2, $this->invoice->payments()->count());
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->status);
    }

    public function test_overpayment_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->register($this->invoice, $this->bankAccountId, 12101, now()->toDateString());
    }

    public function test_payment_on_unposted_document_is_rejected(): void
    {
        $client = Client::create(['name' => 'Beta BV']);
        $unposted = Invoice::create(['client_id' => $client->id, 'status' => 'sent', 'date' => now()]);
        $unposted->lines()->create(['description' => 'X', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 21]);

        $this->expectException(RuntimeException::class);

        $this->service()->register($unposted->refresh(), $this->bankAccountId, 1000, now()->toDateString());
    }

    public function test_remove_reverses_payment_and_bank_leg(): void
    {
        $payment = $this->service()->register($this->invoice, $this->bankAccountId, 12100, now()->toDateString());
        $transactionId = $payment->transaction_id;

        $this->service()->remove($payment);
        $this->invoice->refresh();

        $this->assertFalse($this->invoice->isPaid());
        $this->assertSame(12100, $this->invoice->amountDue());
        $this->assertSame(InvoiceStatus::Sent, $this->invoice->status);
        $this->assertNull(Transaction::find($transactionId));
        $this->assertSame(0, JournalEntry::where('transaction_id', $transactionId)->count());
    }

    public function test_bill_payment_books_a_withdrawal_against_crediteuren(): void
    {
        $vendor = Vendor::create(['name' => 'Hosting BV']);
        $bill = Bill::create(['vendor_id' => $vendor->id, 'bill_number' => 'INK-1', 'status' => 'received', 'date' => now()]);
        $bill->lines()->create(['account_id' => Account::where('code', '4400')->value('id'), 'description' => 'Hosting', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);
        $bill->refresh(); // total 12100
        app(BillPoster::class)->post($bill->refresh());

        $payment = $this->service()->register($bill->refresh(), $this->bankAccountId, 12100, now()->toDateString());
        $bill->refresh();

        $this->assertTrue($bill->isPaid());
        $this->assertSame(BillStatus::Paid, $bill->status);

        // Wij betalen → Withdrawal: debet 1600 Crediteuren, credit 1100 Bank.
        $transaction = $payment->transaction;
        $this->assertSame(TransactionType::Withdrawal, $transaction->type);
        $this->assertSame(12100, $this->entryByCode($transaction, '1600', JournalEntryType::Debit));
        $this->assertSame(12100, $this->entryByCode($transaction, '1100', JournalEntryType::Credit));
    }
}
