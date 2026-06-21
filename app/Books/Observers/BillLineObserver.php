<?php

namespace App\Books\Observers;

use App\Books\Models\BillLine;

/*
 * Houdt de afgeleide bedragen consistent: per regel subtotal/tax/total uit
 * quantity × unit_price × tax_rate, en daarna de bill-totalen. UI-agnostisch —
 * werkt voor Filament, tinker en tests gelijk. Spiegel van InvoiceLineObserver.
 */
class BillLineObserver
{
    public function saving(BillLine $line): void
    {
        $subtotal = (int) round(((float) $line->quantity) * $line->unit_price);
        $taxAmount = (int) round($subtotal * $line->tax_rate / 100);

        $line->subtotal = $subtotal;
        $line->tax_amount = $taxAmount;
        $line->total = $subtotal + $taxAmount;
    }

    public function saved(BillLine $line): void
    {
        $line->bill?->recalculateTotals();
    }

    public function deleted(BillLine $line): void
    {
        $line->bill?->recalculateTotals();
    }
}
