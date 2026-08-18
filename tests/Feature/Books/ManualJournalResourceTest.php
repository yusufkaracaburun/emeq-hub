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
use App\Books\Services\ManualJournalPoster;
use App\Filament\Books\Resources\ManualJournals\ManualJournalResource;
use App\Filament\Books\Resources\ManualJournals\Pages\CreateManualJournal;
use App\Filament\Books\Resources\ManualJournals\Pages\ListManualJournals;
use App\Filament\Books\Resources\ManualJournals\Pages\ViewManualJournal;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualJournalResourceTest extends TestCase
{
    use RefreshDatabase;

    private Account $expense;

    private Account $payable;

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

        $this->expense = $this->account(AccountCategory::Expense, AccountType::OperatingExpense, 'Kosten', '4000');
        $this->payable = $this->account(AccountCategory::Liability, AccountType::CurrentLiability, 'Crediteuren', '1600');
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

    public function test_create_balanced_memoriaal_posts_journal_transaction(): void
    {
        $undo = Repeater::fake();

        Livewire::test(CreateManualJournal::class)
            ->fillForm([
                'posted_at' => now()->toDateTimeString(),
                'description' => 'Afschrijving inventaris',
                'lines' => [
                    ['account_id' => $this->expense->id, 'type' => JournalEntryType::Debit->value, 'amount' => 100],
                    ['account_id' => $this->payable->id, 'type' => JournalEntryType::Credit->value, 'amount' => 100],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undo();

        $txn = Transaction::firstOrFail();

        $this->assertSame(TransactionType::Journal, $txn->type);
        $this->assertSame(10000, $txn->amount);
        $this->assertSame(2, $txn->journalEntries()->count());
        $this->assertSame(
            (int) $txn->journalEntries()->where('type', JournalEntryType::Debit)->sum('amount'),
            (int) $txn->journalEntries()->where('type', JournalEntryType::Credit)->sum('amount'),
        );
    }

    public function test_unbalanced_memoriaal_is_rejected(): void
    {
        $undo = Repeater::fake();

        Livewire::test(CreateManualJournal::class)
            ->fillForm([
                'posted_at' => now()->toDateTimeString(),
                'description' => 'Scheef',
                'lines' => [
                    ['account_id' => $this->expense->id, 'type' => JournalEntryType::Debit->value, 'amount' => 100],
                    ['account_id' => $this->payable->id, 'type' => JournalEntryType::Credit->value, 'amount' => 60],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['lines']);

        $undo();

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_single_sided_memoriaal_is_rejected(): void
    {
        $undo = Repeater::fake();

        Livewire::test(CreateManualJournal::class)
            ->fillForm([
                'posted_at' => now()->toDateTimeString(),
                'description' => 'Alleen debet',
                'lines' => [
                    ['account_id' => $this->expense->id, 'type' => JournalEntryType::Debit->value, 'amount' => 100],
                    ['account_id' => $this->payable->id, 'type' => JournalEntryType::Debit->value, 'amount' => 100],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['lines']);

        $undo();

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_list_is_scoped_to_journal_type(): void
    {
        $journal = app(ManualJournalPoster::class)->post(
            [
                ['account_id' => $this->expense->id, 'type' => JournalEntryType::Debit, 'amount' => 5000],
                ['account_id' => $this->payable->id, 'type' => JournalEntryType::Credit, 'amount' => 5000],
            ],
            ['posted_at' => now(), 'description' => 'Memo'],
        );

        $bank = BankAccount::create([
            'account_id' => $this->account(AccountCategory::Asset, AccountType::CurrentAsset, 'Bank', '1100')->id,
            'type' => 'depository',
            'enabled' => true,
        ]);
        $deposit = Transaction::create([
            'account_id' => $this->expense->id,
            'bank_account_id' => $bank->id,
            'type' => TransactionType::Deposit,
            'amount' => 5000,
            'posted_at' => now(),
            'description' => 'Ontvangst',
        ]);

        Livewire::test(ListManualJournals::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$journal])
            ->assertCanNotSeeTableRecords([$deposit]);
    }

    public function test_view_page_renders_journal_with_lines(): void
    {
        $journal = app(ManualJournalPoster::class)->post(
            [
                ['account_id' => $this->expense->id, 'type' => JournalEntryType::Debit, 'amount' => 5000],
                ['account_id' => $this->payable->id, 'type' => JournalEntryType::Credit, 'amount' => 5000],
            ],
            ['posted_at' => now(), 'description' => 'Memo'],
        );

        Livewire::test(ViewManualJournal::class, ['record' => $journal->getKey()])
            ->assertOk();
    }

    public function test_resource_is_immutable_no_edit_page(): void
    {
        $this->assertArrayNotHasKey('edit', ManualJournalResource::getPages());
    }

    public function test_boekhouder_can_access_staff_cannot(): void
    {
        $this->assertTrue(ManualJournalResource::canAccess());

        Role::firstOrCreate(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->actingAs($staff);

        $this->assertFalse(ManualJournalResource::canAccess());
    }

    public function test_balance_error_messages(): void
    {
        $this->assertNotNull(ManualJournalPoster::balanceError([
            ['type' => JournalEntryType::Debit, 'amount' => 100],
        ]));

        $this->assertNotNull(ManualJournalPoster::balanceError([
            ['type' => JournalEntryType::Debit, 'amount' => 100],
            ['type' => JournalEntryType::Credit, 'amount' => 60],
        ]));

        $this->assertNull(ManualJournalPoster::balanceError([
            ['type' => JournalEntryType::Debit, 'amount' => 100],
            ['type' => JournalEntryType::Credit, 'amount' => 100],
        ]));
    }
}
