<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Models\Connection;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ConnectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'connections';

    protected static ?string $title = 'Connections';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('provider')
                    ->label('Provider')
                    ->badge(),
                TextColumn::make('fingerprint')
                    ->label('Fingerprint')
                    ->state(fn (Connection $record): ?string => $record->fingerprint())
                    ->placeholder('—')
                    ->fontFamily('mono'),
                TextColumn::make('expires_at')
                    ->label('Token expires')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('revoked_at')
                    ->label('Revoked')
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-o-check-circle')
                    ->falseColor('success'),
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
