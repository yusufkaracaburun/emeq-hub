<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Bills\Pages;

use App\Filament\Books\Resources\Bills\BillResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBills extends ListRecords
{
    protected static string $resource = BillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
