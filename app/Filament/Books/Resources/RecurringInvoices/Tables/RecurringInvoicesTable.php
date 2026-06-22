<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\RecurringInvoices\Tables;

use App\Books\Enums\RecurringStatus;
use App\Books\Models\RecurringInvoice;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RecurringInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('next_date')
            ->columns([
                TextColumn::make('client.name')
                    ->label('Klant')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('frequency')
                    ->label('Frequentie')
                    ->badge(),

                TextColumn::make('next_date')
                    ->label('Volgende')
                    ->date('d-m-Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),

                TextColumn::make('occurrences_count')
                    ->label('Gegenereerd')
                    ->alignCenter(),
            ])
            ->recordActions([
                Action::make('toggleStatus')
                    ->label(fn (RecurringInvoice $record): string => $record->status === RecurringStatus::Paused ? 'Hervat' : 'Pauzeer')
                    ->icon(fn (RecurringInvoice $record): Heroicon => $record->status === RecurringStatus::Paused ? Heroicon::OutlinedPlay : Heroicon::OutlinedPause)
                    ->visible(fn (RecurringInvoice $record): bool => $record->status !== RecurringStatus::Ended)
                    ->action(function (RecurringInvoice $record): void {
                        $record->status = $record->status === RecurringStatus::Paused
                            ? RecurringStatus::Active
                            : RecurringStatus::Paused;
                        $record->save();
                    }),

                EditAction::make()->iconButton(),
            ]);
    }
}
