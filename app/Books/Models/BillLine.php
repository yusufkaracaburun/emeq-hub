<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use App\Books\Observers\BillLineObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/*
 * Eén inkoopregel. quantity × unit_price (centen) → subtotal; tax_rate is een
 * BTW-percentage. account_id is de kostenrekening waarop de regel geboekt wordt
 * (de echte categorie-keuze die inkoop van verkoop onderscheidt).
 * subtotal/tax_amount/total worden door de BillLineObserver gezet, niet handmatig.
 */
#[ObservedBy(BillLineObserver::class)]
class BillLine extends Model
{
    use BelongsToBooksCompany;

    protected $table = 'books_bill_lines';

    protected $fillable = [
        'company_id',
        'bill_id',
        'account_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'subtotal',
        'tax_amount',
        'total',
        'sort',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'integer',
        'tax_rate' => 'integer',
        'subtotal' => 'integer',
        'tax_amount' => 'integer',
        'total' => 'integer',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
