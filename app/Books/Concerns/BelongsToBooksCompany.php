<?php

namespace App\Books\Concerns;

use App\Books\Models\BooksCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
