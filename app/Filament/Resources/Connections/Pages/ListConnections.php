<?php

namespace App\Filament\Resources\Connections\Pages;

use App\Filament\Resources\Connections\ConnectionResource;
use Filament\Resources\Pages\ListRecords;

class ListConnections extends ListRecords
{
    protected static string $resource = ConnectionResource::class;

    public function getSubheading(): ?string
    {
        return 'Een Connection is één OAuth-koppeling tussen een Account en een Provider (Mollie, Snelstart, …). '
            .'Tokens zijn versleuteld opgeslagen; in deze lijst zie je alleen een fingerprint. Mollie-Connections kun je hier revoken; Snelstart gebruikt geen OAuth dus die actie is daar verborgen.';
    }

    protected function getHeaderActions(): array
    {
        // Connections worden aangemaakt via OAuth-flow (Phase 4) of
        // hub:consumer:create-CLI — niet via admin-UI.
        return [];
    }
}
