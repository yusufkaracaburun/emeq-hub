<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemoRequests\Pages;

use App\Filament\Resources\DemoRequests\DemoRequestResource;
use App\Filament\Support\InfoModalAction;
use Filament\Resources\Pages\ListRecords;

class ListDemoRequests extends ListRecords
{
    protected static string $resource = DemoRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InfoModalAction::make(
                'Over demo-aanvragen',
                'Leads vanaf de publieke /demo-pagina. De aanvrager kiest een voorkeursmoment; het inplannen '
                .'zelf gebeurt buiten de Hub. Beantwoord de meldingsmail — die gaat rechtstreeks naar de '
                .'aanvrager — en markeer de aanvraag daarna afgehandeld. De badge telt nieuwe aanvragen.',
            ),
        ];
    }
}
