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
use App\Filament\Books\Resources\Transactions\Pages\CreateTransaction;
use App\Filament\Books\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Books\Resources\Transactions\Pages\ViewTransaction;
use App\Filament\Books\Resources\Transactions\TransactionResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransactionResourceTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $bank;

    private Account $revenue;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('books');

        $company = BooksCompany::create(['name' => 'Emeq']);
        config(['books.company_id' => $company->id]);

        Role::firstOrCreate(['name' => 'boekhouder']);
        $boekhouder = User::factory()->create();
        $boekhouder->assignRole('boekhouder');
        $this->actingAs($boekhouder);

        $asset = $this->account(AccountCategory::Asset, AccountType::CurrentAsset, 'Bank', '1100');
        $this->bank = BankAccount::create([
            'account_id' => $asset->id,
            'type' => 'depository',
            'enabled' => true,
        ]);
        $this->revenue = $this->account(AccountCategory::Revenue, AccountType::OperatingRevenue, 'Omzet', '8000');
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

    private function transaction(): Transaction
    {
        return Transaction::create([
            'account_id' => $this->revenue->id,
            'bank_account_id' => $this->bank->id,
            'type' => TransactionType::Deposit,
            'amount' => 5000,
            'posted_at' => now(),
            'description' => 'Verkoop',
        ]);
    }

    public function test_create_books_transaction_with_balanced_ledger(): void
    {
        Livewire::test(CreateTransaction::class)
            ->fillForm([
                'type' => TransactionType::Deposit->value,
                'bank_account_id' => $this->bank->id,
                'account_id' => $this->revenue->id,
                'amount' => 100, // euro's
                'posted_at' => now()->toDateTimeString(),
                'description' => 'Verkoop',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $txn = Transaction::firstOrFail();

        // Euro-invoer is opgeslagen als integer-centen.
        $this->assertSame(10000, $txn->amount);

        // Observer heeft een gebalanceerde debet/credit-boeking gepost.
        $this->assertSame(2, $txn->journalEntries()->count());
        $this->assertSame(
            (int) $txn->journalEntries()->where('type', JournalEntryType::Debit)->sum('amount'),
            (int) $txn->journalEntries()->where('type', JournalEntryType::Credit)->sum('amount'),
        );
    }

    public function test_list_page_shows_transaction(): void
    {
        $txn = $this->transaction();

        Livewire::test(ListTransactions::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$txn]);
    }

    public function test_view_page_loads(): void
    {
        $txn = $this->transaction();

        Livewire::test(ViewTransaction::class, ['record' => $txn->id])
            ->assertOk();
    }

    public function test_resource_is_immutable_no_edit_page(): void
    {
        $this->assertArrayNotHasKey('edit', TransactionResource::getPages());
    }
}
