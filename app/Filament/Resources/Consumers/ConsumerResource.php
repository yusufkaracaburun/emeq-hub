<?php

namespace App\Filament\Resources\Consumers;

use App\Filament\Resources\Consumers\Pages\CreateConsumer;
use App\Filament\Resources\Consumers\Pages\EditConsumer;
use App\Filament\Resources\Consumers\Pages\ListConsumers;
use App\Models\Consumer;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ConsumerResource extends Resource
{
    protected static ?string $model = Consumer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('accounts_count')
                    ->label('Accounts')
                    ->counts('accounts')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('connections_count')
                    ->label('Connections')
                    ->counts('connections')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConsumers::route('/'),
            'create' => CreateConsumer::route('/create'),
            'edit' => EditConsumer::route('/{record}/edit'),
        ];
    }
}
