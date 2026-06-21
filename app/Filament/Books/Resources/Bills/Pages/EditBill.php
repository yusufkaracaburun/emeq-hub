<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Bills\Pages;

use App\Filament\Books\Resources\Bills\BillResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBill extends EditRecord
{
    protected static string $resource = BillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
