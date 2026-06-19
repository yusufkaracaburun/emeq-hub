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
                'Onboarding-leads vanaf de koppel-formulieren op de partner-pagina\'s. Klik "Onboard" om de '
                .'onboarding-wizard voorgevuld te openen (naam, slug, app-URL en — bij één gevraagde '
                .'integratie — de provider); na afronden wordt de aanvraag automatisch aan de nieuwe '
                .'Consumer gekoppeld en op afgehandeld gezet. De badge telt nieuwe aanvragen.',
            ),
        ];
    }
}
