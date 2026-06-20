<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\LedgerAccounts\Pages;

use App\Filament\Books\Resources\LedgerAccounts\LedgerAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLedgerAccounts extends ListRecords
{
    protected static string $resource = LedgerAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
