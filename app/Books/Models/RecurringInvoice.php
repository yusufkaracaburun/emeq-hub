<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use App\Books\Enums\RecurringFrequency;
use App\Books\Enums\RecurringStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringInvoice extends Model
{
    use BelongsToBooksCompany;

    protected $table = 'books_recurring_invoices';

    protected $fillable = [
        'company_id',
        'client_id',
        'status',
        'frequency',
        'start_date',
        'next_date',
        'end_date',
        'max_occurrences',
        'occurrences_count',
        'due_days',
        'notes',
    ];

    protected $casts = [
        'status' => RecurringStatus::class,
        'frequency' => RecurringFrequency::class,
        'start_date' => 'date',
        'next_date' => 'date',
        'end_date' => 'date',
        'max_occurrences' => 'integer',
        'occurrences_count' => 'integer',
        'due_days' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(static function (RecurringInvoice $template): void {
            if (empty($template->next_date)) {
                $template->next_date = $template->start_date;
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecurringInvoiceLine::class, 'recurring_invoice_id');
    }

    public function hasReachedEnd(CarbonInterface $next): bool
    {
        if ($this->max_occurrences !== null && $this->occurrences_count >= $this->max_occurrences) {
            return true;
        }

        return $this->end_date !== null && $next->gt($this->end_date);
    }
}
