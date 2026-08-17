<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\DetailViewRecord;
use App\Filament\Support\InfoModalAction;
use App\Models\Account;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;

class ViewAccount extends DetailViewRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InfoModalAction::make(
                'Wat is een Account?',
                'Een klant van een Consumer (bv. school A bij Naschool). Niet de individuele eindgebruiker/ouder.',
            ),
            EditAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        /** @var Account $record */
        $record = $this->getRecord();

        return $this->detailSchema($schema, AccountResource::statusStripSchema($record));
    }
}
