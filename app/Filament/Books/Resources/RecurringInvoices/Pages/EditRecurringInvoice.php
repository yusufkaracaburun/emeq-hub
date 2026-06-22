<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\RecurringInvoices\Pages;

use App\Filament\Books\Resources\RecurringInvoices\RecurringInvoiceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRecurringInvoice extends EditRecord
{
    protected static string $resource = RecurringInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
