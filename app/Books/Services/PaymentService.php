<?php

namespace App\Books\Services;

use App\Books\Enums\TransactionType;
use App\Books\Models\Account;
use App\Books\Models\Bill;
use App\Books\Models\Invoice;
use App\Books\Models\Payment;
use App\Books\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/*
 * Betalingen/afletteren. De double-entry van de bank-leg leeft NIET hier maar in
 * LedgerPoster: een betaling is een Deposit (klant betaalt → debet 1100 Bank,
 * credit 1300 Debiteuren) of Withdrawal (wij betalen → debet 1600 Crediteuren,
 * credit 1100 Bank). Deze service maakt die Transaction + legt de allocatie vast
 * (Payment) en houdt de doc-status in lijn. Bedragen in centen.
 */
class PaymentService
{
    private const RECEIVABLE = '1300';

    private const PAYABLE = '1600';

    /**
     * Doc-driven: registreer een betaling op een open post en genereer de
     * bijbehorende bank-boeking.
     */
    public function register(Invoice|Bill $document, int $bankAccountId, int $amount, string $date): Payment
    {
        if (! $document->isPosted()) {
            throw new RuntimeException('Boek de factuur eerst naar het grootboek vóór het afletteren.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Een betaling moet groter dan nul zijn.');
        }

        if ($amount > $document->amountDue()) {
            throw new RuntimeException('Het bedrag is hoger dan het openstaande saldo.');
        }

        $isInvoice = $document instanceof Invoice;
        $type = $isInvoice ? TransactionType::Deposit : TransactionType::Withdrawal;
        $counterCode = $isInvoice ? self::RECEIVABLE : self::PAYABLE;
        $reference = $isInvoice ? ($document->invoice_number ?? '#'.$document->getKey()) : ($document->bill_number ?? '#'.$document->getKey());
        $description = 'Betaling '.($isInvoice ? 'factuur' : 'inkoopfactuur').' '.$reference;

        $counterAccount = Account::query()->where('code', $counterCode)->first()
            ?? throw new RuntimeException("Grootboekrekening {$counterCode} ontbreekt — seed het grootboek.");

        return DB::transaction(function () use ($document, $type, $counterAccount, $bankAccountId, $amount, $date, $description): Payment {
            $transaction = Transaction::create([
                'type' => $type,
                'account_id' => $counterAccount->getKey(),
                'bank_account_id' => $bankAccountId,
                'amount' => $amount,
                'description' => $description,
                'posted_at' => $date,
            ]);

            $payment = $this->allocate($transaction, $document, $amount);

            return $payment;
        });
    }

    /**
     * Bank-driven: letter een bestaande bank-Transaction af tegen een open post.
     * De double-entry staat al (de Transaction is eerder geboekt op 1300/1600) —
     * dit legt alleen vast wélke post 'm settelt, na de match-validaties.
     */
    public function reconcile(Transaction $transaction, Invoice|Bill $document, int $amount): Payment
    {
        $isInvoice = $document instanceof Invoice;
        $expectedType = $isInvoice ? TransactionType::Deposit : TransactionType::Withdrawal;
        $expectedCode = $isInvoice ? self::RECEIVABLE : self::PAYABLE;

        if ($transaction->type !== $expectedType || $transaction->account?->code !== $expectedCode) {
            throw new RuntimeException('Deze banktransactie past niet bij dit type open post.');
        }

        if (! $document->isPosted()) {
            throw new RuntimeException('Boek de factuur eerst naar het grootboek vóór het afletteren.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Een aflettering moet groter dan nul zijn.');
        }

        if ($amount > $document->amountDue()) {
            throw new RuntimeException('Het bedrag is hoger dan het openstaande saldo van de post.');
        }

        if ($amount > $transaction->unallocatedAmount()) {
            throw new RuntimeException('Het bedrag is hoger dan het nog niet-afgeletterde deel van de transactie.');
        }

        return DB::transaction(fn (): Payment => $this->allocate($transaction, $document, $amount));
    }

    /**
     * Koppel (een deel van) een bestaande bank-Transaction aan een open post.
     * Gedeeld door doc-driven (nieuwe Transaction) en bank-driven afletteren.
     */
    public function allocate(Transaction $transaction, Invoice|Bill $document, int $amount): Payment
    {
        $payment = $document->payments()->create([
            'transaction_id' => $transaction->getKey(),
            'amount' => $amount,
        ]);

        $document->syncPaymentStatus();

        return $payment;
    }

    /**
     * Doc-driven undo: verwijder de allocatie én de bank-boeking die er bij hoort.
     */
    public function remove(Payment $payment): void
    {
        $document = $payment->payable;
        $transaction = $payment->transaction;

        DB::transaction(function () use ($payment, $transaction): void {
            $payment->delete();
            $transaction?->delete();
        });

        $document?->syncPaymentStatus();
    }
}
