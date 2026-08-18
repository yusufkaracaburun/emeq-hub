<?php

namespace App\Books\Services;

use App\Books\Enums\JournalEntryType;
use App\Books\Models\Account;
use App\Books\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LedgerPoster
{
    public function post(Transaction $transaction): void
    {
        if ($transaction->journalEntries()->exists() || $transaction->type->isJournal()) {
            return;
        }

        [$debitAccount, $creditAccount] = $this->determineAccounts($transaction);

        if ($debitAccount === null || $creditAccount === null) {
            throw new RuntimeException(
                "Transactie {$transaction->id} kan niet geboekt worden: grootboek- of bankrekening ontbreekt."
            );
        }

        DB::transaction(function () use ($transaction, $debitAccount, $creditAccount): void {
            $debitAccount->journalEntries()->create([
                'company_id' => $transaction->company_id,
                'transaction_id' => $transaction->id,
                'type' => JournalEntryType::Debit,
                'amount' => $transaction->amount,
                'description' => $transaction->description,
            ]);

            $creditAccount->journalEntries()->create([
                'company_id' => $transaction->company_id,
                'transaction_id' => $transaction->id,
                'type' => JournalEntryType::Credit,
                'amount' => $transaction->amount,
                'description' => $transaction->description,
            ]);
        });
    }

    /** @return array{0: ?Account, 1: ?Account} [debitAccount, creditAccount] */
    private function determineAccounts(Transaction $transaction): array
    {
        $chartAccount = $transaction->account;
        $bankAccount = $transaction->bankAccount?->account;

        if ($transaction->type->isTransfer()) {
            return [$chartAccount, $bankAccount];
        }

        $debitAccount = $transaction->type->isWithdrawal() ? $chartAccount : $bankAccount;
        $creditAccount = $transaction->type->isWithdrawal() ? $bankAccount : $chartAccount;

        return [$debitAccount, $creditAccount];
    }
}
