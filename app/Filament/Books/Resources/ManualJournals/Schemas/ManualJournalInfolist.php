<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\ManualJournals\Schemas;

use App\Books\Models\Account;
use App\Books\Models\JournalEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ManualJournalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('posted_at')
                    ->label('Boekdatum')
                    ->dateTime('d-m-Y H:i'),

                TextEntry::make('amount')
                    ->label('Totaal')
                    ->formatStateUsing(fn (int $state): string => '€ '.number_format($state / 100, 2, ',', '.')),

                TextEntry::make('description')
                    ->label('Omschrijving'),

                TextEntry::make('reference')
                    ->label('Referentie')
                    ->placeholder('—'),

                RepeatableEntry::make('journalEntries')
                    ->label('Regels')
                    ->table([
                        TableColumn::make('Grootboekrekening'),
                        TableColumn::make('Debet'),
                        TableColumn::make('Credit'),
                    ])
                    ->schema([
                        TextEntry::make('account.name')
                            ->label('Grootboekrekening')
                            ->state(fn (JournalEntry $record): string => $record->account instanceof Account
                                ? "{$record->account->code} — {$record->account->name}"
                                : '—'),

                        TextEntry::make('debit')
                            ->label('Debet')
                            ->state(fn (JournalEntry $record): string => $record->type->isDebit()
                                ? '€ '.number_format($record->amount / 100, 2, ',', '.')
                                : '—'),

                        TextEntry::make('credit')
                            ->label('Credit')
                            ->state(fn (JournalEntry $record): string => $record->type->isCredit()
                                ? '€ '.number_format($record->amount / 100, 2, ',', '.')
                                : '—'),
                    ])
                    ->columnSpanFull(),

                TextEntry::make('notes')
                    ->label('Notities')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }
}
