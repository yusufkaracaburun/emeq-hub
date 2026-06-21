<?php

namespace App\Books\Services;

use App\Books\Enums\JournalEntryType;
use App\Books\Enums\TransactionType;
use App\Books\Models\Account;
use App\Books\Models\Invoice;
use App\Books\Models\JournalEntry;
use App\Books\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/*
 * Boekt een verkoopfactuur naar het grootboek via een memoriaal-Transaction
 * (type=journal) als carrier — het bestaande JournalEntry→Transaction-spoor,
 * géén schema-uitbreiding. De boeking is per constructie in balans:
 *   debet 1300 Debiteuren            = factuur-totaal (incl. BTW)
 *   credit 8000/8010/8020 Omzet      = subtotaal per BTW-tarief
 *   credit 1620/1621 Af te dragen BTW = BTW per tarief
 * Σ credits = Σ subtotalen + Σ BTW = totaal = debet.
 */
class InvoicePoster
{
    /** @var array<int, array{0: string, 1: ?string}> BTW-tarief => [omzetrekening, af-te-dragen-BTW-rekening] */
    private const REVENUE_BY_RATE = [
        21 => ['8000', '1620'],
        9 => ['8010', '1621'],
        0 => ['8020', null],
    ];

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
                [$revenueCode, $vatCode] = self::REVENUE_BY_RATE[(int) $rate]
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
