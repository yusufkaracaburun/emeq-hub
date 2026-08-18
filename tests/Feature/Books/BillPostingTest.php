<?php

namespace Tests\Feature\Books;

use App\Books\Enums\JournalEntryType;
use App\Books\Enums\TransactionType;
use App\Books\Exceptions\PostedDocumentException;
use App\Books\Models\Account;
use App\Books\Models\Bill;
use App\Books\Models\JournalEntry;
use App\Books\Models\Transaction;
use App\Books\Models\Vendor;
use App\Books\Services\AccountService;
use App\Books\Services\BillPoster;
use Database\Seeders\BooksChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BillPostingTest extends TestCase
{
    use RefreshDatabase;

    private Bill $bill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BooksChartSeeder::class);

        $vendor = Vendor::create(['name' => 'Hosting Provider BV']);

        $algemeneKosten = Account::where('code', '4400')->value('id');
        $autokosten = Account::where('code', '4500')->value('id');

        $this->bill = Bill::create([
            'vendor_id' => $vendor->id,
            'bill_number' => 'INK-2026-001',
            'status' => 'received',
            'date' => now(),
        ]);
        $this->bill->lines()->create(['account_id' => $algemeneKosten, 'description' => 'Hosting', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);
        $this->bill->lines()->create(['account_id' => $autokosten, 'description' => 'Brandstof', 'quantity' => 1, 'unit_price' => 20000, 'tax_rate' => 9]);
        $this->bill->refresh();
    }

    private function sumByCode(string $code): int
    {
        return (int) JournalEntry::whereHas('account', fn ($q) => $q->where('code', $code))->sum('amount');
    }

    public function test_posted_bill_cannot_be_updated(): void
    {
        app(BillPoster::class)->post($this->bill);
        $this->bill->refresh();

        $this->expectException(PostedDocumentException::class);

        $this->bill->update(['bill_number' => 'gewijzigd']);
    }

    public function test_posted_bill_cannot_be_deleted(): void
    {
        app(BillPoster::class)->post($this->bill);
        $this->bill->refresh();

        $this->expectException(PostedDocumentException::class);

        $this->bill->delete();
    }

    public function test_posting_creates_a_balanced_purchase_entry(): void
    {
        $transaction = app(BillPoster::class)->post($this->bill);
        $this->bill->refresh();

        $this->assertTrue($this->bill->isPosted());
        $this->assertSame($transaction->id, $this->bill->transaction_id);
        $this->assertSame(TransactionType::Journal, $transaction->type);

        $entries = $transaction->journalEntries()->get();

        $this->assertCount(4, $entries);
        $this->assertSame(
            (int) $entries->where('type', JournalEntryType::Debit)->sum('amount'),
            (int) $entries->where('type', JournalEntryType::Credit)->sum('amount'),
        );

        $this->assertSame(10000, $this->sumByCode('4400'));
        $this->assertSame(20000, $this->sumByCode('4500'));
        $this->assertSame(3900, $this->sumByCode('1530'));
        $this->assertSame(33900, $this->sumByCode('1600'));
    }

    public function test_posting_groups_lines_on_the_same_cost_account(): void
    {
        $algemeneKosten = Account::where('code', '4400')->value('id');
        $this->bill->lines()->create(['account_id' => $algemeneKosten, 'description' => 'Extra hosting', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 21]);
        $this->bill->refresh();

        $transaction = app(BillPoster::class)->post($this->bill);

        $this->assertSame(1, $transaction->journalEntries()->whereHas('account', fn ($q) => $q->where('code', '4400'))->count());
        $this->assertSame(15000, $this->sumByCode('4400'));
    }

    public function test_posting_requires_a_cost_account_on_every_line(): void
    {
        $vendor = Vendor::create(['name' => 'Onbekend']);
        $bill = Bill::create(['vendor_id' => $vendor->id, 'status' => 'received', 'date' => now()]);
        $bill->lines()->create(['account_id' => null, 'description' => 'Ongecategoriseerd', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 21]);

        $this->expectException(RuntimeException::class);

        app(BillPoster::class)->post($bill->refresh());
    }

    public function test_posting_is_idempotent(): void
    {
        $first = app(BillPoster::class)->post($this->bill);
        $second = app(BillPoster::class)->post($this->bill->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(4, $first->journalEntries()->count());
    }

    public function test_unposting_removes_entries_and_clears_link(): void
    {
        app(BillPoster::class)->post($this->bill);
        $transactionId = $this->bill->refresh()->transaction_id;

        app(BillPoster::class)->unpost($this->bill);
        $this->bill->refresh();

        $this->assertFalse($this->bill->isPosted());
        $this->assertNull($this->bill->transaction_id);
        $this->assertNull(Transaction::find($transactionId));
        $this->assertSame(0, JournalEntry::where('transaction_id', $transactionId)->count());
    }

    public function test_posting_moves_the_crediteuren_balance(): void
    {
        app(BillPoster::class)->post($this->bill);

        $crediteuren = Account::where('code', '1600')->firstOrFail();

        $this->assertSame(33900, app(AccountService::class)->netMovement(
            $crediteuren,
            now()->startOfDay()->toDateTimeString(),
            now()->endOfDay()->toDateTimeString(),
        ));
    }
}
