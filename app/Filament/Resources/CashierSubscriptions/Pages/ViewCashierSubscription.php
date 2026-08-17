<?php

namespace App\Filament\Resources\CashierSubscriptions\Pages;

use App\Filament\Resources\CashierSubscriptions\CashierSubscriptionResource;
use App\Filament\Support\DetailViewRecord;
use Filament\Schemas\Schema;
use Laravel\Cashier\Subscription;

class ViewCashierSubscription extends DetailViewRecord
{
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
