<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/*
 * Bankrekening — de "cash"-kant van een boeking. Gekoppeld aan een grootboek-
 * rekening (Account, meestal een Asset). Plaid/ConnectedBankAccount is bewust
 * weggelaten in v1 (zie ADR out-of-scope).
 */
class BankAccount extends Model
{
    use BelongsToBooksCompany;

    protected $table = 'books_bank_accounts';

    protected $fillable = [
        'company_id',
        'account_id',
        'type',
        'number',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'bank_account_id');
    }
}
