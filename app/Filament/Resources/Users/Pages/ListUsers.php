<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\InfoModalAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InfoModalAction::make(
                'Over gebruikers',
                'Emeq-medewerkers met toegang tot dit paneel. Twee rollen: `super-admin` (alles) en `staff` (alles behalve user-management). '
                .'Deze pagina is alleen zichtbaar voor super-admins. Bootstrap-user wordt via EmeqStaffSeeder + env-vars aangemaakt.',
            ),
            CreateAction::make(),
        ];
    }
}
