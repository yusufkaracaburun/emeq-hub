<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getSubheading(): ?string
    {
        return 'Emeq-medewerkers met toegang tot dit paneel. Twee rollen: `super-admin` (alles) en `staff` (alles behalve user-management). '
            .'Deze pagina is alleen zichtbaar voor super-admins. Bootstrap-user wordt via EmeqStaffSeeder + env-vars aangemaakt.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
