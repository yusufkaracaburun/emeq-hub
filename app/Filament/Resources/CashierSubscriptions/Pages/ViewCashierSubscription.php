<?php

namespace App\Filament\Resources\CashierSubscriptions\Pages;

use App\Filament\Resources\CashierSubscriptions\CashierSubscriptionResource;
use App\Filament\Support\HasDetailLayout;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Laravel\Cashier\Subscription;

class ViewCashierSubscription extends ViewRecord
{
    use HasDetailLayout;

    protected static string $resource = CashierSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function content(Schema $schema): Schema
    {
        /** @var Subscription $record */
        $record = $this->getRecord();

        return $this->detailSchema($schema, CashierSubscriptionResource::statusStripSchema($record));
    }
}
