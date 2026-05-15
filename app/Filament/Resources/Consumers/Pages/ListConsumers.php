<?php

namespace App\Filament\Resources\Consumers\Pages;

use App\Filament\Resources\Consumers\ConsumerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConsumers extends ListRecords
{
    protected static string $resource = ConsumerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
