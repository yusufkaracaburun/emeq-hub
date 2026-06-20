<?php

namespace App\Books\Observers;

use App\Books\Models\InvoiceLine;

/*
 * Houdt de afgeleide bedragen consistent: per regel subtotal/tax/total uit
 * quantity × unit_price × tax_rate, en daarna de factuur-totalen. UI-agnostisch
 * — werkt voor Filament, tinker en tests gelijk.
 */
class InvoiceLineObserver
{
    public function saving(InvoiceLine $line): void
    {
        $subtotal = (int) round(((float) $line->quantity) * $line->unit_price);
        $taxAmount = (int) round($subtotal * $line->tax_rate / 100);

        $line->subtotal = $subtotal;
        $line->tax_amount = $taxAmount;
        $line->total = $subtotal + $taxAmount;
    }

    public function saved(InvoiceLine $line): void
    {
        $line->invoice?->recalculateTotals();
    }

    public function deleted(InvoiceLine $line): void
    {
        $line->invoice?->recalculateTotals();
    }
}
