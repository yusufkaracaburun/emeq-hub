<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use App\Books\Concerns\HasAttachments;
use App\Books\Concerns\HasPayments;
use App\Books\Enums\InvoiceStatus;
use App\Books\Exceptions\PostedDocumentException;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/*
 * Verkoopfactuur (debiteur). Header + regels; subtotal/tax_total/total zijn
 * afgeleid van de regels en worden door de InvoiceLineObserver herrekend — niet
 * handmatig gezet. Posten naar het grootboek volgt in een eigen slice.
 */
class Invoice extends Model
{
    use BelongsToBooksCompany;
    use HasAttachments;
    use HasPayments;

    protected $table = 'books_invoices';

    protected $fillable = [
        'company_id',
        'client_id',
        'transaction_id',
        'invoice_number',
        'status',
        'date',
        'due_date',
        'subtotal',
        'tax_total',
        'total',
        'notes',
    ];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'integer',
        'tax_total' => 'integer',
        'total' => 'integer',
    ];

    /**
     * Onwijzigbaarheid van een geboekte factuur: zodra ze aan een Transaction hangt,
     * blokkeer je een (niet-quiet) update of delete — anders divergeert de factuur van
     * het grootboek. De boeking zelf (transaction_id van null → gezet) blijft toegestaan;
     * payment-status- en totaal-herrekeningen lopen via saveQuietly en omzeilen deze guard.
     */
    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            if ($invoice->getOriginal('transaction_id') !== null) {
                throw PostedDocumentException::immutable();
            }
        });

        static::deleting(function (self $invoice): void {
            if ($invoice->isPosted()) {
                throw PostedDocumentException::immutable();
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id');
    }

    /**
     * Een factuur is geboekt zodra ze aan een memoriaal-Transaction hangt.
     */
    public function isPosted(): bool
    {
        return $this->transaction_id !== null;
    }

    public function paidStatus(): BackedEnum
    {
        return InvoiceStatus::Paid;
    }

    public function unpaidStatus(): BackedEnum
    {
        return InvoiceStatus::Sent;
    }

    /**
     * Tel de regel-bedragen op tot de factuur-totalen. Quiet — voorkomt een
     * observer-lus en raakt geen timestamps.
     */
    public function recalculateTotals(): void
    {
        $lines = $this->lines()->get();

        $this->subtotal = (int) $lines->sum('subtotal');
        $this->tax_total = (int) $lines->sum('tax_amount');
        $this->total = (int) $lines->sum('total');

        $this->saveQuietly();
    }
}
