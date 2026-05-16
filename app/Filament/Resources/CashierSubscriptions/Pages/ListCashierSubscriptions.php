<?php

namespace App\Filament\Resources\CashierSubscriptions\Pages;

use App\Filament\Resources\CashierSubscriptions\CashierSubscriptionResource;
use Filament\Resources\Pages\ListRecords;

class ListCashierSubscriptions extends ListRecords
{
    protected static string $resource = CashierSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
