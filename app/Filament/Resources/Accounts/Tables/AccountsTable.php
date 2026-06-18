<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Tables;

use App\Filament\Actions\StartOAuthFlowAction;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('consumer.slug')
                    ->label('Consumer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('external_id')
                    ->label('External ID')
                    ->searchable(),
                TextColumn::make('display_name')
                    ->label('Display name')
                    ->searchable(),
                TextColumn::make('connections_count')
                    ->label('Connections')
                    ->counts('connections')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('consumer')
                    ->relationship('consumer', 'slug')
                    ->label('Consumer')
                    ->searchable()
                    ->preload(),
            ])
            ->recordUrl(fn (Account $record): string => AccountResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                StartOAuthFlowAction::forAccount()->iconButton(),
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ]);
    }
}
