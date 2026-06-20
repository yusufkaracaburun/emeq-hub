<?php

namespace Tests\Feature\Books;

use App\Books\Enums\AccountCategory;
use App\Books\Enums\AccountType;
use App\Books\Models\Account;
use App\Books\Models\BooksCompany;
use App\Filament\Books\Resources\LedgerAccounts\Pages\CreateLedgerAccount;
use App\Filament\Books\Resources\LedgerAccounts\Pages\ListLedgerAccounts;
use App\Models\User;
use Database\Seeders\BooksChartSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LedgerAccountResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('books');

        Role::firstOrCreate(['name' => 'boekhouder']);
        $boekhouder = User::factory()->create();
        $boekhouder->assignRole('boekhouder');
        $this->actingAs($boekhouder);
    }

    public function test_list_page_shows_seeded_grootboek(): void
    {
        $this->seed(BooksChartSeeder::class);

        // Default-sort op code; eerste pagina (10/14). Assert een paar bekende
        // page-1-rekeningen i.p.v. alle 14 (pagineren verbergt de rest).
        Livewire::test(ListLedgerAccounts::class)
            ->assertOk()
            ->assertCanSeeTableRecords(Account::whereIn('code', ['0500', '1000', '1100'])->get());
    }

    public function test_create_derives_category_from_type(): void
    {
        BooksCompany::create(['name' => 'Emeq']);

        Livewire::test(CreateLedgerAccount::class)
            ->fillForm([
                'code' => '4600',
                'name' => 'Verkoopkosten',
                'type' => AccountType::OperatingExpense->value,
                'currency_code' => 'EUR',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $account = Account::where('code', '4600')->firstOrFail();
        $this->assertSame(AccountCategory::Expense, $account->category);
        $this->assertSame(AccountType::OperatingExpense, $account->type);
    }
}
