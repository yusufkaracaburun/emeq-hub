<?php

namespace App\Books\Concerns;

use App\Books\Models\BooksCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/*
 * Single-company trait. Zet company_id op create uit config('books.company_id')
 * i.p.v. session/auth, en heeft GEEN global scope — er is maar één bedrijf, dus
 * niets te filteren. Zie D1 in .docs/decisions/books-module.md.
 */
trait BelongsToBooksCompany
{
    public static function bootBelongsToBooksCompany(): void
    {
        static::creating(static function ($model): void {
            if (empty($model->company_id)) {
                $model->company_id = (int) config('books.company_id', 1);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(BooksCompany::class, 'company_id');
    }
}
