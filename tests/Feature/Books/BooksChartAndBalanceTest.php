<?php

namespace Tests\Feature\Books;

use App\Books\Enums\AccountCategory;
use App\Books\Enums\TransactionType;
use App\Books\Models\Account;
use App\Books\Models\Transaction;
use App\Books\Services\AccountService;
use Database\Seeders\BooksChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BooksChartAndBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function deposit(int $amount, string $postedAt): void
    {
        $bank = Account::where('code', '1100')->firstOrFail();
        $omzet = Account::where('code', '8000')->firstOrFail();

        Transaction::create([
            'account_id' => $omzet->id,
            'bank_account_id' => $bank->bankAccount->id,
            'type' => TransactionType::Deposit,
            'amount' => $amount,
            'posted_at' => $postedAt,
            'description' => 'Verkoop',
        ]);
    }

    public function test_chart_seeder_creates_nl_grootboek_idempotently(): void
    {
        $this->seed(BooksChartSeeder::class);

        $this->assertSame(14, Account::count());
        $this->assertTrue(Account::where('code', '1100')->firstOrFail()->bankAccount()->exists());
        $this->assertSame(AccountCategory::Revenue, Account::where('code', '8000')->firstOrFail()->category);
        $this->assertSame(AccountCategory::Liability, Account::where('code', '1620')->firstOrFail()->category);

        // Tweede run mag niets dupliceren.
        $this->seed(BooksChartSeeder::class);
        $this->assertSame(14, Account::count());
    }

    public function test_balances_follow_normal_balance_side(): void
    {
        $this->seed(BooksChartSeeder::class);
        $this->deposit(10000, '2026-03-01');

        $svc = app(AccountService::class);
        $bank = Account::where('code', '1100')->firstOrFail();
        $omzet = Account::where('code', '8000')->firstOrFail();
        [$start, $end] = ['2026-01-01', '2026-12-31'];

        // Bank = Asset (normaal debet): net = debet - credit.
        $this->assertSame(10000, $svc->debitBalance($bank, $start, $end));
        $this->assertSame(10000, $svc->netMovement($bank, $start, $end));
        $this->assertSame(10000, $svc->endingBalance($bank, $start, $end));

        // Omzet = Revenue (normaal credit): net = credit - debet.
        $this->assertSame(10000, $svc->creditBalance($omzet, $start, $end));
        $this->assertSame(10000, $svc->netMovement($omzet, $start, $end));
        // Nominale rekening → ending == net (geen doorlopend saldo).
        $this->assertSame(10000, $svc->endingBalance($omzet, $start, $end));
    }

    public function test_starting_balance_accumulates_prior_periods_for_real_accounts(): void
    {
        $this->seed(BooksChartSeeder::class);
        $this->deposit(5000, '2025-06-01');  // vorig jaar
        $this->deposit(10000, '2026-03-01');  // dit jaar

        $svc = app(AccountService::class);
        $bank = Account::where('code', '1100')->firstOrFail();
        $omzet = Account::where('code', '8000')->firstOrFail();

        // Bank (reëel): beginsaldo 2026 = vorig jaar (5000), eindsaldo = 5000 + 10000.
        $this->assertSame(5000, $svc->startingBalance($bank, '2026-01-01'));
        $this->assertSame(15000, $svc->endingBalance($bank, '2026-01-01', '2026-12-31'));

        // Omzet (nominaal): geen doorlopend beginsaldo; net 2026 = 10000.
        $this->assertSame(0, $svc->startingBalance($omzet, '2026-01-01'));
        $this->assertSame(10000, $svc->netMovement($omzet, '2026-01-01', '2026-12-31'));
    }
}
