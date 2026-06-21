<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/*
 * Eén betaling-allocatie: koppelt een bank-leg (Transaction) aan een open post
 * (Invoice of Bill) met een bedrag in centen. De double-entry zit in de
 * gekoppelde Transaction (LedgerPoster); deze rij legt alleen de toewijzing vast.
 */
class Payment extends Model
{
    use BelongsToBooksCompany;

    protected $table = 'books_payments';

    protected $fillable = [
        'company_id',
        'transaction_id',
        'payable_type',
        'payable_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
