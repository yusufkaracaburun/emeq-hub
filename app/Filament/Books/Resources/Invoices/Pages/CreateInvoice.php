<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Invoices\Pages;

use App\Filament\Books\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;
}
