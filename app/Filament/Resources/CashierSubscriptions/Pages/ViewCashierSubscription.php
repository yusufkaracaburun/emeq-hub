<?php

namespace App\Filament\Resources\CashierSubscriptions\Pages;

use App\Filament\Resources\CashierSubscriptions\CashierSubscriptionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCashierSubscription extends ViewRecord
{
    protected static string $resource = CashierSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
