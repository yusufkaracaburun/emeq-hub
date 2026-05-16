<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consumers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class AccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'accounts';

    protected static ?string $title = 'Accounts';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('external_id')
            ->columns([
                TextColumn::make('external_id')
                    ->label('External ID')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('display_name')
                    ->label('Naam')
                    ->searchable(),
                TextColumn::make('connections_count')
                    ->label('Connections')
                    ->counts('connections')
                    ->badge(),
                TextColumn::make('account_subscriptions_count')
                    ->label('Subscriptions')
                    ->counts('accountSubscriptions')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
