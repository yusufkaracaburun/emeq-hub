<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use App\Books\Enums\AccountCategory;
use App\Books\Enums\AccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountSubtype extends Model
{
    use BelongsToBooksCompany;

    protected $table = 'books_account_subtypes';

    protected $fillable = [
        'company_id',
        'multi_currency',
        'category',
        'type',
        'name',
        'description',
    ];

    protected $casts = [
        'category' => AccountCategory::class,
        'type' => AccountType::class,
        'multi_currency' => 'boolean',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'subtype_id');
    }
}
