<?php

namespace App\Books\Concerns;

use App\Books\Models\Payment;
use BackedEnum;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/*
 * Betaal-allocatie-logica voor een open post (Invoice/Bill). "Betaald" is
 * afgeleid uit Σ allocaties vs het doc-totaal — niet een losse vlag. De
 * gebruikende class levert de eigen status-enumwaarden (paid/unpaid), zodat het
 * status-vocabulaire bij het model blijft.
 */
trait HasPayments
{
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function amountPaid(): int
    {
        return (int) $this->payments()->sum('amount');
    }

    public function amountDue(): int
    {
        return max(0, $this->total - $this->amountPaid());
    }

    public function isPaid(): bool
    {
        return $this->total > 0 && $this->amountDue() === 0;
    }

    public function isPartiallyPaid(): bool
    {
        $paid = $this->amountPaid();

        return $paid > 0 && $paid < $this->total;
    }

    /**
     * Houdt de workflow-status in lijn met de betaal-stand: volledig afgeletterd
     * → betaald; een eerder-betaalde doc die weer openstaat → terug naar de
     * open-status. Partiële betalingen laten de status ongemoeid (openstaand-
     * saldo is daar de drager).
     */
    public function syncPaymentStatus(): void
    {
        if ($this->isPaid()) {
            $this->status = $this->paidStatus();
        } elseif ($this->status === $this->paidStatus()) {
            $this->status = $this->unpaidStatus();
        } else {
            return;
        }

        $this->saveQuietly();
    }

    abstract public function paidStatus(): BackedEnum;

    abstract public function unpaidStatus(): BackedEnum;
}
