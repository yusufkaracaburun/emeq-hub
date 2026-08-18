<?php

namespace App\Books\Observers;

use App\Books\Models\BillLine;

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
