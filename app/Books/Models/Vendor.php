<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use BelongsToBooksCompany;

    protected $table = 'books_vendors';

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone',
        'vat_number',
        'coc_number',
        'address_line_1',
        'address_line_2',
        'postal_code',
        'city',
        'country_code',
        'website',
        'notes',
    ];
}
