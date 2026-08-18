<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use App\Books\Enums\JournalEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntry extends Model
{
    use BelongsToBooksCompany;

    protected $table = 'books_journal_entries';

    protected $fillable = [
        'company_id',
        'account_id',
        'transaction_id',
        'type',
        'amount',
        'description',
    ];

    protected $casts = [
        'type' => JournalEntryType::class,
        'amount' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
