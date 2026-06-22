<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\RecurringInvoices\Pages;

use App\Filament\Books\Resources\RecurringInvoices\RecurringInvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringInvoice extends CreateRecord
{
    protected static string $resource = RecurringInvoiceResource::class;
}
