<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Transactions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('posted_at')
                    ->label('Boekdatum')
                    ->dateTime('d-m-Y H:i'),

                TextEntry::make('type')
                    ->label('Type')
                    ->badge(),

                TextEntry::make('amount')
                    ->label('Bedrag')
                    ->formatStateUsing(fn (int $state): string => '€ '.number_format($state / 100, 2, ',', '.')),

                TextEntry::make('description')
                    ->label('Omschrijving'),

                TextEntry::make('reference')
                    ->label('Referentie')
                    ->placeholder('—'),

                TextEntry::make('bankAccount.account.name')
                    ->label('Bankrekening'),

                TextEntry::make('account.name')
                    ->label('Tegenrekening (grootboek)'),

                TextEntry::make('notes')
                    ->label('Notities')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }
}
