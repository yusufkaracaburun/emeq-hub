<?php

namespace Tests\Feature\Books;

use App\Books\Enums\AccountCategory;
use App\Books\Enums\AccountType;
use App\Books\Enums\JournalEntryType;
use App\Books\Enums\TransactionType;
use App\Books\Models\Account;
use App\Books\Models\BankAccount;
use App\Books\Models\BooksCompany;
use App\Books\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoubleEntryPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $company = BooksCompany::create(['name' => 'Emeq']);
        config(['books.company_id' => $company->id]);
    }

    private function account(AccountCategory $category, AccountType $type, string $name, string $code): Account
    {
        return Account::create([
            'category' => $category,
            'type' => $type,
            'name' => $name,
            'code' => $code,
            'currency_code' => 'EUR',
        ]);
    }

    private function bankAccount(): BankAccount
    {
        $asset = $this->account(AccountCategory::Asset, AccountType::CurrentAsset, 'Bank', '1000');

        return BankAccount::create([
            'account_id' => $asset->id,
            'type' => 'depository',
            'enabled' => true,
        ]);
    }

    public function test_deposit_posts_balanced_debit_and_credit(): void
    {
        $bank = $this->bankAccount();
        $revenue = $this->account(AccountCategory::Revenue, AccountType::OperatingRevenue, 'Omzet', '8000');

        $txn = Transaction::create([
            'account_id' => $revenue->id,
            'bank_account_id' => $bank->id,
            'type' => TransactionType::Deposit,
            'amount' => 10000,
            'posted_at' => now(),
            'description' => 'Verkoop',
        ]);

        $entries = $txn->journalEntries()->get();
        $this->assertCount(2, $entries);

        $debit = $entries->firstWhere('type', JournalEntryType::Debit);
        $credit = $entries->firstWhere('type', JournalEntryType::Credit);

        // Deposit: bank (Asset) debet, omzet credit.
        $this->assertSame($bank->account_id, $debit->account_id);
        $this->assertSame($revenue->id, $credit->account_id);

        // Double-entry invariant: som debet == som credit.
        $this->assertSame(
            (int) $entries->where('type', JournalEntryType::Debit)->sum('amount'),
            (int) $entries->where('type', JournalEntryType::Credit)->sum('amount'),
        );
        $this->assertSame(10000, $debit->amount);
        $this->assertSame(10000, $credit->amount);
    }

    public function test_withdrawal_reverses_debit_and_credit(): void
    {
        $bank = $this->bankAccount();
        $expense = $this->account(AccountCategory::Expense, AccountType::OperatingExpense, 'Kosten', '4000');

        $txn = Transaction::create([
            'account_id' => $expense->id,
            'bank_account_id' => $bank->id,
            'type' => TransactionType::Withdrawal,
            'amount' => 7500,
            'posted_at' => now(),
            'description' => 'Inkoop',
        ]);

        $entries = $txn->journalEntries()->get();
        $this->assertCount(2, $entries);

        $debit = $entries->firstWhere('type', JournalEntryType::Debit);
        $credit = $entries->firstWhere('type', JournalEntryType::Credit);

        // Withdrawal: kosten (chart) debet, bank credit.
        $this->assertSame($expense->id, $debit->account_id);
        $this->assertSame($bank->account_id, $credit->account_id);
        $this->assertSame($debit->amount, $credit->amount);
    }

    public function test_journal_type_does_not_auto_post(): void
    {
        $bank = $this->bankAccount();
        $revenue = $this->account(AccountCategory::Revenue, AccountType::OperatingRevenue, 'Omzet', '8000');

        $txn = Transaction::create([
            'account_id' => $revenue->id,
            'bank_account_id' => $bank->id,
            'type' => TransactionType::Journal,
            'amount' => 5000,
            'posted_at' => now(),
            'description' => 'Handmatige memoriaalboeking',
        ]);

        // type=journal → LedgerPoster post niet automatisch (handmatige entries volgen later).
        $this->assertSame(0, $txn->journalEntries()->count());
    }

    public function test_company_id_is_set_from_config_on_create(): void
    {
        $account = $this->account(AccountCategory::Asset, AccountType::CurrentAsset, 'Kas', '1010');

        $this->assertSame((int) config('books.company_id'), $account->company_id);
    }
}
