<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use App\Books\Enums\TransactionType;
use App\Books\Observers\TransactionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/*
 * Boeking — koppelt een grootboekrekening (account_id) aan de cash-kant
 * (bank_account_id). De TransactionObserver post bij create de gebalanceerde
 * debet/credit-journaalpost via LedgerPoster. Bedrag in integer-centen.
 */
#[ObservedBy(TransactionObserver::class)]
class Transaction extends Model
{
    use BelongsToBooksCompany;

    protected $table = 'books_transactions';

    protected $fillable = [
        'company_id',
        'account_id',
        'bank_account_id',
        'type',
        'payment_channel',
        'description',
        'notes',
        'reference',
        'amount',
        'pending',
        'reviewed',
        'posted_at',
    ];

    protected $casts = [
        'type' => TransactionType::class,
        'amount' => 'integer',
        'pending' => 'boolean',
        'reviewed' => 'boolean',
        'posted_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'transaction_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'transaction_id');
    }

    public function allocatedAmount(): int
    {
        return (int) $this->payments()->sum('amount');
    }

    /**
     * Nog niet aan een open post toegewezen deel van deze bank-boeking.
     */
    public function unallocatedAmount(): int
    {
        return max(0, $this->amount - $this->allocatedAmount());
    }
}
