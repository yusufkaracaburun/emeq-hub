<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\Invoices\Tables;

use App\Books\Enums\InvoiceStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')
                    ->label('Datum')
                    ->date('d-m-Y')
                    ->sortable(),

                TextColumn::make('invoice_number')
                    ->label('Nr.')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('client.name')
                    ->label('Klant')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),

                TextColumn::make('total')
                    ->label('Totaal')
                    ->formatStateUsing(fn (int $state): string => '€ '.number_format($state / 100, 2, ',', '.'))
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(InvoiceStatus::cases())
                        ->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => $status->getLabel()])
                        ->all()),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
