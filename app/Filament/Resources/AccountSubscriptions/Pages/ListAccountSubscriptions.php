<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountSubscriptions\Pages;

use App\Filament\Resources\AccountSubscriptions\AccountSubscriptionResource;
use App\Filament\Support\InfoModalAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountSubscriptions extends ListRecords
{
    protected static string $resource = AccountSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InfoModalAction::make(
                'Over account-subscriptions',
                'Use-case B — een Consumer (bv. Naschool) factureert haar eigen eindgebruikers via de Hub. '
                .'Eén AccountSubscription per Account. Pause / Resume / Cancel zijn alleen zichtbaar als de huidige status een legale overgang toelaat (state-machine via AccountSubscriptionManager). '
                .'Illegale overgang → notification-error, géén DB-mutatie.',
            ),
        ];
    }
}
