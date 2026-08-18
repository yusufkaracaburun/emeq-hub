<?php

namespace App\Books\Services;

use App\Books\Enums\JournalEntryType;
use App\Books\Enums\TransactionType;
use App\Books\Models\Account;
use App\Books\Models\Invoice;
use App\Books\Models\JournalEntry;
use App\Books\Models\Transaction;
use App\Books\Support\VatLedgerAccounts;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InvoicePoster
{
    private const RECEIVABLE = '1300';

    public function post(Invoice $invoice): Transaction
    {
        if ($invoice->isPosted()) {
            return $invoice->transaction;
        }

        $lines = $invoice->lines()->get();

        if ($lines->isEmpty()) {
            throw new RuntimeException('Factuur zonder regels kan niet geboekt worden.');
        }

        return DB::transaction(function () use ($invoice, $lines): Transaction {
            $description = 'Verkoopfactuur '.($invoice->invoice_number ?? '#'.$invoice->getKey());

            $transaction = Transaction::create([
                'type' => TransactionType::Journal,
                'amount' => $invoice->total,
                'description' => $description,
                'posted_at' => $invoice->date,
            ]);

            $this->entry($transaction, self::RECEIVABLE, JournalEntryType::Debit, $invoice->total, $description);

            foreach ($lines->groupBy('tax_rate') as $rate => $group) {
                [$revenueCode, $vatCode] = VatLedgerAccounts::forRate((int) $rate)
                    ?? throw new RuntimeException("Geen omzetrekening voor BTW-tarief {$rate}%.");

                $revenue = (int) $group->sum('subtotal');
                $vat = (int) $group->sum('tax_amount');

                if ($revenue !== 0) {
                    $this->entry($transaction, $revenueCode, JournalEntryType::Credit, $revenue, $description);
                }

                if ($vat !== 0 && $vatCode !== null) {
                    $this->entry($transaction, $vatCode, JournalEntryType::Credit, $vat, $description);
                }
            }

            $invoice->forceFill(['transaction_id' => $transaction->getKey()])->saveQuietly();

            return $transaction;
        });
    }

    public function unpost(Invoice $invoice): void
    {
        $transaction = $invoice->transaction;

        if ($transaction === null) {
            return;
        }

        DB::transaction(function () use ($invoice, $transaction): void {
            $invoice->forceFill(['transaction_id' => null])->saveQuietly();
            $transaction->delete();
        });
    }

    private function entry(Transaction $transaction, string $code, JournalEntryType $type, int $amount, string $description): void
    {
        $account = Account::query()->where('code', $code)->first()
            ?? throw new RuntimeException("Grootboekrekening {$code} ontbreekt — seed het grootboek.");

        JournalEntry::create([
            'account_id' => $account->getKey(),
            'transaction_id' => $transaction->getKey(),
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
        ]);
    }
}
