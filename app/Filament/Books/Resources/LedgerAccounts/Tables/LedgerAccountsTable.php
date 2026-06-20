<?php

declare(strict_types=1);

namespace App\Filament\Books\Resources\LedgerAccounts\Tables;

use App\Books\Enums\AccountCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LedgerAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Nr.')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Categorie')
                    ->badge()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('subtype.name')
                    ->label('Subcategorie')
                    ->toggleable()
                    ->placeholder('—'),

                IconColumn::make('archived')
                    ->label('Gearchiveerd')
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Categorie')
                    ->options(collect(AccountCategory::cases())
                        ->mapWithKeys(fn (AccountCategory $category): array => [$category->value => $category->getLabel()])
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
