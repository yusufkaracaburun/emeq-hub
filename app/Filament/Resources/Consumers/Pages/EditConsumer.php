<?php

namespace App\Filament\Resources\Consumers\Pages;

use App\Filament\Resources\Consumers\ConsumerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditConsumer extends EditRecord
{
    protected static string $resource = ConsumerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
