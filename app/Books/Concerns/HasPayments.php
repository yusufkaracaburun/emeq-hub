<?php

namespace App\Books\Concerns;

use App\Books\Models\Payment;
use BackedEnum;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
