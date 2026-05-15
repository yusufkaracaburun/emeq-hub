<?php

namespace App\Filament\Resources\Connections;

use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Filament\Resources\Connections\Pages\ViewConnection;
use App\Models\Connection;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConnectionResource extends Resource
{
    protected static ?string $model = Connection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    public static function form(Schema $schema): Schema
    {
        // Read-only resource: form() bestaat om Filament's contract te honoreren
        // maar exposeert niets — alle weergave loopt via infolist() (zie Task 2).
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Per-provider conditional sections worden gevuld in Task 2.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->badge()
                    ->colors([
                        'success' => 'mollie',
                        'info' => 'snelstart',
                    ])
                    ->sortable(),
                TextColumn::make('account.external_id')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('fingerprint')
                    ->label('Fingerprint')
                    ->state(fn (Connection $record): ?string => $record->fingerprint())
                    ->placeholder('—'),
                TextColumn::make('revoked_at')
                    ->label('Revoked at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options([
                        'mollie' => 'Mollie',
                        'snelstart' => 'Snelstart',
                    ]),
                SelectFilter::make('consumer')
                    ->relationship('account.consumer', 'slug')
                    ->label('Consumer'),
                TernaryFilter::make('revoked')
                    ->label('Revoked?')
                    ->nullable()
                    ->trueLabel('Revoked')
                    ->falseLabel('Active')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('revoked_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('revoked_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnections::route('/'),
            'view' => ViewConnection::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
