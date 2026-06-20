<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Invoices\Pages;

use App\Filament\Books\Resources\Invoices\InvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
