<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use Illuminate\Database\Eloquent\Model;

/*
 * Crediteur in emeq's eigen boekhouding (hosting, tooling, accountant, …). Heeft
 * géén Hub-equivalent — leveranciers bestaan niet in de integratielaag.
 */
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
