<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Wat is een Account?')
                    ->description('Een klant van een Consumer (bv. school A bij Naschool). Niet de individuele eindgebruiker/ouder.')
                    ->collapsed()
                    ->schema([]),
                TextEntry::make('id')
                    ->label('ID'),
                TextEntry::make('consumer.slug')
                    ->label('Consumer'),
                TextEntry::make('external_id')
                    ->label('External ID'),
                TextEntry::make('display_name')
                    ->label('Display name'),
                TextEntry::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label('Bijgewerkt')
                    ->dateTime(),
            ]);
    }
}
