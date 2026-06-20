<?php

namespace App\Books\Models;

use Illuminate\Database\Eloquent\Model;

/*
 * De ene company waaronder emeq's boeken vallen (single-company, D1). Bewust
 * minimaal — vervangt filament-companies' Company-model alleen als FK-anker
 * voor de company_id-kolommen.
 */
class BooksCompany extends Model
{
    protected $table = 'books_companies';

    protected $fillable = [
        'name',
    ];
}
