<?php

namespace Tests\Feature\Books;

use App\Books\Enums\JournalEntryType;
use App\Books\Enums\TransactionType;
use App\Books\Exceptions\PostedDocumentException;
use App\Books\Models\Account;
use App\Books\Models\Client;
use App\Books\Models\Invoice;
use App\Books\Models\JournalEntry;
use App\Books\Models\Transaction;
use App\Books\Services\AccountService;
use App\Books\Services\InvoicePoster;
use Database\Seeders\BooksChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePostingTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BooksChartSeeder::class);

        $client = Client::create(['name' => 'Acme BV']);

        $this->invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => '2026-001',
            'status' => 'sent',
            'date' => now(),
        ]);
        $this->invoice->lines()->create(['description' => 'Werk', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);
        $this->invoice->lines()->create(['description' => 'Dienst', 'quantity' => 1, 'unit_price' => 20000, 'tax_rate' => 9]);
        $this->invoice->refresh();
    }

    private function sumByCode(string $code): int
    {
        return (int) JournalEntry::whereHas('account', fn ($q) => $q->where('code', $code))->sum('amount');
    }

    public function test_posting_creates_a_balanced_sales_entry(): void
    {
        $transaction = app(InvoicePoster::class)->post($this->invoice);
        $this->invoice->refresh();

        $this->assertTrue($this->invoice->isPosted());
        $this->assertSame($transaction->id, $this->invoice->transaction_id);
        $this->assertSame(TransactionType::Journal, $transaction->type);

        $entries = $transaction->journalEntries()->get();

        $this->assertCount(5, $entries);
        $this->assertSame(
            (int) $entries->where('type', JournalEntryType::Debit)->sum('amount'),
            (int) $entries->where('type', JournalEntryType::Credit)->sum('amount'),
        );

        $this->assertSame(33900, $this->sumByCode('1300'));
        $this->assertSame(10000, $this->sumByCode('8000'));
        $this->assertSame(2100, $this->sumByCode('1620'));
        $this->assertSame(20000, $this->sumByCode('8010'));
        $this->assertSame(1800, $this->sumByCode('1621'));
    }

    public function test_posting_is_idempotent(): void
    {
        $first = app(InvoicePoster::class)->post($this->invoice);
        $second = app(InvoicePoster::class)->post($this->invoice->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(5, $first->journalEntries()->count());
    }

    public function test_unposting_removes_entries_and_clears_link(): void
    {
        app(InvoicePoster::class)->post($this->invoice);
        $transactionId = $this->invoice->refresh()->transaction_id;

        app(InvoicePoster::class)->unpost($this->invoice);
        $this->invoice->refresh();

        $this->assertFalse($this->invoice->isPosted());
        $this->assertNull($this->invoice->transaction_id);
        $this->assertNull(Transaction::find($transactionId));
        $this->assertSame(0, JournalEntry::where('transaction_id', $transactionId)->count());
    }

    public function test_posted_invoice_cannot_be_updated(): void
    {
        app(InvoicePoster::class)->post($this->invoice);
        $this->invoice->refresh();

        $this->expectException(PostedDocumentException::class);

        $this->invoice->update(['invoice_number' => 'gewijzigd']);
    }

    public function test_posted_invoice_cannot_be_deleted(): void
    {
        app(InvoicePoster::class)->post($this->invoice);
        $this->invoice->refresh();

        $this->expectException(PostedDocumentException::class);

        $this->invoice->delete();
    }

    public function test_unposted_invoice_remains_editable(): void
    {
        $this->invoice->update(['invoice_number' => 'gewijzigd']);

        $this->assertSame('gewijzigd', $this->invoice->refresh()->invoice_number);
    }

    public function test_posting_moves_the_debiteuren_balance(): void
    {
        app(InvoicePoster::class)->post($this->invoice);

        $debiteuren = Account::where('code', '1300')->firstOrFail();

        $this->assertSame(33900, app(AccountService::class)->netMovement(
            $debiteuren,
            now()->startOfDay()->toDateTimeString(),
            now()->endOfDay()->toDateTimeString(),
        ));
    }
}
