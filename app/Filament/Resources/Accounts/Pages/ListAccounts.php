<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\InfoModalAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InfoModalAction::make(
                'Wat is een Account?',
                'Een Account is een klant van een Consumer — bijvoorbeeld één school binnen Naschool, niet de individuele eindgebruiker/ouder. '
                .'Accounts komen primair via de Hub-API (POST /v1/accounts) binnen, maar je kunt ze hier ook handmatig beheren. Aan elk Account hang je één of meer Connections (OAuth-tokens).',
            ),
            CreateAction::make(),
        ];
    }
}
