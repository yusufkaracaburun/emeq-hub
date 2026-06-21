<?php

namespace App\Books\Services;

use App\Books\Enums\AccountCategory;
use App\Books\Models\Account;
use Illuminate\Support\Carbon;

/*
 * Bouwt de financiële overzichten (Winst & Verlies + Balans) bovenop de
 * saldo-primitieven van AccountService. W&V = nominale rekeningen (omzet/kosten)
 * over een periode; Balans = reële rekeningen cumulatief tot een datum, met het
 * cumulatieve resultaat onder het eigen vermogen — zodat Activa = Passiva per
 * constructie sluit (dubbel-boekhouden: elke boeking is in balans).
 */
class ReportService
{
    private const INCEPTION = '2000-01-01 00:00:00';

    public function __construct(private readonly AccountService $accounts) {}

    /**
     * @return array{revenue: list<array{code: string, name: string, amount: int}>, expense: list<array{code: string, name: string, amount: int}>, total_revenue: int, total_expense: int, result: int}
     */
    public function profitAndLoss(string $start, string $end): array
    {
        [$start, $end] = $this->range($start, $end);

        $revenue = $this->lines(AccountCategory::Revenue, $start, $end);
        $expense = $this->lines(AccountCategory::Expense, $start, $end);

        $totalRevenue = $this->sum($revenue);
        $totalExpense = $this->sum($expense);

        return [
            'revenue' => $revenue,
            'expense' => $expense,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'result' => $totalRevenue - $totalExpense,
        ];
    }

    /**
     * @return array{assets: list<array{code: string, name: string, amount: int}>, liabilities: list<array{code: string, name: string, amount: int}>, equity: list<array{code: string, name: string, amount: int}>, result: int, total_assets: int, total_liabilities_and_equity: int, balances: bool}
     */
    public function balanceSheet(string $asOf): array
    {
        $end = Carbon::parse($asOf)->endOfDay()->toDateTimeString();

        $assets = $this->lines(AccountCategory::Asset, self::INCEPTION, $end);
        $liabilities = $this->lines(AccountCategory::Liability, self::INCEPTION, $end);
        $equity = $this->lines(AccountCategory::Equity, self::INCEPTION, $end);

        $result = $this->categoryTotal(AccountCategory::Revenue, self::INCEPTION, $end)
            - $this->categoryTotal(AccountCategory::Expense, self::INCEPTION, $end);

        $totalAssets = $this->sum($assets);
        $totalLiabilitiesAndEquity = $this->sum($liabilities) + $this->sum($equity) + $result;

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'result' => $result,
            'total_assets' => $totalAssets,
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'balances' => $totalAssets === $totalLiabilitiesAndEquity,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(string $start, string $end): array
    {
        return [
            Carbon::parse($start)->startOfDay()->toDateTimeString(),
            Carbon::parse($end)->endOfDay()->toDateTimeString(),
        ];
    }

    /**
     * @return list<array{code: string, name: string, amount: int}>
     */
    private function lines(AccountCategory $category, string $start, string $end): array
    {
        return Account::query()
            ->where('category', $category)
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account): array => [
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $this->accounts->netMovement($account, $start, $end),
            ])
            ->filter(fn (array $line): bool => $line['amount'] !== 0)
            ->values()
            ->all();
    }

    private function categoryTotal(AccountCategory $category, string $start, string $end): int
    {
        return $this->sum($this->lines($category, $start, $end));
    }

    /**
     * @param  list<array{amount: int}>  $lines
     */
    private function sum(array $lines): int
    {
        return (int) array_sum(array_column($lines, 'amount'));
    }
}
