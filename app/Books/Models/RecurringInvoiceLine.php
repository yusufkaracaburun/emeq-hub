<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/*
 * Eén template-regel van een terugkerende factuur. Géén observer/totalen — de
 * RecurringInvoiceGenerator kopieert deze naar een echte InvoiceLine, die haar
 * subtotaal/BTW/totaal zelf rekent. unit_price in integer-centen.
 */
class RecurringInvoiceLine extends Model
{
    use BelongsToBooksCompany;

    protected $table = 'books_recurring_invoice_lines';

    protected $fillable = [
        'company_id',
        'recurring_invoice_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'sort',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'integer',
        'tax_rate' => 'integer',
        'sort' => 'integer',
    ];

    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class, 'recurring_invoice_id');
    }
}
