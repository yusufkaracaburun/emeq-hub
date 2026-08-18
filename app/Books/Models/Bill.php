<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use App\Books\Concerns\HasAttachments;
use App\Books\Concerns\HasPayments;
use App\Books\Enums\BillStatus;
use App\Books\Exceptions\PostedDocumentException;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    use BelongsToBooksCompany;
    use HasAttachments;
    use HasPayments;

    protected $table = 'books_bills';

    protected $fillable = [
        'company_id',
        'vendor_id',
        'transaction_id',
        'bill_number',
        'status',
        'date',
        'due_date',
        'subtotal',
        'tax_total',
        'total',
        'notes',
    ];

    protected $casts = [
        'status' => BillStatus::class,
        'date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'integer',
        'tax_total' => 'integer',
        'total' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $bill): void {
            if ($bill->getOriginal('transaction_id') !== null) {
                throw PostedDocumentException::immutable();
            }
        });

        static::deleting(function (self $bill): void {
            if ($bill->isPosted()) {
                throw PostedDocumentException::immutable();
            }
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class, 'bill_id');
    }

    public function isPosted(): bool
    {
        return $this->transaction_id !== null;
    }

    public function paidStatus(): BackedEnum
    {
        return BillStatus::Paid;
    }

    public function unpaidStatus(): BackedEnum
    {
        return BillStatus::Received;
    }

    public function recalculateTotals(): void
    {
        $lines = $this->lines()->get();

        $this->subtotal = (int) $lines->sum('subtotal');
        $this->tax_total = (int) $lines->sum('tax_amount');
        $this->total = (int) $lines->sum('total');

        $this->saveQuietly();
    }
}
