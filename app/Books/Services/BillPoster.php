<?php

namespace App\Books\Services;

use App\Books\Enums\JournalEntryType;
use App\Books\Enums\TransactionType;
use App\Books\Models\Account;
use App\Books\Models\Bill;
use App\Books\Models\JournalEntry;
use App\Books\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BillPoster
{
    private const PAYABLE = '1600';

    private const INPUT_VAT = '1530';

    public function post(Bill $bill): Transaction
    {
        if ($bill->isPosted()) {
            return $bill->transaction;
        }

        $lines = $bill->lines()->get();

        if ($lines->isEmpty()) {
            throw new RuntimeException('Inkoopfactuur zonder regels kan niet geboekt worden.');
        }

        if ($lines->whereNull('account_id')->isNotEmpty()) {
            throw new RuntimeException('Elke regel heeft een kostenrekening nodig voor het boeken.');
        }

        return DB::transaction(function () use ($bill, $lines): Transaction {
            $description = 'Inkoopfactuur '.($bill->bill_number ?? '#'.$bill->getKey());

            $transaction = Transaction::create([
                'type' => TransactionType::Journal,
                'amount' => $bill->total,
                'description' => $description,
                'posted_at' => $bill->date,
            ]);

            foreach ($lines->groupBy('account_id') as $accountId => $group) {
                $subtotal = (int) $group->sum('subtotal');

                if ($subtotal !== 0) {
                    $account = Account::find($accountId)
                        ?? throw new RuntimeException("Kostenrekening #{$accountId} ontbreekt.");

                    $this->entry($transaction, $account, JournalEntryType::Debit, $subtotal, $description);
                }
            }

            $vat = (int) $lines->sum('tax_amount');

            if ($vat !== 0) {
                $this->entry($transaction, $this->accountByCode(self::INPUT_VAT), JournalEntryType::Debit, $vat, $description);
            }

            $this->entry($transaction, $this->accountByCode(self::PAYABLE), JournalEntryType::Credit, $bill->total, $description);

            $bill->forceFill(['transaction_id' => $transaction->getKey()])->saveQuietly();

            return $transaction;
        });
    }

    public function unpost(Bill $bill): void
    {
        $transaction = $bill->transaction;

        if ($transaction === null) {
            return;
        }

        DB::transaction(function () use ($bill, $transaction): void {
            $bill->forceFill(['transaction_id' => null])->saveQuietly();
            $transaction->delete();
        });
    }

    private function accountByCode(string $code): Account
    {
        return Account::query()->where('code', $code)->first()
            ?? throw new RuntimeException("Grootboekrekening {$code} ontbreekt — seed het grootboek.");
    }

    private function entry(Transaction $transaction, Account $account, JournalEntryType $type, int $amount, string $description): void
    {
        JournalEntry::create([
            'account_id' => $account->getKey(),
            'transaction_id' => $transaction->getKey(),
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
        ]);
    }
}
