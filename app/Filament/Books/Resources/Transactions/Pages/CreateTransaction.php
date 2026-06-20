<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Transactions\Pages;

use App\Filament\Books\Resources\Transactions\TransactionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTransaction extends CreateRecord
{
    protected static string $resource = TransactionResource::class;

    /**
     * Euro-invoer → integer-centen (de opslag-eenheid op het Transaction-model).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['amount'] = (int) round(((float) $data['amount']) * 100);

        return $data;
    }
}
