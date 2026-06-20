<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\LedgerAccounts\Pages;

use App\Books\Enums\AccountType;
use App\Filament\Books\Resources\LedgerAccounts\LedgerAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLedgerAccount extends CreateRecord
{
    protected static string $resource = LedgerAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['category'] = AccountType::from($data['type'])->getCategory()->value;

        return $data;
    }
}
