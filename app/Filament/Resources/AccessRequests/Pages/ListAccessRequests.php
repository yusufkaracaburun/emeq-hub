<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccessRequests\Pages;

use App\Filament\Resources\AccessRequests\AccessRequestResource;
use App\Filament\Support\InfoModalAction;
use Filament\Resources\Pages\ListRecords;

class ListAccessRequests extends ListRecords
{
    protected static string $resource = AccessRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InfoModalAction::make(
                'Over koppel-aanvragen',
                'Onboarding-leads vanaf de publieke /koppelen-pagina. Bekijk een aanvraag, '
                .'onboard de consumer via de OnboardConsumer-wizard (Tenants → Onboard) en markeer de '
                .'aanvraag daarna afgehandeld. De badge telt het aantal nieuwe aanvragen.',
            ),
        ];
    }
}
