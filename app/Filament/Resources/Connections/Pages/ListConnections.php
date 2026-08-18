<?php

namespace App\Filament\Resources\Connections\Pages;

use App\Filament\Resources\Connections\ConnectionResource;
use App\Filament\Support\InfoModalAction;
use Filament\Resources\Pages\ListRecords;

class ListConnections extends ListRecords
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InfoModalAction::make(
                'Wat is een Connection?',
                'Een Connection is één OAuth-koppeling tussen een Account en een Provider (Mollie, Snelstart, …). '
                .'Tokens zijn versleuteld opgeslagen; in deze lijst zie je alleen een fingerprint. Mollie-Connections kun je hier revoken; Snelstart gebruikt geen OAuth dus die actie is daar verborgen.',
            ),
        ];
    }
}
