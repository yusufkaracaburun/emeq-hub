<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\InfoModalAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InfoModalAction::make(
                'Wat is een Account?',
                'Een klant van een Consumer (bv. school A bij Naschool). Niet de individuele eindgebruiker/ouder.',
            ),
            EditAction::make(),
        ];
    }
}
