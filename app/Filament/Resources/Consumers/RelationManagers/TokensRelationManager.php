<?php

declare(strict_types=1);

namespace App\Filament\Resources\Consumers\RelationManagers;

use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Laravel\Sanctum\PersonalAccessToken;

final class TokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static ?string $title = 'Tokens (PAT)';

    protected static bool $isReadOnly = false;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable(),
                TextColumn::make('abilities')
                    ->label('Abilities')
                    ->badge()
                    ->separator(','),
                TextColumn::make('last_used_at')
                    ->label('Laatst gebruikt')
                    ->dateTime('d-m-Y H:i')
                    ->placeholder('nooit')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Uitgegeven')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Intrekken')
                    ->authorize(fn (): bool => auth()->user()?->can('manage-consumers') ?? false)
                    ->modalHeading('Token intrekken')
                    ->modalDescription(fn (PersonalAccessToken $record): string => "Token '{$record->name}' wordt direct ongeldig. Apps die het gebruiken krijgen 401 tot ze een nieuw token hebben."),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
