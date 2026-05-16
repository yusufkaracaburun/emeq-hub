<?php

namespace App\Filament\Resources\CashierSubscriptions\Pages;

use App\Filament\Resources\CashierSubscriptions\CashierSubscriptionResource;
use Filament\Resources\Pages\ListRecords;

class ListCashierSubscriptions extends ListRecords
{
    protected static string $resource = CashierSubscriptionResource::class;

    public function getSubheading(): ?string
    {
        return 'Use-case A — Emeq factureert haar Consumers (= eigen SaaS-apps) voor het gebruik van de Hub. '
            .'Beheerd door Cashier-Mollie; je ziet hier alle subscriptions met owner (=Consumer), plan en derived status (active / on-trial / cancelled / on grace period / ended). '
            .'Read-only — beheer via Cashier-API of Mollie-dashboard.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
