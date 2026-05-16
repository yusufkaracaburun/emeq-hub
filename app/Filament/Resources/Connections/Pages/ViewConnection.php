<?php

namespace App\Filament\Resources\Connections\Pages;

use App\Filament\Resources\Connections\ConnectionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewConnection extends ViewRecord
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        // Read-only: geen Edit/Delete. Revoke leeft als row-action in de table (Task 2).
        return [];
    }
}
