<?php

namespace App\Books\Services;

use App\Books\Enums\AccountCategory;
use App\Books\Enums\JournalEntryType;
use App\Books\Models\Account;
use App\Books\Models\JournalEntry;

/*
 * Saldo-berekening over de journaalposten. De kern-regel
 * (calculateNetMovementByCategory) volgt het dubbel-boekhouden-teken per
 * AccountCategory; bedragen zijn integer-centen (geen Money-VO) en de query loopt
 * over de books_-tabellen.
 */
class AccountService
{
    public function debitBalance(Account $account, string $startDate, string $endDate): int
    {
        return $this->sumInPeriod($account, JournalEntryType::Debit, $startDate, $endDate);
    }

    public function creditBalance(Account $account, string $startDate, string $endDate): int
    {
        return $this->sumInPeriod($account, JournalEntryType::Credit, $startDate, $endDate);
    }

    public function netMovement(Account $account, string $startDate, string $endDate): int
    {
        return $this->calculateNetMovementByCategory(
            $account->category,
            $this->debitBalance($account, $startDate, $endDate),
            $this->creditBalance($account, $startDate, $endDate),
        );
    }

    /**
     * Cumulatief saldo vóór $startDate. Alleen zinvol voor reële rekeningen;
     * nominale rekeningen (revenue/expense) starten elke periode op nul.
     */
    public function startingBalance(Account $account, string $startDate): int
    {
        if ($account->category->isNominal()) {
            return 0;
        }

        return $this->calculateNetMovementByCategory(
            $account->category,
            $this->sumBefore($account, JournalEntryType::Debit, $startDate),
            $this->sumBefore($account, JournalEntryType::Credit, $startDate),
        );
    }

    public function endingBalance(Account $account, string $startDate, string $endDate): int
    {
        $netMovement = $this->netMovement($account, $startDate, $endDate);

        if ($account->category->isNominal()) {
            return $netMovement;
        }

        return $this->startingBalance($account, $startDate) + $netMovement;
    }

    public function calculateNetMovementByCategory(AccountCategory $category, int $debit, int $credit): int
    {
        return $category->isNormalDebitBalance()
            ? $debit - $credit
            : $credit - $debit;
    }

    private function sumInPeriod(Account $account, JournalEntryType $type, string $startDate, string $endDate): int
    {
        return (int) $this->baseQuery($account, $type)
            ->whereHas('transaction', function ($query) use ($startDate, $endDate): void {
                $query->whereBetween('posted_at', [$startDate, $endDate]);
            })
            ->sum('amount');
    }

    private function sumBefore(Account $account, JournalEntryType $type, string $date): int
    {
        return (int) $this->baseQuery($account, $type)
            ->whereHas('transaction', function ($query) use ($date): void {
                $query->where('posted_at', '<', $date);
            })
            ->sum('amount');
    }

    private function baseQuery(Account $account, JournalEntryType $type)
    {
        return JournalEntry::query()
            ->where('account_id', $account->getKey())
            ->where('type', $type);
    }
}
