<?php

namespace App\Filament\Resources\Connections\Pages;

use App\Filament\Resources\Connections\ConnectionResource;
use Filament\Resources\Pages\ListRecords;

class ListConnections extends ListRecords
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        // Connections worden aangemaakt via OAuth-flow (Phase 4) of
        // hub:consumer:create-CLI — niet via admin-UI.
        return [];
    }
}
