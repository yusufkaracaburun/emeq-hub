<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\ManualJournals\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ManualJournalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('posted_at', 'desc')
            ->columns([
                TextColumn::make('posted_at')
                    ->label('Datum')
                    ->date('d-m-Y')
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Omschrijving')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('reference')
                    ->label('Referentie')
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('journalEntries_count')
                    ->label('Regels')
                    ->counts('journalEntries')
                    ->alignCenter(),

                TextColumn::make('amount')
                    ->label('Bedrag')
                    ->formatStateUsing(fn (int $state): string => '€ '.number_format($state / 100, 2, ',', '.'))
                    ->alignEnd()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()->iconButton(),
            ]);
    }
}
