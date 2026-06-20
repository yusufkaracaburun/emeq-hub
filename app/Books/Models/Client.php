<?php

namespace App\Books\Models;

use App\Books\Concerns\BelongsToBooksCompany;
use App\Models\Consumer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/*
 * Debiteur in emeq's eigen boekhouding. Mag optioneel naar een Hub-`Consumer`
 * wijzen (de betalende klant die emeq al kent) — eenrichtings-link, nullOnDelete,
 * zodat dit financiële record het wissen van de operationele Consumer overleeft.
 */
class Client extends Model
{
    use BelongsToBooksCompany;

    protected $table = 'books_clients';

    protected $fillable = [
        'company_id',
        'consumer_id',
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

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }
}
