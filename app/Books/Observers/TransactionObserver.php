<?php

namespace App\Books\Observers;

use App\Books\Models\Transaction;
use App\Books\Services\LedgerPoster;

class TransactionObserver
{
    public function __construct(private readonly LedgerPoster $poster) {}

    public function created(Transaction $transaction): void
    {
        $this->poster->post($transaction);
    }
}
