<?php

namespace App\Filament\Resources\Connections\Pages;

use App\Filament\Actions\ManageAccountingMappingAction;
use App\Filament\Actions\StartOAuthFlowAction;
use App\Filament\Resources\Connections\ConnectionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewConnection extends ViewRecord
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        // Dezelfde acties als de lijst-rij, nu ook vanaf de detailpagina bereikbaar.
        return [
            StartOAuthFlowAction::forConnection(),
            ManageAccountingMappingAction::make(),
            ConnectionResource::refreshTokenAction(),
            ConnectionResource::revokeAction(),
        ];
    }
}
