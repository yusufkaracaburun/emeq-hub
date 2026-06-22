<?php

namespace App\Books\Services;

use App\Books\Enums\JournalEntryType;
use App\Books\Enums\TransactionType;
use App\Books\Models\JournalEntry;
use App\Books\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/*
 * Boekt een handmatige memoriaalboeking (vrije debet/credit-regels) naar het
 * grootboek via een Transaction(type=journal) als carrier — hetzelfde
 * JournalEntry→Transaction-spoor als InvoicePoster/BillPoster, maar generiek
 * i.p.v. document-afgeleid. De balans-invariant is hier niet per constructie
 * gegarandeerd (de gebruiker tikt zelf), dus de service bewaakt 'm expliciet.
 *
 * Bedragen in integer-centen. company_id wordt door BelongsToBooksCompany gezet.
 */
class ManualJournalPoster
{
    /**
     * @param  list<array{account_id: int, type: JournalEntryType, amount: int, description?: ?string}>  $lines
     * @param  array{posted_at: mixed, description: string, reference?: ?string, notes?: ?string}  $meta
     */
    public function post(array $lines, array $meta): Transaction
    {
        if ($error = self::balanceError($lines)) {
            throw new RuntimeException($error);
        }

        return DB::transaction(function () use ($lines, $meta): Transaction {
            $debitTotal = array_sum(array_map(
                static fn (array $line): int => $line['type'] === JournalEntryType::Debit ? (int) $line['amount'] : 0,
                $lines,
            ));

            $transaction = Transaction::create([
                'type' => TransactionType::Journal,
                'amount' => $debitTotal,
                'posted_at' => $meta['posted_at'],
                'description' => $meta['description'],
                'reference' => $meta['reference'] ?? null,
                'notes' => $meta['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                JournalEntry::create([
                    'account_id' => $line['account_id'],
                    'transaction_id' => $transaction->getKey(),
                    'type' => $line['type'],
                    'amount' => (int) $line['amount'],
                    'description' => $line['description'] ?? $meta['description'],
                ]);
            }

            return $transaction;
        });
    }

    /**
     * Canonieke balans-check, gedeeld door de Filament-form-rule (UX-edge) en
     * post() (domein-invariant). Werkt op genormaliseerde regels (centen + enum).
     *
     * @param  list<array{type: ?JournalEntryType, amount: int|float}>  $lines
     * @return string|null Nederlandse foutmelding, of null als de boeking klopt.
     */
    public static function balanceError(array $lines): ?string
    {
        if (count($lines) < 2) {
            return 'Een memoriaalboeking heeft minstens twee regels nodig.';
        }

        $debit = 0;
        $credit = 0;

        foreach ($lines as $line) {
            if (($line['type'] ?? null) === JournalEntryType::Debit) {
                $debit += (int) $line['amount'];
            } elseif (($line['type'] ?? null) === JournalEntryType::Credit) {
                $credit += (int) $line['amount'];
            }
        }

        if ($debit === 0 || $credit === 0) {
            return 'Een memoriaalboeking heeft minstens één debet- en één creditregel met een bedrag nodig.';
        }

        if ($debit !== $credit) {
            return sprintf(
                'Memoriaal niet in balans: debet € %s ≠ credit € %s.',
                number_format($debit / 100, 2, ',', '.'),
                number_format($credit / 100, 2, ',', '.'),
            );
        }

        return null;
    }
}
