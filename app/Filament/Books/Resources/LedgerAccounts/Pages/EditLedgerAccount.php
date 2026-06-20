<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\LedgerAccounts\Pages;

use App\Books\Enums\AccountType;
use App\Filament\Books\Resources\LedgerAccounts\LedgerAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLedgerAccount extends EditRecord
{
    protected static string $resource = LedgerAccountResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['category'] = AccountType::from($data['type'])->getCategory()->value;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
