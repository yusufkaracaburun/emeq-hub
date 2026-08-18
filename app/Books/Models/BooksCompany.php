<?php

namespace App\Books\Models;

use Illuminate\Database\Eloquent\Model;

class BooksCompany extends Model
{
    protected $table = 'books_companies';

    protected $fillable = [
        'name',
    ];
}
