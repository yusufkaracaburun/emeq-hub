<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    public function getSubheading(): ?string
    {
        return 'Een Account is een eindgebruiker bij een Consumer — bijvoorbeeld één school binnen Naschool. '
            .'Accounts worden niet hier aangemaakt maar via de Hub-API (`POST /v1/accounts`) of seeders. Aan elk Account hang je één of meer Connections (OAuth-tokens).';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
