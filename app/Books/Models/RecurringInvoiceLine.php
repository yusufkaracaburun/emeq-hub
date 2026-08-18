<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
