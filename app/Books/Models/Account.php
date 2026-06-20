<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use App\Books\Enums\AccountCategory;
use App\Books\Enums\AccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/*
 * Grootboekrekening (chart of accounts). Geport uit ERPSAAS; reporting-scopes,
 * saldo-attributes en de bank-reconciliatie-static-finders volgen in latere
 * fases (UI/rapportage). Bedragen leven niet op dit model maar op JournalEntry.
 */
class Account extends Model
{
    use BelongsToBooksCompany;

    protected $table = 'books_accounts';

    protected $fillable = [
        'company_id',
        'subtype_id',
        'parent_id',
        'category',
        'type',
        'code',
        'name',
        'currency_code',
        'description',
        'archived',
        'default',
    ];

    protected $casts = [
        'category' => AccountCategory::class,
        'type' => AccountType::class,
        'archived' => 'boolean',
        'default' => 'boolean',
    ];

    public function subtype(): BelongsTo
    {
        return $this->belongsTo(AccountSubtype::class, 'subtype_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class, 'account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'account_id');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'account_id');
    }

    public function isUncategorized(): bool
    {
        return $this->type?->isUncategorized() ?? false;
    }
}
